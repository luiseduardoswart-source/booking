<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Settings;
use App\Payments\PaymentProvider;
use App\Support\DatabaseUpdater;
use App\Support\ScheduledTasks;
use App\Support\MailSettings;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Throwable;

/**
 * At the top of the dashboard: what still has to happen after the installation
 * wizard (or after updating with a new zip package) before the website does
 * everything by itself. Disappears as soon as everything is done.
 */
class FinishInstallation extends Widget
{
    protected static ?int $sort = -10;

    // Show immediately, not only after loading: this is the first thing you should see.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.finish-installation';

    private const DATABASE = 'database';

    public static function canView(): bool
    {
        return self::mayManage() && self::steps() !== [];
    }

    private static function mayManage(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    /**
     * @return list<array{id?: string, title: string, text: string, code?: string, link?: string, button?: string, action?: string}>
     */
    public static function steps(): array
    {
        $steps = [];

        // First: with an outdated database the rest may not work.
        if (($outstanding = DatabaseUpdater::outstanding()) > 0) {
            $steps[] = [
                'id' => self::DATABASE,
                'title' => __('Update the database'),
                'text' => trans_choice('This version of the website needs 1 change to the database. Your bookings and settings are kept.|This version of the website needs :count changes to the database. Your bookings and settings are kept.', $outstanding),
                'action' => 'updateDatabase',
                'button' => __('Update database'),
            ];
        }

        if (! ScheduledTasks::run()) {
            $steps[] = [
                'title' => __('Turn on the automatic tasks'),
                'text' => __('Without this task no emails go out and calendars and prices are not updated. In your hosting panel, add a cron job that runs every minute (at Hostinger: Advanced → Cron Jobs) with exactly this command:'),
                'code' => ScheduledTasks::cronCommand(),
            ];
        }

        if (! MailSettings::sends()) {
            $steps[] = [
                'title' => __('Set up the mailbox'),
                'text' => __('Under Settings → Email, fill in the mailbox the website sends emails from, and send a test email.'),
                'link' => Settings::getUrl(),
                'button' => __('Go to settings'),
            ];
        }

        // The test provider or a test key: every guest books without paying
        // real money, and the booking is confirmed like a paid one.
        $payments = app(PaymentProvider::class);

        if ($payments->isConfigured() && $payments->isTestMode()) {
            $steps[] = [
                'title' => __('Payments are in test mode'),
                'text' => __('The payment provider is the test provider or uses a test key: guests can book without paying real money. Before real guests book, choose Mollie or Stripe under Settings → Keys and fill in the live key.'),
                'link' => Settings::getUrl(),
                'button' => __('Go to settings'),
            ];
        }

        // Closed with `php artisan down` to test before going live. Everything
        // keeps working (routes/console.php); only guests cannot get in.
        if (app()->isDownForMaintenance()) {
            $steps[] = [
                'title' => __('Open the website for guests'),
                'text' => __('The website is closed: guests see "Service Unavailable". Bookings, emails and the automatic tasks keep working, so you can test everything. When it is ready, open the website with this command on the server:'),
                'code' => 'php '.base_path('artisan').' up',
            ];
        }

        return $steps;
    }

    public function updateDatabase(): void
    {
        // A Livewire method can also be called without the button.
        abort_unless(self::mayManage(), 403);

        try {
            DatabaseUpdater::run();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('The database could not be updated'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title(__('The database has been updated'))->success()->send();
    }

    protected function getViewData(): array
    {
        $steps = self::steps();
        $onlyDatabase = count($steps) === 1 && ($steps[0]['id'] ?? null) === self::DATABASE;

        return [
            'steps' => $steps,
            'heading' => $onlyDatabase ? __('Update the website') : __('Finish the installation'),
            'description' => $onlyDatabase
                ? __('A new version is ready. One more step and it is fully in use.')
                : __('A few more steps and the website does everything by itself.'),
        ];
    }
}
