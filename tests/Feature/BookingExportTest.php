<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Host;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use App\Support\Exports\BookingExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The export on the bookings overview: an Excel file of the bookings that
 * arrive in a period, or the tourist tax per accommodation and month.
 */
class BookingExportTest extends TestCase
{
    use RefreshDatabase;

    private Property $beach;

    private Property $cabin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beach = $this->accommodation('Beach house', 'beach-house', 'IT058091C2ABCDE123');
        $this->cabin = $this->accommodation('Jungle cabin', 'jungle-cabin');
    }

    private function accommodation(string $name, string $slug, ?string $registration = null): Property
    {
        return Property::create([
            'name' => $name, 'slug' => $slug, 'type' => 'holiday_home', 'max_guests' => 6, 'active' => true,
            'base_price_cents' => 10000, 'city' => 'Cartagena', 'registration_number' => $registration,
        ]);
    }

    private function booking(Property $property, string $arrival, int $nights, array $extra = []): Booking
    {
        static $number = 0;
        $number++;

        return Booking::create([
            'booking_number' => "BK-EXP-{$number}", 'property_id' => $property->id, 'status' => BookingStatus::Confirmed,
            'payment_status' => 'unpaid', 'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com', 'guest_country' => 'NL',
            'arrival_date' => $arrival, 'departure_date' => \Carbon\CarbonImmutable::parse($arrival)->addDays($nights)->toDateString(),
            'guests' => 2, 'currency' => 'EUR',
            'price_snapshot' => ['lines' => [
                ['key' => 'accommodation', 'label' => 'Nights', 'amount_cents' => 30000],
                ['key' => 'discount', 'label' => 'Weekly discount', 'amount_cents' => -3000],
                ['key' => 'cleaning', 'label' => 'Cleaning fee', 'amount_cents' => 5000],
                ['key' => 'tourist_tax', 'label' => 'Tourist tax', 'amount_cents' => 1200],
            ]],
            'total_cents' => 33200, 'down_payment_cents' => 10000,
            ...$extra,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    /**
     * The rows of the downloaded file; $sheet gets the XML of the sheet itself.
     *
     * @return array<int, array<string, mixed>> keyed by the column titles
     */
    private function export(User $user, array $choices, ?string &$sheet = null): array
    {
        $component = Livewire::actingAs($user)->test(ListBookings::class)
            ->callAction('export', [
                'kind' => BookingExport::BOOKINGS, 'from' => '2027-03-01', 'until' => '2027-03-31', 'with_cancelled' => false,
                ...$choices,
            ])
            ->assertHasNoActionErrors();

        $download = data_get($component->effects, 'download');
        $this->assertNotNull($download, 'no file downloaded');

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, base64_decode($download['content']));
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $worksheet) {
            foreach ($worksheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();
        unlink($path);

        $titles = array_shift($rows);

        return array_map(fn (array $row): array => array_combine($titles, array_pad($row, count($titles), null)), $rows);
    }

    public function test_the_bookings_of_the_period_with_their_amounts_and_payments(): void
    {
        $march = $this->booking($this->beach, '2027-03-10', 3, ['adults' => 2, 'coupon_code' => 'SPRING']);
        $this->booking($this->beach, '2027-04-02', 3);
        $this->booking($this->beach, '2027-03-20', 3, ['status' => BookingStatus::Cancelled]);
        Payment::create([
            'booking_id' => $march->id, 'type' => PaymentType::DownPayment, 'status' => PaymentTransactionStatus::Paid,
            'amount_cents' => 10000, 'provider' => 'stripe', 'provider_payment_id' => 'cs_test_1', 'paid_at' => now(),
        ]);

        $rows = $this->export($this->owner(), []);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($march->booking_number, $row[__('Booking number')]);
        $this->assertSame('Beach house', $row[__('Accommodation')]);
        $this->assertSame('2027-03-10', $row[__('Arrival')]->format('Y-m-d'));
        $this->assertEquals(3, $row[__('Nights')]);
        $this->assertEquals(2, $row[__('Adults')]);
        $this->assertSame('SPRING', $row[__('Coupon code')]);
        $this->assertEquals(300, $row[__('Rent')]);
        $this->assertEquals(-30, $row[__('Discounts')]);
        $this->assertEquals(50, $row[__('Cleaning fee')]);
        $this->assertEquals(12, $row[__('Tourist tax')]);
        $this->assertEquals(332, $row[__('Total')]);
        $this->assertEquals(100, $row[__('Paid')]);
        $this->assertEquals(232, $row[__('Outstanding')]);

        $this->assertSame(1, Activity::query()->where('event', 'bookings_exported')->count());
    }

    public function test_cancelled_bookings_only_when_asked(): void
    {
        $this->booking($this->beach, '2027-03-10', 3);
        $this->booking($this->beach, '2027-03-20', 3, ['status' => BookingStatus::Cancelled]);

        $rows = $this->export($this->owner(), ['with_cancelled' => true]);

        $this->assertCount(2, $rows);
        $this->assertSame(__('Cancelled'), $rows[1][__('Status')]);
    }

    public function test_a_name_typed_as_a_formula_stays_text(): void
    {
        $this->booking($this->beach, '2027-03-10', 3, ['guest_name' => '=HYPERLINK("https://evil.example","Click")']);

        $rows = $this->export($this->owner(), [], $sheet);

        $this->assertSame('=HYPERLINK("https://evil.example","Click")', $rows[0][__('Guest')]);
        // Written as plain text: no formula anywhere in the sheet.
        $this->assertStringContainsString('t="inlineStr"><is><t>=HYPERLINK(', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
    }

    public function test_a_host_exports_only_their_own_bookings_and_amounts_paid_need_that_right(): void
    {
        $host = Host::create(['name' => 'Ana']);
        $this->cabin->hosts()->sync([$host->id]);
        $own = $this->booking($this->cabin, '2027-03-12', 2);
        $this->booking($this->beach, '2027-03-10', 3);
        $user = User::factory()->create(['role' => UserRole::Host, 'host_id' => $host->id, 'permissions' => ['bookings' => [Access::VIEW]]]);

        $rows = $this->export($user, []);

        $this->assertSame([$own->booking_number], array_column($rows, __('Booking number')));
        $this->assertArrayNotHasKey(__('Paid'), $rows[0]);

        $user->forceFill(['permissions' => ['bookings' => [Access::VIEW], 'payments' => [Access::VIEW]]])->save();
        $this->assertArrayHasKey(__('Paid'), $this->export($user->refresh(), [])[0]);
    }

    public function test_the_tourist_tax_per_accommodation_and_month(): void
    {
        $this->booking($this->beach, '2027-03-10', 3);
        $this->booking($this->beach, '2027-03-25', 2, ['guests' => 3, 'price_snapshot' => ['lines' => [['key' => 'tourist_tax', 'label' => 'Tourist tax', 'amount_cents' => 800]]]]);
        $this->booking($this->beach, '2027-03-15', 4, ['status' => BookingStatus::Cancelled]);
        $this->booking($this->beach, '2027-03-18', 4, ['status' => BookingStatus::Pending]);
        $this->booking($this->cabin, '2027-04-01', 2);

        $rows = $this->export($this->owner(), ['kind' => BookingExport::TOURIST_TAX, 'until' => '2027-04-30']);

        $this->assertCount(2, $rows);
        [$beach, $cabin] = $rows;
        $this->assertSame('Beach house', $beach[__('Accommodation')]);
        $this->assertSame('IT058091C2ABCDE123', $beach[__('Registration number')]);
        $this->assertSame('2027-03', $beach[__('Month')]);
        $this->assertEquals(2, $beach[__('Stays')]);
        $this->assertEquals(5, $beach[__('Nights')]);
        $this->assertEquals(5, $beach[__('Guests')]);
        $this->assertEquals(2 * 3 + 3 * 2, $beach[__('Guest nights')]);
        $this->assertEquals(20, $beach[__('Tourist tax')]);
        $this->assertSame('Jungle cabin', $cabin[__('Accommodation')]);
        $this->assertSame('2027-04', $cabin[__('Month')]);
    }
}
