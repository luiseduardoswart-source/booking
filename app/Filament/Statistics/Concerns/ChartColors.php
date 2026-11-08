<?php

namespace App\Filament\Statistics\Concerns;

/**
 * Validated colour palette for the statistics charts.
 *
 * The order of CATEGORICAL is not a matter of taste but the result of a
 * colour-blindness check: this arrangement has the largest mutual distance
 * (worst adjacent ΔE 23.6, well above the threshold of 12) and all colours reach
 * ≥ 3:1 contrast on both the light and the dark admin background, so that
 * one fixed set works in both themes.
 *
 * FUNNEL is deliberately a single colour from light to dark (ordinal ramp): the steps
 * views → price requests → bookings are consecutive stages, not
 * separate categories.
 */
trait ChartColors
{
    /** @var list<string> */
    protected const CATEGORICAL = ['#3987e5', '#c98500', '#199e70', '#9085e9', '#008300', '#e66767'];

    /** @var list<string> */
    protected const FUNNEL = ['#86b6ef', '#3987e5', '#184f95'];

    protected const PRIMARY = '#3987e5';

    /** Neutral for the unlikely case that there are more categories than colours. */
    protected const OTHER = '#898781';

    /**
     * One colour per category, never reused cyclically.
     *
     * @return list<string>
     */
    protected function categoricalColors(int $count): array
    {
        return array_map(
            fn (int $i) => self::CATEGORICAL[$i] ?? self::OTHER,
            range(0, max(0, $count - 1))
        );
    }

    /**
     * Hide the legend for a single series, as the title already names it.
     *
     * @return array<string, mixed>
     */
    protected function withoutLegend(): array
    {
        return ['plugins' => ['legend' => ['display' => false]]];
    }
}
