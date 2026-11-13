<?php

namespace App\Models;

use App\Enums\CancellationPolicy;
use App\Enums\MailType;
use App\Models\Concerns\GuardedOwnership;
use App\Models\Concerns\HasTranslatedSlug;
use App\Models\Concerns\HasTranslations;
use App\Services\PricingServices;
use App\Support\Money;
use App\Support\RichText;
use App\Support\Access;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'is_master', 'name', 'slug', 'type', 'active',
    'short_description', 'description', 'house_rules', 'highlights',
    'slug_translations', 'name_translations', 'short_description_translations', 'description_translations',
    'meta_title_translations', 'meta_description_translations',
    'cancellation_policy', 'cancellation_policy_text', 'rental_terms', 'max_stay_nights',
    'rental_terms_translations', 'house_rules_translations', 'cancellation_policy_text_translations',
    'rental_terms_min_nights',
    'min_stay_nights', 'booking_window_months',
    'id_required', 'prices_in_calendar', 'discounts_add_up', 'pets_allowed', 'max_pets', 'max_babies',
    'down_payment_percentage', 'vat_percentage', 'pricing_service',
    'dynamic_discount_kind', 'dynamic_discount_percentage', 'dynamic_discount_cents', 'manual_prices_win',
    'deposit_days_before_arrival', 'deposit_days_after_departure', 'deposit_auto_refund', 'deposit_moment',
    'balance_payment_days_before_arrival', 'checkin_days_before_arrival', 'checkout_days_before_departure', 'review_days_after_departure', 'mail_texts', 'cancellation_texts',
    'address', 'postal_code', 'city', 'country', 'latitude', 'longitude', 'location_display', 'overview_location_display',
    'max_guests', 'bedrooms', 'beds', 'sofa_beds', 'bathrooms',
    'base_price_cents', 'currency', 'weekend_price_cents', 'weekend_kind', 'weekend_percentage', 'cleaning_fee_cents',
    'tourist_tax_cents', 'extra_guest_fee_cents', 'pet_fee_cents', 'included_guests', 'deposit_cents',
    'check_in_from', 'check_in_until', 'check_out_until', 'check_in_kind', 'check_in_host_id',
    'access_code_kind', 'access_code', 'check_in_fields', 'check_in_values',
    'meta_title', 'meta_description', 'social_links', 'ical_token', 'external_listing_id',
])]
class Property extends Model implements HasMedia
{
    use GuardedOwnership, HasTranslatedSlug, HasTranslations, InteractsWithMedia, LogsActivity, SoftDeletes;

    public const MAX_PHOTOS = 100;

    public const MAX_PHOTO_KB = 25 * 1024;

    /** Videos between the photos: only formats that every browser plays (no .mov). */
    public const MAX_VIDEO_KB = 100 * 1024;

    public const VIDEO_EXTENSIONS = ['mp4', 'webm'];

    public const VIDEO_MIMES = ['video/mp4', 'video/webm'];

    /** Weekend adjustment: a fixed weekend price per night, or a percentage on the nightly price. */
    public const WEEKEND_FIXED = 'fixed';

    public const WEEKEND_PERCENT = 'percent';

    /** Discount on the dynamic price: none, a percentage or a fixed amount per night. */
    public const DISCOUNT_NONE = 'none';

    public const DISCOUNT_PERCENT = 'percent';

    public const DISCOUNT_FIXED = 'fixed';

    /** How the site shows the location: a circle around the area, or a pin on the exact spot. */
    public const LOCATION_NONE = 'none';

    public const LOCATION_APPROXIMATE = 'approximate';

    public const LOCATION_EXACT = 'exact';

    /** The two maps on the site: the accommodation's own page and the overview page. */
    public const MAP_PAGE = 'page';

    public const MAP_OVERVIEW = 'overview';

    /** Radius of the circle on the map, and how far its centre may lie from the real spot (metres). */
    public const LOCATION_RADIUS = 300;

    public const LOCATION_SHIFT = 150;

    /** When the guest pays the deposit: right after booking, or days before arrival. */
    public const DEPOSIT_AT_BOOKING = 'booking';

    public const DEPOSIT_BEFORE_ARRIVAL = 'before_arrival';

    /** Check-in: by themselves (key box, code or smart lock) or welcomed in person by a host. */
    public const CHECK_IN_SELF = 'self';

    public const CHECK_IN_WELCOME = 'welcome';

    /** Access code for self check-in: none, a fixed code, or the last four digits of the guest's phone number. */
    public const ACCESS_NONE = 'none';

    public const ACCESS_FIXED = 'fixed';

    public const ACCESS_PHONE = 'phone';

    /** Codes and check-in details never leave the model by accident (JSON, API, logs). */
    protected $hidden = ['access_code', 'check_in_fields', 'check_in_values'];

    /** Down payment meaning: the guest pays everything at once. */
    public const FULL_PAYMENT = 100;

    /** What a new master record starts with; after that the owner manages them. */
    public const STARTWAARDEN = [
        'down_payment_percentage' => 30,
        'vat_percentage' => 9,
        'deposit_days_before_arrival' => 7,
        'deposit_days_after_departure' => 3,
        'deposit_auto_refund' => false,
        'deposit_moment' => self::DEPOSIT_BEFORE_ARRIVAL,
        'pricing_service' => PricingServices::NO,
        'dynamic_discount_kind' => self::DISCOUNT_NONE,
        'manual_prices_win' => false,
        'check_in_kind' => self::CHECK_IN_SELF,
        'access_code_kind' => self::ACCESS_NONE,
        'balance_payment_days_before_arrival' => 14,
        'checkin_days_before_arrival' => 3,
        'checkout_days_before_departure' => 1,
        'review_days_after_departure' => 2,
        'id_required' => false,
        'prices_in_calendar' => false,
        'discounts_add_up' => false,
        'pets_allowed' => false,
        'weekend_kind' => self::WEEKEND_FIXED,
        'max_babies' => 5,
        'max_pets' => 2,
        'currency' => 'EUR',
    ];

    /**
     * The master record is invisible everywhere (site, admin, dropdowns);
     * only retrievable explicitly via Property::master().
     */
    protected static function booted(): void
    {
        static::addGlobalScope('withoutMaster', function (\Illuminate\Database\Eloquent\Builder $query) {
            $query->where('properties.is_master', false);
        });

        static::created(fn (self $accommodation) => Access::linkToHost($accommodation));

        // Discount on the dynamic price and weekend adjustment: only the value
        // of the chosen kind is kept.
        static::saving(function (self $accommodation): void {
            if ($accommodation->dynamic_discount_kind !== self::DISCOUNT_PERCENT) {
                $accommodation->dynamic_discount_percentage = null;
            }
            if ($accommodation->dynamic_discount_kind !== self::DISCOUNT_FIXED) {
                $accommodation->dynamic_discount_cents = null;
            }
            if ($accommodation->check_in_kind !== self::CHECK_IN_WELCOME) {
                $accommodation->check_in_host_id = null;
            }
            if ($accommodation->access_code_kind !== self::ACCESS_FIXED) {
                $accommodation->access_code = null;
            }
            // Each own check-in field gets a fixed key once: the mail texts refer to it.
            if ($accommodation->is_master && is_array($accommodation->check_in_fields)) {
                $accommodation->check_in_fields = array_values(array_map(
                    fn (array $field) => [...$field, 'key' => filled($field['key'] ?? null) ? $field['key'] : Str::lower(Str::random(8))],
                    $accommodation->check_in_fields,
                ));
            }

            if ($accommodation->weekend_kind === null && ! $accommodation->is_master) {
                if ($accommodation->isDirty('weekend_kind')) {
                    // Deliberately chose "follow the default": own values removed.
                    $accommodation->weekend_price_cents = null;
                    $accommodation->weekend_percentage = null;

                    return;
                }

                // A weekend price without a kind (e.g. from import or demo data).
                $accommodation->weekend_kind = match (true) {
                    $accommodation->weekend_price_cents !== null => self::WEEKEND_FIXED,
                    $accommodation->weekend_percentage !== null => self::WEEKEND_PERCENT,
                    default => null,
                };
            }

            if ($accommodation->weekend_kind === self::WEEKEND_FIXED) {
                $accommodation->weekend_percentage = null;
            } elseif ($accommodation->weekend_kind === self::WEEKEND_PERCENT) {
                $accommodation->weekend_price_cents = null;
            }
        });

        // The default currency is remembered per request (see Money).
        static::saved(function (self $accommodation): void {
            if ($accommodation->is_master) {
                Money::forgetsDefaultCurrency();
            }
        });
    }

    /**
     * The hidden record with the default prices and rules for all
     * properties (created on first use). The system does that creation, even
     * if a host happens to be looking at a screen: without events, otherwise
     * the ownership check refuses and they get an error page.
     */
    public static function master(): self
    {
        return static::withoutEvents(fn () => static::withoutGlobalScope('withoutMaster')->firstOrCreate(
            ['is_master' => true],
            [
                'name' => 'Standaarden',
                'slug' => '__defaults__',
                'type' => 'holiday_home',
                'active' => false,
                'max_guests' => 99,
                'base_price_cents' => 10000,
                ...self::STARTWAARDEN,
            ],
        ));
    }

    /**
     * Value of a field with fallback to the default settings:
     * own value if filled in, otherwise that of the master record.
     */
    public function effective(string $field): mixed
    {
        if ($this->is_master) {
            return $this->{$field};
        }

        return $this->{$field} ?? static::master()->{$field};
    }

    /** The currency of this property's prices (ISO code), falling back to the default. */
    public function priceCurrency(): string
    {
        return strtoupper((string) ($this->effective('currency') ?: 'EUR'));
    }

    /** The base price per night in cents, in this property's currency (0 if there is none). */
    public function basePrice(): int
    {
        return (int) $this->effectiveAmount('base_price_cents');
    }

    /**
     * A money field with fallback to the default, in this property's
     * currency: a default amount in another currency is converted.
     */
    public function effectiveAmount(string $field): ?int
    {
        if ($this->is_master || $this->{$field} !== null) {
            return $this->{$field};
        }

        $default = static::master();

        return Money::convert($default->{$field}, $default->priceCurrency(), $this->priceCurrency());
    }

    /**
     * A translatable field as the guest sees it. First determine which record
     * applies (this property if it has its own Dutch text, otherwise the
     * default), then the language within that record. That way a property
     * with its own terms never shows the translation of the default;
     * that would be different content.
     */
    /**
     * The highlights in the visitor's language. They are stored as rows in a
     * single column, so each row has its own translations; empty = the source text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function localizedHighlights(): array
    {
        $locale = app()->getLocale();

        return array_map(fn (array $row) => [
            ...$row,
            'title' => ($row['title_translations'][$locale] ?? null) ?: ($row['title'] ?? ''),
            'text' => ($row['text_translations'][$locale] ?? null) ?: ($row['text'] ?? ''),
        ], $this->highlights ?? []);
    }

    protected function slugSource(): string
    {
        return 'name';
    }

    public function effectiveLocalized(string $field): ?string
    {
        // An emptied text editor ("<p></p>") is no own text: the default's text applies.
        $source = $this->is_master || RichText::filled($this->{$field}) ? $this : static::master();

        return $source->localized($field);
    }

    /**
     * What applies to each property for this field (id => own value,
     * otherwise the default). In a single query, so the scheduled tasks do not
     * have to fetch the default per property. Deleted properties
     * count too: their bookings simply continue.
     *
     * @return Collection<int, mixed>
     */
    public static function valuePerAccommodation(string $field): Collection
    {
        $default = static::master()->{$field};

        return static::withTrashed()->pluck($field, 'id')->map(fn ($custom) => $custom ?? $default);
    }

    /**
     * The custom text for an automatic mail, in the guest's language.
     */
    public function mailText(MailType $type): ?string
    {
        return $this->textInLanguage('mail_texts', $type->value) ?: null;
    }

    /**
     * The lines of a fixed cancellation policy: the own text of this
     * property or of the default, otherwise the built-in text.
     *
     * @return string[]
     */
    public function cancellationRules(CancellationPolicy $policy): array
    {
        return $policy->lines($this->textInLanguage('cancellation_texts', $policy->value));
    }

    /**
     * A text from a list of language => text. As with
     * effectiveLocalized: if there is a text of its own here, it never comes from the
     * default, not even in a language that is empty here. That would be
     * different content.
     */
    private function textInLanguage(string $column, string $key): ?string
    {
        $custom = array_filter($this->{$column}[$key] ?? []);
        $source = $this->is_master || $custom !== [] ? $this : static::master();

        return $source->{$column}[$key][app()->getLocale()] ?? null;
    }

    /**
     * ID verification for this property: own choice, otherwise the default.
     */
    public function idRequired(): bool
    {
        return (bool) $this->effective('id_required');
    }

    /**
     * Show price per night in the calendar: own choice, otherwise the default.
     */
    public function pricesInCalendar(): bool
    {
        return (bool) $this->effective('prices_in_calendar');
    }

    /**
     * Do discounts that apply at the same time (e.g. week and early bird) stack, or
     * does the guest get only the most favourable? Own choice, otherwise the default.
     */
    public function discountsAddUp(): bool
    {
        return (bool) $this->effective('discounts_add_up');
    }

    /**
     * May guests bring pets: own choice, otherwise the default.
     */
    public function petsAllowed(): bool
    {
        return (bool) $this->effective('pets_allowed');
    }

    /** Maximum number of pets per booking (0 if not allowed): own value, otherwise the default. */
    public function maxPets(): int
    {
        return $this->petsAllowed() ? (int) $this->effective('max_pets') : 0;
    }

    /** Maximum number of babies per booking: own value, otherwise the default. */
    public function maxBabies(): int
    {
        return (int) $this->effective('max_babies');
    }

    /**
     * Validation of the number of pets when booking. The guest picker already limits
     * it on the site; this also stops a tampered request.
     *
     * @return list<string>
     */
    public function petsRule(): array
    {
        return ['nullable', 'integer', 'min:0', 'max:'.$this->maxPets()];
    }

    /** @return list<string> */
    public function babyRule(): array
    {
        return ['nullable', 'integer', 'min:0', 'max:'.$this->maxBabies()];
    }

    /**
     * Notices for too many babies or pets, in the guest's language.
     *
     * @return array<string, string>
     */
    public function guestsNotifications(): array
    {
        return [
            'pets.max' => $this->petsAllowed()
                ? trans_choice('{1} At most 1 pet per booking.|[2,*] At most :count pets per booking.', $this->maxPets())
                : __('Pets are not allowed.'),
            'infants.max' => trans_choice('{0} Infants cannot come along here.|{1} At most 1 infant per booking.|[2,*] At most :count infants per booking.', $this->maxBabies()),
        ];
    }

    /** Fixed amount or percentage for Friday and Saturday nights; own choice, otherwise the default. */
    public function weekendKind(): string
    {
        return $this->effective('weekend_kind') ?: self::WEEKEND_FIXED;
    }

    /**
     * The price of a Friday or Saturday night for this regular nightly price
     * (base price or season price).
     *
     * - Percentage: the nightly price plus (or minus) that percentage.
     * - Fixed amount: the own weekend price; empty = the nightly price. If the
     *   property follows the default, then the weekend price of the default
     *   (converted if the currency differs).
     */
    public function weekendPrice(int $nightlyPrice): int
    {
        if ($this->weekendKind() === self::WEEKEND_PERCENT) {
            return max(0, (int) round($nightlyPrice * (100 + (int) $this->effective('weekend_percentage')) / 100));
        }

        $amount = $this->weekend_kind === null && ! $this->is_master
            ? $this->effectiveAmount('weekend_price_cents')
            : $this->weekend_price_cents;

        return $amount ?? $nightlyPrice;
    }

    /**
     * Prices from the dynamic pricing service as the guest pays them on this
     * site: minus the discount on dynamic prices. A service like PriceLabs is
     * often set up with a surcharge that covers the Airbnb or Vrbo commission;
     * that surcharge does not belong on the own booking site. Never below zero.
     *
     * @param  array<string, int>  $prices  date (Y-m-d) => price in cents
     * @return array<string, int>
     */
    public function withDynamicDiscount(array $prices): array
    {
        if ($prices === []) {
            return $prices;
        }

        $discount = match ($this->effective('dynamic_discount_kind')) {
            self::DISCOUNT_PERCENT => fn (int $cents) => $cents * (float) $this->effective('dynamic_discount_percentage') / 100,
            self::DISCOUNT_FIXED => fn () => (int) $this->effectiveAmount('dynamic_discount_cents'),
            default => null,
        };

        if ($discount === null) {
            return $prices;
        }

        return array_map(fn (int $cents) => max(0, (int) round($cents - $discount($cents))), $prices);
    }

    /**
     * How a map shows this accommodation: none, approximately or exactly.
     * $map: MAP_PAGE (its own page, also the API) or MAP_OVERVIEW (the overview
     * page with all accommodations).
     */
    public function locationDisplay(string $map = self::MAP_PAGE): string
    {
        $value = $map === self::MAP_OVERVIEW ? $this->overview_location_display : $this->location_display;

        return in_array($value, [self::LOCATION_NONE, self::LOCATION_EXACT], true) ? $value : self::LOCATION_APPROXIMATE;
    }

    /** Does the accommodation's own page show the exact spot (pin and street)? */
    public function showsExactLocation(): bool
    {
        return $this->locationDisplay() === self::LOCATION_EXACT;
    }

    /**
     * The coordinates a map may show. Exact: the real spot. Approximate: a
     * spot up to LOCATION_SHIFT metres away, always the same for this
     * accommodation (derived from the app key, so it cannot be worked back);
     * the real spot lies inside the circle, but not in its centre. Null
     * without coordinates, or when that map should not show it at all.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function publicCoordinates(string $map = self::MAP_PAGE): ?array
    {
        if ($this->latitude === null || $this->longitude === null || $this->locationDisplay($map) === self::LOCATION_NONE) {
            return null;
        }

        $lat = (float) $this->latitude;
        $lng = (float) $this->longitude;

        if ($this->locationDisplay($map) === self::LOCATION_EXACT) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        $hash = hash_hmac('sha256', 'location:'.$this->id, (string) config('app.key'));
        $angle = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF * 2 * M_PI;
        $distance = (0.3 + 0.7 * hexdec(substr($hash, 8, 8)) / 0xFFFFFFFF) * self::LOCATION_SHIFT;

        // Metres to degrees: one degree of latitude is about 111 km; longitude shrinks towards the poles.
        $lat += ($distance * cos($angle)) / 111320;
        $lng += ($distance * sin($angle)) / (111320 * max(cos(deg2rad($lat)), 0.01));

        return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }

    /** Does the guest pay the deposit right after booking? Own choice, otherwise the default. */
    public function depositAtBooking(): bool
    {
        return $this->effective('deposit_moment') === self::DEPOSIT_AT_BOOKING;
    }

    /** How the guest checks in; own choice, otherwise the default. */
    public function checkInKind(): string
    {
        return $this->effective('check_in_kind') ?: self::CHECK_IN_SELF;
    }

    /**
     * Who welcomes the guest in person: the host chosen with the check-in kind
     * (the own choice, otherwise that of the default). Null = self check-in, or
     * no one named.
     */
    public function checkInHost(): ?Host
    {
        if ($this->checkInKind() !== self::CHECK_IN_WELCOME) {
            return null;
        }

        $owner = $this->is_master || $this->check_in_kind !== null ? $this : static::master();

        return $owner->welcomedBy;
    }

    /** The host chosen to welcome guests (own column only, without the default). */
    public function welcomedBy(): BelongsTo
    {
        return $this->belongsTo(Host::class, 'check_in_host_id');
    }

    /** The kind of access code for self check-in; own choice, otherwise the default. */
    public function accessCodeKind(): string
    {
        return $this->effective('access_code_kind') ?: self::ACCESS_NONE;
    }

    /** Must the guest give a phone number when booking? Yes when its last digits are the door code. */
    public function phoneRequired(): bool
    {
        return $this->checkInKind() === self::CHECK_IN_SELF && $this->accessCodeKind() === self::ACCESS_PHONE;
    }

    /**
     * The code the guest opens the door with, or null if there is none to give:
     * no self check-in, no code chosen, a booking that is not confirmed (yet),
     * or a phone number with fewer than four digits.
     */
    public function accessCodeFor(Booking $booking): ?string
    {
        if ($this->checkInKind() !== self::CHECK_IN_SELF || ! $booking->status?->isConfirmed()) {
            return null;
        }

        return match ($this->accessCodeKind()) {
            self::ACCESS_FIXED => ($this->is_master || $this->access_code_kind !== null ? $this : static::master())->access_code ?: null,
            self::ACCESS_PHONE => strlen($digits = preg_replace('/\D/', '', (string) $booking->guest_phone)) >= 4 ? substr($digits, -4) : null,
            default => null,
        };
    }

    /**
     * The own check-in fields (for example "WiFi password"), defined on the
     * defaults: key => name.
     *
     * @return array<string, string>
     */
    public static function checkInFieldNames(): array
    {
        return collect(static::master()->check_in_fields ?? [])
            ->filter(fn (array $field) => filled($field['key'] ?? null) && filled($field['name'] ?? null))
            ->mapWithKeys(fn (array $field) => [$field['key'] => $field['name']])
            ->all();
    }

    /** The value of an own check-in field: the own value, otherwise that of the default. */
    public function checkInValue(string $key): string
    {
        if (! $this->is_master && filled($own = $this->check_in_values[$key] ?? null)) {
            return (string) $own;
        }

        return (string) (collect(static::master()->check_in_fields ?? [])->firstWhere('key', $key)['value'] ?? '');
    }

    /** Does a price set by hand in the calendar go before the dynamic price? Own choice, otherwise the default. */
    public function manualPricesWin(): bool
    {
        return (bool) $this->effective('manual_prices_win');
    }

    /**
     * Icons that can be chosen for the highlights blocks
     * (in addition to the amenity icons).
     */
    public const HIGHLIGHT_ICONS = [
        'clock' => 'Klok (check-in)',
        'map-pin' => 'Kaartspeld (locatie)',
        'credit-card' => 'Betaalkaart',
        'check-circle' => 'Vinkje in cirkel',
        'photo' => 'Photo',
    ];

    /**
     * In the order the owner dragged them (Amenities on the accommodation); one
     * without a position yet (just added via Create or a seeder) comes last.
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)
            ->withPivot('position')
            ->orderByRaw('amenity_property.position is null')
            ->orderBy('amenity_property.position')
            ->orderBy('amenities.id');
    }

    /**
     * Saves the chosen amenities: the ones that stay keep their place, new ones
     * are added at the end.
     *
     * @param  array<int, int>  $amenityIds
     */
    public function syncAmenities(array $amenityIds): void
    {
        $current = $this->amenities()->pluck('amenities.id')->all();
        $order = [
            ...array_values(array_intersect($current, $amenityIds)),
            ...array_values(array_diff($amenityIds, $current)),
        ];

        $this->amenities()->sync($order);
        $this->orderAmenities($order);
    }

    /**
     * Puts one amenity of this accommodation at a new place (0 = first).
     */
    public function moveAmenity(int $amenityId, int $position): void
    {
        $order = $this->amenities()->pluck('amenities.id')->all();

        if (! in_array($amenityId, $order, true)) {
            return;
        }

        $order = array_values(array_diff($order, [$amenityId]));
        array_splice($order, max(0, $position), 0, [$amenityId]);

        $this->orderAmenities($order);
    }

    /**
     * Positions 1, 2, 3 … in one query.
     *
     * @param  array<int, int>  $amenityIds  already linked to this accommodation
     */
    private function orderAmenities(array $amenityIds): void
    {
        if ($amenityIds === []) {
            return;
        }

        DB::table('amenity_property')->upsert(
            array_map(fn (int $id, int $index) => [
                'property_id' => $this->getKey(), 'amenity_id' => $id, 'position' => $index + 1,
            ], $amenityIds, array_keys($amenityIds)),
            ['property_id', 'amenity_id'],
            ['position'],
        );

        $this->unsetRelation('amenities');
    }

    public function rateSeasons(): HasMany
    {
        return $this->hasMany(RateSeason::class);
    }

    public function nightlyRates(): HasMany
    {
        return $this->hasMany(NightlyRate::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function calendarBlocks(): HasMany
    {
        return $this->hasMany(CalendarBlock::class);
    }

    public function bookingRules(): HasMany
    {
        return $this->hasMany(BookingRule::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function channelConnections(): HasMany
    {
        return $this->hasMany(ChannelConnection::class);
    }

    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(Host::class);
    }

    /** Only the properties this host is linked to. */
    public function scopeOfHost(\Illuminate\Database\Eloquent\Builder $query, int $hostId): void
    {
        $query->whereHas('hosts', fn (\Illuminate\Database\Eloquent\Builder $host) => $host->whereKey($hostId));
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class)
            ->where('status', \App\Enums\ReviewStatus::Approved->value)
            ->latest();
    }

    /**
     * The cancellation terms as lines: preset lines, or the own text.
     *
     * @return string[]
     */
    public function cancellationPolicyLines(): array
    {
        // Own policy filled in? Then that applies (incl. own text).
        // Otherwise fall back completely to the default settings.
        $source = $this->cancellation_policy !== null || $this->is_master ? $this : static::master();

        $policy = $source->cancellation_policy ?? \App\Enums\CancellationPolicy::Moderate;
        $lines = $this->cancellationRules($policy);

        // The record has already been chosen above; now only the language.
        if ($text = $source->localized('cancellation_policy_text')) {
            $lines = array_merge($lines, preg_split('/\r?\n/', trim($text)));
        }

        return array_values(array_filter($lines));
    }

    /**
     * The applicable cancellation policy (own or default).
     */
    public function effectiveCancellationPolicy(): \App\Enums\CancellationPolicy
    {
        return $this->effective('cancellation_policy') ?? \App\Enums\CancellationPolicy::Moderate;
    }

    /**
     * Do the rental terms apply to a stay of this many nights? Only
     * if there are rental terms, and from the configured number of nights
     * (own value, otherwise that of the default, otherwise 0 = always). Also
     * determines whether the guest must sign.
     */
    public function rentalTermsApply(int $nights): bool
    {
        return filled($this->effectiveLocalized('rental_terms'))
            && $nights >= (int) ($this->effective('rental_terms_min_nights') ?? 0);
    }

    /**
     * The full text the guest accepts when booking:
     * rental terms (if they apply to this number of nights) + house rules +
     * cancellation terms. This text is frozen as a snapshot on the booking
     * as proof of acceptance.
     */
    public function bookingTermsText(int $nights): string
    {
        $share = [];

        if ($this->rentalTermsApply($nights)) {
            $share[] = __('Rental conditions')."\n".trim($this->effectiveLocalized('rental_terms'));
        }

        if ($houseRules = $this->effectiveLocalized('house_rules')) {
            $share[] = __('House rules')."\n".trim($houseRules);
        }

        if (($rules = $this->cancellationPolicyLines()) !== []) {
            $share[] = __('Cancellation policy')."\n- ".implode("\n- ", $rules);
        }

        return implode("\n\n", $share);
    }

    /**
     * Secret token for the iCal export feed; created on first use.
     */
    public function icalToken(): string
    {
        if ($this->ical_token === null) {
            $this->ical_token = bin2hex(random_bytes(20));
            $this->save();
        }

        return $this->ical_token;
    }

    /** An uploaded photo added at the end of the property's photos, under a clean file name. */
    public function addPhoto(\Illuminate\Http\UploadedFile $file): Media
    {
        $name = \Illuminate\Support\Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'photo';

        return $this->addMedia($file->getRealPath())
            ->usingFileName($name.'.'.strtolower($file->getClientOriginalExtension()))
            ->toMediaCollection('photos');
    }

    /** A video between the photos. It has no resized versions (no ffmpeg on the server): the site plays the original. */
    public static function isVideo(Media $media): bool
    {
        return str_starts_with((string) $media->mime_type, 'video/');
    }

    /** The explanation above the photos, with the limits from the constants above. */
    public static function photoHint(): string
    {
        return __('The first photo is the main photo; a video never is. Drag to reorder. Max. :photo MB per photo, :video MB per video (mp4 or webm).', [
            'photo' => self::MAX_PHOTO_KB / 1024,
            'video' => self::MAX_VIDEO_KB / 1024,
        ]);
    }

    /** The main photo: the first photo that is not a video. A video never becomes the main photo. */
    public function coverPhoto(): ?Media
    {
        return $this->getMedia('photos')->first(fn (Media $media): bool => ! self::isVideo($media));
    }

    /** The url of the main photo in a resized version, or '' without a photo (like getFirstMediaUrl). */
    public function coverPhotoUrl(string $conversion = 'large'): string
    {
        $photo = $this->coverPhoto();

        if ($photo === null) {
            return '';
        }

        return $photo->hasGeneratedConversion($conversion) ? $photo->getUrl($conversion) : $photo->getUrl();
    }

    /**
     * The photos and videos for the site in the owner's order, but with the
     * main photo first: at the top of the page there is always a photo.
     *
     * @return \Illuminate\Support\Collection<int, Media>
     */
    public function gallery(): \Illuminate\Support\Collection
    {
        $media = $this->getMedia('photos');
        $cover = $this->coverPhoto();

        return $cover === null
            ? $media->values()
            : $media->reject(fn (Media $item): bool => $item->is($cover))->prepend($cover)->values();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')
            ->useDisk('public')
            ->registerMediaConversions(function (?Media $media) {
                // The original is kept (source), but the site always serves
                // these resized + compressed versions — never the full
                // upload. Good quality, much smaller file. Videos are
                // skipped (Spatie has no generator for them without ffmpeg).
                $this->addMediaConversion('thumb')
                    ->width(400)
                    ->height(300)
                    ->quality(82)
                    ->optimize()
                    ->nonQueued();

                $this->addMediaConversion('large')
                    ->fit(\Spatie\Image\Enums\Fit::Max, 1600, 1600) // max 1600px, never upscale
                    ->quality(82)
                    ->optimize()
                    ->nonQueued();
            });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            // Door codes and check-in details stay out of the log.
            ->logExcept(['access_code', 'check_in_fields', 'check_in_values'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * The name of the property type in the visitor's language.
     */
    public function typeName(): string
    {
        return PropertyType::nameFor($this->type);
    }

    /**
     * The social links for this property: an own link overrides the
     * general one, and channels left empty here fall back to the
     * settings. Only safe http(s) links are returned.
     *
     * @return array<string, string> channel => url
     */
    public function socialLinks(): array
    {
        $links = $this->is_master ? [] : \App\Support\SocialChannels::general();

        foreach ((array) ($this->social_links ?? []) as $channel => $url) {
            if (filled($url) && in_array($channel, \App\Support\SocialChannels::keys(), true)) {
                $links[$channel] = $url;
            }
        }

        return collect($links)
            ->map(fn (string $url) => \App\Support\SocialChannels::safeUrl($url))
            ->filter()
            ->all();
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_master' => 'boolean',
            // pets_allowed deliberately without boolean cast, like the other on/off-or-default
            // fields: otherwise Filament turns false in that select into "Follow the default".
            'highlights' => 'array',
            'social_links' => 'array',
            'mail_texts' => 'array',
            'cancellation_texts' => 'array',
            'slug_translations' => 'array',
            'name_translations' => 'array',
            'short_description_translations' => 'array',
            'description_translations' => 'array',
            'meta_title_translations' => 'array',
            'meta_description_translations' => 'array',
            'rental_terms_translations' => 'array',
            'house_rules_translations' => 'array',
            'cancellation_policy_text_translations' => 'array',
            'cancellation_policy' => \App\Enums\CancellationPolicy::class,
            'access_code' => 'encrypted',
            'check_in_fields' => 'encrypted:array',
            'check_in_values' => 'encrypted:array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
