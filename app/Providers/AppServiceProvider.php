<?php

namespace App\Providers;

use App\Http\Middleware\StartSessionWithoutDataAddresses;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\MollieProvider;
use App\Payments\PaymentProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The AI connection logs in through the browser (authorization
        // code): no device login and no routes for it. Here, before Passport
        // registers its routes.
        \Laravel\Passport\Passport::$deviceCodeGrantEnabled = false;

        // The active payment provider comes from the settings; new providers
        // (Stripe) are one extra match line here.
        $this->app->bind(\App\Services\MachineTranslator::class, \App\Services\DeeplTranslator::class);
        $this->app->bind(\App\Services\IdentityVerifier::class, \App\Services\StripeIdentityVerifier::class);

        // Mailbox from the admin (Settings → Email), only applied when mail
        // is sent. On a fresh installation the table does not exist yet;
        // then just use the .env.
        $this->app->afterResolving(\Illuminate\Mail\MailManager::class, fn () => rescue(
            fn () => \App\Support\MailSettings::apply(),
            report: false,
        ));

        // The fixed words of the website can be edited in the admin.
        $this->app->extend('translation.loader', fn ($loader) => new \App\Support\WebsiteTextsLoader($loader));

        // The menu on the left in the order the user dragged it into.
        $this->app->scoped(\Filament\Navigation\NavigationManager::class, fn () => new \App\Filament\Navigation\PersonalOrder);

        // Listing import: safe public-metadata importer or (buyer's own
        // key) the Apify service. Without a token Apify falls back to metadata.
        $this->app->bind(\App\Services\Importing\ListingImportProvider::class, function ($app) {
            return match (Setting::get('import', 'service', 'metadata')) {
                'apify' => new \App\Services\Importing\ApifyImporter($app->make(\App\Services\Importing\MetadataImporter::class)),
                default => new \App\Services\Importing\MetadataImporter,
            };
        });

        // AI text generation for listings: Claude, OpenAI or Gemini, chosen via
        // the settings. Without a choice the Null variant gives an explanation.
        $this->app->bind(\App\Services\Ai\AiTextGenerator::class, fn () => \App\Services\Ai\AiServices::make(Setting::get('ai', 'service', 'none')));

        // Session as Laravel makes it, but data addresses (api.*) do not
        // count as the previous page; see StartSessionWithoutDataAddresses.
        $this->app->singleton(StartSession::class, fn ($app) => new StartSessionWithoutDataAddresses(
            $app->make(SessionManager::class),
            fn () => $app->make(CacheFactory::class),
        ));

        $this->app->singleton(PaymentProvider::class, function () {
            return match (Setting::get('payments', 'provider', 'mollie')) {
                'stripe' => new \App\Payments\StripeProvider,
                'fake' => new FakeProvider,
                default => new MollieProvider,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // After an update with the zip package: clear old views and caches once.
        \App\Support\Release::refreshIfNew();

        // Live, every link (also in mails) uses APP_URL, not the Host header.
        \App\Support\SiteUrl::pinToAppUrl();

        // The AI connection (App\Support\McpConnection): short-lived tokens
        // that the app renews itself, and our own "Allow" screen.
        \Laravel\Passport\Passport::tokensExpireIn(now()->addHours(\App\Support\McpConnection::ACCESS_TOKEN_HOURS));
        \Laravel\Passport\Passport::refreshTokensExpireIn(now()->addDays(\App\Support\McpConnection::REFRESH_TOKEN_DAYS));
        \Laravel\Passport\Passport::authorizationView(fn (array $parameters) => view('mcp.authorize', $parameters));

        // Row buttons in every admin table: an icon with the name as tooltip,
        // so tables stay narrow enough to fit without scrolling sideways.
        // All in the interface colour; only delete is red.
        \Filament\Tables\Table::configureUsing(fn (\Filament\Tables\Table $table) => $table->modifyUngroupedRecordActionsUsing(
            fn (\Filament\Actions\Action $action) => $action
                ->iconButton()
                ->tooltip(fn (\Filament\Actions\Action $action) => $action->getLabel())
                ->color($action instanceof \Filament\Actions\DeleteAction ? 'danger' : 'gray'),
        ));

        // Buttons in repeater blocks (add, delete, move) do not take focus
        // on a mouse click. After the click Livewire rebuilds the block
        // and the clicked button disappears; Filament's tabs then hand the
        // lost focus back to the active tab and scroll to it with
        // scrollIntoView() (focusTab() in filament/schemas tabs.js).
        // Without focus on the button there is nothing to hand back and the page
        // stays put. With the keyboard the buttons work normally.
        \Filament\Forms\Components\Repeater::configureUsing(function (\Filament\Forms\Components\Repeater $repeater): void {
            $noFocusOnClick = fn (\Filament\Actions\Action $action) => $action->extraAttributes(['x-on:mousedown.prevent' => ''], merge: true);

            $repeater
                ->addAction($noFocusOnClick)
                ->addBetweenAction($noFocusOnClick)
                ->deleteAction($noFocusOnClick)
                ->cloneAction($noFocusOnClick)
                ->moveUpAction($noFocusOnClick)
                ->moveDownAction($noFocusOnClick);
        });

        // Mail to owner and host on a new booking, cancellation or payment.
        \App\Models\Booking::observe(\App\Observers\BookingNotifications::class);
        \App\Models\Payment::observe(\App\Observers\PaymentNotifications::class);

        // A cancelled booking can no longer be paid.
        \App\Models\Booking::observe(\App\Observers\BookingPaymentLinks::class);

        // One place for the password requirements: the server enforces them and the
        // installation screen reads them out for the strength meter, so the two
        // never say different things. Following NIST SP 800-63B-4:
        //  - minimum 15 characters, because the admin has no second factor;
        //  - no requirements for capitals or symbols, length is what counts;
        //  - check against leaked passwords. Only the first 5 characters
        //    of the SHA-1 hash go to Have I Been Pwned; if that service is
        //    unreachable, the installation simply continues.
        // Maximum 64: bcrypt only uses the first 72 bytes.
        \Illuminate\Validation\Rules\Password::defaults(
            fn () => \Illuminate\Validation\Rules\Password::min(15)->max(64)->uncompromised()
        );

        // Shrink the original on upload (optional, via settings).
        \Illuminate\Support\Facades\Event::listen(
            \Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent::class,
            \App\Listeners\OptimizeOriginalImage::class,
        );

        // Media picker + hero repeater on Pages: the modals and scripts must be in
        // the initial HTML (scripts in Livewire-morphed modals do not run).
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::BODY_END,
            fn (): string => view('filament.partials.media-assets')->render()
                .'<script src="'.asset('js/hero-images.js').'?v='.filemtime(public_path('js/hero-images.js')).'"></script>',
            scopes: [\App\Filament\Resources\Pages\Pages\ManagePages::class],
        );

        // The photo editor on the Photos tab of an accommodation, only for those who may use the Media manager.
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::BODY_END,
            fn (): string => \App\Filament\Pages\MediaManager::canAccess() ? view('filament.partials.media-assets')->render() : '',
            scopes: [\App\Filament\Resources\Properties\Pages\EditProperty::class],
        );
    }
}
