<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use App\Support\MailPlaceholders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Self check-in with a door code in the automatic mails: a fixed code, or the
 * last four digits of the guest's phone number. Plus own check-in details
 * (WiFi password, parking code...) as merge fields.
 */
class AccessCodeTest extends TestCase
{
    use RefreshDatabase;

    private function field(string $key): string
    {
        return '<span data-type="mergeTag" data-id="'.$key.'"></span>';
    }

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => 'Strandhuis', 'slug' => "strandhuis-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, ...$extra,
        ]);
    }

    private function booking(Property $accommodation, array $fields = []): Booking
    {
        static $counter = 0;
        $counter++;

        return Booking::create([
            'booking_number' => "BK-CODE-{$counter}", 'property_id' => $accommodation->id,
            'status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Unpaid,
            'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com', 'guest_phone' => '+31 6 1234 5678',
            'arrival_date' => today()->addDays(30)->toDateString(), 'departure_date' => today()->addDays(33)->toDateString(),
            'guests' => 2, 'total_cents' => 30000, 'down_payment_cents' => 9000,
            'price_snapshot' => ['lines' => []], 'locale' => 'en',
            ...$fields,
        ]);
    }

    /** The check-in mail with the given text, as plain text. */
    private function mail(Booking $booking, string $text): string
    {
        $booking->property->update(['mail_texts' => ['checkin' => ['en' => $text]]]);
        $html = (new BookingMail($booking->refresh(), MailType::Checkin))->render();

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES));
    }

    private function code(Booking $booking): string
    {
        return $this->mail($booking, '<p>Your code: '.$this->field('access_code').'.</p>');
    }

    public function test_a_fixed_code_appears_in_the_mail(): void
    {
        $booking = $this->booking($this->accommodation(['access_code_kind' => 'fixed', 'access_code' => '4711']));

        $this->assertStringContainsString('Your code: 4711.', $this->code($booking));
    }

    public function test_the_last_four_digits_of_the_phone_number_are_the_code(): void
    {
        $booking = $this->booking($this->accommodation(['access_code_kind' => 'phone']));

        $this->assertStringContainsString('Your code: 5678.', $this->code($booking));
    }

    public function test_without_a_usable_code_the_guest_hears_it_comes_separately(): void
    {
        $separately = 'Your code: '.__('You will receive the code from us separately.', [], 'en');
        $phone = $this->accommodation(['access_code_kind' => 'phone']);
        $fixed = $this->accommodation(['access_code_kind' => 'fixed', 'access_code' => '4711']);

        // No phone number (for example a booking from Airbnb).
        $this->assertStringContainsString($separately, $this->code($this->booking($phone, ['guest_phone' => null])));
        // Not confirmed (yet), or cancelled.
        $this->assertStringContainsString($separately, $this->code($this->booking($fixed, ['status' => BookingStatus::Pending])));
        $this->assertStringContainsString($separately, $this->code($this->booking($fixed, ['status' => BookingStatus::Cancelled])));
        // A personal welcome instead of self check-in.
        $fixed->update(['check_in_kind' => 'welcome']);
        $this->assertStringNotContainsString('4711', $this->code($this->booking($fixed)));
    }

    public function test_an_accommodation_follows_the_code_of_the_default(): void
    {
        Property::master()->update(['access_code_kind' => 'fixed', 'access_code' => '9999']);

        $this->assertStringContainsString('Your code: 9999.', $this->code($this->booking($this->accommodation())));
        $this->assertStringContainsString('Your code: 1234.', $this->code($this->booking($this->accommodation(['access_code_kind' => 'fixed', 'access_code' => '1234']))));
    }

    public function test_the_code_is_stored_encrypted_and_stays_out_of_the_log(): void
    {
        $accommodation = $this->accommodation(['access_code_kind' => 'fixed', 'access_code' => '4711']);
        $accommodation->update(['access_code' => '8822', 'check_in_values' => ['wifi' => 'geheim']]);

        $raw = DB::table('properties')->where('id', $accommodation->id)->first();
        $this->assertStringNotContainsString('8822', (string) $raw->access_code);
        $this->assertStringNotContainsString('geheim', (string) $raw->check_in_values);

        $logged = Activity::query()->get()->map(fn (Activity $activity) => json_encode($activity->properties))->implode(' ');
        $this->assertStringNotContainsString('4711', $logged);
        $this->assertStringNotContainsString('8822', $logged);
        $this->assertStringNotContainsString('geheim', $logged);

        $this->assertArrayNotHasKey('access_code', $accommodation->toArray());
    }

    public function test_choosing_another_kind_forgets_the_fixed_code(): void
    {
        $accommodation = $this->accommodation(['access_code_kind' => 'fixed', 'access_code' => '4711']);

        $accommodation->update(['access_code_kind' => 'phone']);

        $this->assertNull($accommodation->refresh()->access_code);
    }

    public function test_own_check_in_fields_become_merge_fields(): void
    {
        Property::master()->update(['check_in_fields' => [
            ['name' => 'WiFi password', 'value' => 'standaard-wifi'],
            ['name' => 'Parking code', 'value' => '0000'],
        ]]);
        $keys = array_keys(Property::checkInFieldNames());
        [$wifi, $parking] = $keys;

        // Each field got a fixed key and is offered in the editor.
        $this->assertCount(2, $keys);
        $this->assertSame('WiFi password', MailPlaceholders::labels()[MailPlaceholders::FIELD_PREFIX.$wifi]);

        $accommodation = $this->accommodation(['check_in_values' => [$wifi => 'eigen-wifi']]);
        $text = $this->mail($this->booking($accommodation),
            '<p>WiFi: '.$this->field(MailPlaceholders::FIELD_PREFIX.$wifi).', parking: '.$this->field(MailPlaceholders::FIELD_PREFIX.$parking).'.</p>');

        // Own value, otherwise that of the default.
        $this->assertStringContainsString('WiFi: eigen-wifi, parking: 0000.', $text);

        // Renaming a field keeps its key, so existing mail texts keep working.
        $fields = Property::master()->check_in_fields;
        $fields[0]['name'] = 'Wireless';
        Property::master()->update(['check_in_fields' => $fields]);
        $this->assertSame([$wifi, $parking], array_keys(Property::checkInFieldNames()));
    }

    public function test_the_booking_form_asks_the_phone_number_when_it_is_the_code(): void
    {
        $this->withoutVite();
        $phone = $this->accommodation(['access_code_kind' => 'phone']);
        $fixed = $this->accommodation(['access_code_kind' => 'fixed', 'access_code' => '4711']);
        $dates = ['arrival' => today()->addDays(30)->toDateString(), 'departure' => today()->addDays(33)->toDateString(), 'guests' => 2];

        $this->get(route('bookings.create', ['property' => $phone->slug, ...$dates]))
            ->assertOk()->assertSee(__('The last four digits are the code of the door.'));
        $this->get(route('bookings.create', ['property' => $fixed->slug, ...$dates]))
            ->assertOk()->assertDontSee(__('The last four digits are the code of the door.'));

        $guest = ['name' => 'Jan', 'email' => 'jan@example.com', 'terms' => '1', ...$dates];
        $this->post(route('bookings.store', $phone->slug), $guest)->assertSessionHasErrors('phone');
        $this->post(route('bookings.store', $phone->slug), [...$guest, 'phone' => '12'])->assertSessionHasErrors('phone');
        $this->post(route('bookings.store', $fixed->slug), $guest)->assertSessionDoesntHaveErrors('phone');
    }

    public function test_code_and_fields_are_saved_from_the_admin(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($owner)
            ->test(EditDefaults::class)
            ->fillForm(['check_in_fields' => [['name' => 'WiFi password', 'value' => 'standaard-wifi']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $key = array_key_first(Property::checkInFieldNames());
        $this->assertNotNull($key);

        $accommodation = $this->accommodation();

        Livewire::actingAs($owner)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertSee('WiFi password')
            ->fillForm(['check_in_kind' => 'self'])
            ->fillForm(['access_code_kind' => 'fixed'])
            ->fillForm(['access_code' => '4711', "check_in_values.{$key}" => 'eigen-wifi'])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame('4711', $accommodation->access_code);
        $this->assertSame('eigen-wifi', $accommodation->checkInValue($key));
    }
}
