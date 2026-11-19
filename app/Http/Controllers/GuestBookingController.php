<?php

namespace App\Http\Controllers;

use App\Engines\DocumentEngine;
use App\Enums\BookingStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Mail\GuestLoginMail;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\GuestAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "My bookings" for guests, without a password: the bookings of their email
 * address, each with its documents (confirmation, invoice, receipts), what
 * is still to pay and the ID check. See App\Support\GuestAccess for who may
 * see what.
 */
class GuestBookingController extends Controller
{
    /** Login links per address per 10 minutes. */
    private const LINKS_PER_ADDRESS = 3;

    public function index(Request $request): View
    {
        $email = GuestAccess::email($request);

        return view('site.guest.index', [
            'email' => $email,
            'bookings' => $email === null ? collect() : Booking::query()
                ->with('property')
                ->whereRaw('lower(guest_email) = ?', [$email])
                ->orderByDesc('arrival_date')
                ->limit(100)
                ->get(),
        ]);
    }

    /** Always the same answer, so nobody can find out who books here. */
    public function sendLink(Request $request): RedirectResponse
    {
        $email = GuestAccess::normalise((string) $request->validate(['email' => ['required', 'email', 'max:255']])['email']);
        $limit = 'guest-link:'.hash('sha256', $email);

        if (! RateLimiter::tooManyAttempts($limit, self::LINKS_PER_ADDRESS)) {
            RateLimiter::hit($limit, 600);

            // Queued like the other mails (sent within a minute by the
            // scheduled task): sending right away takes time, and a slower
            // answer would tell that this address has booked here.
            if (Booking::query()->whereRaw('lower(guest_email) = ?', [$email])->exists()) {
                try {
                    Mail::to($email)->queue(new GuestLoginMail(GuestAccess::issueLink($email), app()->getLocale()));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return redirect()->route('guest.bookings')->with('guest_status', __('If we know this address, we have sent a login link to it. It is valid for :minutes minutes.', ['minutes' => GuestAccess::LINK_MINUTES]));
    }

    /**
     * The link from the email only shows a button: mail programs that open
     * links in advance to scan them would otherwise use up the one-time link.
     */
    public function confirmLogin(string $token): View
    {
        return view('site.guest.login', ['token' => $token]);
    }

    public function login(Request $request, string $token): RedirectResponse
    {
        $cookie = GuestAccess::redeem($request, $token);

        if ($cookie === null) {
            return redirect()->route('guest.bookings')->with('guest_error', __('This login link has expired or has already been used. Ask for a new one below.'));
        }

        return redirect()->route('guest.bookings')->withCookie($cookie);
    }

    public function logout(Request $request): RedirectResponse
    {
        $cookie = GuestAccess::logout($request, everywhere: $request->boolean('everywhere'));

        return redirect()->route('guest.bookings')->withCookie($cookie)->with('guest_status', __('You have been logged out.'));
    }

    public function show(Request $request, Booking $booking): View|RedirectResponse
    {
        if ($request->hasValidSignature()) {
            GuestAccess::grant($request, $booking);
        }

        if (! GuestAccess::mayView($request, $booking)) {
            return redirect()->route('guest.bookings')->with('guest_error', $request->has('signature')
                ? __('This link has expired. Ask for a login link below to see your booking.')
                : __('Log in to see this booking.'));
        }

        $booking->loadMissing('property');
        $overview = $booking->paymentOverview();

        return view('site.guest.booking', [
            'booking' => $booking,
            'documents' => $this->documents($booking),
            'outstanding' => $overview['outstanding'],
            'payLink' => $this->payLink($booking, $overview),
            'loggedIn' => GuestAccess::email($request) !== null,
        ]);
    }

    public function document(Request $request, DocumentEngine $documents, Booking $booking, string $kind, ?Payment $payment = null): Response
    {
        abort_unless(GuestAccess::mayView($request, $booking), 403);

        $document = $this->documents($booking)->firstWhere('key', $payment === null ? $kind : "{$kind}-{$payment->id}");
        abort_if($document === null, 404);

        $pdf = match ($kind) {
            'confirmation' => $documents->confirmation($booking),
            'invoice' => $documents->invoice($booking),
            'receipt' => $documents->receipt($payment),
        };

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document['file'].'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The documents of a booking the guest may download: the confirmation
     * once the booking stands, the invoice once it is confirmed (or already
     * made), and a receipt per paid payment.
     *
     * @return Collection<int, array{key: string, label: string, file: string, url: string}>
     */
    private function documents(Booking $booking): Collection
    {
        $number = $booking->booking_number;
        $documents = collect();
        $confirmed = $booking->status->isConfirmed();

        // A cancelled booking keeps its confirmation when something was paid (insurance, tax).
        if ($confirmed || (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true) && $booking->payments()->exists())) {
            $documents->push(['key' => 'confirmation', 'kind' => 'confirmation', 'payment' => null, 'label' => __('Booking confirmation'), 'file' => "confirmation-{$number}.pdf"]);
        }

        $invoice = Invoice::query()->where('booking_id', $booking->id)->first();

        if ($invoice !== null || $confirmed) {
            $documents->push(['key' => 'invoice', 'kind' => 'invoice', 'payment' => null, 'label' => __('Invoice'), 'file' => 'invoice-'.($invoice?->invoice_number ?? $number).'.pdf']);
        }

        $booking->payments()
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->where('type', '!=', PaymentType::Refund->value)
            ->orderBy('paid_at')
            ->get()
            ->each(fn (Payment $payment, int $index) => $documents->push([
                'key' => "receipt-{$payment->id}",
                'kind' => 'receipt',
                'payment' => $payment,
                'label' => __('Payment receipt').': '.$payment->kind().' ('.$payment->paid_at?->translatedFormat('j M Y').')',
                'file' => "payment_receipt-{$number}-".($index + 1).'.pdf',
            ]));

        return $documents->map(fn (array $document) => [
            ...$document,
            'url' => route('guest.document', ['booking' => $booking->id, 'kind' => $document['kind'], 'payment' => $document['payment']?->id]),
        ]);
    }

    /**
     * What the guest can still pay now: the first payment when nothing has
     * been paid yet, otherwise the balance. Not for a stay that is over or cancelled.
     *
     * @param  array{paid: int, refunded: int, outstanding: int}  $overview
     */
    private function payLink(Booking $booking, array $overview): ?string
    {
        if ($overview['outstanding'] <= 0 || ! in_array($booking->status, [BookingStatus::New, BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            return null;
        }

        return $booking->paymentLink($overview['paid'] > 0 ? PaymentType::Balance : PaymentType::DownPayment);
    }
}
