<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Profile;
use App\Http\Controllers\Admin\AdminLanguageController;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\TwoStepRequired;
use App\Support\AdminLanguages;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            // Own details and password (with the current password for
            // confirmation, requirements from Password::defaults()), plus setting up 2FA.
            ->profile(Profile::class, isSimple: false)
            // "Required" is always on here; TwoStepRequired checks per
            // request whether the owner has enabled it under Settings.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(TwoStepRequired::class)
            ->colors([
                'primary' => Color::Rose,
            ])
            ->brandName(fn () => \App\Models\Setting::get('company', 'name', config('app.name')))
            // Content across the full width next to the sidebar; the default (7xl)
            // leaves margins on wide screens and forces tables to scroll.
            ->maxContentWidth(Width::Full)
            // Anyone navigating away with unsaved changes is asked first.
            ->unsavedChangesAlerts()
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.partials.sidebar-width-heading'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => \App\Support\Favicon::tags())
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.partials.sidebar-width-foot'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn () => view('filament.partials.menu-order'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Note: only App\Filament\Widgets is discovered and therefore ends up
            // on the Dashboard. The statistics widgets are deliberately in
            // App\Filament\Statistics, so they only appear on the
            // Statistics page.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Only when the panel boots: the list is in the database,
            // and we do not want to hit it on every request to the website.
            ->bootUsing(fn (Panel $panel) => $panel->userMenuItems(
                collect(AdminLanguages::active())
                    ->map(fn (string $locale) => \Filament\Navigation\MenuItem::make('language-'.$locale)
                        ->label(strtoupper($locale))
                        ->icon(\Filament\Support\Icons\Heroicon::OutlinedLanguage)
                        ->url(fn () => route('filament.admin.language', $locale)))
                    ->all()
            ))
            ->authenticatedRoutes(fn () => Route::get('language/{locale}', AdminLanguageController::class)->name('language'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistent: a Livewire request goes through the web group, and would
            // otherwise get the website's customised words.
            ->middleware([SetAdminLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
