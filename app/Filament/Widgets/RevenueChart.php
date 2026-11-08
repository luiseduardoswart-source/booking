<?php

namespace App\Filament\Widgets;

use App\Support\Access;
use App\Models\Payment;
use App\Support\Money;
use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Revenue per month (received payments)');
    }

    protected function getData(): array
    {
        $labels = [];
        $data = [];

        foreach (range(11, 0) as $monthsAgo) {
            $month = now()->subMonths($monthsAgo);
            $labels[] = $month->translatedFormat('M Y');

            // Summed per currency and converted to the default currency.
            $data[] = Money::inDefaultCurrency(Payment::revenuePerCurrency(
                Access::restrict(Payment::query())
                    ->whereBetween('payments.paid_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            )) / 100;
        }

        return [
            'datasets' => [
                [
                    'label' => __('Revenue (:currency)', ['currency' => Money::defaultCurrency()]),
                    'data' => $data,
                    'backgroundColor' => 'rgba(225, 29, 72, .6)',
                    'borderColor' => 'rgb(225, 29, 72)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
