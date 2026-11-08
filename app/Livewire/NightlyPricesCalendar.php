<?php

namespace App\Livewire;

use App\Engines\CalendarEngine;
use App\Engines\PricingEngine;
use App\Models\NightlyRate;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Interactive month calendar with the price per night, embedded as a tab in the
 * accommodation form. Select nights and apply a price, discount or
 * block. Nightly overrides (source 'manual'/'pricelabs') take precedence over
 * season/base; the origin of each price is visible per night.
 */
class NightlyPricesCalendar extends Component
{
    /** Limit per drag or Shift+click, so a request cannot blow up the selection without bound. */
    private const MAX_NIGHTS_PER_RANGE = 731;

    public Property $property;

    public int $monthOffset = 0;

    /** @var array<int, string> selected dates (Y-m-d) */
    public array $selected = [];

    public ?string $priceInput = null;

    public ?string $discountInput = null;

    public function mount(Property $property): void
    {
        $this->property = $property;
    }

    public function previous(): void
    {
        $this->monthOffset = max(0, $this->monthOffset - 1);
    }

    public function next(): void
    {
        $this->monthOffset++;
    }

    public function toggle(string $date): void
    {
        $this->select($date, $date, ! in_array($date, $this->selected, true));
    }

    /**
     * Turns a range of nights on or off, whichever direction it was dragged in.
     * Past and booked nights are skipped by the range.
     */
    public function select(string $of, string $until, bool $on = true): void
    {
        if (! CarbonImmutable::canBeCreatedFromFormat($of, 'Y-m-d') || ! CarbonImmutable::canBeCreatedFromFormat($until, 'Y-m-d')) {
            return;
        }

        $limits = [CarbonImmutable::parse($of), CarbonImmutable::parse($until)];
        $nights = $this->selectableNights(min($limits), max($limits));

        $this->selected = $on
            ? array_values(array_unique([...$this->selected, ...$nights]))
            : array_values(array_diff($this->selected, $nights));
    }

    /**
     * @return array<int, string>
     */
    private function selectableNights(CarbonImmutable $of, CarbonImmutable $until): array
    {
        $start = max($of, CarbonImmutable::today());
        $end = min($until, $start->addDays(self::MAX_NIGHTS_PER_RANGE - 1));

        if ($start->greaterThan($end)) {
            return [];
        }

        $booked = $this->bookingNights($start, $end);
        $nights = [];

        foreach (\Carbon\CarbonPeriod::create($start, $end) as $night) {
            if (! isset($booked[$night->toDateString()])) {
                $nights[] = $night->toDateString();
            }
        }

        return $nights;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function save(): void
    {
        $cents = $this->centsFromInput();

        if ($cents === null) {
            Notification::make()->title(__('Enter a valid price.'))->danger()->send();

            return;
        }

        if ($this->selected === []) {
            Notification::make()->title(__('Select one or more nights first.'))->warning()->send();

            return;
        }

        foreach ($this->selected as $date) {
            NightlyRate::updateOrCreate(
                ['property_id' => $this->property->id, 'date' => $date],
                ['price_cents' => $cents, 'discount_percent' => null, 'source' => NightlyRate::SOURCE_MANUAL],
            );
        }

        $count = count($this->selected);
        $this->selected = [];
        $this->priceInput = null;

        Notification::make()
            ->title(trans_choice('{1} 1 night updated.|[2,*] :count nights updated.', $count))
            ->success()
            ->send();
    }

    public function restore(): void
    {
        if ($this->selected === []) {
            Notification::make()->title(__('Select one or more nights first.'))->warning()->send();

            return;
        }

        $count = NightlyRate::query()
            ->where('property_id', $this->property->id)
            ->whereIn('date', $this->selected)
            ->delete();

        $this->selected = [];

        Notification::make()
            ->title(trans_choice('{0} These nights were already at the standard price.|{1} 1 night reset to the standard price.|[2,*] :count nights reset to the standard price.', $count))
            ->success()
            ->send();
    }

    public function discount(): void
    {
        $percent = str_replace(',', '.', trim((string) $this->discountInput));

        if ($percent === '' || ! is_numeric($percent) || (float) $percent <= 0 || (float) $percent >= 100) {
            Notification::make()->title(__('Enter a discount percentage between 1 and 99.'))->danger()->send();

            return;
        }

        if ($this->selected === []) {
            Notification::make()->title(__('Select one or more nights first.'))->warning()->send();

            return;
        }

        $prices = app(PricingEngine::class)->nightlyPriceMap(
            $this->property,
            CarbonImmutable::parse(min($this->selected)),
            CarbonImmutable::parse(max($this->selected)),
        );

        $percentInt = (int) round((float) $percent);
        $factor = (100 - (float) $percent) / 100;

        foreach ($this->selected as $date) {
            $base = $prices[$date] ?? null;
            if ($base === null) {
                continue;
            }

            NightlyRate::updateOrCreate(
                ['property_id' => $this->property->id, 'date' => $date],
                ['price_cents' => (int) round($base * $factor), 'discount_percent' => $percentInt, 'source' => NightlyRate::SOURCE_MANUAL],
            );
        }

        $count = count($this->selected);
        $this->selected = [];
        $this->discountInput = null;

        Notification::make()
            ->title(trans_choice('{1} Discount applied to 1 night.|[2,*] Discount applied to :count nights.', $count))
            ->success()
            ->send();
    }

    public function block(): void
    {
        if ($this->selected === []) {
            Notification::make()->title(__('Select one or more nights first.'))->warning()->send();

            return;
        }

        $calendar = app(CalendarEngine::class);
        $existing = $this->manualBlockDates();
        $new = 0;

        foreach ($this->selected as $date) {
            if (isset($existing[$date])) {
                continue;
            }

            $calendar->block($this->property, CarbonImmutable::parse($date), CarbonImmutable::parse($date));
            $new++;
        }

        $this->selected = [];

        Notification::make()
            ->title(trans_choice('{0} These nights were already blocked.|{1} 1 night blocked.|[2,*] :count nights blocked.', $new))
            ->success()
            ->send();
    }

    public function unblock(): void
    {
        if ($this->selected === []) {
            Notification::make()->title(__('Select one or more nights first.'))->warning()->send();

            return;
        }

        $count = $this->property->calendarBlocks()
            ->whereNull('source')
            ->where(fn ($q) => collect($this->selected)->each(fn ($d) => $q->orWhere(fn ($qq) => $qq
                ->whereDate('start_date', '<=', $d)
                ->whereDate('end_date', '>=', $d))))
            ->delete();

        $this->selected = [];

        Notification::make()
            ->title(trans_choice('{0} No manual blocks on these nights.|{1} 1 block removed.|[2,*] :count blocks removed.', $count))
            ->success()
            ->send();
    }

    /**
     * @return array<string, true>
     */
    private function manualBlockDates(): array
    {
        $dates = [];

        foreach ($this->property->calendarBlocks()->whereNull('source')->get() as $block) {
            foreach (\Carbon\CarbonPeriod::create($block->start_date, $block->end_date) as $night) {
                $dates[$night->toDateString()] = true;
            }
        }

        return $dates;
    }

    private function centsFromInput(): ?int
    {
        $value = str_replace(',', '.', trim((string) $this->priceInput));

        if ($value === '' || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (int) round((float) $value * 100);
    }

    /**
     * @return array<int, array{label: string, days: array<int, ?array<string, mixed>>}>
     */
    public function monthData(): array
    {
        $engine = app(PricingEngine::class);
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->addMonths($this->monthOffset);

        $seasons = $this->property->rateSeasons()->get();
        if ($seasons->isEmpty() && ! $this->property->is_master) {
            $seasons = Property::master()->rateSeasons()->get();
        }

        $months = [];

        foreach ([0, 1] as $i) {
            $monthStart = $start->addMonths($i);
            $monthEnd = $monthStart->endOfMonth();

            $prices = $engine->nightlyPriceMap($this->property, $monthStart, $monthEnd);
            $booked = $this->bookingNights($monthStart, $monthEnd);
            $blocked = $this->blockNights($monthStart, $monthEnd);
            $overrides = $this->property->nightlyRates()
                ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->get()
                ->keyBy(fn (NightlyRate $r) => $r->date->toDateString());

            $days = [];

            for ($b = 1; $b < $monthStart->dayOfWeekIso; $b++) {
                $days[] = null;
            }

            for ($day = 1; $day <= $monthStart->daysInMonth; $day++) {
                $date = $monthStart->setDay($day);
                $key = $date->toDateString();
                $override = $overrides->get($key);

                $origin = match (true) {
                    $override !== null => 'override',
                    $seasons->contains(fn ($s) => $date->betweenIncluded($s->start_date, $s->end_date)) => 'season',
                    in_array($date->dayOfWeekIso, [5, 6], true) => 'weekend',
                    default => 'base',
                };

                $days[] = [
                    'day' => $day,
                    'date' => $key,
                    'price' => $prices[$key] ?? null,
                    'past' => $date->lessThan($today),
                    'occupied' => isset($booked[$key]),
                    'blocked' => isset($blocked[$key]),
                    'override' => $override !== null,
                    'source' => $override?->source,
                    'discount' => $override?->discount_percent,
                    'origin' => $origin,
                ];
            }

            $months[] = [
                'label' => ucfirst($monthStart->translatedFormat('F Y')),
                'days' => $days,
            ];
        }

        return $months;
    }

    /**
     * @return array<string, true>
     */
    private function bookingNights(CarbonImmutable $of, CarbonImmutable $until): array
    {
        $nights = [];

        $bookings = $this->property->bookings()
            ->whereNotIn('status', \App\Models\Booking::INACTIVE_STATUSES)
            ->whereDate('arrival_date', '<=', $until)
            ->whereDate('departure_date', '>', $of)
            ->get();

        foreach ($bookings as $booking) {
            $startNight = $booking->arrival_date->greaterThan($of) ? $booking->arrival_date : $of;
            $last = $booking->departure_date->subDay();
            $end = $last->lessThan($until) ? $last : $until;

            foreach (\Carbon\CarbonPeriod::create($startNight, $end) as $night) {
                $nights[$night->toDateString()] = true;
            }
        }

        return $nights;
    }

    /**
     * @return array<string, true>
     */
    private function blockNights(CarbonImmutable $of, CarbonImmutable $until): array
    {
        $nights = [];

        $blocks = $this->property->calendarBlocks()
            ->whereDate('start_date', '<=', $until)
            ->whereDate('end_date', '>=', $of)
            ->get();

        foreach ($blocks as $block) {
            $startNight = $block->start_date->greaterThan($of) ? $block->start_date : $of;
            $end = $block->end_date->lessThan($until) ? $block->end_date : $until;

            foreach (\Carbon\CarbonPeriod::create($startNight, $end) as $night) {
                $nights[$night->toDateString()] = true;
            }
        }

        return $nights;
    }

    /**
     * @return array<int, string>
     */
    public function weekdays(): array
    {
        $monday = CarbonImmutable::create(2026, 6, 1);
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[] = ucfirst(mb_substr($monday->addDays($i)->translatedFormat('D'), 0, 2));
        }

        return $days;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.nightly-prices-calendar');
    }
}
