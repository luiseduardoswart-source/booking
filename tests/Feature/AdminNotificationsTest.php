<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\AdminNotification;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Mail\AdminNotificationMail;
use App\Models\Booking;
use App\Models\Host;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use App\Support\AdminLanguages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Mail to owner and host on a new booking, a cancellation and a
 * received payment.
 */
class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner@example.com';

    private Property $accommodation;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::set('company', 'email', self::OWNER);

        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);

        $this->accommodation = $this->accommodation('Strandhuis');
    }

    private function accommodation(string $name): Property
    {
        $accommodation = Property::create([
            'name' => $name, 'slug' => strtolower($name), 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000,
        ]);
        $accommodation->hosts()->attach(Host::create(['name' => "Host van {$name}"]));

        return $accommodation;
    }

    private function host(Property $accommodation, array $permissions = ['bookings' => ['view'], 'payments' => ['view']], bool $active = true): User
    {
        $host = User::factory()->create(['role' => UserRole::Host, 'host_id' => $accommodation->hosts()->first()->id, 'permissions' => $permissions]);
        $host->forceFill(['password_set_at' => $active ? now() : null])->save();

        return $host;
    }

    private function book(?Property $accommodation = null): Booking
    {
        return app(BookingEngine::class)->create(
            $accommodation ?? $this->accommodation,
            CarbonImmutable::parse('2026-12-01'),
            CarbonImmutable::parse('2026-12-04'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    /** Who was a notification of this kind sent to? */
    private function recipients(AdminNotification $notification): array
    {
        return Mail::sent(AdminNotificationMail::class, fn (AdminNotificationMail $mail) => $mail->notification === $notification)
            ->flatMap(fn (AdminNotificationMail $mail) => collect($mail->to)->pluck('address'))
            ->sort()->values()->all();
    }

    public function test_at_a_new_booking_get_owner_and_host_a_mail(): void
    {
        $host = $this->host($this->accommodation);

        $this->book();

        $this->assertEqualsCanonicalizing([self::OWNER, $host->email], $this->recipients(AdminNotification::NewBooking));
    }

    public function test_a_host_gets_only_notifications_in_are_own_accommodations(): void
    {
        $other = $this->host($this->accommodation('Stadshuis'));

        $this->book();

        $this->assertNotContains($other->email, $this->recipients(AdminNotification::NewBooking));
    }

    public function test_no_notification_for_a_host_without_permission_or_still_not_active_account(): void
    {
        $withoutPermission = $this->host($this->accommodation, ['calendar' => ['view']]);
        $invited = $this->host($this->accommodation, active: false);

        $this->book();

        $this->assertSame([self::OWNER], $this->recipients(AdminNotification::NewBooking));
        $this->assertNotContains($withoutPermission->email, $this->recipients(AdminNotification::NewBooking));
        $this->assertNotContains($invited->email, $this->recipients(AdminNotification::NewBooking));
    }

    public function test_at_cancel_comes_a_notification(): void
    {
        $host = $this->host($this->accommodation);
        $booking = $this->book();

        app(BookingEngine::class)->cancel($booking);

        $this->assertEqualsCanonicalizing([self::OWNER, $host->email], $this->recipients(AdminNotification::Cancellation));
    }

    public function test_who_itself_cancels_gets_there_no_mail_in(): void
    {
        $host = $this->host($this->accommodation, ['bookings' => ['view', 'edit']]);
        $booking = $this->book();

        $this->actingAs($host);
        app(BookingEngine::class)->cancel($booking);

        $this->assertSame([self::OWNER], $this->recipients(AdminNotification::Cancellation));
    }

    public function test_at_a_received_payment_comes_a_notification_but_not_at_refund(): void
    {
        $host = $this->host($this->accommodation);
        $payment = app(PaymentEngine::class)->createPayment($this->book(), PaymentType::DownPayment);

        $this->assertSame([], $this->recipients(AdminNotification::Payment)); // still open

        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        app(PaymentEngine::class)->syncFromProvider($payment);

        $this->assertEqualsCanonicalizing([self::OWNER, $host->email], $this->recipients(AdminNotification::Payment));

        app(PaymentEngine::class)->refund($payment->refresh());
        $this->assertCount(2, $this->recipients(AdminNotification::Payment));
    }

    public function test_a_reverted_booking_gives_no_notification(): void
    {
        try {
            DB::transaction(function () {
                $this->book();
                throw new RuntimeException('failed after creation');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->recipients(AdminNotification::NewBooking));
    }

    public function test_the_mail_contains_the_booking_and_a_link_in_the_language_of_the_app(): void
    {
        app()->setLocale('es'); // the guest books in Spanish
        $booking = $this->book();

        $mail = Mail::sent(AdminNotificationMail::class)->first();

        $this->assertSame(AdminLanguages::default(), $mail->locale);
        $this->assertNotSame('es', $mail->locale);
        $mail->assertSeeInHtml($booking->booking_number);
        $mail->assertSeeInHtml(route('filament.admin.resources.bookings.edit', $booking));
        $mail->assertSeeInHtml('Jan Jansen');
    }
}
