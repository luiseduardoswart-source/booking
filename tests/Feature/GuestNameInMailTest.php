<?php

namespace Tests\Feature;

use App\Enums\AdminNotification;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Mail\AdminNotificationMail;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guest fills in the name themselves. In a Markdown mail it must not
 * become a link or formatting: otherwise someone could book with another
 * person's email address and so send a phishing link from the company's mail.
 */
class GuestNameInMailTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = '[Klik hier](https://kwaadaardig.test) **Jan** | extra';

    private function booking(): Booking
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        return Booking::create([
            'booking_number' => 'BK-NAAM-1', 'property_id' => $accommodation->id,
            'status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Unpaid,
            'guest_name' => self::NAME, 'guest_email' => 'victim@example.com',
            'arrival_date' => today()->addDays(30)->toDateString(),
            'departure_date' => today()->addDays(33)->toDateString(), 'guests' => 2,
            'total_cents' => 30000, 'down_payment_cents' => 9000,
            'price_snapshot' => ['lines' => []], 'locale' => 'nl',
        ]);
    }

    private function assertAsPlainText(string $html, string $mail): void
    {
        $this->assertStringNotContainsString('kwaadaardig.test"', $html, "{$mail}: the name became a link");
        $this->assertDoesNotMatchRegularExpression('/<strong[^>]*>Jan<\/strong>/', $html, "{$mail}: the name got formatting");
        $this->assertStringContainsString('[Klik hier](https://kwaadaardig.test) **Jan** | extra', html_entity_decode(strip_tags($html)), "{$mail}: the name is not there literally");
    }

    public function test_in_no_single_mail_on_the_guest_is_the_name_a_link(): void
    {
        $booking = $this->booking();
        $payment = $booking->payments()->create([
            'type' => 'down_payment', 'status' => 'open', 'amount_cents' => 9000, 'provider' => 'fake',
        ]);

        foreach (MailType::cases() as $type) {
            $this->assertAsPlainText((new BookingMail($booking, $type, $payment))->render(), $type->value);
        }
    }

    public function test_also_in_the_notification_on_the_admin_not(): void
    {
        $booking = $this->booking();
        $payment = $booking->payments()->create([
            'type' => 'down_payment', 'status' => 'paid', 'amount_cents' => 9000, 'provider' => 'fake',
        ]);

        foreach (AdminNotification::cases() as $notification) {
            $html = (new AdminNotificationMail($notification, $booking, $payment))->render();

            $this->assertAsPlainText($html, $notification->value);
            // A | in the name does not create an extra column in the table.
            $this->assertDoesNotMatchRegularExpression('/<td[^>]*>\s*extra/', $html);
        }
    }

    public function test_a_regular_name_stays_simply_are(): void
    {
        $booking = $this->booking();
        $booking->update(['guest_name' => "Anne-Marie O'Brien"]);

        $html = (new BookingMail($booking, MailType::Confirmation))->render();

        $this->assertStringContainsString("Bedankt voor je boeking, Anne-Marie O'Brien!", html_entity_decode($html, ENT_QUOTES));
    }
}
