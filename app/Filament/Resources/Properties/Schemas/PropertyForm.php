<?php

namespace App\Filament\Resources\Properties\Schemas;

use App\Enums\CancellationPolicy;
use App\Enums\MailType;
use App\Filament\Forms\Components\AmenitiesPicker;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Support\RichEditors;
use App\Filament\Support\Switches;
use App\Livewire\AccommodationAmenities;
use App\Filament\Support\TranslationFields;
use App\Livewire\NightlyPricesCalendar;
use App\Models\Amenity;
use App\Models\Discount;
use App\Filament\Resources\Hosts\HostResource;
use App\Models\Property;
use App\Payments\PaymentProvider;
use App\Services\Importing\ListingImportException;
use App\Services\PricingServices;
use App\Support\MailPlaceholders;
use App\Support\Money;
use App\Support\Access;
use App\Support\Currencies;
use App\Services\Importing\ListingImportProvider;
use App\Support\Languages;
use Closure;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Support\Str;

class PropertyForm
{
    private const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /** @return array<int, string> ISO day number => translated day name */
    private static function weekdays(): array
    {
        return array_map(fn (string $day) => __($day), self::WEEKDAYS);
    }

    /** Choices for "When does the guest pay?". Only the percentage is stored. */
    private const PAYMENT_METHOD_FOLLOW = 'follow';

    private const ALL_AT_ONCE = 'everything';

    private const DOWN_PAYMENT = 'down_payment';

    /** The automatic mails for which an own text can be set. */
    private const MAIL_TEXTS = [
        'confirmation' => 'Text after booking (in the booking confirmation)',
        'payment_received' => 'Text for "payment received"',
        'balance_payment' => 'Text for the balance payment reminder',
        'deposit' => 'Text for the deposit request',
        'payment_request' => 'Text for the payment request (email from the admin)',
        'checkin' => 'Text for the check-in instructions',
        'checkout' => 'Text for the check-out information',
        'review' => 'Text for the review request',
        'verified' => 'Text for "verification successful, pay now"',
        'identity_reminder' => 'Text for the identity check reminder',
        'identity_cancelled' => 'Text for "cancelled: no identity check"',
    ];

    /**
     * Yes/no choice: an accommodation follows the default when empty. On the
     * defaults screen itself a choice is required, because it stops there.
     */
    private static function inheritToggle(string $name, string $label, ?string $hint = null): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(['1' => __('Yes'), '0' => __('No')])
            ->placeholder(__('Follow the default'))
            ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
            ->required(fn (?Property $record) => (bool) $record?->is_master)
            ->native(false)
            ->hint($hint)
            ->formatStateUsing(fn ($state) => $state === null ? null : ((int) $state ? '1' : '0'))
            ->dehydrateStateUsing(fn ($state) => ($state === null || $state === '') ? null : (bool) (int) $state);
    }

    /** A choice that follows the default when empty; required on the defaults screen itself. */
    private static function inheritChoice(string $name, string $label, array $options): Select
    {
        return Select::make($name)
            ->label($label)
            ->options($options)
            ->placeholder(__('Follow the default'))
            ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
            ->required(fn (?Property $record) => (bool) $record?->is_master)
            ->native(false);
    }

    /** What a field is in the form right now, or the default's when it is empty here. */
    private static function formOrDefault(Get $get, ?Property $record, string $field): mixed
    {
        $state = $get($field);

        return ($state === null || $state === '') && ! $record?->is_master ? self::default()->{$field} : $state;
    }

    private static function idCheckOn(Get $get, ?Property $record): bool
    {
        return (bool) (int) self::formOrDefault($get, $record, 'id_required');
    }

    private static function idCheckAfterPayment(Get $get, ?Property $record): bool
    {
        return self::idCheckOn($get, $record)
            && self::formOrDefault($get, $record, 'id_moment') === Property::ID_AFTER_PAYMENT;
    }

    /**
     * Choose amenities with a button per amenity. For an existing
     * accommodation every click is saved immediately; for a new one the choice
     * is saved along with Create.
     */
    private static function amenitiesPicker(): AmenitiesPicker
    {
        return Switches::direct(
            AmenitiesPicker::make('amenities')->label(__('Amenities'))->columnSpanFull(),
            fn (mixed $chosen, object $livewire): bool => self::saveAmenities($livewire, (array) $chosen),
        )->afterNewAmenity(function (array $chosen, array $previous, object $livewire, AmenitiesPicker $component): void {
            if (self::saveAmenities($livewire, $chosen)) {
                Switches::rememberSaved($livewire, $component->getStatePath(), $previous);
                Switches::notifySaved();
            }
        });
    }

    /** @param  array<int, int|string>  $chosen */
    private static function saveAmenities(object $livewire, array $chosen): bool
    {
        if (! $livewire instanceof EditRecord || ! PropertyResource::canEdit($livewire->getRecord())) {
            return false;
        }

        // In the order they were ticked; the order list below the picker shows the change.
        $livewire->getRecord()->syncAmenities(Amenity::query()->whereKey($chosen)->pluck('id')
            ->sortBy(fn (int $id) => array_search((string) $id, array_map('strval', $chosen), true))->values()->all());
        $livewire->dispatch(AccommodationAmenities::CHANGED);

        return true;
    }

    /** The defaults, fetched once per request: this form asks for them often. */
    private static function default(): Property
    {
        return once(fn (): Property => Property::master());
    }

    /**
     * Language tabs for lists with a text per language (mail texts, the fixed
     * cancellation texts): per tab all lists in that language.
     *
     * @param  array<int, string>  $paths  the path of each list
     * @param  Closure(string $path, string $locale): Field  $field
     * @return array<int, Component>
     */
    private static function languageLists(string $key, array $paths, Closure $field): array
    {
        $inLanguage = fn (string $locale) => array_map(fn (string $path) => "{$path}.{$locale}", $paths);

        return [
            TranslationFields::languageTabs($key, fn (string $locale) => array_map(fn (string $path) => $field($path, $locale), $inLanguage($locale))),
            ...TranslationFields::hidden($inLanguage),
        ];
    }

    /** The text that currently applies to this policy in this language, as the default has it. */
    private static function cancellationText(CancellationPolicy $policy, string $locale): string
    {
        $current = app()->getLocale();
        app()->setLocale($locale);

        try {
            return implode("\n", self::default()->cancellationRules($policy));
        } finally {
            app()->setLocale($current);
        }
    }

    /**
     * Number that an accommodation can leave empty to follow the default. The
     * grey text shows what the default currently is; on the defaults screen
     * itself the field is required, because it stops there.
     */
    private static function defaultNumber(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->numeric()
            ->integer()
            ->minValue(0)
            ->required(fn (?Property $record) => (bool) $record?->is_master)
            ->placeholder(fn (?Property $record) => $record?->is_master ? null : (string) self::default()->{$field})
            ->hint(fn (?Property $record) => $record?->is_master ? null : __('Empty = follow the default'));
    }

    /**
     * Payment, price and mail rules belong to the owner. A user sees
     * them on an accommodation only with this separate permission; hidden fields
     * are not saved. On the defaults screen its own permission applies.
     */
    private static function withPaymentRules(): Closure
    {
        return fn (?Property $record): bool => (bool) $record?->is_master
            || Access::may(auth()->user(), 'payment_rules', Access::EDIT);
    }

    private static function asPaymentMethod(string $paymentMethod): string
    {
        return "\$get('payment_method') === '{$paymentMethod}'";
    }

    /** The slider passes one value as a number or as a list with one number. */
    private static function sliderValue(mixed $value): int
    {
        return (int) round((float) (is_array($value) ? reset($value) : $value));
    }

    private static function downPaymentExplanation(mixed $value): string
    {
        $downPayment = self::sliderValue($value);

        return __('The guest pays :down_payment% when booking and :rest% before arrival.', [
            'down_payment' => $downPayment,
            'rest' => Property::FULL_PAYMENT - $downPayment,
        ]);
    }

    private static function defaultPaymentMethod(): string
    {
        $percentage = (int) self::default()->down_payment_percentage;

        return $percentage >= Property::FULL_PAYMENT
            ? __('The default is now: everything at once when booking.')
            : __('The default is now: :percentage% down payment, the rest before arrival.', ['percentage' => $percentage]);
    }

    /**
     * Button that writes the accommodation texts with AI (short
     * description, description and SEO) based on the fields already filled in.
     * Works both on the edit form and in the wizard.
     */
    private static function aiTextAction(): Action
    {
        return Action::make('aiText')
            ->label(__('Generate with AI'))
            ->icon(Heroicon::OutlinedSparkles)
            ->action(function (callable $get, callable $set) {
                $name = trim((string) $get('name'));

                if ($name === '') {
                    Notification::make()->title(__('Enter a name first.'))->warning()->send();

                    return;
                }

                $amenityIds = array_filter((array) $get('amenities'));
                $amenities = $amenityIds
                    ? \App\Models\Amenity::whereIn('id', $amenityIds)->pluck('name')->all()
                    : [];

                $facts = array_filter([
                    'name' => $name,
                    'type' => $get('type'),
                    'city' => $get('city'),
                    'country' => filled($get('country')) ? \App\Support\Countries::name($get('country')) : null,
                    'max_guests' => $get('max_guests'),
                    'bedrooms' => $get('bedrooms'),
                    'beds' => $get('beds'),
                    'sofa_beds' => $get('sofa_beds'),
                    'bathrooms' => $get('bathrooms'),
                    'amenities' => $amenities,
                    'existing_short_description' => $get('short_description'),
                ], fn ($value) => filled($value));

                try {
                    $copy = app(\App\Services\Ai\ListingCopywriter::class)->write($facts, Languages::SOURCE);
                } catch (\Throwable $e) {
                    Notification::make()->title(__('AI generation failed'))->body($e->getMessage())->danger()->send();

                    return;
                }

                if (filled($copy['short_description'])) {
                    $set('short_description', $copy['short_description']);
                }
                if (filled($copy['description'])) {
                    $set('description', $copy['description']);
                }
                if (filled($copy['meta_title'])) {
                    $set('meta_title', $copy['meta_title']);
                }
                if (filled($copy['meta_description'])) {
                    $set('meta_description', $copy['meta_description']);
                }

                Notification::make()->title(__('Texts generated — review and save.'))->success()->send();
            });
    }

    /**
     * Fields to create a new accommodation type on the spot (as with
     * amenities). The name is English; translations are managed under
     * Accommodation types.
     *
     * @return array<int, TextInput>
     */
    private static function typeCreationFields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Name (English)'))
                ->required()
                ->unique('property_types', 'name')
                ->hint(__('You set translations under Accommodation types')),
        ];
    }

    /**
     * Money field: shows the amount in the accommodation's currency (the symbol
     * follows the Currency choice), stores hundredths (integer).
     */
    private static function euroInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->prefix(fn ($livewire): string => Currencies::character(self::chosenCurrency($livewire)))
            ->step(0.01)
            ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round((float) $state * 100));
    }

    /**
     * Weekend adjustment for Friday and Saturday nights: a fixed price per
     * night or a percentage on the nightly price (also for seasons without their own
     * weekend price). Empty = follow the default; required on the defaults
     * screen.
     *
     * @return array<int, Select|TextInput>
     */
    private static function weekendFields(): array
    {
        // The amount field of the kind that applies, also when the accommodation
        // follows the default: then it shows the default's value, read-only.
        // This way the fields after it keep their place.
        $kind = fn (Get $get, ?Property $record): string => self::formOrDefault($get, $record, 'weekend_kind') ?: Property::WEEKEND_FIXED;
        $followsDefault = fn (Get $get, ?Property $record): bool => ! $record?->is_master && blank($get('weekend_kind'));

        return [
            Select::make('weekend_kind')
                ->label(__('Weekend adjustment (Fri/Sat)'))
                ->options([
                    Property::WEEKEND_FIXED => __('Fixed weekend price per night'),
                    Property::WEEKEND_PERCENT => __('Percentage on the nightly price'),
                ])
                ->placeholder(__('Follow the default'))
                ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
                ->required(fn (?Property $record) => (bool) $record?->is_master)
                ->live()
                // Following the default: the own amounts no longer count (the model clears them on save).
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if (blank($state)) {
                        $set('weekend_price_cents', null);
                        $set('weekend_percentage', null);
                    }
                })
                ->native(false),
            self::euroInput('weekend_price_cents', __('Weekend price per night'))
                ->hint(fn (Get $get, ?Property $record): string => $followsDefault($get, $record) ? __('Follows the default') : __('Empty = base price'))
                ->disabled($followsDefault)
                // The default's weekend price in the currency of this form.
                ->placeholder(function (Get $get, ?Property $record, $livewire) use ($followsDefault): ?string {
                    $default = self::default();
                    $amount = Money::convert($default->weekend_price_cents, $default->priceCurrency(), self::chosenCurrency($livewire));

                    return $followsDefault($get, $record) && $amount !== null ? (string) ($amount / 100) : null;
                })
                ->visible(fn (Get $get, ?Property $record): bool => $kind($get, $record) === Property::WEEKEND_FIXED),
            TextInput::make('weekend_percentage')
                ->label(__('Weekend adjustment'))
                ->numeric()
                ->integer()
                ->minValue(-50)
                ->maxValue(100)
                ->suffix('%')
                ->required(fn (Get $get, ?Property $record): bool => ! $followsDefault($get, $record))
                ->disabled($followsDefault)
                ->placeholder(fn (Get $get, ?Property $record): ?string => $followsDefault($get, $record) ? (string) self::default()->weekend_percentage : null)
                ->live(onBlur: true)
                ->hint(fn (Get $get, ?Property $record): string => $followsDefault($get, $record) ? __('Follows the default') : __('E.g. 15 for 15% more, -10 for 10% less'))
                ->helperText(function (Get $get, ?Property $record, $livewire): ?string {
                    if (! is_numeric($get('weekend_percentage'))) {
                        return null;
                    }

                    // The base price as it currently is in the form (in whole units).
                    $base = is_numeric($get('base_price_cents'))
                        ? (int) round((float) $get('base_price_cents') * 100)
                        : ($record?->basePrice() ?? (int) self::default()->base_price_cents);
                    $weekend = (int) round($base * (100 + (int) $get('weekend_percentage')) / 100);

                    return __(':amount per night on Friday and Saturday (at the base price).', [
                        'amount' => Money::format($weekend, self::chosenCurrency($livewire)),
                    ]);
                })
                ->visible(fn (Get $get, ?Property $record): bool => $kind($get, $record) === Property::WEEKEND_PERCENT),
        ];
    }

    /**
     * How the guest checks in: by themselves, or welcomed in person by one of
     * the hosts (the same people as under Hosts, with name and photo). Empty =
     * follow the default; required on the defaults screen.
     *
     * @return array<int, Select>
     */
    private static function checkInFields(): array
    {
        return [
            Select::make('check_in_kind')
                ->label(__('Type of check-in'))
                ->options([
                    Property::CHECK_IN_SELF => __('Self check-in (key box, code or smart lock)'),
                    Property::CHECK_IN_WELCOME => __('Welcomed in person'),
                ])
                ->placeholder(__('Follow the default'))
                ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
                ->required(fn (?Property $record) => (bool) $record?->is_master)
                ->live()
                ->native(false),
            Select::make('check_in_host_id')
                ->label(__('Who welcomes the guest?'))
                ->relationship('welcomedBy', 'name', fn ($query) => $query->orderBy('name'))
                ->preload()
                ->placeholder(__('No name on the site'))
                ->hint(__('Not in the list yet? Add the person with the + button, for example a family member.'))
                ->native(false)
                // A new host (name, photo, text) right here, without leaving the accommodation.
                ->createOptionForm(fn (): array => HostResource::profileFields())
                ->createOptionModalHeading(fn (): string => __('filament-actions::create.single.modal.heading', ['label' => HostResource::getModelLabel()]))
                ->createOptionAction(fn (Action $action) => $action->visible(fn (): bool => HostResource::canCreate()))
                ->visible(fn (Get $get): bool => $get('check_in_kind') === Property::CHECK_IN_WELCOME),
            ...self::accessCodeFields(),
        ];
    }

    /**
     * The door code for self check-in, for the "Access code" merge field in the
     * automatic mails: a fixed code or the last four digits of the guest's
     * phone number. Only with self check-in (own choice or the default).
     *
     * @return array<int, Select|TextInput>
     */
    private static function accessCodeFields(): array
    {
        $selfCheckIn = fn (Get $get, ?Property $record): bool => ($get('check_in_kind') ?? ($record?->is_master ? null : Property::master()->check_in_kind)) === Property::CHECK_IN_SELF;

        return [
            Select::make('access_code_kind')
                ->label(__('Access code'))
                ->options([
                    Property::ACCESS_NONE => __('No code'),
                    Property::ACCESS_FIXED => __('Fixed code'),
                    Property::ACCESS_PHONE => __('Last 4 digits of the guest\'s phone number'),
                ])
                ->placeholder(__('Follow the default'))
                ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
                ->required(fn (?Property $record) => (bool) $record?->is_master)
                ->helperText(fn (Get $get): ?string => $get('access_code_kind') === Property::ACCESS_PHONE
                    ? __('The phone number becomes required on the booking form.')
                    : null)
                ->live()
                ->native(false)
                ->visible($selfCheckIn),
            TextInput::make('access_code')
                ->label(__('Code'))
                ->password()
                ->revealable()
                ->maxLength(50)
                ->required()
                ->hint(__('Put it in a mail with the merge field "Access code".'))
                ->visible(fn (Get $get, ?Property $record): bool => $selfCheckIn($get, $record) && $get('access_code_kind') === Property::ACCESS_FIXED),
        ];
    }

    /**
     * Own check-in details, such as a WiFi password or a parking code. On the
     * defaults screen you name the fields (with a default value); on an
     * accommodation you fill in its own value. Each field becomes a merge field
     * in the automatic mails.
     */
    private static function checkInDetails(): Section
    {
        $fields = Property::checkInFieldNames();

        return Section::make(__('Check-in details'))
            ->description(__('Codes and details for the automatic mails, for example a WiFi password. Insert them in a mail text with {{.'))
            ->components([
                Repeater::make('check_in_fields')
                    ->label(__('Check-in details'))
                    ->hiddenLabel()
                    ->schema([
                        Hidden::make('key'),
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(60),
                        TextInput::make('value')
                            ->label(__('Default value'))
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('Add field'))
                    ->defaultItems(0)
                    ->visible(fn (?Property $record) => (bool) $record?->is_master),
                Grid::make(2)
                    ->schema(collect($fields)->map(fn (string $name, string $key) => TextInput::make("check_in_values.{$key}")
                        ->label($name)
                        ->maxLength(255)
                        ->placeholder(fn () => Property::master()->checkInValue($key))
                        ->hint(__('Empty = follow the default')))->values()->all())
                    ->visible(fn (?Property $record) => ! $record?->is_master),
                Text::make(__('No fields yet. Add them on the defaults screen.'))
                    ->visible(fn (?Property $record) => ! $record?->is_master && $fields === []),
            ]);
    }

    /**
     * Discount on the price of the dynamic pricing service, for example the
     * surcharge set up there for the Airbnb or Vrbo commission, which does not
     * belong on the own booking site. Empty = follow the default; required on
     * the defaults screen. A price rule, so only with that permission.
     *
     * @return array<int, Select|TextInput>
     */
    private static function dynamicDiscountFields(): array
    {
        $paymentRules = self::withPaymentRules();
        $kind = fn (Get $get, ?Property $record, string $wanted): bool => $paymentRules($record) && $get('dynamic_discount_kind') === $wanted;

        return [
            Select::make('dynamic_discount_kind')
                ->label(__('Discount on the dynamic price'))
                ->options([
                    Property::DISCOUNT_NONE => __('No discount'),
                    Property::DISCOUNT_PERCENT => __('Percentage off'),
                    Property::DISCOUNT_FIXED => __('Fixed amount off per night'),
                ])
                ->hint(__('For example the surcharge for Airbnb or Vrbo that does not belong on your own site'))
                ->placeholder(__('Follow the default'))
                ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
                ->required(fn (?Property $record) => (bool) $record?->is_master)
                ->live()
                ->native(false)
                ->visible($paymentRules),
            TextInput::make('dynamic_discount_percentage')
                ->label(__('Discount'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01)
                ->suffix('%')
                ->required()
                ->hint(__('E.g. 18 for 18% off the dynamic price'))
                ->visible(fn (Get $get, ?Property $record): bool => $kind($get, $record, Property::DISCOUNT_PERCENT)),
            self::euroInput('dynamic_discount_cents', __('Discount per night'))
                ->minValue(0)
                ->required()
                ->visible(fn (Get $get, ?Property $record): bool => $kind($get, $record, Property::DISCOUNT_FIXED)),
        ];
    }

    /**
     * The currency of all amounts of this accommodation. Empty = the default;
     * required on the defaults screen. Warns if the payment service cannot
     * settle in it.
     */
    private static function currencyField(): Select
    {
        return Select::make('currency')
            ->label(__('Currency'))
            ->options(fn (): array => Currencies::options())
            ->searchable()
            ->native(false)
            ->live()
            ->placeholder(fn (): string => __('Follow the default (:currency)', ['currency' => Money::defaultCurrency()]))
            ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
            ->required(fn (?Property $record) => (bool) $record?->is_master);
    }

    /**
     * Explanation of the currency: what it means, and a warning if the
     * configured payment service cannot settle in it.
     */
    private static function currencyExplanation(mixed $livewire): string
    {
        $currency = self::chosenCurrency($livewire);

        if (! app(PaymentProvider::class)->supportsCurrency($currency)) {
            return __('Note: the configured payment provider cannot charge in :currency. Guests will not be able to pay online; choose Stripe in Settings or a different currency.', ['currency' => $currency]);
        }

        return __('All amounts for this accommodation are in this currency. Existing amounts are not converted if you change the currency.');
    }

    /** The currency currently chosen in the form, otherwise that of the default. */
    private static function chosenCurrency(mixed $livewire): string
    {
        $chosen = data_get($livewire, 'data.currency');

        return strtoupper((string) ($chosen ?: Money::defaultCurrency()));
    }

    /** Where the short description reappears; for the info icon next to the field. */
    private static function shortDescriptionExplanation(): string
    {
        return __('Not shown as text on the accommodation page. Used as the description in Google and when sharing via WhatsApp or Facebook (if you leave the SEO description under Presentation & SEO empty), as background for the AI chat, and below the name on the homepage in the "fullscreen" layout.');
    }

    /** Name, slug and descriptions: one language tab per language, so everything of that language together. */
    private static function textsSection(): Section
    {
        return TranslationFields::languageSection(
            __('Name and description'),
            __('Choose a language: all its texts are together there. Empty = the English text. The slug is created automatically and opens the page in that language.'),
            [
                'name' => fn (string $path, string $locale) => TextInput::make($path)
                    ->label(__('Name'))
                    ->required($locale === Languages::SOURCE)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => $locale === Languages::SOURCE
                        ? $set('slug', Str::slug((string) $state))
                        : TranslationFields::fillEmptySlug($locale, $state, $get, $set)),
                'slug' => fn (string $path, string $locale) => TranslationFields::slugField($path, $locale),
                'short_description' => fn (string $path) => TextInput::make($path)
                    ->label(__('Short description'))
                    ->hintIcon(Heroicon::OutlinedInformationCircle, self::shortDescriptionExplanation())
                    ->maxLength(255)
                    ->columnSpanFull(),
                'description' => fn (string $path, string $locale) => RichEditors::forSite(RichEditor::make($path))
                    ->label(__('Description'))
                    ->when($locale === Languages::SOURCE, fn (RichEditor $field) => $field
                        ->hint(__('Let AI write the texts based on the filled-in fields'))
                        ->hintAction(self::aiTextAction()))
                    ->columnSpanFull(),
            ],
            ['name', 'short_description', 'description'],
            columns: 2,
        );
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('accommodation')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('Property'))
                            ->icon(Heroicon::OutlinedHomeModern)
                            ->columns(2)
                            ->components([
                                self::textsSection()->columnSpanFull(),
                                Select::make('type')
                                    ->label(__('Type'))
                                    ->options(fn () => \App\Models\PropertyType::options())
                                    ->createOptionForm(self::typeCreationFields())
                                    ->createOptionUsing(fn (array $data) => \App\Models\PropertyType::create($data)->slug)
                                    // A new type is for all accommodations: only with the right to create types.
                                    ->createOptionAction(fn (Action $action) => $action->visible(fn (): bool => \App\Filament\Resources\PropertyTypes\PropertyTypeResource::canCreate()))
                                    ->required(),
                                Switches::direct(
                                    Toggle::make('active')
                                        ->label(__('Active (bookable and visible on the site)'))
                                        ->default(true)
                                        ->inline(false),
                                    // For a new accommodation the state is saved along with Create.
                                    fn (bool $on, object $livewire): bool => $livewire instanceof EditRecord
                                        && PropertyResource::canEdit($livewire->getRecord())
                                        && $livewire->getRecord()->update(['active' => $on]),
                                ),
                                TextInput::make('bedrooms')
                                    ->label(__('Bedrooms'))
                                    ->numeric(),
                                TextInput::make('beds')
                                    ->label(__('Beds'))
                                    ->numeric(),
                                TextInput::make('sofa_beds')
                                    ->label(__('Sofa beds'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(255),
                                TextInput::make('bathrooms')
                                    ->label(__('Bathrooms'))
                                    ->numeric(),
                                self::amenitiesPicker(),
                                LivewireComponent::make(AccommodationAmenities::class, fn (Property $record) => ['property' => $record])
                                    ->key('amenities_order')
                                    ->visible(fn (?Property $record) => $record !== null && ! $record->is_master)
                                    ->columnSpanFull(),
                                ...self::locationHostFields(),
                            ]),

                        Tab::make(__("Photos"))
                            ->id('photos')
                            ->key('photos')
                            ->icon(Heroicon::OutlinedPhoto)
                            ->visible(fn (?Property $record) => $record !== null && ! $record->is_master)
                            ->components([
                                LivewireComponent::make(\App\Livewire\AccommodationPhotos::class, fn (Property $record) => ['property' => $record]),
                            ]),

                        self::pricesTab(),

                        Tab::make(__('Calendar'))
                            ->id('calendar')
                            ->key('calendar')
                            ->icon(Heroicon::OutlinedCalendarDays)
                            ->visible(fn (?Property $record) => $record !== null && ! $record->is_master)
                            ->schema([
                                LivewireComponent::make(NightlyPricesCalendar::class, fn (Property $record) => ['property' => $record]),
                            ]),

                        self::calendarRulesTab(),
                        self::paymentsTab(),
                        self::mailsTab(),
                        self::presentationSeoTab(),
                    ]),
            ]);
    }

    /**
     * Quick setup wizard: a property online in a few minutes. Only the
     * essentials in steps; the rest falls back to the defaults and can be refined
     * later in the full tab form.
     *
     * @return array<int, Step>
     */
    public static function wizardSteps(): array
    {
        return [
            Step::make(__('Property'))
                ->icon(Heroicon::OutlinedHomeModern)
                ->description(__('The basics'))
                ->columns(2)
                ->schema([
                    TextInput::make('import_url')
                        ->label(__('Quick-fill from a link (optional)'))
                        ->placeholder('https://... (Airbnb / Vrbo / Booking)')
                        ->url()
                        ->columnSpanFull()
                        ->dehydrated(false)
                        ->helperText(__('Fetches what the page makes public. Photos are added on save. Complete the rest below.'))
                        ->suffixAction(
                            Action::make('fetch')
                                ->label(__('Fetch'))
                                ->icon(Heroicon::OutlinedArrowDownTray)
                                ->action(function (callable $get, callable $set) {
                                    $url = trim((string) $get('import_url'));

                                    if ($url === '') {
                                        Notification::make()->title(__('Paste a link first.'))->warning()->send();

                                        return;
                                    }

                                    try {
                                        $draft = app(ListingImportProvider::class)->import($url);
                                    } catch (ListingImportException $e) {
                                        Notification::make()->title(__('Import failed'))->body($e->getMessage())->danger()->send();

                                        return;
                                    } catch (\Throwable $e) {
                                        report($e);
                                        Notification::make()->title(__('Import failed'))->body(__('Something went wrong while fetching. Try again later or enter the property manually.'))->danger()->send();

                                        return;
                                    }

                                    // Only fill in what was found; do not overwrite anything with empty.
                                    foreach ([
                                        'name' => $draft->name,
                                        'short_description' => $draft->shortDescription,
                                        'description' => $draft->description,
                                        'city' => $draft->city,
                                        'country' => $draft->country,
                                        'max_guests' => $draft->maxGuests,
                                        'latitude' => $draft->latitude,
                                        'longitude' => $draft->longitude,
                                    ] as $field => $value) {
                                        if ($value !== null) {
                                            $set($field, $value);
                                        }
                                    }

                                    if ($draft->basePriceCents !== null) {
                                        $set('base_price_cents', $draft->basePriceCents / 100); // euroInput expects euros
                                    }

                                    $set('import_photo_urls', $draft->photoUrls);

                                    $countPhotos = count($draft->photoUrls);
                                    Notification::make()
                                        ->title(__('Data fetched — review and complete.'))
                                        ->body($countPhotos > 0
                                            ? trans_choice('{1} 1 photo will be added when saving.|[2,*] :count photos will be added when saving.', $countPhotos)
                                            : __('No photos found; add them yourself in the next step.'))
                                        ->success()
                                        ->send();
                                }),
                        ),
                    Hidden::make('import_photo_urls'),
                    Hidden::make('latitude'),
                    Hidden::make('longitude'),
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->columnSpanFull(),
                    Select::make('type')
                        ->label(__('Type'))
                        ->options(fn () => \App\Models\PropertyType::options())
                        ->createOptionForm(self::typeCreationFields())
                        ->createOptionUsing(fn (array $data) => \App\Models\PropertyType::create($data)->slug)
                        ->default('holiday_home')
                        ->required(),
                    TextInput::make('max_guests')
                        ->label(__('Max. guests'))
                        ->numeric()
                        ->minValue(1)
                        ->default(2)
                        ->required(),
                    TextInput::make('city')
                        ->label(__('City')),
                    Select::make('country')
                        ->label(__('Country'))
                        ->options(fn () => \App\Support\Countries::options())
                        ->searchable()
                        ->default('NL'),
                ]),

            Step::make(__("Photos"))
                ->icon(Heroicon::OutlinedPhoto)
                ->description(__('Drag to reorder'))
                ->schema([
                    SpatieMediaLibraryFileUpload::make('photos')
                        ->label('')
                        ->collection('photos')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        // Named formats, not image/*: that would let an SVG (with a script) in.
                        ->acceptedFileTypes([...Property::PHOTO_MIMES, ...Property::VIDEO_MIMES])
                        ->maxFiles(Property::MAX_PHOTOS)
                        ->maxSize(Property::MAX_VIDEO_KB)
                        // The upper limit above is the video one; a photo keeps its own limit.
                        ->rule(fn (): Closure => function (string $attribute, mixed $file, Closure $fail): void {
                            if ($file instanceof \Illuminate\Http\UploadedFile
                                && ! str_starts_with((string) $file->getMimeType(), 'video/')
                                && $file->getSize() > Property::MAX_PHOTO_KB * 1024) {
                                $fail(__('validation.max.file', ['attribute' => $file->getClientOriginalName(), 'max' => Property::MAX_PHOTO_KB]));
                            }
                        })
                        ->panelLayout('grid')
                        ->hint(Property::photoHint()),
                ]),

            Step::make(__('Price'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->description(__('Empty = standard price'))
                ->columns(3)
                ->schema([
                    self::currencyField()->columnSpanFull()->helperText(fn ($livewire): string => self::currencyExplanation($livewire)),
                    self::euroInput('base_price_cents', __('Base price per night'))
                        ->hint(__('Empty = standard price')),
                    self::euroInput('cleaning_fee_cents', __('Cleaning fee')),
                    self::euroInput('deposit_cents', __('Deposit')),
                ]),

            Step::make(__('Description & amenities'))
                ->icon(Heroicon::OutlinedSparkles)
                ->schema([
                    TextInput::make('short_description')
                        ->label(__('Short description'))
                        ->hintIcon(Heroicon::OutlinedInformationCircle, self::shortDescriptionExplanation())
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->hint(__('Let AI write the texts based on the filled-in fields'))
                        ->hintAction(self::aiTextAction())
                        ->rows(5),
                    self::amenitiesPicker(),
                    Toggle::make('active')
                        ->label(__('Publish now (visible and bookable on the site)'))
                        ->default(true),
                ]),
        ];
    }

    /**
     * Reusable: the same prices tab for accommodations and for the
     * default settings (the master record).
     */
    public static function pricesTab(): Tab
    {
        return Tab::make(__('Prices'))
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->components([
                                Section::make(__('Prices'))
                                    // Fixed key: otherwise Filament derives it from the translated heading.
                                    ->key('prices')
                                    ->description(fn ($livewire): string => __('Empty fields fall back to the default settings.').' '.self::currencyExplanation($livewire))
                                    // The currency in the heading: this way base price and weekend adjustment sit side by side.
                                    ->afterHeader([
                                        self::currencyField()->hiddenLabel()->prefix(__('Currency')),
                                    ])
                                    ->columns(3)
                                    ->components([
                                        self::euroInput('base_price_cents', __('Base price per night')),
                                        ...self::weekendFields(),
                                        self::euroInput('cleaning_fee_cents', __('Cleaning fee (one-off)')),
                                        self::euroInput('tourist_tax_cents', __('Tourist tax')),
                                        self::inheritChoice('tourist_tax_basis', __('Tourist tax charged'), [
                                            Property::TAX_PER_PERSON_NIGHT => __('Per person per night'),
                                            Property::TAX_PER_NIGHT => __('Per night'),
                                            Property::TAX_PER_STAY => __('Once per stay'),
                                        ]),
                                        TextInput::make('included_guests')
                                            ->label(__('Guests included in the price'))
                                            ->numeric()
                                            ->minValue(1)
                                            // On an accommodation empty follows the default; only without a default there is no fee.
                                            ->placeholder(fn (?Property $record) => $record?->is_master ? null : self::default()->included_guests)
                                            ->hint(fn (?Property $record) => $record?->is_master || self::default()->included_guests === null
                                                ? __('Empty = no extra guest fee')
                                                : __('Empty = follow the default')),
                                        self::euroInput('extra_guest_fee_cents', __('Fee per extra guest per night')),
                                        self::euroInput('pet_fee_cents', __('Pet fee'))
                                            ->hint(__('One-time, per pet')),
                                    ]),

                                Section::make(__('Discounts'))
                                    ->description(__('Automatic discount on the stay (not on cleaning and taxes). For length of stay, the highest threshold reached counts. A coupon code comes on top.'))
                                    ->components([
                                        self::inheritToggle('discounts_add_up', __('Combine discounts'), __('Yes: if several apply at once (e.g. weekly and early bird), the guest gets all of them. No: only the best one.')),
                                        Repeater::make('discounts')
                                            ->relationship()
                                            ->label(__('Discounts'))
                                            ->hiddenLabel()
                                            ->columns(4)
                                            ->defaultItems(0)
                                            ->collapsible()
                                            ->addActionLabel(__('Add discount'))
                                            // In the heading of each discount: what it is about.
                                            ->itemLabel(fn (array $state): string => collect([
                                                filled($state['name'] ?? null) ? $state['name'] : (Discount::kindOptions()[$state['kind'] ?? Discount::STAY_DURATION] ?? null),
                                                Discount::condition($state),
                                            ])->filter()->implode(' · '))
                                            ->components([
                                                TranslationFields::languageSection(
                                                    __('Name (optional)'),
                                                    __('Visible to guests in the price breakdown, e.g. "Weekly discount (10%)". Empty = a description of the type.'),
                                                    ['name' => fn (string $path) => TextInput::make($path)->hiddenLabel()->placeholder(__('E.g. Weekly discount'))->maxLength(80)],
                                                    ['name'],
                                                )->contained(false)->columnSpanFull(),
                                                Select::make('kind')
                                                    ->label(__('Discount type'))
                                                    ->options(fn (): array => Discount::kindOptions())
                                                    ->default(Discount::STAY_DURATION)
                                                    ->selectablePlaceholder(false)
                                                    ->required()
                                                    ->live()
                                                    ->native(false),
                                                TextInput::make('min_nights')
                                                    ->label(__('From number of nights'))
                                                    ->helperText(__('E.g. 7 for a weekly discount, 28 for a monthly discount.'))
                                                    ->numeric()
                                                    ->integer()
                                                    ->minValue(2)
                                                    ->required()
                                                    ->visible(fn (Get $get): bool => ($get('kind') ?? Discount::STAY_DURATION) === Discount::STAY_DURATION),
                                                TextInput::make('days_before_arrival')
                                                    ->label(__('Booked at most days before arrival'))
                                                    ->helperText(__('E.g. 14: applies to bookings made 0 to 14 days before arrival.'))
                                                    ->numeric()
                                                    ->integer()
                                                    ->minValue(0)
                                                    ->maxValue(90)
                                                    ->required()
                                                    ->visible(fn (Get $get): bool => $get('kind') === Discount::LASTMINUTE),
                                                TextInput::make('months_before_arrival')
                                                    ->label(__('Booked at least months before arrival'))
                                                    ->helperText(__('E.g. 2: applies to bookings made 2 months or more before arrival.'))
                                                    ->numeric()
                                                    ->integer()
                                                    ->minValue(1)
                                                    ->maxValue(24)
                                                    ->required()
                                                    ->visible(fn (Get $get): bool => $get('kind') === Discount::EARLY_BOOKING),
                                                Select::make('type')
                                                    ->label(__('Percentage or fixed amount'))
                                                    ->options([
                                                        Discount::TYPE_PERCENT => __('Percentage of the stay'),
                                                        Discount::TYPE_FIXED => __('Fixed amount'),
                                                    ])
                                                    ->default(Discount::TYPE_PERCENT)
                                                    ->selectablePlaceholder(false)
                                                    ->required()
                                                    ->live()
                                                    ->native(false),
                                                TextInput::make('percentage')
                                                    ->label(__('Discount %'))
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->maxValue(100)
                                                    ->suffix('%')
                                                    ->required()
                                                    ->visible(fn (Get $get): bool => $get('type') !== Discount::TYPE_FIXED),
                                                self::euroInput('amount_cents', __('Discount (amount)'))
                                                    ->helperText(__('Once for the whole stay, not per night.'))
                                                    ->minValue(0.01)
                                                    ->required()
                                                    ->visible(fn (Get $get): bool => $get('type') === Discount::TYPE_FIXED),
                                            ]),
                                    ]),

                                Section::make(__('Seasons'))
                                    ->description(__('Season prices take precedence over the base price. Each night is matched to its season.'))
                                    ->components([
                                        Repeater::make('rateSeasons')
                                            ->relationship()
                                            ->label(__('Seasons'))
                                            ->hiddenLabel()
                                            ->columns(5)
                                            ->defaultItems(0)
                                            ->addActionLabel(__('Add season'))
                                            ->components([
                                                TextInput::make('name')
                                                    ->label(__('Name'))
                                                    ->required(),
                                                DatePicker::make('start_date')
                                                    ->label(__('From'))
                                                    ->required(),
                                                DatePicker::make('end_date')
                                                    ->label(__('Up to and including'))
                                                    ->required()
                                                    ->afterOrEqual('start_date'),
                                                self::euroInput('price_cents', __('Price per night'))
                                                    ->required(),
                                                self::euroInput('weekend_price_cents', __('Weekend price'))
                                                    ->hint(__('Empty = season price, with the weekend adjustment if it is a percentage')),
                                            ]),
                                    ]),

                                Section::make(__('Dynamic pricing'))
                                    ->description(__('Fetches recommended nightly prices every day and puts them in the calendar. Enter the API key under Settings → Keys.'))
                                    ->components([
                                        Select::make('pricing_service')
                                            ->label(__('Dynamic pricing service'))
                                            ->options(fn () => PricingServices::options())
                                            ->placeholder(__('Follow the default'))
                                            ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
                                            ->required(fn (?Property $record) => (bool) $record?->is_master)
                                            ->native(false)
                                            ->visible(self::withPaymentRules()),
                                        TextInput::make('external_listing_id')
                                            ->label(__('Listing ID at that service'))
                                            ->hint(__('Empty = no dynamic pricing for this property'))
                                            ->visible(fn (?Property $record) => ! $record?->is_master),
                                        ...self::dynamicDiscountFields(),
                                        self::inheritToggle('manual_prices_win', __('Manual prices go before the dynamic price'), __('Yes: a price you set yourself in the calendar stays. No: the next update overwrites it.'))
                                            ->visible(self::withPaymentRules()),
                                        self::inheritToggle('prices_in_calendar', __('Show the price per night in the calendar'), __('Like flight prices — the price is shown on each calendar day')),
                                    ]),

                            ]);
    }

    /**
     * Reusable: the same calendar/rules tab for accommodations and for the
     * default settings (the master record).
     */
    public static function calendarRulesTab(): Tab
    {
        return Tab::make(__('Rules'))
                            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                            ->components([
                                Section::make(__('Stay length & booking window'))
                                    ->description(__('Hard limits that always apply, regardless of the booking rules below. The maximum term prevents a long stay from legally counting as residential tenancy — common advice is 28 days at most.'))
                                    ->columns(3)
                                    ->components([
                                        TextInput::make('min_stay_nights')
                                            ->label(__('Min. nights per booking'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->hint(__('Empty = no minimum')),
                                        TextInput::make('max_stay_nights')
                                            ->label(__('Max. nights per booking'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->hint(__('Empty = no limit')),
                                        Select::make('booking_window_preference')
                                            ->label(__('Calendar open until'))
                                            ->options([
                                                '3' => __(':count months ahead', ['count' => 3]),
                                                '6' => __(':count months ahead', ['count' => 6]),
                                                '12' => __(':count months ahead', ['count' => 12]),
                                                'adjusted' => __('Custom…'),
                                                'unlimited' => __('Unlimited'),
                                            ])
                                            ->native(false)
                                            ->live()
                                            ->dehydrated(false)
                                            ->formatStateUsing(function ($state, ?Property $record) {
                                                $months = $record?->booking_window_months;

                                                return $months === null
                                                    ? 'unlimited'
                                                    : (in_array((int) $months, [3, 6, 12], true) ? (string) $months : 'adjusted');
                                            })
                                            ->afterStateUpdated(function (?string $state, callable $set) {
                                                if ($state === 'unlimited') {
                                                    $set('booking_window_months', null);
                                                } elseif ($state !== 'adjusted' && $state !== null) {
                                                    $set('booking_window_months', (int) $state);
                                                }
                                            })
                                            ->hint(__('How far ahead guests can book')),
                                        TextInput::make('booking_window_months')
                                            ->label(__('Number of months ahead'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->suffix(__('months'))
                                            ->visible(fn (callable $get) => $get('booking_window_preference') === 'adjusted'),
                                    ]),

                                // What the house sleeps: its own number, so not on the defaults screen.
                                Section::make(__('Guests'))
                                    ->visible(fn (?Property $record) => ! $record?->is_master)
                                    ->columns(3)
                                    ->components([
                                        TextInput::make('max_guests')
                                            ->label(__('Max. guests'))
                                            ->required()
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(2),
                                    ]),

                                Section::make(__('Pets and infants'))
                                    ->description(__('May guests bring pets, and how many pets and infants may come along? Infants don\'t count towards the maximum number of guests. Set any pet fee under Prices.'))
                                    ->columns(3)
                                    ->components([
                                        self::inheritToggle('pets_allowed', __('Pets allowed')),
                                        self::defaultNumber('max_pets', __('Maximum number of pets'))
                                            ->maxValue(20)
                                            ->visible(fn (Get $get, ?Property $record): bool => $get('pets_allowed') !== '0'),
                                        self::defaultNumber('max_babies', __('Maximum number of infants'))
                                            ->maxValue(20),
                                    ]),

                                Section::make(__('Booking rules'))
                                    ->description(__('Rules without a period always apply; rules with a period only for arrivals within that period.'))
                                    ->components([
                                        Repeater::make('bookingRules')
                                            ->relationship()
                                            ->hiddenLabel()
                                            ->columns(3)
                                            ->defaultItems(0)
                                            ->addActionLabel(__('Add rule'))
                                            ->components([
                                                TranslationFields::languageSection(
                                                    __('Name (optional)'),
                                                    __('Visible to guests: shown before the message, e.g. "High season: minimum stay is 7 nights".'),
                                                    ['name' => fn (string $path) => TextInput::make($path)->hiddenLabel()->placeholder(__('E.g. High season'))],
                                                    ['name'],
                                                )->contained(false)->columnSpanFull(),
                                                DatePicker::make('start_date')
                                                    ->label(__('Valid from')),
                                                DatePicker::make('end_date')
                                                    ->label(__('Valid until'))
                                                    ->afterOrEqual('start_date'),
                                                TextInput::make('min_nights')
                                                    ->label(__('Min. nights'))
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->default(1)
                                                    ->required(),
                                                TextInput::make('max_nights')
                                                    ->label(__('Max. nights'))
                                                    ->numeric()
                                                    ->minValue(1),
                                                Select::make('arrival_days')
                                                    ->label(__('Arrival only on'))
                                                    ->multiple()
                                                    ->options(self::weekdays())
                                                    ->placeholder(__('All days')),
                                                Select::make('departure_days')
                                                    ->label(__('Departure only on'))
                                                    ->multiple()
                                                    ->options(self::weekdays())
                                                    ->placeholder(__('All days')),
                                            ]),
                                    ]),

                                Section::make(__('Rental conditions'))
                                    ->key('rental_terms_section')
                                    ->description(__('The guest must accept this text when booking (together with the house rules and cancellation policy). The accepted text is stored with the booking as proof.'))
                                    ->headerActions([TranslationFields::uploadAction('rental_terms')])
                                    ->components([
                                        TextInput::make('rental_terms_min_nights')
                                            ->label(__('Apply from'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->suffix(__('nights'))
                                            ->placeholder(fn (?Property $record) => $record?->is_master
                                                ? '0'
                                                : (string) ((int) (Property::master()->rental_terms_min_nights ?? 0)))
                                            ->hint(fn (?Property $record) => $record?->is_master
                                                ? __('0 or empty = for every booking')
                                                : __('Empty = follow the default'))
                                            ->helperText(__('For a shorter stay, only the house rules and cancellation policy apply, and the guest does not need to sign.')),
                                        TranslationFields::languageSection(
                                            __('Text'),
                                            __('Choose a language. Empty = the English text.'),
                                            ['rental_terms' => fn (string $path) => Textarea::make($path)->hiddenLabel()->rows(6)],
                                            ['rental_terms'],
                                        )->contained(false),
                                    ]),

                                Section::make(__('ID verification'))
                                    ->columns(2)
                                    ->components([
                                        self::inheritToggle('id_required', __('Ask guests to verify their identity'), __('Via Stripe Identity — requires a Stripe secret key; cost per verification'))
                                            ->columnSpanFull()
                                            ->live(),
                                        self::inheritChoice('id_moment', __('When'), [
                                            Property::ID_BEFORE_PAYMENT => __('Before the payment'),
                                            Property::ID_AFTER_PAYMENT => __('After the payment, before arrival'),
                                        ])
                                            ->live()
                                            ->visible(fn (Get $get, ?Property $record): bool => self::idCheckOn($get, $record)),
                                        self::defaultNumber('id_deadline_days', __('Done at the latest: days before arrival'))
                                            ->visible(fn (Get $get, ?Property $record): bool => self::idCheckAfterPayment($get, $record)),
                                        self::defaultNumber('id_reminder_days', __('Reminder: days before that date'))
                                            ->helperText(__('0 = no reminder.'))
                                            ->visible(fn (Get $get, ?Property $record): bool => self::idCheckAfterPayment($get, $record)),
                                        self::inheritChoice('id_overdue_action', __('Not done by then'), [
                                            Property::ID_OVERDUE_NOTIFY => __('Notify me, I decide'),
                                            Property::ID_OVERDUE_CANCEL => __('Cancel the booking automatically'),
                                        ])
                                            ->live()
                                            ->visible(fn (Get $get, ?Property $record): bool => self::idCheckAfterPayment($get, $record)),
                                        self::inheritChoice('id_cancel_refund', __('Refund when cancelled'), [
                                            Property::ID_REFUND_FULL => __('Everything (stay and deposit)'),
                                            Property::ID_REFUND_DEPOSIT => __('Only the deposit'),
                                            Property::ID_REFUND_MANUAL => __('I decide myself (via Payments)'),
                                        ])
                                            ->visible(fn (Get $get, ?Property $record): bool => self::idCheckAfterPayment($get, $record)
                                                && self::formOrDefault($get, $record, 'id_overdue_action') === Property::ID_OVERDUE_CANCEL),
                                    ]),

                                Section::make(__('Cancellation policy'))
                                    ->columns(2)
                                    ->components([
                                        Select::make('cancellation_policy')
                                            ->label(__('Policy'))
                                            ->options(\App\Enums\CancellationPolicy::class)
                                            ->default(\App\Enums\CancellationPolicy::Moderate)
                                            ->live(),
                                        Placeholder::make('cancellation_preview')
                                            ->label(__('What this policy means'))
                                            ->columnSpanFull()
                                            ->content(function (callable $get, ?Property $record) {
                                                $value = $get('cancellation_policy');
                                                $policy = $value instanceof \App\Enums\CancellationPolicy
                                                    ? $value
                                                    : \App\Enums\CancellationPolicy::tryFrom((string) $value);

                                                if ($policy === null) {
                                                    return null;
                                                }

                                                if ($policy === \App\Enums\CancellationPolicy::Custom) {
                                                    return __('Your own text from the field below is shown to the guest.');
                                                }

                                                return new \Illuminate\Support\HtmlString(
                                                    implode('<br>', array_map(fn (string $rule) => '• '.e($rule), ($record ?? self::default())->cancellationRules($policy)))
                                                );
                                            })
                                            ->visible(fn (callable $get) => filled($get('cancellation_policy'))),
                                        TranslationFields::languageSection(
                                            __('Custom or additional conditions'),
                                            __('Each line is shown as a separate bullet'),
                                            ['cancellation_policy_text' => fn (string $path, string $locale) => Textarea::make($path)
                                                ->hiddenLabel()
                                                ->rows(3)
                                                ->when($locale === Languages::SOURCE, fn (Textarea $field) => $field
                                                    ->requiredIf('cancellation_policy', \App\Enums\CancellationPolicy::Custom->value))],
                                            ['cancellation_policy_text'],
                                        )->contained(false)->columnSpanFull(),
                                        Section::make(__('Text of the fixed policies'))
                                            ->key('fixed_policy_texts')
                                            ->description(fn (?Property $record) => $record?->is_master
                                                ? __('Adjust the text of the fixed policies, per language. Each line becomes a separate point. Empty = the built-in default text.')
                                                : __('Adjust the text of the fixed policies, per language. Each line becomes a separate point. Empty = the text of the default.'))
                                            ->collapsed()
                                            ->columnSpanFull()
                                            ->headerActions([
                                                TranslationFields::fillLanguageListsAction('translate_cancellation', array_map(
                                                    fn (CancellationPolicy $policy) => "cancellation_texts.{$policy->value}",
                                                    self::fixedPolicyTypes(),
                                                )),
                                            ])
                                            ->components(self::languageLists(
                                                'cancellation',
                                                array_map(fn (CancellationPolicy $policy) => "cancellation_texts.{$policy->value}", self::fixedPolicyTypes()),
                                                function (string $path, string $locale) {
                                                    $policy = CancellationPolicy::from(explode('.', $path)[1]);

                                                    return Textarea::make($path)
                                                        ->label($policy->getLabel())
                                                        ->rows(3)
                                                        // The default shows the built-in text to edit; an
                                                        // accommodation shows it greyed out, because filling in means overriding.
                                                        ->formatStateUsing(fn ($state, ?Property $record) => $record?->is_master && blank($state)
                                                            ? self::cancellationText($policy, $locale)
                                                            : $state)
                                                        ->placeholder(fn (?Property $record) => $record?->is_master
                                                            ? null
                                                            : self::cancellationText($policy, $locale));
                                                },
                                            )),
                                    ]),

                                Section::make(__('House rules & times'))
                                    ->columns(3)
                                    ->components([
                                        TimePicker::make('check_in_from')
                                            ->label(__('Check-in from'))
                                            ->seconds(false),
                                        TimePicker::make('check_in_until')
                                            ->label(__('Check-in until'))
                                            ->seconds(false),
                                        TimePicker::make('check_out_until')
                                            ->label(__('Check-out (time)'))
                                            ->seconds(false),
                                        ...self::checkInFields(),
                                        // Codes and details for the check-in mails belong with the check-in, above the house rules.
                                        self::checkInDetails()->contained(false)->columnSpanFull(),
                                        TranslationFields::languageSection(
                                            __('House rules'),
                                            __('Choose a language. Empty = the English text.'),
                                            ['house_rules' => fn (string $path) => Textarea::make($path)->hiddenLabel()->rows(4)],
                                            ['house_rules'],
                                        )->contained(false)->columnSpanFull(),
                                    ]),
                            ]);
    }

    /** @return array<int, CancellationPolicy> */
    private static function fixedPolicyTypes(): array
    {
        return array_values(array_filter(CancellationPolicy::cases(), fn (CancellationPolicy $policy) => $policy !== CancellationPolicy::Custom));
    }

    /**
     * Reusable: the same payments tab for accommodations and for the
     * default settings (the master record).
     */
    public static function paymentsTab(): Tab
    {
        return Tab::make(__('Payments'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->components([
                Section::make(__('Payment by the guest'))
                    ->columns(2)
                    ->visible(self::withPaymentRules())
                    ->components([
                        // Showing and hiding happens in the browser: a round trip via the
                        // server costs seconds on this large form.
                        ToggleButtons::make('payment_method')
                            ->label(__('When does the guest pay?'))
                            ->options(fn (?Property $record) => array_filter([
                                self::PAYMENT_METHOD_FOLLOW => $record?->is_master ? null : __('Follow the default'),
                                self::ALL_AT_ONCE => __('Everything at once'),
                                self::DOWN_PAYMENT => __('Down payment and balance'),
                            ]))
                            ->inline()
                            ->required()
                            ->dehydrated(false)
                            ->formatStateUsing(function ($state, ?Property $record) {
                                $percentage = $record?->down_payment_percentage;

                                return match (true) {
                                    $percentage === null => self::PAYMENT_METHOD_FOLLOW,
                                    $percentage >= Property::FULL_PAYMENT => self::ALL_AT_ONCE,
                                    default => self::DOWN_PAYMENT,
                                };
                            })
                            ->columnSpanFull(),
                        Text::make(fn (): string => self::defaultPaymentMethod())
                            ->color('gray')
                            ->visibleJs(self::asPaymentMethod(self::PAYMENT_METHOD_FOLLOW))
                            ->columnSpanFull(),
                        Text::make(__('The guest pays the full amount immediately when booking.'))
                            ->color('gray')
                            ->visibleJs(self::asPaymentMethod(self::ALL_AT_ONCE))
                            ->columnSpanFull(),
                        Slider::make('down_payment_percentage')
                            ->label(__('Down payment amount'))
                            ->range(1, 99)
                            ->step(1)
                            ->decimalPlaces(0)
                            ->fillTrack([true, false])
                            ->tooltips(RawJs::make('`${Math.round($value)}%`'))
                            ->live(debounce: 300)
                            ->partiallyRenderAfterStateUpdated()
                            ->visibleJs(self::asPaymentMethod(self::DOWN_PAYMENT))
                            ->belowContent(fn (Get $get): string => self::downPaymentExplanation($get('down_payment_percentage')))
                            // The slider always shows a usable height, even if the choice above is different.
                            ->formatStateUsing(function (?Property $record) {
                                $percentage = (int) ($record?->down_payment_percentage ?? self::default()->down_payment_percentage);

                                return $percentage < Property::FULL_PAYMENT
                                    ? $percentage
                                    : Property::STARTWAARDEN['down_payment_percentage'];
                            })
                            ->dehydrateStateUsing(fn ($state, Get $get) => match ($get('payment_method')) {
                                self::PAYMENT_METHOD_FOLLOW => null,
                                self::ALL_AT_ONCE => Property::FULL_PAYMENT,
                                default => self::sliderValue($state),
                            })
                            ->columnSpanFull(),
                        self::defaultNumber('vat_percentage', __('VAT % (invoices)'))
                            ->maxValue(100)
                            ->suffix('%'),
                    ]),

                Section::make(__('Deposit'))
                    ->columns(2)
                    ->components([
                        self::euroInput('deposit_cents', __('Deposit')),
                        self::inheritToggle('deposit_auto_refund', __('Refund deposit automatically'), __('No = daily reminder by email'))
                            ->visible(self::withPaymentRules()),
                        Select::make('deposit_moment')
                            ->label(__('Collect the deposit'))
                            ->options([
                                Property::DEPOSIT_AT_BOOKING => __('Right after booking'),
                                Property::DEPOSIT_BEFORE_ARRIVAL => __('A number of days before arrival'),
                            ])
                            ->placeholder(__('Follow the default'))
                            ->selectablePlaceholder(fn (?Property $record) => ! $record?->is_master)
                            ->required(fn (?Property $record) => (bool) $record?->is_master)
                            ->hint(__('Always a separate payment, never part of the price.'))
                            ->live()
                            ->native(false)
                            ->visible(self::withPaymentRules()),
                        self::defaultNumber('deposit_days_before_arrival', __('Deposit request: days before arrival'))
                            // Only when the deposit is collected before arrival (own choice or the default).
                            ->visible(fn (Get $get, ?Property $record): bool => self::withPaymentRules()($record)
                                && ($get('deposit_moment') ?? ($record?->is_master ? null : Property::master()->deposit_moment)) !== Property::DEPOSIT_AT_BOOKING),
                        self::defaultNumber('deposit_days_after_departure', __('Refund: days after departure'))
                            ->visible(self::withPaymentRules()),
                    ]),
            ]);
    }

    /**
     * Reusable: the same mail tab for accommodations and for the
     * default settings (the master record).
     */
    public static function mailsTab(): Tab
    {
        return Tab::make(__('Automatic emails'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(self::withPaymentRules())
            ->components([
                Section::make(__('Schedule'))
                    ->columns(4)
                    ->headerActions([
                        Action::make('mail_schedule_info')
                            ->label(__('When does each email go out?'))
                            ->icon(Heroicon::OutlinedInformationCircle)
                            ->color('gray')
                            ->link()
                            ->modalHeading(__('When does each email go out?'))
                            ->modalContent(view('filament.properties.mail-schedule'))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(__('Close')),
                    ])
                    ->components([
                        self::defaultNumber('balance_payment_days_before_arrival', __('Balance payment: days before arrival')),
                        self::defaultNumber('checkin_days_before_arrival', __('Check-in email: days before arrival')),
                        self::defaultNumber('checkout_days_before_departure', __('Check-out email: days before departure')),
                        self::defaultNumber('review_days_after_departure', __('Review request: days after departure')),
                    ]),

                Section::make(__('Custom texts'))
                    ->key('own_texts')
                    ->description(fn (?Property $record) => ($record?->is_master
                        ? __('This text is added at the top of the email, below the greeting, in the guest\'s language. Empty = default text only.')
                        : __('This text is added at the top of the email in question, below the greeting, in the guest’s language. Empty = the custom text of the default.'))
                        .' '.__('Type {{ to insert a field, such as the guest\'s name or the arrival date, or pick one with the toolbar button. In the email it shows the booking details.'))
                    ->headerActions([
                        TranslationFields::fillLanguageListsAction('translate_mail_texts', array_map(
                            fn (string $type) => "mail_texts.{$type}",
                            array_keys(self::MAIL_TEXTS),
                        )),
                    ])
                    ->components(self::languageLists(
                        'mail_texts',
                        array_map(fn (string $type) => "mail_texts.{$type}", array_keys(self::MAIL_TEXTS)),
                        fn (string $path, string $locale) => RichEditor::make($path)
                            ->label(__(self::MAIL_TEXTS[explode('.', $path)[1]]))
                            ->hintAction(self::standardMailAction(MailType::from(explode('.', $path)[1]), $locale))
                            // Deposit with the first payment: this email is then only the fallback.
                            ->helperText(fn (?Property $record): ?string => explode('.', $path)[1] === MailType::Deposit->value
                                && ($record ?? self::default())->depositAtBooking()
                                    ? __('The deposit is paid along with the first payment, so this email only goes to bookings you make or confirm yourself.')
                                    : null)
                            ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['mergeTags'], ['undo', 'redo']])
                            ->mergeTags(fn (): array => MailPlaceholders::labels())
                            // Empty stays empty, so the default's text keeps applying.
                            ->dehydrateStateUsing(fn ($state) => TranslationFields::textOrNull($state)),
                    )),
            ]);
    }

    /**
     * Info button at a custom mail text: the standard email in the language of
     * the tab, for an example booking, with a marker where the custom text goes.
     */
    private static function standardMailAction(MailType $type, string $locale): Action
    {
        return Action::make('standard_mail')
            ->label(__('Show the standard email'))
            ->icon(Heroicon::OutlinedInformationCircle)
            ->color('gray')
            ->link()
            ->modalHeading(fn () => __(self::MAIL_TEXTS[$type->value]))
            ->modalDescription(__('What the guest receives without own text, for an example booking. Your own text goes where the marker is.'))
            ->modalContent(fn (?Property $record) => view('filament.properties.standard-mail', [
                'url' => route('filament.admin.mail-preview', [
                    'property' => ($record ?? self::default())->getKey(),
                    'type' => $type->value,
                    'locale' => $locale,
                ]),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    /** @return array<int, Section|Select> */
    private static function locationHostFields(): array
    {
        return [
            Section::make(__('Location'))
                ->columns(2)
                ->columnSpanFull()
                ->components([
                    TextInput::make('address')
                        ->label(__('Address')),
                    TextInput::make('postal_code')
                        ->label(__('Postal code')),
                    TextInput::make('city')
                        ->label(__('City')),
                    Select::make('country')
                        ->label(__('Country'))
                        ->options(fn () => \App\Support\Countries::options())
                        ->searchable()
                        ->required()
                        ->default('NL'),
                    // Degrees with a decimal point, as Google Maps gives them. Without the
                    // point (-755514039) the number is far outside the world and the
                    // database cannot store it: say so instead of "saving failed".
                    TextInput::make('latitude')
                        ->label(__('Latitude'))
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        // A hint below, no placeholder: a grey number in the field looks filled in.
                        ->helperText(__('With a decimal point, as Google Maps gives it'))
                        ->validationMessages(['min' => __('A latitude lies between -90 and 90. Did you forget the decimal point?'), 'max' => __('A latitude lies between -90 and 90. Did you forget the decimal point?')]),
                    TextInput::make('longitude')
                        ->label(__('Longitude'))
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->helperText(__('With a decimal point, as Google Maps gives it'))
                        ->validationMessages(['min' => __('A longitude lies between -180 and 180. Did you forget the decimal point?'), 'max' => __('A longitude lies between -180 and 180. Did you forget the decimal point?')]),
                    // Two maps on the site, each with its own choice.
                    Select::make('location_display')
                        ->label(__('Map on the accommodation page'))
                        ->options([
                            Property::LOCATION_NONE => __('No map'),
                            Property::LOCATION_APPROXIMATE => __('Approximately: a circle around the area'),
                            Property::LOCATION_EXACT => __('Exactly: a pin on the address'),
                        ])
                        ->default(Property::LOCATION_APPROXIMATE)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->native(false),
                    Select::make('overview_location_display')
                        ->label(__('Map on the overview page'))
                        ->options([
                            Property::LOCATION_NONE => __('Not on the map'),
                            Property::LOCATION_APPROXIMATE => __('Approximately: a pin near the area'),
                            Property::LOCATION_EXACT => __('Exactly: a pin on the address'),
                        ])
                        ->default(Property::LOCATION_APPROXIMATE)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->native(false),
                    Text::make(__('Approximately: the exact spot stays hidden until the guest has booked. Copy the coordinates from Google Maps (right-click on the spot).'))
                        ->columnSpanFull(),
                ]),
            Select::make('hosts')
                ->label(__('Hosts'))
                // A host manages only their own accommodations and cannot transfer them.
                ->hidden(fn (): bool => Access::isHost(auth()->user()))
                ->relationship('hosts', 'name')
                ->multiple()
                ->preload()
                // Only with the right to create hosts, like the welcome host field.
                ->createOptionAction(fn (Action $action) => $action->visible(fn (): bool => HostResource::canCreate()))
                ->createOptionForm([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required(),
                    Textarea::make('bio')
                        ->label(__('About the host (visible on the site)'))
                        ->rows(3),
                ])
                ->hint(__('Manage the profile photo under Hosts'))
                ->columnSpanFull(),
        ];
    }

    private static function presentationSeoTab(): Tab
    {
        return Tab::make(__('Presentation & SEO'))
                            ->icon(Heroicon::OutlinedSparkles)
                            ->components([
                                Section::make(__('Highlights on the detail page'))
                                    ->description(__('Leave empty = automatic blocks (self check-in, location, secure payment). Maximum 4 custom blocks.'))
                                    ->components([
                                        Repeater::make('highlights')
                                            ->label('')
                                            ->columns(3)
                                            ->defaultItems(0)
                                            ->maxItems(4)
                                            ->addActionLabel(__('Add highlight'))
                                            ->components([
                                                Select::make('icon')
                                                    ->label(__('Icon'))
                                                    ->options(fn () => \App\Models\Property::HIGHLIGHT_ICONS + \App\Models\Amenity::iconOptions())
                                                    ->default('check-circle')
                                                    ->required(),
                                                TranslationFields::languageSection(
                                                    __('Title and text'),
                                                    __('Choose a language. Empty = the English text.'),
                                                    [
                                                        'title' => fn (string $path, string $locale) => TextInput::make($path)
                                                            ->label(__('Title'))
                                                            ->required($locale === Languages::SOURCE),
                                                        'text' => fn (string $path) => TextInput::make($path)->label(__('Text (optional)')),
                                                    ],
                                                    ['title', 'text'],
                                                    columns: 2,
                                                )->contained(false)->columnSpanFull(),
                                            ]),
                                    ]),

                                TranslationFields::languageSection(
                                    __('SEO'),
                                    __('Choose a language. Empty = the English text.'),
                                    [
                                        'meta_title' => fn (string $path) => TextInput::make($path)->label(__('Meta title'))->maxLength(70),
                                        'meta_description' => fn (string $path) => TextInput::make($path)->label(__('Meta description'))->maxLength(160),
                                    ],
                                    ['meta_title', 'meta_description'],
                                    columns: 2,
                                ),

                                self::socialSection(),
                            ]);
    }

    /** On the defaults screen its own tab: the rest of Presentation & SEO is not there. */
    public static function socialMediaTab(): Tab
    {
        return Tab::make(__('Social media'))
            ->icon(Heroicon::OutlinedShare)
            ->components([self::socialSection()]);
    }

    private static function socialSection(): Section
    {
        return Section::make(__('Social media'))
            ->description(fn (?Property $record) => $record?->is_master
                ? __('These links appear in the site footer and with your accommodations. You can override them per accommodation with their own link.')
                : __('Own social links for this accommodation. Empty = the link of the default; the grey text shows which one that is now.'))
            ->collapsed(fn (?Property $record) => ! $record?->is_master)
            ->columns(2)
            ->components(
                collect(\App\Support\SocialChannels::CHANNELS)
                    ->map(fn (array $channel, string $key) => TextInput::make("social_links.{$key}")
                        ->label($channel[0])
                        ->url()
                        // Shows the default's link if there is one, otherwise an example.
                        ->placeholder(fn (?Property $record) => $record?->is_master
                            ? $channel[2]
                            : (self::default()->social_links[$key] ?? $channel[2])))
                    ->values()
                    ->all()
            );
    }
}
