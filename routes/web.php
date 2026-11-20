<?php

use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\BookingPayController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\IcalExportController;
use App\Http\Controllers\PaymentReturnController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SiteBookingController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

// Accommodation slugs live at root level (/duinhuisje-aan-zee). This pattern
// excludes all reserved paths so they are never mistaken for a slug.
$slugPattern = '(?!admin$|api$|my-bookings$|accommodations$|accommodation$|payment$|booking$|build$|contact$|ical$|install$|livewire$|storage$|language$|up$|vendor$|webhooks$)[a-z0-9-]+';

// Installation wizard: first start without SSH (database + admin).
// Closes itself once an account exists.
//
// The site middleware is deliberately removed here: it queries settings,
// pages and the logged-in user, and at this point those tables do not
// exist yet. Without this exception the wizard crashes before it can show
// anything. (That is why sessions and cache are also set to 'file' in .env.example.)
Route::middleware(\App\Http\Middleware\EnsureNotInstalled::class)
    ->withoutMiddleware([
        \App\Http\Middleware\ApplySiteLayout::class,
        \App\Http\Middleware\InjectStyleEditor::class,
        \App\Http\Middleware\ApplyContentLinks::class,
    ])
    ->group(function () {
        // Lock in front of the wizard: the code is in a file on the server, so
        // only those who can access it may continue. These two routes are outside it,
        // otherwise you could never enter the code.
        Route::get('/install/code', [\App\Http\Controllers\InstallController::class, 'code'])->name('install.code');
        Route::post('/install/code', [\App\Http\Controllers\InstallController::class, 'verifyCode'])->name('install.code.verify');

        Route::middleware(\App\Http\Middleware\RequiresInstallCode::class)->group(function () {
            Route::get('/install', [\App\Http\Controllers\InstallController::class, 'database'])->name('install.database');
            Route::post('/install', [\App\Http\Controllers\InstallController::class, 'storeDatabase'])->name('install.database.store');
            Route::get('/install/account', [\App\Http\Controllers\InstallController::class, 'account'])->name('install.account');
            Route::post('/install/account', [\App\Http\Controllers\InstallController::class, 'storeAccount'])->name('install.account.store');
        });
    });

// Website
Route::get('/', [SiteController::class, 'home'])->name('home');

// Language switch
Route::get('/language/{locale}', function (string $locale) {
    abort_unless(in_array($locale, \App\Support\Languages::active(), true), 404);
    session()->put('locale', $locale);

    return redirect(\App\Support\Slugs::addressInLanguage(\App\Support\SiteUrl::previous(), $locale));
})->name('locale.switch');

// Currency switch (display only; payment is in euros)
Route::get('/currency/{code}', function (string $code) {
    $code = strtoupper($code);
    abort_unless(in_array($code, \App\Support\Money::availableCurrencies(), true), 404);
    session()->put('currency', $code);

    return redirect(\App\Support\SiteUrl::previous());
})->name('currency.switch');

// Old URLs keep working (permanent redirects, filters are preserved)
Route::get('/accommodations', fn () => redirect()->route('home', request()->query(), 301))
    ->name('properties.index');
Route::get('/accommodation/{slug}', fn (string $slug) => redirect()->route(
    'properties.show',
    array_merge(['property' => $slug], request()->query()),
    301,
));
Route::get('/accommodation/{slug}/book', fn (string $slug) => redirect()->route(
    'bookings.create',
    array_merge(['property' => $slug], request()->query()),
    301,
));

// Contact
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');
// Own forms from the form builder, shown on a page.
Route::post('/forms/{form}', [\App\Http\Controllers\FormController::class, 'store'])
    ->whereNumber('form')->middleware('throttle:5,1')->name('forms.store');

// AI chat on the site (can be enabled under Settings → Website → AI chat)
Route::post('/chat', [\App\Http\Controllers\SiteChatController::class, 'question'])->middleware('throttle:20,1')->name('chat');

// Performance tracking (photo views from the lightbox, via sendBeacon)
Route::post('/track/photo', [\App\Http\Controllers\TrackingController::class, 'photo'])->middleware('throttle:60,1')->name('track.photo');

// Media management (ported from devsite): folders and files on the public disk under
// media/, with alt texts and a photo editor. Administrator only; the
// UI lives in the admin (Filament page Media + the picker on Pages).
// Order of the menu on the left in the admin, per user (see MenuOrderController).
// Admin pages outside the panel get the panel's own security too: the
// required two-step verification and logging out other sessions after a
// password change.
$adminSecurity = [
    \Filament\Http\Middleware\SetUpPanel::class.':admin',
    \Filament\Http\Middleware\AuthenticateSession::class,
    \App\Http\Middleware\TwoStepRequired::class,
];

Route::post('/admin-menu/order', \App\Http\Controllers\Admin\MenuOrderController::class)
    ->middleware(['throttle:30,1', ...$adminSecurity])
    ->name('admin.menu-order');

Route::prefix('admin-media')->middleware([\App\Http\Middleware\SetAdminLocale::class, ...$adminSecurity])->group(function () {
    Route::get('list', [\App\Http\Controllers\Admin\MediaController::class, 'list'])->name('media.list');
    Route::post('upload', [\App\Http\Controllers\Admin\MediaController::class, 'upload'])->name('media.upload');
    Route::post('edit', [\App\Http\Controllers\Admin\MediaController::class, 'editSave'])->name('media.edit');
    Route::post('folder', [\App\Http\Controllers\Admin\MediaController::class, 'folder'])->name('media.folder');
    Route::post('delete', [\App\Http\Controllers\Admin\MediaController::class, 'delete'])->name('media.delete');
    Route::post('delete-many', [\App\Http\Controllers\Admin\MediaController::class, 'deleteMany'])->name('media.delete-many');
    Route::post('rename', [\App\Http\Controllers\Admin\MediaController::class, 'rename'])->name('media.rename');
    Route::post('alt', [\App\Http\Controllers\Admin\MediaController::class, 'alt'])->name('media.alt');

    // AI images: generate + approval queue
    Route::post('ai/generate', [\App\Http\Controllers\Admin\AiImageController::class, 'generate'])->name('ai-images.generate');
    Route::get('ai/pending', [\App\Http\Controllers\Admin\AiImageController::class, 'pending'])->name('ai-images.pending');
    Route::post('ai/{aiImage}/approve', [\App\Http\Controllers\Admin\AiImageController::class, 'approve'])->name('ai-images.approve');
    Route::post('ai/{aiImage}/reject', [\App\Http\Controllers\Admin\AiImageController::class, 'reject'])->name('ai-images.reject');
});

// Payments
// Signed: otherwise someone could view the status of other payments
// by changing the number (see Payment::returnUrl).
Route::get('/payment/{payment}', PaymentReturnController::class)->name('payment.return')->middleware('signed');

// Fake payment page (only active with the test provider; see the controller)
// Signed as well: otherwise anyone could mark any payment number as paid
// while the test provider is switched on.
Route::get('/payment/test/{payment}', [\App\Http\Controllers\TestCheckoutController::class, 'show'])
    ->middleware('signed')
    ->name('payment.test');
Route::get('/payment/test/{payment}/{outcome}', [\App\Http\Controllers\TestCheckoutController::class, 'process'])
    ->middleware('signed')
    ->name('payment.test.process');
Route::get('/booking/{booking}/pay/{type}', BookingPayController::class)
    ->name('booking.pay')
    ->middleware('signed');
Route::get('/booking/{booking}/confirmation', [SiteBookingController::class, 'confirmation'])
    ->name('bookings.confirmation')
    ->middleware('signed');
// Invitation to the admin (signed link from the mail): choose your own
// password. The controller checks the signature itself, so an
// expired link gives a proper explanation instead of an error page.
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/invitation/{user}', [\App\Http\Controllers\AdminInvitationController::class, 'show'])->name('admin.invitation');
    Route::post('/invitation/{user}', [\App\Http\Controllers\AdminInvitationController::class, 'store'])->name('admin.invitation.store');
});
Route::post('/webhooks/mollie', [PaymentWebhookController::class, 'mollie'])->name('webhooks.mollie');
Route::post('/webhooks/stripe', [PaymentWebhookController::class, 'stripe'])->name('webhooks.stripe');

// ID verification (signed link from the confirmation mail)
Route::get('/booking/{booking}/verification', [\App\Http\Controllers\IdentityController::class, 'start'])
    ->name('identity.start')
    ->middleware('signed');
Route::get('/booking/{booking}/verification/ready', [\App\Http\Controllers\IdentityController::class, 'back'])
    ->name('identity.return')
    ->middleware('signed');

// Reviews (signed link from the review request mail)
Route::get('/booking/{booking}/review', [\App\Http\Controllers\ReviewController::class, 'create'])
    ->name('reviews.create')
    ->middleware('signed');
Route::post('/booking/{booking}/review', [\App\Http\Controllers\ReviewController::class, 'store'])
    ->name('reviews.store')
    ->middleware('signed');

// My bookings: guests see their bookings and documents without a password
// (login link by email, or the signed link in every guest email; see GuestAccess).
Route::prefix('my-bookings')->name('guest.')->controller(\App\Http\Controllers\GuestBookingController::class)->group(function () {
    Route::get('/', 'index')->name('bookings');
    Route::post('/link', 'sendLink')->middleware('throttle:5,1')->name('link');
    Route::get('/login/{token}', 'confirmLogin')->where('token', '[A-Za-z0-9]{64}')->name('login');
    Route::post('/login/{token}', 'login')->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name('login.use');
    Route::post('/logout', 'logout')->name('logout');
    Route::get('/{booking}', 'show')->whereNumber('booking')->name('booking');
    Route::post('/{booking}/phone', 'addPhone')->whereNumber('booking')->middleware('throttle:10,1')->name('phone');
    Route::get('/{booking}/documents/{kind}/{payment?}', 'document')
        ->whereNumber('booking')->whereIn('kind', ['confirmation', 'invoice', 'receipt'])->whereNumber('payment')
        ->middleware('throttle:30,1')->name('document');
});

// iCal export (secret feed per accommodation, for Airbnb/Booking.com)
Route::get('/ical/{property}/{token}.ics', IcalExportController::class)->name('ical.export');

// Public API (Module 13 — first endpoint)
Route::get('/api/properties/{property}/quote', QuoteController::class)->middleware('throttle:60,1')->name('api.properties.quote');

// Autocomplete for the "Where to" search field
Route::get('/api/search_suggestions', \App\Http\Controllers\Api\SearchSuggestionsController::class)->middleware('throttle:120,1')->name('api.search_suggestions');

// Accommodation routes at root level — at the BOTTOM so all fixed paths take precedence.
Route::get('/{property:slug}/book', [SiteBookingController::class, 'create'])
    // It also says whether a coupon code exists: no trying out codes by the thousand.
    ->middleware('throttle:30,1')
    ->name('bookings.create')
    ->where('property', $slugPattern);
Route::post('/{property:slug}/book', [SiteBookingController::class, 'store'])
    // A booking holds its dates: no script that books up the calendar.
    ->middleware('throttle:10,1')
    ->name('bookings.store')
    ->where('property', $slugPattern);
Route::get('/{property:slug}', [SiteController::class, 'show'])
    ->name('properties.show')
    ->where('property', $slugPattern)
    // No accommodation with this slug? Then it may be a free-form page.
    ->missing(function ($request) {
        $slug = $request->route()->parameter('property');
        $page = \App\Models\Page::findBySlug($slug);

        abort_if($page === null || ! $page->published, 404);

        // This response is produced during route binding, before SetLocale
        // gets its turn in the chain — so set the language explicitly here.
        return app(\App\Http\Middleware\SetLocale::class)->handle(
            $request,
            fn () => $page->slugFor() === $slug
                ? response()->view('site.pages.show', ['page' => $page])
                : redirect(\App\Support\Slugs::addressInLanguage($request->fullUrl(), app()->getLocale())),
        );
    });
