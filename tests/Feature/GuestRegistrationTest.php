<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\GuestRegistration;
use App\Models\Property;
use App\Models\User;
use App\Support\Exports\BookingExport;
use App\Support\GuestAccess;
use App\Support\GuestDataEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Where the law requires it, the guest registers every traveller before
 * arrival; the owner exports them for the police or city portal, and they
 * are erased a number of days after departure.
 */
class GuestRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->property = Property::create([
            'name' => 'Casa Toscana', 'slug' => 'casa-toscana', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true,
            'base_price_cents' => 10000, 'country' => 'IT', 'guest_registration' => true, 'guest_registration_keep_days' => 10,
        ]);
    }

    private function booking(array $extra = []): Booking
    {
        static $number = 0;
        $number++;

        return Booking::create([
            'booking_number' => "BK-REG-{$number}", 'property_id' => $this->property->id, 'status' => BookingStatus::Confirmed,
            'payment_status' => 'paid', 'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com', 'locale' => 'en',
            'arrival_date' => today()->addDays(20)->toDateString(), 'departure_date' => today()->addDays(23)->toDateString(),
            'guests' => 2, 'adults' => 2, 'children' => 0, 'infants' => 1,
            'total_cents' => 30000, 'down_payment_cents' => 30000, 'price_snapshot' => ['lines' => []],
            ...$extra,
        ]);
    }

    private function traveller(string $first, string $birthDate, bool $document = true, array $extra = []): array
    {
        return [
            'first_name' => $first, 'last_name' => 'de Vries', 'sex' => 'male', 'birth_date' => $birthDate,
            'birth_country' => 'NL', 'nationality' => 'NL',
            'document_type' => $document ? 'passport' : '', 'document_number' => $document ? 'NX'.strtoupper($first).'123' : '',
            'document_country' => $document ? 'NL' : '', 'residence_country' => 'NL', 'residence_city' => 'Utrecht',
            'residence_address' => 'Oudegracht 1', ...$extra,
        ];
    }

    /** Jan, Eva and their baby: three travellers, the baby without a document. */
    private function family(): array
    {
        return [
            1 => $this->traveller('Jan', '1985-04-02'),
            2 => $this->traveller('Eva', '1987-09-12', extra: ['sex' => 'female', 'residence_country' => '', 'residence_city' => '']),
            3 => $this->traveller('Baby', today()->subMonths(8)->toDateString(), document: false),
        ];
    }

    public function test_the_guest_registers_every_traveller_through_the_link(): void
    {
        $booking = $this->booking();
        $link = $booking->signedLink('guest-registration.show');

        $page = $this->get($link)->assertOk()->getContent();
        $this->assertSame(3, substr_count($page, '<fieldset'));

        $this->post($booking->signedLink('guest-registration.store'), ['travellers' => $this->family()])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $travellers = $booking->guestRegistrations()->get();
        $this->assertSame(['Jan', 'Eva', 'Baby'], $travellers->pluck('first_name')->all());
        $this->assertSame('NXJAN123', $travellers[0]->document_number);
        $this->assertNull($travellers[2]->document_number);
        // The document number and the address are encrypted, and never in JSON.
        $raw = DB::table('guest_registrations')->where('position', 1)->first();
        $this->assertStringNotContainsString('NXJAN123', $raw->document_number);
        $this->assertStringNotContainsString('Oudegracht', $raw->residence_address);
        $this->assertArrayNotHasKey('document_number', $travellers[0]->toArray());
        $this->assertFalse($booking->refresh()->guestRegistrationMissing());

        // Filled in again: replaced, not added.
        $this->post($booking->signedLink('guest-registration.store'), ['travellers' => $this->family()])->assertRedirect();
        $this->assertSame(3, $booking->guestRegistrations()->count());

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertSee(trans_choice('{0} No guests registered yet, of :total.|{1} 1 of :total guests registered.|[2,*] :count of :total guests registered.', 3, ['total' => 3]))
            ->assertSee('Eva de Vries')
            ->assertDontSee('NXJAN123');
    }

    public function test_every_traveller_and_a_document_from_fourteen_are_required(): void
    {
        $booking = $this->booking();
        $family = $this->family();

        // An adult without a document.
        $withoutDocument = $family;
        $withoutDocument[2] = $this->traveller('Eva', '1987-09-12', document: false);
        $this->post($booking->signedLink('guest-registration.store'), ['travellers' => $withoutDocument])
            ->assertSessionHasErrors(['travellers.2.document_number']);

        // Too few travellers, or the main guest without a place of residence.
        $this->post($booking->signedLink('guest-registration.store'), ['travellers' => [1 => $family[1], 2 => $family[2]]])
            ->assertSessionHasErrors(['travellers']);
        $family[1]['residence_city'] = '';
        $this->post($booking->signedLink('guest-registration.store'), ['travellers' => $family])
            ->assertSessionHasErrors(['travellers.1.residence_city']);

        $this->assertSame(0, GuestRegistration::count());
    }

    public function test_only_through_the_signed_link_and_only_where_it_is_asked(): void
    {
        $booking = $this->booking();

        $this->get(route('guest-registration.show', $booking))->assertForbidden();
        $this->post(route('guest-registration.store', $booking), ['travellers' => $this->family()])->assertForbidden();

        $cancelled = $this->booking(['status' => BookingStatus::Cancelled]);
        $this->get($cancelled->signedLink('guest-registration.show'))->assertNotFound();

        $this->property->update(['guest_registration' => false]);
        $this->get($booking->signedLink('guest-registration.show'))->assertNotFound();
    }

    public function test_the_confirmation_my_booking_and_the_check_in_email_ask_for_it_until_it_is_done(): void
    {
        $booking = $this->booking();
        // The emails are in the guest's language (English here); the page in the visitor's: look for the link itself.
        $button = __('Register your guests', [], 'en');
        $link = "/booking/{$booking->id}/guests?";

        $this->assertStringContainsString($button, (new BookingMail($booking, MailType::Confirmation))->render());
        $this->assertStringContainsString($button, (new BookingMail($booking, MailType::Checkin))->render());
        $this->get(GuestAccess::bookingUrl($booking))->assertOk()->assertSee($link, false);

        $this->post($booking->signedLink('guest-registration.store'), ['travellers' => $this->family()])->assertRedirect();

        $this->assertStringNotContainsString($button, (new BookingMail($booking->refresh(), MailType::Checkin))->render());
        $this->get(GuestAccess::bookingUrl($booking))->assertOk()->assertDontSee($link, false);
    }

    public function test_the_owner_exports_the_registered_guests(): void
    {
        $booking = $this->booking();
        $this->post($booking->signedLink('guest-registration.store'), ['travellers' => $this->family()])->assertRedirect();

        $component = Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(ListBookings::class)
            ->callAction('export', [
                'kind' => BookingExport::GUEST_REGISTRATION,
                'from' => today()->toDateString(), 'until' => today()->addMonths(2)->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, base64_decode(data_get($component->effects, 'download.content')));
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        $titles = array_shift($rows);
        $this->assertCount(3, $rows);
        $first = array_combine($titles, array_pad($rows[0], count($titles), null));
        $this->assertSame($booking->booking_number, $first[__('Booking number')]);
        $this->assertSame('Jan', $first[__('First name')]);
        $this->assertSame('NXJAN123', $first[__('Document number')]);
        $this->assertSame('NL', $first[__('Nationality')]);
        $this->assertSame('1985-04-02', $first[__('Date of birth')]->format('Y-m-d'));
    }

    public function test_the_details_are_erased_after_the_days_to_keep_and_with_the_guest_data(): void
    {
        $past = $this->booking(['arrival_date' => today()->subDays(15)->toDateString(), 'departure_date' => today()->subDays(11)->toDateString()]);
        $recent = $this->booking(['arrival_date' => today()->subDays(5)->toDateString(), 'departure_date' => today()->subDays(2)->toDateString()]);
        $cancelled = $this->booking(['status' => BookingStatus::Cancelled]);
        foreach ([$past, $recent, $cancelled] as $booking) {
            GuestRegistration::create(['booking_id' => $booking->id, 'position' => 1, ...$this->traveller('Jan', '1985-04-02')]);
        }

        $this->artisan('registrations:purge')->assertSuccessful();

        $this->assertSame([$recent->id], GuestRegistration::query()->pluck('booking_id')->all());

        GuestDataEraser::eraseGuestOf($recent);
        $this->assertSame(0, GuestRegistration::count());
    }
}
