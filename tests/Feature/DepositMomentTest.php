<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The deposit is a separate payment, never part of the price. The owner
 * chooses when the guest pays it: right after booking, or a number of days
 * before arrival.
 */
class DepositMomentTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
        Setting::set('payments', 'provider', 'fake');
    }

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => 'Strandhuis', 'slug' => "strandhuis-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'deposit_cents' => 25000, ...$extra,
        ]);
    }

    private function booking(Property $accommodation, int $arrivalInDays = 60): Booking
    {
        return app(BookingEngine::class)->create(
            $accommodation,
            CarbonImmutable::today()->addDays($arrivalInDays),
            CarbonImmutable::today()->addDays($arrivalInDays + 3),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    private function depositRequested(Booking $booking): bool
    {
        return Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->booking->is($booking) && $mail->type === MailType::Deposit)->isNotEmpty();
    }

    /** The guest pays the down payment and comes back to the payment page. */
    private function payStay(Booking $booking)
    {
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);

        return $this->get($payment->returnUrl())->assertOk();
    }

    public function test_the_deposit_is_a_separate_notice_on_the_booking_form(): void
    {
        $before = $this->accommodation(['deposit_moment' => 'before_arrival', 'deposit_days_before_arrival' => 7]);
        $atBooking = $this->accommodation(['deposit_moment' => 'booking']);
        $dates = '?arrival='.today()->addDays(60)->toDateString().'&departure='.today()->addDays(63)->toDateString().'&guests=2';

        $this->get("/{$before->slug}/book{$dates}")->assertOk()
            ->assertSee(trans_choice('{0} The deposit of :amount is not part of the price. You pay it separately on the day of arrival and get it back after your stay.|{1} The deposit of :amount is not part of the price. You pay it separately, 1 day before arrival, and get it back after your stay.|[2,*] The deposit of :amount is not part of the price. You pay it separately, :count days before arrival, and get it back after your stay.', 7, ['amount' => '€ 250,00']));
        $this->get("/{$atBooking->slug}/book{$dates}")->assertOk()
            ->assertSee(__('The deposit of :amount is not part of the price. You pay it separately, right after your booking, and get it back after your stay.', ['amount' => '€ 250,00']));
    }

    public function test_right_after_booking_the_request_follows_the_first_payment(): void
    {
        $booking = $this->booking($this->accommodation(['deposit_moment' => 'booking']));

        $this->assertFalse($this->depositRequested($booking));

        $this->payStay($booking)
            ->assertSee(__('Pay the deposit'))
            ->assertSee(e($booking->paymentLink(PaymentType::Deposit)), false);

        $this->assertTrue($this->depositRequested($booking));
        // The deposit is not part of what the stay costs.
        $this->assertSame(PaymentTransactionStatus::Paid, $booking->payments()->where('type', PaymentType::DownPayment->value)->sole()->status);
        $this->assertSame(0, $booking->payments()->where('type', PaymentType::Deposit->value)->count());
    }

    public function test_before_arrival_nothing_happens_at_the_first_payment(): void
    {
        $booking = $this->booking($this->accommodation(['deposit_moment' => 'before_arrival', 'deposit_days_before_arrival' => 7]));

        $this->payStay($booking)->assertDontSee(__('Pay the deposit'));

        $this->assertFalse($this->depositRequested($booking));
    }

    public function test_the_daily_run_catches_bookings_confirmed_by_hand(): void
    {
        Property::master()->update(['deposit_moment' => 'before_arrival', 'deposit_days_before_arrival' => 7]);
        $atBooking = $this->booking($this->accommodation(['deposit_moment' => 'booking']), 60);
        $before = $this->booking($this->accommodation(), 60);
        $atBooking->update(['status' => BookingStatus::Confirmed]);
        $before->update(['status' => BookingStatus::Confirmed]);

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        $this->assertTrue($this->depositRequested($atBooking));
        $this->assertFalse($this->depositRequested($before));
    }

    public function test_an_accommodation_follows_the_moment_of_the_default(): void
    {
        Property::master()->update(['deposit_moment' => 'booking']);

        $this->assertTrue($this->accommodation()->depositAtBooking());
        $this->assertFalse($this->accommodation(['deposit_moment' => 'before_arrival'])->depositAtBooking());
    }

    public function test_the_days_field_only_shows_when_the_deposit_comes_before_arrival(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['deposit_moment' => 'booking'])
            ->assertFormFieldIsHidden('deposit_days_before_arrival')
            ->fillForm(['deposit_moment' => 'before_arrival'])
            ->assertFormFieldIsVisible('deposit_days_before_arrival')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('before_arrival', $accommodation->refresh()->deposit_moment);
    }
}
