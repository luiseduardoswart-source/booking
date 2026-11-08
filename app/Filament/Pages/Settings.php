<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Filament\Concerns\SwitchesDirect;
use App\Filament\Support\Switches;
use App\Filament\Support\TranslationFields;
use App\Models\AdminLanguage;
use App\Models\AiUsage;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiServices;
use App\Services\Ai\ClaudeTextGenerator;
use App\Services\Ai\GeminiTextGenerator;
use App\Services\Ai\SiteChat;
use App\Services\MachineTranslator;
use App\Support\SiteLayouts;
use App\Support\SiteTemplates;
use App\Support\AdminLanguages;
use App\Support\Favicon;
use App\Support\MailSettings;
use App\Support\Money;
use App\Support\Languages;
use App\Support\Currencies;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Settings extends Page implements HasActions, HasForms
{
    use HasUnsavedDataChangesAlert;
    use SwitchesDirect;
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    /**
     * Form field => [group, key] in the settings table.
     */
    private static function fields(): array
    {
        $fields = [
            'company_name' => ['company', 'name'],
            'company_email' => ['company', 'email'],
            'company_phone' => ['company', 'phone'],
            'company_address' => ['company', 'address'],
            'company_postal_code_city' => ['company', 'postal_code_city'],
            'company_coc' => ['company', 'coc'],
            'company_vat_number' => ['company', 'vat_number'],
            'company_iban' => ['company', 'iban'],
            'mail_sender' => ['mail', 'sender'],
            'mail_host' => ['mail', 'host'],
            'mail_security' => ['mail', 'security'],
            'mail_port' => ['mail', 'port'],
            'mail_user' => ['mail', 'user'],
            'mail_password' => ['mail', 'password'],
            'branding_logo' => ['branding', 'logo'],
            'branding_favicon' => ['branding', 'favicon'],
            'payments_provider' => ['payments', 'provider'],
            'payments_mollie_api_key' => ['payments', 'mollie_api_key'],
            'payments_stripe_secret_key' => ['payments', 'stripe_secret_key'],
            'payments_stripe_webhook_secret' => ['payments', 'stripe_webhook_secret'],
            'site_single_accommodation_direct' => ['site', 'single_accommodation_direct'],
            'site_currency_picker' => ['site', 'currency_picker'],
            'site_currency_default' => ['site', 'currency_default'],
            'security_two_step_required' => ['security', 'two_step_required'],
            'prices_pricelabs_api_key' => ['prices', 'pricelabs_api_key'],
            'prices_beyond_api_key' => ['prices', 'beyond_api_key'],
            'prices_wheelhouse_api_key' => ['prices', 'wheelhouse_api_key'],
            'import_service' => ['import', 'service'],
            'import_apify_token' => ['import', 'apify_token'],
            'import_apify_actor' => ['import', 'apify_actor'],
            'ai_service' => ['ai', 'service'],
            'ai_openai_api_key' => ['ai', 'openai_api_key'],
            'ai_openai_model' => ['ai', 'openai_model'],
            'ai_claude_api_key' => ['ai', 'claude_api_key'],
            'ai_claude_model' => ['ai', 'claude_model'],
            'ai_gemini_model' => ['ai', 'gemini_model'],
            'chat_on' => ['chat', 'on'],
            'chat_service' => ['chat', 'service'],
            'chat_model' => ['chat', 'model'],
            'chat_instructions' => ['chat', 'instructions'],
            'chat_monthly_limit' => ['chat', 'monthly_limit'],
            'ai_image_provider' => ['ai', 'image_provider'],
            'ai_gemini_api_key' => ['ai', 'gemini_api_key'],
            'ai_image_model' => ['ai', 'image_model'],
            'ai_image_gemini_model' => ['ai', 'image_gemini_model'],
            'ai_image_monthly_limit' => ['ai', 'image_monthly_limit'],
            'reviews_auto_approve' => ['reviews', 'auto_approve'],
            'reviews_auto_approve_from' => ['reviews', 'auto_approve_from'],
            'media_original_shrink' => ['media', 'original_shrink'],
            'media_original_max_px' => ['media', 'original_max_px'],
            'frontend_layout' => ['frontend', 'layout'],
            'frontend_template_name' => ['frontend', 'template_name'],
            'frontend_accent_color' => ['frontend', 'accent_color'],
            'frontend_label_color' => ['frontend', 'label_color'],
            'frontend_background_color' => ['frontend', 'background_color'],
            'frontend_header_style' => ['frontend', 'header_style'],
            'frontend_font_style' => ['frontend', 'font_style'],
            'frontend_button_style' => ['frontend', 'button_style'],
            'frontend_head_code' => ['frontend', 'head_code'],
            'frontend_body_code' => ['frontend', 'body_code'],
            'cookie_on' => ['cookie', 'on'],
            'cookie_marketing_on' => ['cookie', 'marketing_on'],
            'cookie_privacy_url' => ['cookie', 'privacy_url'],
            'cookie_code_statistics' => ['cookie', 'code_statistics'],
            'cookie_code_marketing' => ['cookie', 'code_marketing'],
        ];

        $fields['translations_deepl_api_key'] = ['translations', 'deepl_api_key'];

        // Tagline per language (multilingual).
        foreach (Languages::available() as $locale) {
            $fields["company_slogan_{$locale}"] = ['company', "slogan_{$locale}"];
        }

        // Welcome message of the AI chat, per language.
        foreach (Languages::available() as $locale) {
            $fields["chat_welcome_{$locale}"] = ['chat', "welcome_{$locale}"];
        }

        // Title and text of the cookie notice, per language.
        foreach (['title', 'text'] as $key) {
            foreach (Languages::available() as $locale) {
                $fields["cookie_{$key}_{$locale}"] = ['cookie', "{$key}_{$locale}"];
            }
        }

        return $fields;
    }

    /**
     * Base fields that exist per language (for automatic translation).
     *
     * @return string[]
     */
    private static function multilingualFields(): array
    {
        return ['company_slogan', 'cookie_title', 'cookie_text', 'chat_welcome'];
    }

    public function mount(): void
    {
        $values = [];

        foreach (self::fields() as $field => [$group, $key]) {
            $values[$field] = Setting::get($group, $key);
        }

        // Toggles: string from the database → boolean for the form.
        $values['site_single_accommodation_direct'] = ($values['site_single_accommodation_direct'] ?? '1') !== '0';
        $values['security_two_step_required'] = ($values['security_two_step_required'] ?? '0') === '1';
        $values['mail_security'] = $values['mail_security'] ?? MailSettings::SSL;
        $values['import_service'] = $values['import_service'] ?? 'metadata';
        $values['ai_service'] = $values['ai_service'] ?? 'none';
        $values['chat_on'] = SiteChat::on();
        $values['chat_service'] = SiteChat::service();
        $values['media_original_shrink'] = ($values['media_original_shrink'] ?? '0') === '1';
        $values['reviews_auto_approve'] = ($values['reviews_auto_approve'] ?? '1') === '1';
        $values['frontend_layout'] = $values['frontend_layout'] ?? SiteLayouts::DEFAULT;
        $values['languages'] = self::languagesRows(Language::allCached());
        $values['site_default_language'] = Languages::default();
        $values['admin_languages'] = self::languagesRows(AdminLanguage::allCached());
        $values['admin_default_language'] = AdminLanguages::default();
        $values['site_currency_picker'] = ($values['site_currency_picker'] ?? '0') === '1';
        $values['site_currency_default'] = $values['site_currency_default'] ?? 'EUR';
        $values['reviews_auto_approve_from'] = $values['reviews_auto_approve_from'] ?? '4';
        $values['cookie_on'] = ($values['cookie_on'] ?? '0') === '1';
        $values['cookie_marketing_on'] = ($values['cookie_marketing_on'] ?? '0') === '1';

        // Migration of the old single-language values to the NL field.
        $values['company_slogan_nl'] = $values['company_slogan_nl'] ?? Setting::get('company', 'slogan');

        $this->form->fill($values);
    }

    /**
     * Automatically translates all still-empty language fields from the source
     * language via the configured translation service.
     */
    public function translateAction(): Action
    {
        return Action::make('translate')
            ->label(__('Translate empty languages automatically'))
            ->icon(Heroicon::OutlinedLanguage)
            ->action(function () {
                $translator = app(MachineTranslator::class);
                $translated = 0;

                try {
                    foreach (self::multilingualFields() as $base) {
                        $source = trim((string) ($this->data[$base.'_'.Languages::SOURCE] ?? ''));

                        if ($source === '') {
                            continue;
                        }

                        foreach (Languages::active() as $locale) {
                            if ($locale === Languages::SOURCE || trim((string) ($this->data["{$base}_{$locale}"] ?? '')) !== '') {
                                continue;
                            }

                            $this->data["{$base}_{$locale}"] = $translator->translate($source, Languages::SOURCE, $locale);
                            $translated++;
                        }
                    }
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Translation failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(trans_choice('{0} Nothing to translate: all language fields are already filled.|{1} 1 field translated — review the text and save.|[2,*] :count fields translated — review the texts and save.', $translated))
                    ->success()
                    ->send();
            });
    }

    /**
     * Saves the settings and sends a test email to the logged-in admin, so
     * you can see right away whether the mailbox works.
     */
    private function testMailAction(): Action
    {
        return Action::make('test_mail')
            ->label(__('Send test email'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->action(function () {
                $this->save();
                MailSettings::apply(app('mail.manager'));
                $recipient = (string) auth()->user()?->email;

                try {
                    Mail::raw(__('This test email shows that your website can send emails.'), fn ($message) => $message
                        ->to($recipient)
                        ->subject(__('Test email from your website')));
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('The test email could not be sent'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $notification = MailSettings::sends()
                    ? Notification::make()->title(__('Test email sent to :address', ['address' => $recipient]))->success()
                    : Notification::make()->title(__('No mailbox has been set up yet: the email was only written to the log file.'))->warning();

                $notification->send();
            });
    }

    /**
     * Downloads the current style (as it is now in the form) as a template
     * file, so it can be shared or loaded elsewhere.
     */
    public function exportTemplate(): StreamedResponse
    {
        $tokens = [];

        foreach (SiteTemplates::FIELDS as $field) {
            $tokens[$field] = $this->data["frontend_{$field}"] ?? null;
        }

        $content = json_encode([
            'template' => $this->data['frontend_template_name'] ?? null,
            'tokens' => SiteTemplates::cleanTokens($tokens),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response()->streamDownload(
            fn () => print ($content),
            'website-template.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Loads a template file into the form. Nothing is saved until the admin
     * clicks Save; invalid or unknown values are silently skipped (see
     * SiteTemplates::cleanTokens).
     */
    public function importTemplate(mixed $file): void
    {
        $content = $file instanceof TemporaryUploadedFile
            ? $file->get()
            : (is_string($file) ? $file : null);

        $data = $content !== null ? json_decode($content, true) : null;

        if (! is_array($data) || ! is_array($data['tokens'] ?? null)) {
            Notification::make()
                ->title(__('Import failed'))
                ->body(__('This is not a valid template file. Expected a JSON file previously created via "Export as file".'))
                ->danger()
                ->send();

            return;
        }

        foreach (SiteTemplates::cleanTokens($data['tokens']) as $field => $value) {
            $this->data["frontend_{$field}"] = $value;
        }

        // Only take over the name if it refers to a known template.
        $this->data['frontend_template_name'] = array_key_exists((string) ($data['template'] ?? ''), SiteTemplates::all())
            ? $data['template']
            : null;

        Notification::make()
            ->title(__('Template loaded — review the style and click Save.'))
            ->success()
            ->send();
    }

    /**
     * Installs an uploaded layout template (zip) and prepares it in the
     * form. Saving remains a deliberate click by the admin.
     */
    public function installLayout(mixed $file): void
    {
        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->title(__('Upload a zip file first.'))->warning()->send();

            return;
        }

        try {
            $name = SiteLayouts::installZip($file->getRealPath());
        } catch (\RuntimeException $e) {
            Notification::make()->title(__('Installation failed'))->body($e->getMessage())->danger()->send();

            return;
        }

        $this->data['frontend_layout'] = $name;

        Notification::make()
            ->title(__('Template ":name" installed — it is ready in the list. Click Save to activate it.', ['name' => SiteLayouts::all()[$name]['name'] ?? $name]))
            ->success()
            ->send();
    }

    /**
     * Builds a working example template file (the bundled "Fullscreen
     * magazine" layout) so a template maker has a complete starting point:
     * edit, re-zip and upload.
     */
    public function downloadExampleTemplate(): StreamedResponse
    {
        $source = SiteLayouts::path('fullscreen');
        $zipPath = tempnam(sys_get_temp_dir(), 'example').'.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach (File::allFiles($source) as $file) {
            $zip->addFile($file->getPathname(), 'my-template/'.str_replace('\\', '/', $file->getRelativePathname()));
        }

        // Empty stylesheet as a starting point for custom styling.
        $zip->addFromString('my-template/assets/template.css', '/* '.__('Your template\'s own CSS. This file is loaded automatically while the template is active.')." */\n");
        $zip->close();

        return response()->streamDownload(
            function () use ($zipPath) {
                readfile($zipPath);
                @unlink($zipPath);
            },
            'example-template.zip',
            ['Content-Type' => 'application/zip'],
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('Company'))
                            ->icon(Heroicon::OutlinedBuildingOffice)
                            ->columns(2)
                            ->components([
                                // autocomplete off: this is business data, not a
                                // personal contact form. Otherwise Chrome recognises these
                                // fields as an address form and fills them with the
                                // logged-in visitor's data — putting, for example,
                                // an email address in the IBAN field.
                                TextInput::make('company_name')
                                    ->label(__('Company name'))
                                    ->autocomplete(false)
                                    ->required(),
                                TextInput::make('company_email')
                                    ->label(__('Email address'))
                                    ->email()
                                    ->autocomplete(false)
                                    ->hint(__('Receives contact form and conflict alerts')),
                                TextInput::make('company_phone')
                                    ->label(__('Phone'))
                                    ->autocomplete(false),
                                TextInput::make('company_address')
                                    ->label(__('Address'))
                                    ->autocomplete(false),
                                TextInput::make('company_postal_code_city')
                                    ->label(__('Postal code and city'))
                                    ->autocomplete(false),
                                TextInput::make('company_coc')
                                    ->label(__('Chamber of Commerce no.'))
                                    ->autocomplete(false),
                                TextInput::make('company_vat_number')
                                    ->label(__('VAT number'))
                                    ->autocomplete(false),
                                TextInput::make('company_iban')
                                    ->label(__('IBAN (on invoices)'))
                                    ->autocomplete(false),
                                FileUpload::make('branding_logo')
                                    ->label(__('Logo'))
                                    ->disk('public')
                                    ->directory('branding')
                                    ->image(),
                                FileUpload::make('branding_favicon')
                                    ->label(__('Favicon'))
                                    ->disk('public')
                                    ->directory('branding')
                                    ->image()
                                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                    ->maxSize(2048)
                                    ->helperText(__('The small icon in the browser tab and on a phone home screen. Preferably a square PNG of at least 192 × 192 pixels; the sizes are created automatically. Empty = the logo.')),
                            ]),

                        Tab::make(__('Email'))
                            ->icon(Heroicon::OutlinedEnvelope)
                            ->components([
                                Section::make(__('Sending email'))
                                    ->key('mailbox')
                                    ->description(__('The mailbox the website uses to send emails to guests and to you. You find the details at your hosting provider, for example under Emails at Hostinger. Empty = the server settings.'))
                                    ->columns(2)
                                    ->headerActions([$this->testMailAction()])
                                    ->components([
                                        TextInput::make('mail_sender')
                                            ->label(__('Sender address'))
                                            ->email()
                                            ->autocomplete(false)
                                            ->placeholder('bookings@uwdomein.nl')
                                            ->requiredWith('mail_host'),
                                        TextInput::make('mail_host')
                                            ->label(__('Mail server (SMTP)'))
                                            ->autocomplete(false)
                                            ->placeholder('smtp.hostinger.com')
                                            ->maxLength(255),
                                        Select::make('mail_security')
                                            ->label(__('Security'))
                                            ->options(MailSettings::securityMeasures())
                                            ->native(false)
                                            ->selectablePlaceholder(false),
                                        TextInput::make('mail_port')
                                            ->label(__('Port'))
                                            ->integer()
                                            ->minValue(1)
                                            ->maxValue(65535)
                                            ->placeholder(fn (Get $get) => $get('mail_security') === MailSettings::STARTTLS ? '587' : '465')
                                            ->hint(__('Empty = the usual port for this security')),
                                        TextInput::make('mail_user')
                                            ->label(__('Username'))
                                            ->autocomplete(false)
                                            ->hint(__('Usually the full email address'))
                                            ->requiredWith('mail_host'),
                                        TextInput::make('mail_password')
                                            ->label(__('Mailbox password'))
                                            ->password()->revealable()
                                            ->autocomplete('new-password')
                                            ->requiredWith('mail_host'),
                                    ]),
                            ]),

                        Tab::make(__('Website'))
                            ->icon(Heroicon::OutlinedGlobeAlt)
                            ->components([
                                Section::make(__('Languages'))
                                    ->description(__('The website\'s languages: add, rename, enable or disable them and set the default. The admin languages are separate, on the Admin tab.'))
                                    ->components([
                                        self::languagesList('languages', __('On the site'), Language::class),
                                        self::defaultLanguageField('site_default_language', 'languages')
                                            ->label(__('Default language of the website'))
                                            ->hint(__('A visitor sees their own browser language first if it is enabled, otherwise this one.')),
                                    ])
                                    ->footerActions([$this->translateLanguageFileAction()]),
                                Section::make(__('Tagline (homepage)'))
                                    ->description(__('The tagline in the visitor\'s language. Empty = default text.'))
                                    ->components([
                                        TranslationFields::languageTabs('slogan', fn (string $locale) => [
                                            TextInput::make("company_slogan_{$locale}")->hiddenLabel(),
                                        ]),
                                    ]),
                                self::directSetting(Toggle::make('site_single_accommodation_direct')
                                    ->label(__('With one accommodation: show it directly on the main domain'))
                                    ->hint(__('Off = always show the home page with the search bar'))
                                    ->default(true)),
                                Section::make(__('Currency'))
                                    ->description(__('Show visitors prices in their own currency, converted automatically at the current exchange rate. The selector offers all common currencies. Display only: payment is always settled in euros.'))
                                    ->columns(2)
                                    ->components([
                                        self::directSetting(Toggle::make('site_currency_picker')
                                            ->label(__('Show currency selector on the site'))
                                            ->inline(false)),
                                        Select::make('site_currency_default')
                                            ->label(__('Default currency'))
                                            ->options(fn () => Currencies::options())
                                            ->default('EUR')
                                            ->searchable()
                                            ->hint(__('The currency visitors see before making their own choice'))
                                            ->visible(fn (callable $get) => (bool) $get('site_currency_picker')),
                                    ]),

                                Section::make(__('Import listing'))
                                    ->description(__('When adding an accommodation you can paste a listing link (Airbnb/Vrbo/Booking). "Public data" safely fetches what the page reveals. An external service (Apify) fetches more; you then use your own account (token on the Keys tab) and accept the platform\'s terms yourself.'))
                                    ->components([
                                        Select::make('import_service')
                                            ->label(__('Import method'))
                                            ->options([
                                                'metadata' => __('Public data (safe)'),
                                                'apify' => __('External service — Apify (own account)'),
                                            ])
                                            ->default('metadata'),
                                    ]),

                                Section::make(__("Photos"))
                                    ->description(__('Visitors always get automatically resized versions; the original is kept as the source. Turn this on to also shrink the stored original and save storage.'))
                                    ->columns(2)
                                    ->components([
                                        self::directSetting(Toggle::make('media_original_shrink')
                                            ->label(__('Downsize original photos on upload'))
                                            ->columnSpanFull()),
                                        TextInput::make('media_original_max_px')
                                            ->label(__('Max. resolution of original (px)'))
                                            ->numeric()
                                            ->minValue(600)
                                            ->default(2560)
                                            ->suffix('px')
                                            ->visible(fn (callable $get) => (bool) $get('media_original_shrink')),
                                    ]),

                                Section::make(__('Reviews'))
                                    ->description(__('Guest reviews normally wait for your approval before appearing on the site. Turn this on and only positive reviews from the chosen score are published straight away — lower ratings always keep waiting for your approval.'))
                                    ->columns(2)
                                    ->components([
                                        self::directSetting(Toggle::make('reviews_auto_approve')
                                            ->label(__('Automatically approve positive reviews'))
                                            ->default(true)
                                            ->columnSpanFull()),
                                        Select::make('reviews_auto_approve_from')
                                            ->label(__('Auto-approve from'))
                                            ->options([
                                                '3' => __(':count stars or higher', ['count' => 3]),
                                                '4' => __(':count stars or higher', ['count' => 4]),
                                                '5' => __('5 stars only'),
                                            ])
                                            ->default('4')
                                            ->native(false)
                                            ->visible(fn (callable $get) => (bool) $get('reviews_auto_approve')),
                                    ]),

                                Section::make(__('AI texts'))
                                    ->description(__('Let AI write compelling texts for your accommodations (description, short description and SEO). When editing an accommodation a "Generate with AI" button appears next to the description. Choose your service; enter the API key on the Keys tab.'))
                                    ->components([
                                        Select::make('ai_service')
                                            ->label(__('AI service'))
                                            ->options(['none' => __('None')] + AiServices::names())
                                            ->default('none'),
                                    ]),

                                Section::make(__('Custom code'))
                                    ->description(__('Paste your own code here (e.g. Google Analytics, a tracking pixel or a chat widget). It is placed exactly as-is on every site page — only use code you trust.'))
                                    ->collapsed()
                                    ->components([
                                        Textarea::make('frontend_head_code')
                                            ->label(__('Code in the <head>'))
                                            ->rows(4)
                                            ->extraInputAttributes(['style' => 'font-family: monospace;']),
                                        Textarea::make('frontend_body_code')
                                            ->label(__('Code before the end of the <body>'))
                                            ->rows(4)
                                            ->extraInputAttributes(['style' => 'font-family: monospace;']),
                                    ]),
                            ]),

                        Tab::make(__('Admin'))
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->components([
                                Section::make(__('Languages'))
                                    ->description(__('The languages of the admin screen: add, rename, enable or disable them and set the default. This is separate from the website; you choose your own language in the user menu at the top right.'))
                                    ->components([
                                        self::languagesList('admin_languages', __('In the admin'), AdminLanguage::class),
                                        self::defaultLanguageField('admin_default_language', 'admin_languages')
                                            ->label(__('Default language of the admin'))
                                            ->hint(__('For users who have not chosen a language and whose browser language is not listed.')),
                                    ])
                                    ->footerActions([$this->translateLanguageFileAction()]),
                            ]),

                        Tab::make(__('Website texts'))
                            ->icon(Heroicon::OutlinedLanguage)
                            ->components([
                                LivewireComponent::make(\App\Livewire\WebsiteTextsAdmin::class),
                            ]),

                        Tab::make(__('Layout & style'))
                            ->icon(Heroicon::OutlinedPaintBrush)
                            ->components([
                                Section::make(__('Style editor'))
                                    ->description(__('Customise the site visually: click an element in a preview of your site and change colour, font, spacing and more — you see the result instantly.'))
                                    ->components([
                                        Actions::make([
                                            Action::make('openStyleEditor')
                                                ->label(__('Open the style editor'))
                                                ->icon(Heroicon::OutlinedCursorArrowRays)
                                                ->url(StyleEditor::getUrl()),
                                        ]),
                                    ]),

                                Section::make(__('Layout'))
                                    ->description(__('Determines the layout of the entire site. Upload purchased or self-designed layout templates below as a zip file — the system installs everything automatically. Anything a template does not provide falls back to the default.'))
                                    ->components([
                                        Select::make('frontend_layout')
                                            ->label(__('Layout'))
                                            ->options(fn () => SiteLayouts::options())
                                            ->default(SiteLayouts::DEFAULT)
                                            ->native(false)
                                            ->live()
                                            ->helperText(fn (?string $state) => SiteLayouts::all()[$state]['description'] ?? null),
                                        Actions::make([
                                            Action::make('uploadLayout')
                                                ->label(__('Upload layout template (.zip)'))
                                                ->icon(Heroicon::OutlinedArrowUpTray)
                                                ->color('gray')
                                                ->schema([
                                                    FileUpload::make('file')
                                                        ->label(__('Template file (.zip)'))
                                                        ->storeFiles(false)
                                                        ->required(),
                                                ])
                                                ->action(function (array $data) {
                                                    $this->installLayout($data['file'] ?? null);
                                                }),
                                        ]),

                                        Section::make(__('Creating your own template'))
                                            ->collapsed()
                                            ->components([
                                                Placeholder::make('template_manual')
                                                    ->hiddenLabel()
                                                    ->content(fn () => new HtmlString(
                                                        view('filament.settings.template-manual')->render()
                                                    )),
                                                Actions::make([
                                                    Action::make('downloadExample')
                                                        ->label(__('Download example template (.zip)'))
                                                        ->icon(Heroicon::OutlinedArrowDownTray)
                                                        ->color('gray')
                                                        ->action(fn () => $this->downloadExampleTemplate()),
                                                ]),
                                            ]),
                                    ]),

                                Section::make(__('Template'))
                                    ->description(__('Pick a ready-made style for the website or load a template file. Applying fills in the style fields below — feel free to tweak them, then click Save.'))
                                    ->components([
                                        Select::make('frontend_template_name')
                                            ->label(__('Template'))
                                            ->options(SiteTemplates::options())
                                            ->placeholder(__('Custom settings'))
                                            ->native(false)
                                            ->live()
                                            ->helperText(fn (?string $state) => SiteTemplates::all()[$state]['description'] ?? null)
                                            ->afterStateUpdated(function (?string $state, callable $set) {
                                                $template = SiteTemplates::all()[$state] ?? null;

                                                if ($template === null) {
                                                    return;
                                                }

                                                foreach (SiteTemplates::cleanTokens($template['tokens']) as $field => $value) {
                                                    $set("frontend_{$field}", $value);
                                                }

                                                Notification::make()
                                                    ->title(__('Template ":name" staged — review the style below and save.', ['name' => $template['name']]))
                                                    ->success()
                                                    ->send();
                                            }),
                                        Actions::make([
                                            Action::make('exportTemplate')
                                                ->label(__('Export as file'))
                                                ->icon(Heroicon::OutlinedArrowDownTray)
                                                ->color('gray')
                                                ->action(fn () => $this->exportTemplate()),
                                            Action::make('importTemplate')
                                                ->label(__('Import template file'))
                                                ->icon(Heroicon::OutlinedArrowUpTray)
                                                ->color('gray')
                                                ->schema([
                                                    FileUpload::make('file')
                                                        ->label(__('Template file (.json)'))
                                                        ->acceptedFileTypes(['application/json', 'text/plain'])
                                                        ->storeFiles(false)
                                                        ->required(),
                                                ])
                                                ->action(function (array $data) {
                                                    $this->importTemplate($data['file'] ?? null);
                                                }),
                                        ]),
                                    ]),

                                Section::make(__('Colours & style'))
                                    ->description(__('The individual style fields behind the template. Empty = the default look. Manual tweaks are always possible, also after picking a template.'))
                                    ->columns(3)
                                    ->components([
                                        ColorPicker::make('frontend_accent_color')
                                            ->label(__('Button and accent colour')),
                                        ColorPicker::make('frontend_label_color')
                                            ->label(__('Photo label colour')),
                                        ColorPicker::make('frontend_background_color')
                                            ->label(__('Background colour')),
                                        Select::make('frontend_header_style')
                                            ->label(__('Header'))
                                            ->options(['light' => __('Light'), 'dark' => __('Dark')])
                                            ->placeholder(__('Light (default)'))
                                            ->native(false),
                                        Select::make('frontend_font_style')
                                            ->label(__('Font'))
                                            ->options([
                                                'default' => __('Default (Instrument Sans)'),
                                                'serif' => __('Serif headings (Lora)'),
                                                'system' => __('System font'),
                                            ])
                                            ->placeholder(__('Default (Instrument Sans)'))
                                            ->native(false),
                                        Select::make('frontend_button_style')
                                            ->label(__('Button shape'))
                                            ->options(['round' => __('Rounded'), 'tight' => __('Sharp')])
                                            ->placeholder(__('Rounded (default)'))
                                            ->native(false),
                                    ]),
                            ]),

                        Tab::make(__('Keys'))
                            ->icon(Heroicon::OutlinedKey)
                            ->components([
                                Section::make(__('Payments'))
                                    ->description(__('The payment provider for all accommodations and its key.'))
                                    ->columns(2)
                                    ->components([
                                        Select::make('payments_provider')
                                            ->label(__('Payment provider'))
                                            ->options(['mollie' => 'Mollie (iDEAL, creditcard, Bancontact...)', 'stripe' => 'Stripe (creditcard, iDEAL, Apple/Google Pay...)', 'fake' => __('Test (fake provider)')])
                                            ->default('mollie')
                                            ->columnSpanFull(),
                                        TextInput::make('payments_mollie_api_key')
                                            ->label(__('Mollie API key'))
                                            ->password()->revealable(),
                                        TextInput::make('payments_stripe_secret_key')
                                            ->label(__('Stripe secret key'))
                                            ->hint(__('Also needed for ID verification'))
                                            ->password()->revealable(),
                                        TextInput::make('payments_stripe_webhook_secret')
                                            ->label(__('Stripe webhook secret (optional)'))
                                            ->password()->revealable(),
                                    ]),

                                Section::make(__('Dynamic pricing'))
                                    ->description(__('The keys of the dynamic pricing services. You choose which service an accommodation uses at the accommodation or under Default accommodation settings.'))
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('prices_pricelabs_api_key')
                                            ->label(__('PriceLabs API key'))->password()->revealable(),
                                        TextInput::make('prices_beyond_api_key')
                                            ->label(__('Beyond API key'))->password()->revealable(),
                                        TextInput::make('prices_wheelhouse_api_key')
                                            ->label(__('Wheelhouse API key'))->password()->revealable(),
                                    ]),

                                Section::make(__('Listing import (Apify)'))
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('import_apify_token')
                                            ->label(__('Apify API token'))->password()->revealable(),
                                        TextInput::make('import_apify_actor')
                                            ->label(__('Apify actor ID'))
                                            ->hint(__('The actor that scrapes the listing, e.g. for Airbnb')),
                                    ]),

                                Section::make(__('Translations'))
                                    ->description(__('With a (free) DeepL API key, the "Translate empty languages" button translates your texts automatically.'))
                                    ->components([
                                        TextInput::make('translations_deepl_api_key')
                                            ->label(__('DeepL API key'))->password()->revealable(),
                                    ]),

                                Section::make(__('AI texts'))
                                    ->description(__('Enter the key of the AI service you selected under Website. The model is optional — empty = a strong default model.'))
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('ai_openai_api_key')
                                            ->label(__('OpenAI API key'))->password()->revealable(),
                                        TextInput::make('ai_openai_model')
                                            ->label(__('OpenAI model (optional)'))
                                            ->placeholder('gpt-4o'),
                                        TextInput::make('ai_claude_api_key')
                                            ->label(__('Claude (Anthropic) API key'))->password()->revealable(),
                                        TextInput::make('ai_claude_model')
                                            ->label(__('Claude model (optional)'))
                                            ->placeholder(ClaudeTextGenerator::DEFAULT_MODEL),
                                        TextInput::make('ai_gemini_model')
                                            ->label(__('Gemini model (optional)'))
                                            ->placeholder(GeminiTextGenerator::DEFAULT_MODEL)
                                            ->helperText(__('You enter the Google key under AI images below.')),
                                    ]),

                                Section::make(__('AI images'))
                                    ->description(__('Generate photos under Media (with an approval queue). OpenAI uses the OpenAI key above; for Nano Banana enter a Google key.'))
                                    ->columns(2)
                                    ->components([
                                        Select::make('ai_image_provider')
                                            ->label(__('Image provider'))
                                            ->options([
                                                'openai' => 'OpenAI (DALL·E / GPT Image)',
                                                'gemini' => 'Google Nano Banana (Gemini)',
                                            ])
                                            ->default('openai'),
                                        TextInput::make('ai_gemini_api_key')
                                            ->label(__('Google (Gemini) API key'))->password()->revealable(),
                                        Select::make('ai_image_model')
                                            ->label(__('OpenAI image model'))
                                            ->options([
                                                'dall-e-3' => 'DALL·E 3',
                                                'gpt-image-1' => 'GPT Image 1',
                                            ])
                                            ->default('dall-e-3'),
                                        Select::make('ai_image_gemini_model')
                                            ->label(__('Nano Banana model'))
                                            ->options([
                                                'gemini-3.1-flash-image' => 'Nano Banana 2 (nieuwste)',
                                                'gemini-3-pro-image' => 'Nano Banana Pro (beste kwaliteit)',
                                                'gemini-2.5-flash-image' => 'Nano Banana 1 (legacy)',
                                            ])
                                            ->default('gemini-3.1-flash-image'),
                                        TextInput::make('ai_image_monthly_limit')
                                            ->label(__('Monthly limit (number of images, 0 = unlimited)'))
                                            ->numeric()
                                            ->default(50),
                                    ]),
                            ]),

                        Tab::make(__('Security'))
                            ->icon(Heroicon::OutlinedLockClosed)
                            ->components([
                                Section::make(__('Two-factor authentication'))
                                    ->description(__('Everyone can turn on two-factor authentication in their profile (top right). If you make it mandatory here, anyone who has not set it up yet must do so with an authenticator app before continuing in the admin.'))
                                    ->components([
                                        self::directSetting(Toggle::make('security_two_step_required')
                                            ->label(__('Two-factor authentication required for everyone in the admin'))
                                            ->helperText(fn (): string => once(function (): string {
                                                $count = User::query()
                                                    ->whereIn('role', [UserRole::Administrator->value, UserRole::Staff->value])
                                                    ->selectRaw('count(*) as total, sum(case when app_authentication_secret is null then 1 else 0 end) as without')
                                                    ->toBase()
                                                    ->first();

                                                return trans_choice('{0} Everyone has already set it up.|{1} 1 of the :total users has not set it up yet.|[2,*] :count of the :total users have not set it up yet.', (int) $count->without, ['total' => (int) $count->total]);
                                            }))),
                                    ]),
                            ]),

                        Tab::make(__('Cookie notice (GDPR)'))
                            ->icon(Heroicon::OutlinedShieldCheck)
                            ->components([
                                Section::make(__('Cookie notice'))
                                    ->description(__('Shows visitors a consent banner. Required as soon as you use non-essential cookies (such as Google Analytics): these are only loaded after consent. Necessary cookies (session, language, currency) are exempt from the notice.'))
                                    ->components([
                                        self::directSetting(Toggle::make('cookie_on')
                                            ->label(__('Show cookie notice'))),
                                        Placeholder::make('cookie_empty')
                                            ->hiddenLabel()
                                            ->content(__('The notice only appears once there is something to consent to: add a statistics script below or enable the marketing category.'))
                                            ->visible(fn (callable $get) => $get('cookie_on')
                                                && blank($get('cookie_code_statistics'))
                                                && ! $get('cookie_marketing_on')),
                                        self::directSetting(Toggle::make('cookie_marketing_on')
                                            ->label(__('Show marketing category'))
                                            ->hint(__('Extra choice for ad scripts; available in JS as window.cookieConsent.marketing'))
                                            ->visible(fn (callable $get) => (bool) $get('cookie_on'))),
                                        TextInput::make('cookie_privacy_url')
                                            ->label(__('Privacy policy link (optional)'))
                                            ->url()
                                            ->placeholder('https://...')
                                            ->hint(__('Appears as "More information" in the notice'))
                                            ->visible(fn (callable $get) => (bool) $get('cookie_on')),
                                    ]),

                                Section::make(__('Notice text'))
                                    ->description(__('Title and text per language. Empty = the default text.'))
                                    ->collapsed()
                                    ->visible(fn (callable $get) => (bool) $get('cookie_on'))
                                    ->components([
                                        TranslationFields::languageTabs('cookie', fn (string $locale) => [
                                            TextInput::make("cookie_title_{$locale}")->label(__('Title')),
                                            Textarea::make("cookie_text_{$locale}")->label(__('Text'))->rows(2),
                                        ]),
                                    ]),

                                Section::make(__('Scripts per category'))
                                    ->description(__('Paste third-party scripts here (e.g. Google Analytics, Meta Pixel, Hotjar). They are only loaded after the visitor accepts the matching category — so you don’t have to build the consent yourself.'))
                                    ->collapsed()
                                    ->visible(fn (callable $get) => (bool) $get('cookie_on'))
                                    ->components([
                                        Textarea::make('cookie_code_statistics')
                                            ->label(__('Statistics scripts'))
                                            ->rows(4)
                                            ->extraInputAttributes(['style' => 'font-family: monospace;']),
                                        Textarea::make('cookie_code_marketing')
                                            ->label(__('Marketing scripts'))
                                            ->rows(4)
                                            ->extraInputAttributes(['style' => 'font-family: monospace;'])
                                            ->visible(fn (callable $get) => (bool) $get('cookie_marketing_on')),
                                    ]),
                            ]),

                        Tab::make(__('AI chat'))
                            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                            ->components([
                                Grid::make(['default' => 1, 'xl' => 2])
                                    ->components([
                                        self::chatSection(),
                                        Section::make(__('Knowledge base'))
                                            ->description(__('Extra information for the chat, such as frequently asked questions, terms or directions. A document for one accommodation is only read by the chat on that accommodation\'s page; the chat on all other pages reads everything. Changes here are saved immediately.'))
                                            ->components([
                                                LivewireComponent::make(\App\Livewire\KnowledgeBaseAdmin::class),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param  Collection<int, Language>  $languages
     * @return array<int, array<string, mixed>>
     */
    private static function languagesRows(Collection $languages): array
    {
        return $languages
            ->map(fn (Language $language) => [
                'code' => $language->code,
                'name' => $language->name,
                'active' => $language->active,
            ])->values()->all();
    }

    /**
     * The list of languages, for the website and for the admin.
     *
     * @param  class-string<Language>  $model
     */
    private static function languagesList(string $field, string $columnOn, string $model): Repeater
    {
        return Repeater::make($field)
            ->hiddenLabel()
            ->table([
                TableColumn::make(__('Code'))->width('7rem'),
                TableColumn::make(__('Name')),
                TableColumn::make($columnOn)->width('9rem')->alignCenter(),
            ])
            ->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(5)
                    ->alphaDash() // ends up in a URL and in the name of the language file
                    ->placeholder('it')
                    ->extraInputAttributes(['class' => 'lowercase']),
                TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->placeholder(__('Italiano')),
                Switches::direct(
                    Toggle::make('active')->inline(false)->default(true),
                    fn (bool $on, self $livewire, Toggle $switch) => $livewire->switchLanguage($model, $on, $switch),
                ),
            ])
            ->addActionLabel(__('Add language'))
            ->reorderable()
            ->deletable()
            ->live()
            ->columnSpanFull();
    }

    /** The default language, chosen from the languages currently in the list. */
    private static function defaultLanguageField(string $field, string $list): Select
    {
        return Select::make($field)
            ->options(fn (callable $get) => collect($get($list) ?: [])
                ->filter(fn (array $row) => filled($row['code'] ?? null))
                ->mapWithKeys(fn (array $row) => [
                    $row['code'] => $row['name'] ?: $row['code'],
                ])->all())
            ->selectablePlaceholder(false)
            ->required();
    }

    /**
     * Update the languages table from the form. Rows the admin has removed are
     * really deleted, except the default language: without it there would be
     * nothing to fall back on.
     *
     * @param  class-string<Language>  $model
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function saveLanguages(string $model, array $rows, string $default): void
    {
        $seen = [];
        $sort = 0;

        foreach ($rows as $row) {
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));

            if ($code === '' || $name === '') {
                continue;
            }

            $model::updateOrCreate(['code' => $code], [
                'name' => $name,
                // The default language must always be selectable.
                'active' => $code === $default ? true : (bool) ($row['active'] ?? false),
                'is_default' => $code === $default,
                'sort' => $sort++,
            ]);

            $seen[] = $code;
        }

        if ($seen !== []) {
            $model::query()->whereNotIn('code', $seen)->delete();
        }

        // If the chosen default has disappeared (for example because the row was
        // deleted), we pick the first language instead of leaving nothing to
        // fall back on.
        if (! $model::query()->where('is_default', true)->exists()) {
            $model::query()->orderBy('sort')->first()?->update(['active' => true, 'is_default' => true]);
        }

        $model::forgets();
    }

    /**
     * Switching a language on or off saves immediately, as you would expect
     * from a switch. A new row, or a row whose code was just changed, is not
     * in the database yet and simply goes along with Save.
     *
     * @param  class-string<Language>  $model
     * @return bool whether anything was saved
     */
    private function switchLanguage(string $model, bool $on, Toggle $switch): bool
    {
        abort_unless(static::canAccess(), 403);

        $code = $switch->makeGetUtility()('code');
        $language = $model::query()->where('code', strtolower(trim((string) $code)))->first();

        if ($language === null) {
            return false;
        }

        // Same rule as on Save: the default language must be selectable.
        if (! $on && $language->is_default) {
            $switch->state(true);

            Notification::make()
                ->title(__('The default language is always on. Choose another default language first.'))
                ->warning()
                ->send();

            return false;
        }

        $language->update(['active' => $on]);
        $model::forgets();

        return true;
    }

    /**
     * A standalone switch in the settings (cookie notice, currency picker,
     * and so on) saves immediately, the same way Save does.
     */
    /**
     * The AI chat on the site: on/off, service and model, welcome message per
     * language, extra instructions, monthly limit and this month's usage.
     */
    private static function chatSection(): Section
    {
        $consumption = function (): string {
            $month = AiUsage::thisMonth('chat');
            $text = __('This month: :count questions, :costs.', [
                'count' => $month['count'],
                'costs' => Money::format((int) round($month['costs'] * 100), 'EUR'),
            ]);

            return SiteChat::limitReached()
                ? $text.' '.__('The monthly limit has been reached: the chat is off until next month.')
                : $text;
        };

        return Section::make(__('AI chat'))
            ->description(__('A chat button at the bottom right of the site. The chat knows your accommodations (amenities, house rules, times, cancellation, price from), your pages, your company details and the knowledge base, and refers to the accommodation page for availability and booking. On that page the chat only knows that accommodation. Enter the key for the chosen service in the Keys tab.'))
            ->columns(2)
            ->components([
                self::directSetting(Toggle::make('chat_on')
                    ->label(__('Chat on the site'))
                    ->helperText(fn (callable $get) => $get('chat_on') && ! AiServices::hasKey($get('chat_service'))
                        ? __('Not visible yet: no API key has been set for this service.')
                        : null)
                    ->columnSpanFull()),
                Select::make('chat_service')
                    ->label(__('AI service'))
                    ->options(AiServices::names())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (callable $set) => $set('chat_model', null)),
                Select::make('chat_model')
                    ->label(__('Model'))
                    ->options(fn (callable $get) => collect(config('ai_prices.text.'.$get('chat_service'), []))
                        ->except('default')
                        ->map(fn (array $rate) => __($rate['label']).' · '.__('$:in / $:out per million tokens', ['in' => $rate['in'], 'out' => $rate['out']]))
                        ->all())
                    ->placeholder(fn (callable $get) => __('Model under Keys (:model)', ['model' => AiServices::defaultModel((string) $get('chat_service'))])),
                TranslationFields::languageTabs('chat_welcome', fn (string $locale) => [
                    TextInput::make("chat_welcome_{$locale}")
                        ->label(__('Welcome message'))
                        ->placeholder(__('Hi! Feel free to ask anything about our accommodations, I am happy to help.'))
                        ->maxLength(500),
                ]),
                Textarea::make('chat_instructions')
                    ->label(__('Extra instructions'))
                    ->placeholder(__('For example: always refer questions about groups to the contact form.'))
                    ->rows(3)
                    ->maxLength(4000)
                    ->columnSpanFull(),
                TextInput::make('chat_monthly_limit')
                    ->label(__('Monthly limit in euros (0 = no limit)'))
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('0')
                    ->helperText(__('Once the limit is reached, the chat disappears until next month.')),
                Placeholder::make('chat_consumption')
                    ->label(__('Usage'))
                    ->content($consumption),
            ]);
    }

    private static function directSetting(Toggle $switch): Toggle
    {
        return Switches::direct($switch, function (bool $on, self $livewire, Toggle $component): bool {
            abort_unless(static::canAccess(), 403);

            [$group, $key] = self::fields()[$component->getName()];
            Setting::set($group, $key, $on ? '1' : '0');

            return true;
        });
    }

    /**
     * Adding a language does not produce translations yet: the software's
     * texts live in lang/<code>.json. This button has them filled by machine
     * translation, so a new language is not left half in another language.
     */
    private function translateLanguageFileAction(): Action
    {
        return Action::make('language_file')
            ->label(__('Complete language files'))
            ->icon(Heroicon::OutlinedLanguage)
            ->requiresConfirmation()
            ->modalDescription(__('Translates the software texts into every language that has no file yet. This can take a few minutes and uses your translation service.'))
            ->action(function () {
                $done = [];

                foreach (array_unique([...Languages::available(), ...AdminLanguages::available()]) as $code) {
                    if ($code === Languages::SOURCE || file_exists(lang_path("{$code}.json"))) {
                        continue;
                    }

                    try {
                        Artisan::call('lang:translate', ['locale' => $code]);
                        $done[] = $code;
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('Translation failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }
                }

                Notification::make()
                    ->title($done === []
                        ? __('Every language already has a language file.')
                        : __('Language file created for: :languages', ['languages' => implode(', ', $done)]))
                    ->success()
                    ->send();
            });
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if (isset($data['languages'])) {
            $this->saveLanguages(Language::class, $data['languages'], (string) ($data['site_default_language'] ?? ''));
        }

        if (isset($data['admin_languages'])) {
            $this->saveLanguages(AdminLanguage::class, $data['admin_languages'], (string) ($data['admin_default_language'] ?? ''));
        }

        foreach (self::fields() as $field => [$group, $key]) {
            // Not shown = not touched. Without this line, switching a language
            // off would wipe its translations on save.
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Setting::set($group, $key, is_array($value) ? (array_values($value)[0] ?? null) : $value);
        }

        // Favicon or logo changed: regenerate the sizes.
        Favicon::make();

        // Everything is now in the database: no more warning when leaving.
        $this->rememberData();

        Notification::make()
            ->title(__('Settings saved'))
            ->success()
            ->send();
    }
}
