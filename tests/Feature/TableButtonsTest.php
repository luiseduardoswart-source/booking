<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The row buttons in the admin tables: icons with the name as tooltip, so the
 * tables stay narrow, and the menus behind them keep working as normal.
 */
class TableButtonsTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        return Booking::create([
            'booking_number' => 'BK-'.uniqid(), 'property_id' => $accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03', 'guests' => 2,
            'total_cents' => 32000, 'down_payment_cents' => 9600, 'deposit_cents' => 5000,
            'price_snapshot' => ['lines' => []],
        ]);
    }

    private function bookingsTable(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(ListBookings::class);
    }

    /** @return array<class-string, Table> every table in the admin, per resource */
    private function allTables(): array
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        return collect(Filament::getPanel('admin')->getResources())
            ->mapWithKeys(fn (string $resource) => [
                $resource => Livewire::test($resource::getPages()['index']->getPage())->instance()->getTable(),
            ])
            ->all();
    }

    public function test_every_row_button_in_every_table_is_a_icon_with_are_name_as_tooltip(): void
    {
        $seen = 0;

        foreach ($this->allTables() as $resource => $table) {
            foreach ($table->getRecordActions() as $button) {
                $seen++;

                $this->assertTrue($button->isIconButton(), "{$resource}: {$button->getLabel()} is not an icon");
                $this->assertSame($button->getLabel(), $button->getTooltip(), "{$resource}: tooltip is wrong");
            }
        }

        $this->assertGreaterThan(20, $seen);
    }

    public function test_row_buttons_have_the_interface_color_and_only_delete_is_red(): void
    {
        $deleteButtons = 0;

        foreach ($this->allTables() as $resource => $table) {
            foreach ($table->getRecordActions() as $button) {
                $delete = $button instanceof DeleteAction;
                $deleteButtons += (int) $delete;

                $this->assertSame($delete ? 'danger' : 'gray', $button->getColor(), "{$resource}: {$button->getLabel()}");
            }
        }

        $this->assertGreaterThan(5, $deleteButtons);
    }

    public function test_inside_a_table_has_every_button_a_own_icon(): void
    {
        foreach ($this->allTables() as $resource => $table) {
            $icons = array_map(
                fn (Action|ActionGroup $button) => $button instanceof Action ? ($button->getTableIcon() ?? $button->getIcon()) : $button->getIcon(),
                array_values($table->getRecordActions()),
            );

            $this->assertSame(count($icons), count(array_unique($icons, SORT_REGULAR)), "{$resource} uses an icon twice");
        }
    }

    public function test_documents_and_status_stay_menus_at_the_bookings(): void
    {
        $groups = array_filter(
            $this->bookingsTable()->instance()->getTable()->getRecordActions(),
            fn ($button): bool => $button instanceof ActionGroup,
        );

        $this->assertSame([__('Documents'), __('Status')], array_values(array_map(fn (ActionGroup $group) => $group->getLabel(), $groups)));
    }

    public function test_the_status_changes_still_still_via_the_menu(): void
    {
        Mail::fake();
        $booking = $this->booking();

        $this->bookingsTable()->callAction(TestAction::make('to_'.BookingStatus::Cancelled->value)->table($booking));

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_the_confirmation_downloads_still_still_via_the_menu(): void
    {
        $booking = $this->booking();

        $this->bookingsTable()
            ->callAction(TestAction::make('confirmation_pdf')->table($booking))
            ->assertFileDownloaded("confirmation-{$booking->booking_number}.pdf");
    }
}
