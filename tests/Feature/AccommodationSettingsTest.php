<?php

namespace Tests\Feature;

use App\Engines\DocumentEngine;
use App\Engines\PricingEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Mail\BookingMail;
use App\Mail\DepositReminderMail;
use App\Models\Booking;
use App\Models\Host;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Services\FakeDynamicPricingProvider;
use App\Services\MachineTranslator;
use App\Services\PriceLabsProvider;
use App\Services\WheelhouseProvider;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Payment, deposit, mail and pricing service rules live on the accommodation: an
 * own value takes precedence, empty follows the default. Exactly like the rest of
 * the defaults.
 */
class AccommodationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('company', 'email', 'owner@example.com');
    }

    private function accommodation(array $fields = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => "Huis {$counter}", 'slug' => "house-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            ...$fields,
        ]);
    }

    private function booking(Property $accommodation, array $fields = []): Booking
    {
        static $counter = 0;
        $counter++;

        return Booking::create([
            'booking_number' => "BK-INST-{$counter}", 'property_id' => $accommodation->id,
            'status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Unpaid,
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => today()->addDays(30)->toDateString(),
            'departure_date' => today()->addDays(33)->toDateString(), 'guests' => 2,
            'total_cents' => 30000, 'down_payment_cents' => 9000,
            'price_snapshot' => ['lines' => []],
            ...$fields,
        ]);
    }

    private function arrivalIn(int $days): array
    {
        return [
            'arrival_date' => today()->addDays($days)->toDateString(),
            'departure_date' => today()->addDays($days + 3)->toDateString(),
        ];
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    /** A host with permission on their accommodations and optionally on the payment rules. */
    private function host(Property $accommodation, array $extraPermissions = []): User
    {
        $host = Host::create(['name' => 'Host']);
        $accommodation->hosts()->sync([$host->id]);

        return User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $host->id,
            'permissions' => ['accommodations' => Access::ACTIONS, ...$extraPermissions],
        ]);
    }

    private function sentFor(Booking $booking, MailType $type): bool
    {
        return Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->booking->is($booking) && $mail->type === $type)->isNotEmpty();
    }

    public function test_a_new_master_record_starts_with_the_starting_values(): void
    {
        $master = Property::master();

        $this->assertSame(30, $master->down_payment_percentage);
        $this->assertSame(9, $master->vat_percentage);
        $this->assertSame('none', $master->pricing_service);
    }

    public function test_down_payment_own_value_goes_for_and_empty_follows_the_default(): void
    {
        Property::master()->update(['down_payment_percentage' => 40]);
        $engine = app(PricingEngine::class);
        $arrival = CarbonImmutable::parse('2027-03-02');
        $departure = CarbonImmutable::parse('2027-03-05');

        $follows = $engine->quote($this->accommodation(), $arrival, $departure, 2);
        $custom = $engine->quote($this->accommodation(['down_payment_percentage' => 50]), $arrival, $departure, 2);
        $everything = $engine->quote($this->accommodation(['down_payment_percentage' => 100]), $arrival, $departure, 2);

        $this->assertSame(12000, $follows->downPaymentCents);
        $this->assertSame(15000, $custom->downPaymentCents);
        $this->assertSame(30000, $everything->downPaymentCents);
        $this->assertSame(0, $everything->remainingCents);
    }

    public function test_vat_on_the_invoice_follows_the_accommodation(): void
    {
        Property::master()->update(['vat_percentage' => 9]);
        $engine = app(DocumentEngine::class);

        $default = $engine->findOrCreateInvoice($this->booking($this->accommodation()));
        $custom = $engine->findOrCreateInvoice($this->booking($this->accommodation(['vat_percentage' => 19])));
        $zero = $engine->findOrCreateInvoice($this->booking($this->accommodation(['vat_percentage' => 0])));

        $this->assertSame(9, $default->vat_percentage);
        $this->assertSame(19, $custom->vat_percentage);
        $this->assertSame((int) round(30000 * 19 / 119), $custom->vat_cents);
        $this->assertSame(0, $zero->vat_percentage);
    }

    public function test_invoice_of_a_deleted_accommodation_used_the_default(): void
    {
        $accommodation = $this->accommodation(['vat_percentage' => 19]);
        $booking = $this->booking($accommodation);
        $accommodation->delete();

        $invoice = app(DocumentEngine::class)->findOrCreateInvoice($booking->fresh());

        $this->assertSame(9, $invoice->vat_percentage);
    }

    public function test_balance_payment_follows_the_days_of_the_accommodation(): void
    {
        Property::master()->update(['balance_payment_days_before_arrival' => 14]);
        $downPaymentPaid = ['payment_status' => PaymentStatus::DownPaymentPaid, ...$this->arrivalIn(20)];

        $default = $this->booking($this->accommodation(), $downPaymentPaid);
        $earlier = $this->booking($this->accommodation(['balance_payment_days_before_arrival' => 25]), $downPaymentPaid);

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        $this->assertFalse($this->sentFor($default, MailType::BalancePayment));
        $this->assertTrue($this->sentFor($earlier, MailType::BalancePayment));
    }

    public function test_checkin_mail_follows_the_days_of_the_accommodation(): void
    {
        Property::master()->update(['checkin_days_before_arrival' => 3]);

        $default = $this->booking($this->accommodation(), $this->arrivalIn(10));
        $earlier = $this->booking($this->accommodation(['checkin_days_before_arrival' => 12]), $this->arrivalIn(10));

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        $this->assertFalse($this->sentFor($default, MailType::Checkin));
        $this->assertTrue($this->sentFor($earlier, MailType::Checkin));
    }

    public function test_deposit_request_follows_the_days_of_the_accommodation(): void
    {
        Property::master()->update(['deposit_days_before_arrival' => 7]);
        $deposit = ['deposit_cents' => 20000, ...$this->arrivalIn(10)];

        $default = $this->booking($this->accommodation(), $deposit);
        $earlier = $this->booking($this->accommodation(['deposit_days_before_arrival' => 12]), $deposit);

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        $this->assertFalse($this->sentFor($default, MailType::Deposit));
        $this->assertTrue($this->sentFor($earlier, MailType::Deposit));
    }

    public function test_review_request_goes_exactly_on_the_day_of_the_accommodation(): void
    {
        Property::master()->update(['review_days_after_departure' => 2]);
        $departed = fn (int $days) => [
            'status' => BookingStatus::Completed,
            'arrival_date' => today()->subDays($days + 3)->toDateString(),
            'departure_date' => today()->subDays($days)->toDateString(),
        ];

        $default = $this->booking($this->accommodation(), $departed(2));
        $later = $this->booking($this->accommodation(['review_days_after_departure' => 5]), $departed(5));
        $tooLate = $this->booking($this->accommodation(['review_days_after_departure' => 5]), $departed(2));

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        $this->assertTrue($this->sentFor($default, MailType::Review));
        $this->assertTrue($this->sentFor($later, MailType::Review));
        $this->assertFalse($this->sentFor($tooLate, MailType::Review));
    }

    public function test_scheduled_mails_run_through_for_a_deleted_accommodation(): void
    {
        $accommodation = $this->accommodation();
        $this->booking($accommodation, $this->arrivalIn(1));
        $accommodation->delete();

        $this->assertArrayHasKey($accommodation->id, Property::valuePerAccommodation('checkin_days_before_arrival')->all());
    }

    public function test_the_count_queries_grows_not_with_the_count_accommodations(): void
    {
        foreach (range(1, 12) as $i) {
            $this->accommodation();
        }

        Property::master(); // already exists in the real world

        DB::enableQueryLog();
        Property::valuePerAccommodation('checkin_days_before_arrival');

        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
    }

    private function paidDeposit(Property $accommodation, int $departedDaysAgo): Payment
    {
        $booking = $this->booking($accommodation, [
            'status' => BookingStatus::Completed, 'deposit_cents' => 20000,
            'arrival_date' => today()->subDays($departedDaysAgo + 3)->toDateString(),
            'departure_date' => today()->subDays($departedDaysAgo)->toDateString(),
        ]);

        return $booking->payments()->create([
            'type' => PaymentType::Deposit, 'status' => PaymentTransactionStatus::Paid,
            'amount_cents' => 20000, 'provider' => 'fake', 'provider_payment_id' => 'deposit-'.$booking->id,
            'description' => 'Borg',
        ]);
    }

    public function test_deposit_refund_can_per_accommodation_differ(): void
    {
        Property::master()->update(['deposit_auto_refund' => false, 'deposit_days_after_departure' => 3]);

        $automatic = $this->paidDeposit($this->accommodation(['deposit_auto_refund' => true]), 5);
        $manual = $this->paidDeposit($this->accommodation(), 5);

        $this->artisan('deposits:process')->assertSuccessful();

        $this->assertSame(1, $automatic->refunds()->count());
        $this->assertSame(0, $manual->refunds()->count());
        Mail::assertSent(DepositReminderMail::class, fn (DepositReminderMail $mail) => $mail->deposits->count() === 1
            && $mail->deposits->first()->is($manual));
    }

    public function test_everything_automatic_sends_no_empty_reminder(): void
    {
        Property::master()->update(['deposit_auto_refund' => true]);
        $this->paidDeposit($this->accommodation(), 5);

        $this->artisan('deposits:process')->assertSuccessful();

        Mail::assertNotSent(DepositReminderMail::class);
    }

    public function test_the_refund_period_comes_of_the_accommodation(): void
    {
        Property::master()->update(['deposit_auto_refund' => true, 'deposit_days_after_departure' => 3]);

        $shorter = $this->paidDeposit($this->accommodation(['deposit_days_after_departure' => 1]), 2);
        $longer = $this->paidDeposit($this->accommodation(['deposit_days_after_departure' => 7]), 5);
        $default = $this->paidDeposit($this->accommodation(), 3);

        $this->artisan('deposits:process')->assertSuccessful();

        $this->assertSame(1, $shorter->refunds()->count());
        $this->assertSame(0, $longer->refunds()->count());
        $this->assertSame(1, $default->refunds()->count());
    }

    public function test_mail_text_own_text_goes_for_and_empty_follows_the_default(): void
    {
        Property::master()->update(['mail_texts' => ['checkin' => ['nl' => 'Standaardtekst']]]);

        $this->assertSame('Standaardtekst', $this->accommodation()->mailText(MailType::Checkin));

        $custom = $this->accommodation(['mail_texts' => ['checkin' => ['nl' => 'Eigen tekst']]]);
        $this->assertSame('Eigen tekst', $custom->mailText(MailType::Checkin));
        $this->assertNull($custom->mailText(MailType::Review));
    }

    public function test_a_own_text_shows_never_the_translation_of_the_default(): void
    {
        Property::master()->update(['mail_texts' => ['checkin' => ['nl' => 'Standaard', 'en' => 'Default']]]);
        $custom = $this->accommodation(['mail_texts' => ['checkin' => ['nl' => 'Eigen tekst', 'en' => null]]]);

        app()->setLocale('en');

        $this->assertNull($custom->mailText(MailType::Checkin));
    }

    public function test_only_empty_fields_count_not_as_own_text(): void
    {
        Property::master()->update(['mail_texts' => ['checkin' => ['nl' => 'Standaard']]]);
        $empty = $this->accommodation(['mail_texts' => ['checkin' => ['nl' => null, 'en' => '']]]);

        $this->assertSame('Standaard', $empty->mailText(MailType::Checkin));
    }

    public function test_the_mail_on_the_guest_used_the_text_of_the_accommodation(): void
    {
        Property::master()->update(['mail_texts' => ['confirmation' => ['nl' => 'Standaardtekst']]]);
        $booking = $this->booking($this->accommodation(['mail_texts' => ['confirmation' => ['nl' => 'Neem je sleutel mee']]]));

        $html = (new BookingMail($booking, MailType::Confirmation))->render();

        $this->assertStringContainsString('Neem je sleutel mee', $html);
        $this->assertStringNotContainsString('Standaardtekst', $html);
    }

    public function test_pricing_service_per_accommodation_with_the_default_as_fallback(): void
    {
        $day = today()->addDays(10)->toDateString();
        Property::master()->update(['pricing_service' => 'pricelabs']);

        $pricelabs = new FakeDynamicPricingProvider;
        $pricelabs->prices = [$day => 11000];
        $this->app->instance(PriceLabsProvider::class, $pricelabs);

        $wheelhouse = new FakeDynamicPricingProvider;
        $wheelhouse->prices = [$day => 22000];
        $this->app->instance(WheelhouseProvider::class, $wheelhouse);

        $follows = $this->accommodation(['external_listing_id' => 'A']);
        $custom = $this->accommodation(['external_listing_id' => 'B', 'pricing_service' => 'wheelhouse']);
        $off = $this->accommodation(['external_listing_id' => 'C', 'pricing_service' => 'none']);

        $this->artisan('prices:sync')->assertSuccessful();

        $this->assertDatabaseHas('nightly_rates', ['property_id' => $follows->id, 'price_cents' => 11000]);
        $this->assertDatabaseHas('nightly_rates', ['property_id' => $custom->id, 'price_cents' => 22000]);
        $this->assertDatabaseMissing('nightly_rates', ['property_id' => $off->id]);
    }

    public function test_the_defaults_show_and_save_all_rules(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->fillForm([
                'payment_method' => 'down_payment', 'down_payment_percentage' => 25, 'vat_percentage' => 21,
                'deposit_days_before_arrival' => 8, 'deposit_days_after_departure' => 4, 'deposit_auto_refund' => '1',
                'pricing_service' => 'beyond', 'balance_payment_days_before_arrival' => 20,
                'checkin_days_before_arrival' => 2, 'review_days_after_departure' => 6,
                'mail_texts.review.nl' => 'Bedankt!',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $master = Property::master();

        $this->assertSame(25, $master->down_payment_percentage);
        $this->assertSame(21, $master->vat_percentage);
        $this->assertSame(8, $master->deposit_days_before_arrival);
        $this->assertSame(4, $master->deposit_days_after_departure);
        $this->assertTrue((bool) $master->deposit_auto_refund);
        $this->assertSame('beyond', $master->pricing_service);
        $this->assertSame(20, $master->balance_payment_days_before_arrival);
        $this->assertSame(2, $master->checkin_days_before_arrival);
        $this->assertSame(6, $master->review_days_after_departure);
        // The mail texts come from a text editor and are stored as HTML.
        $this->assertSame('<p>Bedankt!</p>', $master->mail_texts['review']['nl']);
        // An empty editor stays empty, so the default text keeps applying.
        $this->assertNull($master->mail_texts['review']['en'] ?? null);
    }

    public function test_a_default_may_not_empty_are(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->fillForm(['vat_percentage' => null, 'review_days_after_departure' => null, 'pricing_service' => null])
            ->call('save')
            ->assertHasFormErrors(['vat_percentage', 'review_days_after_departure', 'pricing_service']);
    }

    public function test_a_accommodation_can_the_default_follow_and_override(): void
    {
        $accommodation = $this->accommodation(['down_payment_percentage' => 50, 'vat_percentage' => 19]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertFormSet(['payment_method' => 'down_payment', 'down_payment_percentage' => 50])
            ->fillForm(['payment_method' => 'follow', 'vat_percentage' => null, 'pricing_service' => 'wheelhouse'])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();

        $this->assertNull($accommodation->down_payment_percentage);
        $this->assertNull($accommodation->vat_percentage);
        $this->assertSame('wheelhouse', $accommodation->pricing_service);
    }

    public function test_a_new_accommodation_follows_the_default_on_the_screen(): void
    {
        Property::master()->update(['down_payment_percentage' => 100]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->assertFormSet(['payment_method' => 'follow'])
            ->assertSee(__('The default is now: everything at once when booking.'));
    }

    public function test_a_text_in_a_switched_off_language_stays_saved(): void
    {
        Language::query()->where('code', 'de')->update(['active' => false]);
        Language::forgets();

        $accommodation = $this->accommodation(['mail_texts' => ['review' => ['nl' => 'Hoi', 'de' => 'Hallo']]]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['mail_texts.review.nl' => 'Hoi daar'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['nl' => '<p>Hoi daar</p>', 'de' => 'Hallo'], array_filter($accommodation->refresh()->mail_texts['review']));
    }

    public function test_the_translate_button_fills_only_empty_languages_of_the_mail_texts(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$to}] {$text}";
            }
        });

        Property::master()->update(['mail_texts' => ['review' => ['nl' => 'Bedankt', 'en' => 'Thanks']]]);

        Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->callAction(TestAction::make('translate_mail_texts')->schemaComponent('own_texts', schema: 'form'))
            ->assertFormSet([
                'mail_texts.review.nl' => '<p>Bedankt</p>',
                'mail_texts.review.de' => '[de] <p>Thanks</p>',
            ]);
    }

    public function test_host_without_permission_sees_and_changes_the_rules_not(): void
    {
        $accommodation = $this->accommodation(['down_payment_percentage' => 50, 'deposit_cents' => 10000]);
        $host = $this->host($accommodation);

        Livewire::actingAs($host)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertDontSee(__('When does the guest pay?'))
            ->assertDontSee(__('Automatic emails'))
            ->assertSee(__('Deposit'))
            ->set('data.down_payment_percentage', 1)
            ->set('data.payment_method', 'everything')
            ->set('data.vat_percentage', 0)
            ->set('data.deposit_auto_refund', '1')
            ->set('data.pricing_service', 'beyond')
            ->set('data.review_days_after_departure', 99)
            ->set('data.mail_texts', ['review' => ['nl' => 'Gehackt']])
            ->set('data.deposit_cents', 150)
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();

        $this->assertSame(50, $accommodation->down_payment_percentage);
        $this->assertNull($accommodation->vat_percentage);
        $this->assertNull($accommodation->deposit_auto_refund);
        $this->assertNull($accommodation->pricing_service);
        $this->assertNull($accommodation->review_days_after_departure);
        $this->assertNull($accommodation->mail_texts);
        $this->assertSame(15000, $accommodation->deposit_cents); // he was already allowed the deposit amount
    }

    public function test_host_with_the_separate_permission_can_the_rules_change(): void
    {
        $accommodation = $this->accommodation();
        $host = $this->host($accommodation, ['payment_rules' => [Access::EDIT]]);

        Livewire::actingAs($host)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertSee(__('When does the guest pay?'))
            ->fillForm(['payment_method' => 'everything', 'review_days_after_departure' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();

        $this->assertSame(100, $accommodation->down_payment_percentage);
        $this->assertSame(9, $accommodation->review_days_after_departure);
    }

    public function test_the_permission_is_default_off_for_a_host_and_on_for_a_staff(): void
    {
        $host = User::factory()->create(['role' => UserRole::Host]);
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->assertFalse(Access::may($host, 'payment_rules', Access::EDIT));
        $this->assertTrue(Access::may($staff, 'payment_rules', Access::EDIT));
    }

    public function test_settings_have_only_still_what_for_the_whole_platform_applies(): void
    {
        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->assertDontSee(__('When does the guest pay?'))
            ->assertDontSee(__('Automatic emails'))
            ->fillForm(['company_name' => 'Duinhuisjes', 'payments_provider' => 'stripe'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('stripe', Setting::get('payments', 'provider'));
    }

    public function test_the_move_takes_the_old_settings_along_to_the_defaults(): void
    {
        Property::master()->update(['down_payment_percentage' => 30]);

        foreach ([
            ['payments', 'down_payment_percentage', '100'],
            ['tax', 'vat_percentage', '21'],
            ['deposit', 'auto_refund', '1'],
            ['prices', 'service', 'pricelabs'],
            ['mail', 'review_days_after_departure', '5'],
            ['mail', 'text_review', 'Oud zonder taal'],
            ['mail', 'text_payment_received_nl', 'Ontvangen'],
            ['mail', 'text_payment_received_en', 'Received'],
            ['payments', 'provider', 'stripe'],
        ] as [$group, $key, $value]) {
            Setting::set($group, $key, $value);
        }

        $migration = require database_path('migrations/2026_10_30_140000_accommodation_settings.php');
        (new \ReflectionMethod($migration, 'moveSettings'))->invoke($migration);

        $master = Property::master();

        $this->assertSame(100, $master->down_payment_percentage);
        $this->assertSame(21, $master->vat_percentage);
        $this->assertTrue((bool) $master->deposit_auto_refund);
        $this->assertSame('pricelabs', $master->pricing_service);
        $this->assertSame(5, $master->review_days_after_departure);
        $this->assertSame(14, $master->balance_payment_days_before_arrival);
        $this->assertSame(['nl' => 'Oud zonder taal'], $master->mail_texts['review']);
        $this->assertSame(['nl' => 'Ontvangen', 'en' => 'Received'], $master->mail_texts['payment_received']);

        // Moved, so one source; what applies to the whole platform stays.
        $this->assertNull(Setting::get('payments', 'down_payment_percentage'));
        $this->assertNull(Setting::get('mail', 'text_review'));
        $this->assertSame('stripe', Setting::get('payments', 'provider'));
    }
}
