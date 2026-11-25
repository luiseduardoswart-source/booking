<?php

namespace App\Filament\Resources\ActivityLog;

use App\Filament\Resources\ActivityLog\Pages\ListActivities;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * The log (Logboek): who changed what and when. Bookings, payments,
 * accommodations, reviews and calendar blocks; accounts and their rights;
 * which setting someone changed (never its value); sign-ins and failed
 * sign-ins. Read only, owner only: nobody can change or wipe their tracks
 * here. Entries older than a year are removed (activitylog:clean).
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'log';

    /** What happened, for the filter and the column. */
    private const EVENTS = [
        'created' => 'Created',
        'updated' => 'Changed',
        'deleted' => 'Deleted',
        'login' => 'Signed in',
        'login_failed' => 'Sign-in failed',
        'password_changed' => 'Password changed',
        'two_factor_on' => 'Two-factor authentication turned on',
        'two_factor_off' => 'Two-factor authentication turned off',
        'setting_changed' => 'Setting changed',
        'guest_data_erased' => 'Guest data erased',
        'backup_made' => 'Backup made',
        'backup_downloaded' => 'Backup downloaded',
        'backup_deleted' => 'Backup deleted',
        'bookings_exported' => 'Bookings exported',
    ];

    /** The kind of record, by model class. */
    private const SUBJECTS = [
        \App\Models\Booking::class => 'Booking',
        \App\Models\Payment::class => 'Payment',
        \App\Models\Property::class => 'Accommodation',
        \App\Models\Review::class => 'Review',
        \App\Models\CalendarBlock::class => 'Calendar block',
        \App\Models\User::class => 'User',
    ];

    public static function getModelLabel(): string
    {
        return __('log entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('log entries');
    }

    public static function getNavigationLabel(): string
    {
        return __('Log');
    }

    /** Owner only, and only to look: no creating, changing or deleting. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $actionName = $action instanceof UnitEnum ? $action->name : $action;

        return (auth()->user()?->isAdministrator() ?? false) && in_array($actionName, ['viewAny', 'view'], true)
            ? Response::allow()
            : Response::deny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('causer'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime('j M Y H:i'),
                TextColumn::make('causer')
                    ->label(__('Who'))
                    ->state(fn (Activity $activity): string => self::who($activity)),
                TextColumn::make('event')
                    ->label(__('What'))
                    ->state(fn (Activity $activity): string => self::what($activity))
                    ->badge()
                    ->color(fn (Activity $activity): string => match ($activity->event) {
                        'login_failed', 'deleted', 'two_factor_off', 'guest_data_erased', 'backup_deleted' => 'danger',
                        'setting_changed', 'password_changed', 'backup_downloaded', 'bookings_exported' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('subject_type')
                    ->label(__('About'))
                    ->state(fn (Activity $activity): string => self::about($activity)),
                TextColumn::make('properties')
                    ->label(__('Details'))
                    ->state(fn (Activity $activity): string => self::details($activity))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('What'))
                    ->options(collect(self::EVENTS)->map(fn (string $label) => __($label))->all()),
                SelectFilter::make('subject_type')
                    ->label(__('About'))
                    ->options(collect(self::SUBJECTS)->map(fn (string $label) => __($label))->all()),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }

    private static function who(Activity $activity): string
    {
        $causer = $activity->causer;

        if ($causer !== null) {
            return (string) ($causer->name ?? $causer->email ?? '?');
        }

        // A failed sign-in has no account: the address that was tried.
        return $activity->event === 'login_failed'
            ? (string) ($activity->properties['email'] ?? '?')
            : __('The website');
    }

    private static function what(Activity $activity): string
    {
        return __(self::EVENTS[$activity->event] ?? $activity->description);
    }

    private static function about(Activity $activity): string
    {
        if ($activity->event === 'setting_changed') {
            return __('Settings');
        }

        $kind = self::SUBJECTS[$activity->subject_type] ?? class_basename((string) $activity->subject_type);

        return $activity->subject_type === null ? '' : __($kind).' #'.$activity->subject_id;
    }

    /** Which fields changed, which setting, from which address: no values. */
    private static function details(Activity $activity): string
    {
        $properties = $activity->properties;

        return collect([
            $properties['setting'] ?? $properties['backup'] ?? null,
            isset($properties['from'], $properties['until']) ? $properties['from'].' – '.$properties['until'] : null,
            ($fields = array_keys((array) ($activity->attribute_changes['attributes'] ?? []))) !== [] ? implode(', ', $fields) : null,
            isset($properties['ip']) ? __('from :ip', ['ip' => $properties['ip']]) : null,
        ])->filter()->implode(' · ');
    }
}
