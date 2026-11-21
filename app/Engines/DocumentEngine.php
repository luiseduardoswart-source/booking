<?php

namespace App\Engines;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Generates all documents (Module 10): invoice, booking confirmation and
 * payment receipt. Invoices get a running number per year; the number is
 * fixed when first created and never changes afterwards.
 */
class DocumentEngine
{
    use Localizable;

    /** The PDF font has no glyphs for these languages; those documents are produced in English. */
    private const WITHOUT_FONT = ['zh'];

    public function invoice(Booking $booking): string
    {
        $invoice = $this->findOrCreateInvoice($booking);

        return $this->pdf($booking, 'pdf.invoice', [
            'booking' => $booking,
            'invoice' => $invoice,
            'company' => $this->companyDetails(),
        ]);
    }

    public function confirmation(Booking $booking): string
    {
        return $this->pdf($booking, 'pdf.confirmation', [
            'booking' => $booking,
            'company' => $this->companyDetails(),
        ]);
    }

    public function receipt(Payment $payment): string
    {
        return $this->pdf($payment->booking, 'pdf.payment-receipt', [
            'payment' => $payment,
            'booking' => $payment->booking,
            'company' => $this->companyDetails(),
        ]);
    }

    /**
     * A document is for the guest, so always in their language: even if the
     * admin fetches it in another language.
     *
     * @param  array<string, mixed>  $details
     */
    private function pdf(Booking $booking, string $view, array $details): string
    {
        $language = in_array($booking->locale, self::WITHOUT_FONT, true) ? 'en' : $booking->locale;

        // A document names what was charged, not the currency a visitor picked to browse in.
        return Money::inChargedCurrency(fn () => $this->withLocale($language, fn () => Pdf::loadView($view, $details)->output()));
    }

    public function findOrCreateInvoice(Booking $booking): Invoice
    {
        return DB::transaction(function () use ($booking) {
            $existing = Invoice::query()->where('booking_id', $booking->id)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $year = now()->year;
            $sequence = (int) Setting::get('documents', "invoiceNumber_{$year}", '0') + 1;
            Setting::set('documents', "invoiceNumber_{$year}", (string) $sequence);

            // The booking of a deleted property also gets an invoice.
            $vatPercentage = (int) ($booking->property?->effective('vat_percentage') ?? Property::master()->vat_percentage);
            // Prices include VAT; the VAT component is total × p / (100 + p).
            $vatCents = (int) round($booking->total_cents * $vatPercentage / (100 + $vatPercentage));

            return Invoice::create([
                'booking_id' => $booking->id,
                'invoice_number' => sprintf('F-%d-%04d', $year, $sequence),
                'issued_at' => today(),
                'total_cents' => $booking->total_cents,
                'vat_cents' => $vatCents,
                'vat_percentage' => $vatPercentage,
            ]);
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function companyDetails(): array
    {
        return [
            'name' => Setting::get('company', 'name', config('app.name')),
            'address' => Setting::get('company', 'address'),
            'postal_code_city' => Setting::get('company', 'postal_code_city'),
            'email' => Setting::get('company', 'email'),
            'phone' => Setting::get('company', 'phone'),
            'coc' => Setting::get('company', 'coc'),
            'vat_number' => Setting::get('company', 'vat_number'),
            'iban' => Setting::get('company', 'iban'),
        ];
    }
}
