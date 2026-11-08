<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\User;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layout of the Prices tab, and the page jumping when
 * adding or removing a discount or season.
 */
class PricesLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        $default = \App\Models\Property::master();
        $default->discounts()->create(['min_nights' => 7, 'percentage' => 10]);
        $default->rateSeasons()->create(['name' => 'Zomer', 'start_date' => '2026-07-01', 'end_date' => '2026-08-31', 'price_cents' => 15000]);

        return Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditDefaults::class)
            ->html();
    }

    public function test_buttons_in_repeat_blocks_take_at_a_click_no_focus(): void
    {
        $html = $this->html();

        // Otherwise Filament gives the lost focus back to the active tab
        // after rebuilding and scrolls the page there.
        foreach (['discounts', 'rateSeasons'] as $block) {
            foreach (['add', 'delete'] as $action) {
                $this->assertMatchesRegularExpression(
                    '/<button(?=[^>]*'.preg_quote("mountAction('{$action}'", '/').')(?=[^>]*'.preg_quote($block, '/').')(?=[^>]*x-on:mousedown\.prevent)[^>]*>/',
                    $html,
                    "{$block}: {$action}",
                );
            }
        }
    }

    public function test_the_currency_is_in_the_heading_of_the_prices(): void
    {
        $html = $this->html();

        // From the heading "Prices" to the end of the header of that section.
        $start = strpos($html, 'id="form.prices-heading"');
        $this->assertNotFalse($start);
        $heading = substr($html, $start, strpos($html, '</header>', $start) - $start);

        $this->assertStringContainsString('for="form.prices.currency"', $heading);
    }
}
