<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Filament\Auth\Profile;
use App\Filament\Pages\MediaManager;
use App\Filament\Pages\Statistics;
use App\Filament\Resources\Amenities\AmenityResource;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\CalendarBlocks\CalendarBlockResource;
use App\Filament\Resources\ChannelConnections\ChannelConnectionResource;
use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Hosts\HostResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\PropertyTypes\PropertyTypeResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Models\Booking;
use App\Models\CalendarBlock;
use App\Models\ChannelConnection;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may do what in the admin. One place for three questions:
 *  1. may()   – may this user perform this action on this screen?
 *  2. restrict() – which data does he see? A host only that of their own
 *     accommodations; owner and staff see everything.
 *  3. checkOwnership() – final check when saving and deleting,
 *     so even a forged request cannot touch another host's data.
 *
 * The owner (role Administrator) may always do everything; Settings, the
 * style editor and Users are for them alone and are not listed here.
 */
final class Access
{
    public const VIEW = 'view';

    public const CREATE = 'create';

    public const EDIT = 'edit';

    public const DELETE = 'delete';

    public const ACTIONS = [self::VIEW, self::CREATE, self::EDIT, self::DELETE];

    /**
     * Every screen the owner can switch on or off per user, with the
     * actions that exist. 'class' supplies the name in the menu; 'platform'
     * means the screen shows data of the whole platform, also for
     * a host (nothing is attached to an accommodation); 'onlyHost' means the
     * permission only has meaning for a host.
     *
     * @var array<string, array{class: class-string, actions: array<int, string>, platform?: bool, onlyHost?: bool, label?: string}>
     */
    public const SCREENS = [
        'host_profile' => ['class' => Profile::class, 'actions' => [self::EDIT], 'onlyHost' => true, 'label' => 'Own host profile (name, photo and text)'],
        'bookings' => ['class' => BookingResource::class, 'actions' => self::ACTIONS],
        'payments' => ['class' => PaymentResource::class, 'actions' => [self::VIEW, self::EDIT]],
        'accommodations' => ['class' => PropertyResource::class, 'actions' => self::ACTIONS],
        'payment_rules' => ['class' => PropertyResource::class, 'actions' => [self::EDIT], 'label' => 'Payment, pricing and email rules of accommodations'],
        'calendar' => ['class' => CalendarBlockResource::class, 'actions' => self::ACTIONS],
        'channel_connections' => ['class' => ChannelConnectionResource::class, 'actions' => self::ACTIONS],
        'reviews' => ['class' => ReviewResource::class, 'actions' => [self::VIEW, self::EDIT, self::DELETE]],
        'discount_codes' => ['class' => CouponResource::class, 'actions' => self::ACTIONS],
        'defaults' => ['class' => EditDefaults::class, 'actions' => [self::EDIT], 'platform' => true, 'label' => 'Default accommodation settings'],
        'amenities' => ['class' => AmenityResource::class, 'actions' => self::ACTIONS, 'platform' => true],
        'accommodation_types' => ['class' => PropertyTypeResource::class, 'actions' => self::ACTIONS, 'platform' => true],
        'hosts' => ['class' => HostResource::class, 'actions' => self::ACTIONS, 'platform' => true],
        'pages' => ['class' => PageResource::class, 'actions' => self::ACTIONS, 'platform' => true],
        'media' => ['class' => MediaManager::class, 'actions' => [self::EDIT], 'platform' => true],
        'statistics' => ['class' => Statistics::class, 'actions' => [self::VIEW], 'platform' => true],
    ];

    /** Screens that were owner-only before the permission system. */
    private const BEFORE_ONLY_OWNER = ['media'];

    /** Models attached to an accommodation, with the path to that accommodation. */
    private const VIA_ACCOMMODATION = [
        Booking::class => 'property',
        CalendarBlock::class => 'property',
        ChannelConnection::class => 'property',
        Review::class => 'property',
        Coupon::class => 'property',
        Payment::class => 'booking.property',
    ];

    public static function may(?User $user, string $screen, string $action): bool
    {
        if ($user === null || ! in_array($user->role, UserRole::admin(), true)) {
            return false;
        }

        if ($user->isAdministrator()) {
            return true;
        }

        if (! in_array($action, self::SCREENS[$screen]['actions'] ?? [], true)) {
            return false;
        }

        // A staff member without configured permissions works as before the
        // permission system: everything except what was then owner-only.
        if ($user->permissions === null) {
            return $user->role === UserRole::Staff && ! in_array($screen, self::BEFORE_ONLY_OWNER, true);
        }

        return in_array($action, $user->permissions[$screen] ?? [], true);
    }

    /** A host with the permission to edit their own profile (name, photo, text). */
    public static function mayOwnHostProfile(?User $user): bool
    {
        return self::isHost($user) && $user->host_id !== null && self::may($user, 'host_profile', self::EDIT);
    }

    /**
     * The media files (upload, edit, AI images), both in
     * Media manager and in the media picker on Pages.
     */
    public static function mayMediaFiles(?User $user): bool
    {
        return self::may($user, 'media', self::EDIT);
    }

    /** Name of a screen as shown in the menu. */
    public static function label(string $screen): string
    {
        $setting = self::SCREENS[$screen];

        return isset($setting['label']) ? __($setting['label']) : $setting['class']::getNavigationLabel();
    }

    /** @return array<string, string> action => name */
    public static function actionLabels(string $screen): array
    {
        $names = [
            self::VIEW => __('View'),
            self::CREATE => __('Create'),
            self::EDIT => __('Edit'),
            self::DELETE => __('Remove'),
        ];

        return array_intersect_key($names, array_flip(self::SCREENS[$screen]['actions']));
    }

    /**
     * Keep only existing screens and actions, in a fixed order: whatever comes
     * out of a form is never saved blindly.
     *
     * @param  array<string, mixed>|null  $permissions
     * @return array<string, array<int, string>>
     */
    public static function clean(?array $permissions): array
    {
        $clean = [];

        foreach (self::SCREENS as $screen => $setting) {
            $chosen = (array) ($permissions[$screen] ?? []);
            $clean[$screen] = array_values(array_filter($setting['actions'], fn (string $action) => in_array($action, $chosen, true)));
        }

        return $clean;
    }

    /** @return array<string, array<int, string>> all screens with all actions */
    public static function everything(): array
    {
        return array_map(fn (array $setting) => $setting['actions'], self::SCREENS);
    }

    /**
     * What a staff member without configured permissions may do, as checkmarks: this
     * shows the owner in the form exactly what currently applies.
     *
     * @return array<string, array<int, string>>
     */
    public static function defaultForStaff(): array
    {
        $permissions = self::everything();

        foreach (self::BEFORE_ONLY_OWNER as $screen) {
            $permissions[$screen] = [];
        }

        return $permissions;
    }

    /** The screen a Filament resource or page belongs to. */
    public static function screenOf(string $class): ?string
    {
        foreach (self::SCREENS as $screen => $setting) {
            if ($setting['class'] === $class) {
                return $screen;
            }
        }

        return null;
    }

    public static function isHost(?User $user): bool
    {
        return $user?->role === UserRole::Host;
    }

    /**
     * Restricts a query to what the user may see. For a host: only
     * their own accommodations and what is attached to them; without a linked
     * host profile they see nothing. Other users see everything.
     */
    public static function restrict(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! self::isHost($user)) {
            return $query;
        }

        $model = $query->getModel();
        $path = self::VIA_ACCOMMODATION[$model::class] ?? null;

        if (! $model instanceof Property && $path === null) {
            return $query; // platform-wide screen
        }

        if ($user->host_id === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($model instanceof Property) {
            return $query->ofHost($user->host_id)
                ->where($model->qualifyColumn('is_master'), false);
        }

        return $query->whereHas($path, fn (Builder $accommodation) => $accommodation
            ->ofHost($user->host_id)
            ->where('is_master', false));
    }

    /**
     * For select lists and filters (Filament only passes the query):
     * only the own accommodations and what is attached to them.
     */
    public static function onlyOwn(Builder $query): Builder
    {
        return self::restrict($query);
    }

    /** Does this record belong to what the user may see? */
    public static function isOwn(Model $record, ?User $user = null): bool
    {
        $user ??= auth()->user();

        return ! self::isHost($user)
            || ! $record->exists
            || self::restrict($record->newQuery(), $user)->whereKey($record->getKey())->exists();
    }

    /**
     * When saving and deleting: a host may only change something on their own
     * accommodations, and may not move anything of it to someone else.
     *
     * @throws AuthorizationException
     */
    public static function checkOwnership(Model $record): void
    {
        $user = auth()->user();

        if (! self::isHost($user)) {
            return;
        }

        if ($record instanceof Property) {
            $allowed = $user->host_id !== null && ! $record->is_master
                && (! $record->exists || $record->hosts()->whereKey($user->host_id)->exists());
        } else {
            $allowed = self::isOwnAccommodation($record, $user, false)
                && (! $record->exists || self::isOwnAccommodation($record, $user, true));
        }

        if (! $allowed) {
            throw new AuthorizationException(__('This does not belong to your accommodations.'));
        }
    }

    /** A host's new accommodation automatically gets their profile. */
    public static function linkToHost(Property $accommodation): void
    {
        $user = auth()->user();

        if (self::isHost($user)) {
            $accommodation->hosts()->syncWithoutDetaching([$user->host_id]);
        }
    }

    /** Is the record (now, or before the change) attached to an accommodation of this host? */
    private static function isOwnAccommodation(Model $record, User $user, bool $original): bool
    {
        if ($user->host_id === null) {
            return false;
        }

        $propertyId = match (true) {
            $record instanceof Payment => Booking::query()
                ->whereKey($original ? $record->getOriginal('booking_id') : $record->booking_id)
                ->value('property_id'),
            array_key_exists($record::class, self::VIA_ACCOMMODATION) => $original ? $record->getOriginal('property_id') : $record->property_id,
            default => null,
        };

        if (! array_key_exists($record::class, self::VIA_ACCOMMODATION)) {
            return true; // platform-wide: may() handles that
        }

        return $propertyId !== null && Property::query()
            ->whereKey($propertyId)
            ->ofHost($user->host_id)
            ->where('is_master', false)
            ->exists();
    }
}
