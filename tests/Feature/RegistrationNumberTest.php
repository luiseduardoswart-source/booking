<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The registration number of a rental (the CIN in Italy, the registro
 * único in Spain, the number of a Dutch city): entered per accommodation,
 * shown on its page and on the documents.
 */
class RegistrationNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'IT', ...$extra,
        ]);
    }

    private function company(): array
    {
        return ['name' => 'Bedrijf', 'address' => null, 'postal_code_city' => null, 'email' => null, 'phone' => null, 'coc' => null, 'vat_number' => null, 'iban' => null];
    }

    public function test_the_owner_enters_it_per_accommodation_and_not_on_the_defaults(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Administrator]);
        $accommodation = $this->accommodation();

        Livewire::actingAs($owner)
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->fillForm(['registration_number' => 'IT058091C2ABCDE123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('IT058091C2ABCDE123', $accommodation->refresh()->registration_number);

        Livewire::actingAs($owner)
            ->test(EditDefaults::class)
            ->assertFormFieldDoesNotExist('registration_number');
    }

    public function test_the_accommodation_page_shows_it_only_when_there_is_one(): void
    {
        $accommodation = $this->accommodation(['registration_number' => 'IT058091C2ABCDE123']);

        $this->get('/en/strandhuis')->assertOk()->assertSee('IT058091C2ABCDE123');

        $accommodation->update(['registration_number' => null]);
        app()->setLocale('en');
        $this->get('/en/strandhuis')->assertOk()->assertDontSee(__('Registration number'));
    }

    public function test_the_confirmation_and_the_invoice_show_it(): void
    {
        $accommodation = $this->accommodation(['registration_number' => 'IT058091C2ABCDE123']);
        $booking = Booking::create([
            'booking_number' => 'BK-REG-1', 'property_id' => $accommodation->id, 'status' => BookingStatus::Confirmed, 'payment_status' => 'paid',
            'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com',
            'arrival_date' => today()->addDays(10)->toDateString(), 'departure_date' => today()->addDays(13)->toDateString(), 'guests' => 2,
            'total_cents' => 30000, 'down_payment_cents' => 30000, 'currency' => 'EUR',
            'price_snapshot' => ['lines' => [['key' => 'accommodation', 'label' => '3 nights', 'amount_cents' => 30000]]],
        ]);
        $invoice = app(\App\Engines\DocumentEngine::class)->findOrCreateInvoice($booking);

        foreach (['pdf.confirmation' => [], 'pdf.invoice' => ['invoice' => $invoice]] as $view => $extra) {
            $html = view($view, ['booking' => $booking, 'company' => $this->company(), ...$extra])->render();

            $this->assertStringContainsString('IT058091C2ABCDE123', $html, $view);
        }
    }
}
