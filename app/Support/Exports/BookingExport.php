<?php

namespace App\Support\Exports;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel files for the accountant and the city, for the bookings that arrive
 * in a period:
 *
 *  - bookings: one row per booking with the guest, the price lines, the
 *    invoice and (with the right to see payments) what was paid;
 *  - tourist tax: per accommodation and month the stays, nights, guest
 *    nights and tourist tax of the confirmed bookings.
 *
 * The query that comes in is already narrowed to what the user may see
 * (a host: only their own accommodations).
 *
 * Text always goes in as text: the library turns a value that starts with
 * "=" into a formula, and a guest could type one as their name.
 */
final class BookingExport
{
    public const BOOKINGS = 'bookings';

    public const TOURIST_TAX = 'tourist_tax';

    private const CHUNK = 500;

    /** Price lines by key; a key not listed counts as "Other". */
    private const LINE_COLUMNS = [
        'accommodation' => 'Rent',
        'extra_guests' => 'Extra guests',
        'discount' => 'Discounts',
        'coupon' => 'Discounts',
        'cleaning' => 'Cleaning fee',
        'pet_fee' => 'Pet fee',
        'tourist_tax' => 'Tourist tax',
    ];

    private const OTHER = 'Other';

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private Style $date;

    private Style $amount;

    public function __construct(
        private readonly Builder $bookings,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $until,
    ) {
        $this->date = (new Style)->setFormat('yyyy-mm-dd');
        $this->amount = (new Style)->setFormat('#,##0.00');
    }

    public function download(string $kind, bool $withCancelled = false, bool $withPayments = false): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'export');
        $writer = new Writer;
        $writer->openToFile($path);

        $kind === self::TOURIST_TAX
            ? $this->writeTouristTax($writer)
            : $this->writeBookings($writer, $withCancelled, $withPayments);

        $writer->close();

        activity()->event('bookings_exported')
            ->withProperties(['export' => $kind, 'from' => $this->from->toDateString(), 'until' => $this->until->toDateString()])
            ->log('bookings_exported');

        $name = ($kind === self::TOURIST_TAX ? 'tourist-tax' : 'bookings').'-'.$this->from->toDateString().'-'.$this->until->toDateString().'.xlsx';

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $name, ['Content-Type' => self::XLSX]);
    }

    private function writeBookings(Writer $writer, bool $withCancelled, bool $withPayments): void
    {
        $lineColumns = array_values(array_unique([...array_values(self::LINE_COLUMNS), self::OTHER]));

        $this->header($writer, [
            'Booking number', 'Status', 'Accommodation', 'Arrival', 'Departure', 'Nights', 'Guests',
            'Adults', 'Children', 'Babies', 'Pets', 'Guest', 'Email', 'Phone', 'Country', 'Booked on',
            'Coupon code', 'Currency', ...$lineColumns, 'Total', 'Invoice number', 'VAT %', 'VAT',
            ...($withPayments ? ['Paid', 'Refunded', 'Outstanding', 'Deposit'] : []),
        ]);

        $query = $this->inPeriod()->with('property:id,name');

        if (! $withCancelled) {
            $query->whereNot('status', BookingStatus::Cancelled->value);
        }

        $query->chunk(self::CHUNK, function (Collection $bookings) use ($writer, $withPayments, $lineColumns): void {
            $ids = $bookings->modelKeys();
            $invoices = Invoice::query()->whereIn('booking_id', $ids)->get()->keyBy('booking_id');
            $payments = $withPayments ? Booking::stayPayments($ids) : [];

            foreach ($bookings as $booking) {
                $lines = $this->lineTotals($booking, $lineColumns);
                $invoice = $invoices[$booking->id] ?? null;

                $cells = [
                    $this->text($booking->booking_number),
                    $this->text($booking->status->getLabel()),
                    $this->text($booking->property?->name),
                    $this->day($booking->arrival_date),
                    $this->day($booking->departure_date),
                    $this->number($booking->nights()),
                    $this->number($booking->guests),
                    $this->number($booking->adults),
                    $this->number($booking->children),
                    $this->number($booking->infants),
                    $this->number($booking->pets),
                    $this->text($booking->guest_name),
                    $this->text($booking->guest_email),
                    $this->text($booking->guest_phone),
                    $this->text($booking->guest_country),
                    $this->day($booking->created_at),
                    $this->text($booking->coupon_code),
                    $this->text($booking->currency),
                    ...array_map(fn (int $cents): Cell => $this->money($cents), $lines),
                    $this->money($booking->total_cents),
                    $this->text($invoice?->invoice_number),
                    $this->number($invoice?->vat_percentage),
                    $invoice === null ? new EmptyCell(null, null) : $this->money($invoice->vat_cents),
                ];

                if ($withPayments) {
                    ['paid' => $paid, 'refunded' => $refunded] = $payments[$booking->id];
                    $cells[] = $this->money($paid);
                    $cells[] = $this->money($refunded);
                    $cells[] = $this->money(max(0, $booking->total_cents - ($paid - $refunded)));
                    $cells[] = $this->money($booking->deposit_cents);
                }

                $writer->addRow(new Row($cells));
            }
        });
    }

    private function writeTouristTax(Writer $writer): void
    {
        $this->header($writer, [
            'Accommodation', 'City', 'Registration number', 'Month', 'Stays', 'Nights', 'Guests', 'Guest nights', 'Tourist tax', 'Currency',
        ]);

        $confirmed = array_map(
            fn (BookingStatus $status): string => $status->value,
            array_filter(BookingStatus::cases(), fn (BookingStatus $status): bool => $status->isConfirmed()),
        );

        $totals = [];

        $this->inPeriod()
            ->with('property:id,name,city,registration_number')
            ->whereIn('status', $confirmed)
            ->chunk(self::CHUNK, function (Collection $bookings) use (&$totals): void {
                foreach ($bookings as $booking) {
                    $key = implode('|', [$booking->property?->name, $booking->property_id, $booking->arrival_date->format('Y-m'), $booking->currency]);
                    $nights = $booking->nights();

                    $totals[$key] ??= [
                        'property' => $booking->property,
                        'month' => $booking->arrival_date->format('Y-m'),
                        'currency' => $booking->currency,
                        'stays' => 0, 'nights' => 0, 'guests' => 0, 'guest_nights' => 0, 'tax' => 0,
                    ];

                    $totals[$key]['stays']++;
                    $totals[$key]['nights'] += $nights;
                    $totals[$key]['guests'] += $booking->guests;
                    $totals[$key]['guest_nights'] += $booking->guests * $nights;
                    $totals[$key]['tax'] += $this->lineAmount($booking, 'tourist_tax');
                }
            });

        ksort($totals, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($totals as $row) {
            $writer->addRow(new Row([
                $this->text($row['property']?->name),
                $this->text($row['property']?->city),
                $this->text($row['property']?->registration_number),
                $this->text($row['month']),
                $this->number($row['stays']),
                $this->number($row['nights']),
                $this->number($row['guests']),
                $this->number($row['guest_nights']),
                $this->money($row['tax']),
                $this->text($row['currency']),
            ]));
        }
    }

    /** Bookings that arrive in the period, in order of arrival. */
    private function inPeriod(): Builder
    {
        return (clone $this->bookings)
            ->whereDate('arrival_date', '>=', $this->from)
            ->whereDate('arrival_date', '<=', $this->until)
            ->orderBy('arrival_date')
            ->orderBy('id');
    }

    /**
     * The price lines of the booking added up per column.
     *
     * @param  array<int, string>  $columns
     * @return array<int, int>
     */
    private function lineTotals(Booking $booking, array $columns): array
    {
        $totals = array_fill_keys($columns, 0);

        foreach ($booking->price_snapshot['lines'] ?? [] as $line) {
            $totals[self::LINE_COLUMNS[$line['key'] ?? ''] ?? self::OTHER] += (int) ($line['amount_cents'] ?? 0);
        }

        return array_values($totals);
    }

    private function lineAmount(Booking $booking, string $key): int
    {
        return (int) collect($booking->price_snapshot['lines'] ?? [])
            ->where('key', $key)
            ->sum('amount_cents');
    }

    /** @param array<int, string> $titles */
    private function header(Writer $writer, array $titles): void
    {
        $writer->addRow(new Row(
            array_map(fn (string $title): Cell => new StringCell(__($title), null), $titles),
            (new Style)->setFontBold(),
        ));
    }

    private function text(?string $value): Cell
    {
        return blank($value) ? new EmptyCell(null, null) : new StringCell($value, null);
    }

    /** A count or percentage; the database may hand it over as a numeric string. */
    private function number(int|float|string|null $value): Cell
    {
        return is_numeric($value) ? new NumericCell($value + 0, null) : new EmptyCell(null, null);
    }

    private function money(int|string|null $cents): Cell
    {
        return new NumericCell(((int) $cents) / 100, $this->amount);
    }

    private function day(?DateTimeInterface $date): Cell
    {
        return $date === null ? new EmptyCell(null, null) : new DateTimeCell($date, $this->date);
    }
}
