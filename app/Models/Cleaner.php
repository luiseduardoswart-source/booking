<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\URL;

/**
 * Someone who cleans one or more accommodations: gets the changeovers by
 * email (every day or every Monday) and on a private page, without an
 * account (see App\Support\CleaningSchedule).
 */
#[Fillable(['name', 'email', 'phone', 'locale', 'frequency', 'active'])]
class Cleaner extends Model
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    /** How far ahead the email looks. */
    public const DAYS_AHEAD = [self::DAILY => 7, self::WEEKLY => 14];

    /** How far ahead the private page looks. */
    public const PAGE_DAYS = 30;

    /** The same as the table's defaults, so a new cleaner has them before it is read back. */
    protected $attributes = [
        'frequency' => self::DAILY,
        'active' => true,
        'link_version' => 1,
    ];

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class);
    }

    /**
     * The private page with the changeovers: signed, without an end date,
     * and only valid with the current link version (see newLink).
     */
    public function scheduleUrl(): string
    {
        return URL::signedRoute('cleaning.schedule', ['cleaner' => $this->id, 'version' => $this->link_version]);
    }

    /** Give a new link: the old one stops working at once. */
    public function newLink(): void
    {
        $this->forceFill(['link_version' => $this->link_version + 1])->save();
    }

    /** Is a mail due today: every day, or on Monday for weekly. */
    public function mailDueToday(): bool
    {
        return $this->frequency === self::DAILY || today()->isMonday();
    }

    public function daysAhead(): int
    {
        return self::DAYS_AHEAD[$this->frequency] ?? self::DAYS_AHEAD[self::DAILY];
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'link_version' => 'integer',
        ];
    }
}
