<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PricingEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Widgets\StatsOverview;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\MollieProvider;
use App\Payments\StripeProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Each accommodation works in its own currency (empty = the default): prices,
 * bookings, mails, payments and revenue.
 */
class CurrencyPerAccommodationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Fixed rates relative to the euro.
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1.25, 'JPY' => 160.0, 'COP' => 4500.0, 'GBP' => 0.8]]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);
    }

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => "Huis {$counter}", 'slug' => "house-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'cleaning_fee_cents' => null, ...$extra,
        ]);
    }

    private function quote(Property $accommodation, string $from = '2026-03-02', string $until = '2026-03-05', ?Coupon $coupon = null)
    {
        return app(PricingEngine::class)->quote($accommodation, CarbonImmutable::parse($from), CarbonImmutable::parse($until), 2, coupon: $coupon);
    }

    public function test_an_accommodation_converts_in_its_own_currency(): void
    {
        $accommodation = $this->accommodation(['currency' => 'USD', 'base_price_cents' => 20000, 'cleaning_fee_cents' => 5000]);

        $quote = $this->quote($accommodation);

        // Own amounts are not converted: 3 × $200 + $50.
        $this->assertSame('USD', $quote->currency);
        $this->assertSame(65000, $quote->totalCents);
        $this->assertSame('USD', $quote->toArray()['currency']);
    }

    public function test_without_own_currency_the_default_applies(): void
    {
        $accommodation = $this->accommodation(['base_price_cents' => 10000]);
        $this->assertSame('EUR', $accommodation->priceCurrency());

        Property::master()->update(['currency' => 'GBP']);

        $this->assertSame('GBP', $accommodation->fresh()->priceCurrency());
        $this->assertSame('GBP', Money::defaultCurrency());
    }

    public function test_amounts_of_the_default_are_converted_to_the_currency_of_the_accommodation(): void
    {
        $default = Property::master();
        $default->update(['base_price_cents' => 10000, 'cleaning_fee_cents' => 4000]);
        $default->rateSeasons()->create(['name' => 'Zomer', 'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'price_cents' => 20000]);
        $default->discounts()->create(['min_nights' => 3, 'type' => 'fixed', 'amount_cents' => 2000]);

        // No own prices, but an own currency: everything × 1.25.
        $accommodation = $this->accommodation(['currency' => 'USD']);

        $outside = $this->quote($accommodation); // 3 nights × $125, cleaning $50, discount $25
        $this->assertSame(37500, $outside->line('accommodation')->amountCents);
        $this->assertSame(5000, $outside->line('cleaning')->amountCents);
        $this->assertSame(-2500, $outside->line('discount')->amountCents);
        $this->assertStringContainsString('25,00', $outside->line('discount')->label);

        $summer = $this->quote($accommodation, '2026-07-06', '2026-07-08'); // season €200 → $250
        $this->assertSame(50000, $summer->line('accommodation')->amountCents);
    }

    public function test_a_fixed_discount_code_for_all_accommodations_is_converted(): void
    {
        $accommodation = $this->accommodation(['currency' => 'JPY', 'base_price_cents' => 2000000]); // ¥20.000 per night
        $code = Coupon::create(['code' => 'WELKOM', 'type' => Coupon::TYPE_FIXED, 'value' => 1000]); // €10

        $quote = $this->quote($accommodation, coupon: $code);

        // €10 × 160 = ¥1.600.
        $this->assertSame(-160000, $quote->line('coupon')->amountCents);
    }

    public function test_the_booking_remembers_its_currency_and_the_mail_shows_it(): void
    {
        $accommodation = $this->accommodation(['currency' => 'USD', 'base_price_cents' => 20000]);

        $booking = app(BookingEngine::class)->create(
            $accommodation, CarbonImmutable::parse('2026-12-07'), CarbonImmutable::parse('2026-12-10'), 2,
            ['name' => 'Jan', 'email' => 'jan@example.com'],
        );

        $this->assertSame('USD', $booking->currency);

        // Even if the accommodation later changes currency.
        $accommodation->update(['currency' => 'GBP']);
        $html = (new BookingMail($booking->fresh(), MailType::Confirmation))->render();

        $this->assertStringContainsString('600,00', $html);
        $this->assertMatchesRegularExpression('/US\$|\$/u', $html);
        $this->assertStringNotContainsString('€ 600,00', $html);
        $this->assertStringNotContainsString('£', $html);
    }

    public function test_amounts_are_in_the_right_currency(): void
    {
        app()->setLocale('nl');

        $this->assertSame('€ 1.234,50', Money::format(123450, 'EUR'));
        $this->assertMatchesRegularExpression('/^US\$\x{00A0}1\.234,50$/u', Money::format(123450, 'USD'));
        // Yen has no cents.
        $this->assertMatchesRegularExpression('/¥\x{00A0}?1\.235$/u', Money::format(123456, 'JPY'));
        // Without a currency: the default currency.
        Property::master()->update(['currency' => 'GBP']);
        $this->assertMatchesRegularExpression('/£\x{00A0}?1\.234,50$/u', Money::format(123450));
    }

    public function test_the_visitor_sees_prices_converted_from_the_currency_of_the_accommodation(): void
    {
        Setting::set('site', 'currency_picker', '1');
        $accommodation = $this->accommodation(['currency' => 'USD', 'base_price_cents' => 12500]);

        // If the visitor chooses euros: $125 / 1.25 = €100.
        $this->withSession(['currency' => 'EUR', 'locale' => 'nl'])
            ->get("/{$accommodation->slug}")
            ->assertOk()
            ->assertSee('€ 100,00');

        // If they choose dollars: no conversion, just $125.
        $this->withSession(['currency' => 'USD', 'locale' => 'nl'])
            ->get("/{$accommodation->slug}")
            ->assertOk()
            ->assertSee('125,00')
            ->assertDontSee('156,25');
    }

    public function test_without_currency_picker_the_visitor_sees_the_own_currency(): void
    {
        $accommodation = $this->accommodation(['currency' => 'USD', 'base_price_cents' => 12500]);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$accommodation->slug}")
            ->assertOk()
            ->assertSee('125,00')
            ->assertDontSee('€ 125,00');
    }

    public function test_the_price_filter_compares_in_the_same_currency(): void
    {
        $this->accommodation(['name' => 'Goedkoop in yen', 'currency' => 'JPY', 'base_price_cents' => 1280000]); // ¥12.800 = €80
        $this->accommodation(['name' => 'Duur in dollars', 'currency' => 'USD', 'base_price_cents' => 25000]); // $250 = €200

        $this->withSession(['locale' => 'nl'])
            ->get('/?price_max=100')
            ->assertOk()
            ->assertSee('Goedkoop in yen')
            ->assertDontSee('Duur in dollars');
    }

    public function test_mollie_gets_the_currency_with_the_right_number_of_decimals(): void
    {
        $this->assertSame(['currency' => 'EUR', 'value' => '12.50'], MollieProvider::amount(1250, 'EUR'));
        $this->assertSame(['currency' => 'JPY', 'value' => '1235'], MollieProvider::amount(123450, 'jpy'));

        $this->assertTrue((new MollieProvider)->supportsCurrency('USD'));
        $this->assertFalse((new MollieProvider)->supportsCurrency('COP'));

        $this->expectException(RuntimeException::class);
        MollieProvider::amount(100000, 'COP');
    }

    public function test_stripe_gets_the_amount_in_the_smallest_unit_of_the_currency(): void
    {
        $this->assertSame(1250, StripeProvider::amount(1250, 'EUR'));
        $this->assertSame(1235, StripeProvider::amount(123450, 'JPY'));   // whole yen
        $this->assertSame(12500, StripeProvider::amount(1250, 'KWD'));    // thousandths
        $this->assertSame(123500, StripeProvider::amount(123450, 'ISK')); // whole krona, as ×100
        $this->assertTrue((new StripeProvider)->supportsCurrency('COP'));
    }

    public function test_the_revenue_adds_up_in_the_default_currency(): void
    {
        $eur = $this->accommodation(['base_price_cents' => 10000]);
        $usd = $this->accommodation(['currency' => 'USD', 'base_price_cents' => 10000]);

        foreach ([[$eur, 'EUR', 10000], [$usd, 'USD', 12500]] as $i => [$accommodation, $currency, $amount]) {
            $booking = Booking::create([
                'booking_number' => "BK-VAL-{$i}", 'property_id' => $accommodation->id,
                'status' => BookingStatus::Paid, 'payment_status' => PaymentStatus::Paid,
                'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
                'arrival_date' => today()->addDays(10), 'departure_date' => today()->addDays(12), 'guests' => 2,
                'total_cents' => $amount, 'down_payment_cents' => $amount, 'currency' => $currency,
                'price_snapshot' => ['lines' => []],
            ]);
            $booking->payments()->create([
                'type' => PaymentType::DownPayment, 'status' => PaymentTransactionStatus::Paid,
                'amount_cents' => $amount, 'provider' => 'fake', 'paid_at' => now(),
            ]);
        }

        // €100 + $125 (= €100) = €200, not "22.500".
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        Livewire::test(StatsOverview::class)->assertSee('€ 200,00');
    }

    public function test_you_choose_the_currency_in_the_admin_and_the_money_fields_follow(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $accommodation = $this->accommodation(['base_price_cents' => 10000]);

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['currency' => 'USD'])
            ->assertSee('US$')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('USD', $accommodation->fresh()->currency);

        // Empty = follow the default.
        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['currency' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($accommodation->fresh()->currency);
    }

    public function test_the_admin_warns_if_the_payment_service_cannot_handle_the_currency(): void
    {
        Setting::set('payments', 'provider', 'mollie');
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->fillForm(['currency' => 'COP'])
            ->assertSee(__('Note: the configured payment provider cannot charge in :currency. Guests will not be able to pay online; choose Stripe in Settings or a different currency.', ['currency' => 'COP']));
    }

    public function test_the_default_must_have_a_currency(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditDefaults::class)
            ->fillForm(['currency' => null])
            ->call('save')
            ->assertHasFormErrors(['currency' => 'required']);
    }
}
