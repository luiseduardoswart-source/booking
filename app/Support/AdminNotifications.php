<?php

namespace App\Support;

use App\Enums\AdminNotification;
use App\Enums\UserRole;
use App\Mail\AdminNotificationMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mail to the owner (the email address under Settings → Company) and to the
 * host of the accommodation on a new booking, a cancellation or a received
 * payment. A host only gets notifications about their own accommodations,
 * and only if they may view that screen and their account is active.
 * Whoever made the change themselves gets no mail about their own action.
 *
 * Every recipient with an account gets the mail in their own language
 * (User::preferredLocale); the company address without an account in the
 * default admin language. A failed mail never breaks the booking or
 * payment: it is logged.
 */
final class AdminNotifications
{
    public static function send(AdminNotification $notification, Booking $booking, ?Payment $payment = null): void
    {
        foreach (self::recipients($notification, $booking) as $recipient) {
            try {
                Mail::to($recipient)->send(new AdminNotificationMail($notification, $booking, $payment));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @return array<int, User|string> accounts, or a standalone email address
     */
    public static function recipients(AdminNotification $notification, Booking $booking): array
    {
        $companyAddress = mb_strtolower(trim((string) Setting::get('company', 'email', config('mail.from.address'))));
        $hostIds = $booking->property?->hosts()->pluck('hosts.id') ?? collect();

        // The company address as an owner's account, so they also get their own language.
        $owner = $companyAddress === '' ? null : (User::query()
            ->where('role', UserRole::Administrator->value)
            ->whereRaw('lower(email) = ?', [$companyAddress])
            ->first() ?? $companyAddress);

        $hosts = User::query()
            ->where('role', UserRole::Host->value)
            ->whereIn('host_id', $hostIds)
            ->whereNotNull('password_set_at')
            ->get()
            ->filter(fn (User $host) => $host->may($notification->screen(), $notification->permission()));

        $itself = mb_strtolower((string) auth()->user()?->email);
        $address = fn (User|string $recipient): string => mb_strtolower($recipient instanceof User ? $recipient->email : $recipient);

        return collect([$owner])
            ->merge($hosts)
            ->filter()
            ->unique($address)
            ->reject(fn (User|string $recipient) => $address($recipient) === $itself)
            ->values()
            ->all();
    }
}
