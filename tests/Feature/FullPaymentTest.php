<?php

namespace Tests\Feature;

use App\Engines\PaymentEngine;
use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The owner chooses between everything at once and a down payment plus remainder. With
 * everything at once, nobody sees the word down payment.
 */
class FullPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('company', 'name', 'Duinhuisjes Verhuur');
        Setting::set('company', 'email', 'info@duinhuisjes.nl');
    }

    private function booking(int $downPayment): Booking
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        return Booking::create([
            'booking_number' => 'BK-TEST-1', 'property_id' => $accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03', 'guests' => 2,
            'total_cents' => 32000, 'down_payment_cents' => $downPayment,
            'price_snapshot' => ['lines' => []],
        ]);
    }

    private function defaults(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditDefaults::class);
    }

    public function test_everything_in_a_time_saves_hundred_percent(): void
    {
        $this->defaults()
            ->fillForm(['payment_method' => 'everything'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(100, Property::master()->down_payment_percentage);
    }

    public function test_the_screen_shows_everything_in_a_time_at_hundred_percent(): void
    {
        Property::master()->update(['down_payment_percentage' => 100]);

        $this->defaults()
            ->assertFormSet([
                'payment_method' => 'everything',
                'down_payment_percentage' => 30,
            ]);
    }

    public function test_choose_of_the_payment_method_goes_not_via_the_server(): void
    {
        // This screen is large: a live field reloads it completely on every click.
        $this->defaults()
            ->assertSeeHtml('wire:model="data.payment_method"')
            ->assertDontSeeHtml('wire:model.live="data.payment_method"');
    }

    public function test_back_to_down_payment_saves_the_chosen_percentage(): void
    {
        Property::master()->update(['down_payment_percentage' => 100]);

        $this->defaults()
            ->fillForm(['payment_method' => 'down_payment', 'down_payment_percentage' => 40])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(40, Property::master()->down_payment_percentage);
    }

    public function test_a_down_payment_of_zero_or_hundred_percent_can_not_via_the_slider(): void
    {
        foreach ([0, 100] as $value) {
            $this->defaults()
                ->fillForm(['down_payment_percentage' => $value])
                ->call('save')
                ->assertHasFormErrors(['down_payment_percentage']);
        }
    }

    public function test_the_explanation_converts_the_balance_off(): void
    {
        Property::master()->update(['down_payment_percentage' => 35]);

        $this->defaults()->assertSee(__('The guest pays :down_payment% when booking and :rest% before arrival.', [
            'down_payment' => 35,
            'rest' => 65,
        ]));
    }

    public function test_the_payment_is_called_full_payment_at_stripe_and_in_the_admin(): void
    {
        $booking = $this->booking(32000);

        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);

        $this->assertSame('Full payment BK-TEST-1', $payment->description);
        $this->assertSame(__('Full payment'), $payment->kind());

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(ManagePayments::class)
            ->assertSee(__('Full payment'));
    }

    public function test_at_a_real_down_payment_stays_the_name_down_payment(): void
    {
        $payment = app(PaymentEngine::class)->createPayment($this->booking(9600), PaymentType::DownPayment);

        $this->assertSame('Down payment BK-TEST-1', $payment->description);
        $this->assertSame(__('Down payment'), $payment->kind());
    }

    public function test_the_mails_mention_no_down_payment_at_everything_in_a_time(): void
    {
        $booking = $this->booking(32000);
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);

        foreach ([MailType::Verified, MailType::PaymentRequest, MailType::PaymentReceived] as $type) {
            // The text the guest reads; the type is in the link itself.
            $text = strip_tags((new BookingMail($booking, $type, $payment))->render());

            $this->assertStringNotContainsStringIgnoringCase('down_payment', $text, $type->value);
        }
    }

    public function test_the_verification_mail_mentions_the_down_payment_as_that_there_is(): void
    {
        $booking = $this->booking(9600);
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);

        $html = (new BookingMail($booking, MailType::Verified, $payment))->render();

        $this->assertStringContainsString(__('Pay the down payment'), $html);
    }

    public function test_the_confirmation_page_mentions_no_down_payment_at_everything_in_a_time(): void
    {
        $booking = $this->booking(32000);

        $this->get(URL::signedRoute('bookings.confirmation', $booking))
            ->assertOk()
            ->assertDontSee('down_payment');
    }
}
