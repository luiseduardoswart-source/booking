<?php

namespace App\Console\Commands;

use App\Models\NightlyRate;
use App\Models\Property;
use App\Services\PricingServices;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncDynamicPrices extends Command
{
    protected $signature = 'prices:sync {--months=12 : Number of months ahead to synchronise}';

    protected $description = 'Fetches dynamic prices (PriceLabs) and stores them as nightly prices per accommodation';

    public function handle(PricingServices $services): int
    {
        $from = CarbonImmutable::today();
        $to = $from->addMonths((int) $this->option('months'));

        $total = 0;
        $configured = 0;

        Property::query()
            ->whereNotNull('external_listing_id')
            ->each(function (Property $property) use ($services, $from, $to, &$total, &$configured) {
                $provider = $services->before($property);

                if (! $provider->isConfigured()) {
                    return;
                }

                $configured++;
                $prices = $provider->pricesFor($property, $from, $to);

                // Prices set by hand in the calendar stay when the accommodation says so.
                if ($property->manualPricesWin()) {
                    $prices = array_diff_key($prices, array_flip($this->manualDates($property, $from, $to)));
                }

                foreach ($prices as $date => $cents) {
                    NightlyRate::updateOrCreate(
                        ['property_id' => $property->id, 'date' => $date],
                        ['price_cents' => $cents, 'source' => NightlyRate::SOURCE_PRICELABS],
                    );
                    $total++;
                }

                $this->line("{$property->name}: ".count($prices).' nights updated.');
            });

        if ($configured === 0) {
            $this->info('Dynamic prices are not set up — nothing to synchronise.');

            return self::SUCCESS;
        }

        $this->info("Done — {$total} nightly prices synchronised.");

        return self::SUCCESS;
    }

    /**
     * The nights in the period with a price set by hand.
     *
     * @return array<int, string> dates (Y-m-d)
     */
    private function manualDates(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $property->nightlyRates()
            ->where('source', NightlyRate::SOURCE_MANUAL)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['date'])
            ->map(fn (NightlyRate $rate) => $rate->date->toDateString())
            ->all();
    }
}
