<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Mail\MailManager;

/**
 * Sends mail through the mailbox set in the admin (Settings → Email), so
 * nobody has to edit the .env for it. If nothing is filled in there, the
 * MAIL_ lines from .env apply, as always.
 *
 * Applied as soon as Laravel creates the mail manager (see
 * AppServiceProvider), not at boot: this way these details never end up in
 * the config cache of `php artisan config:cache`.
 */
final class MailSettings
{
    public const SSL = 'ssl';

    public const STARTTLS = 'tls';

    /** @return array<string, string> security => name in the form */
    public static function securityMeasures(): array
    {
        return [
            self::SSL => __('SSL (port 465)'),
            self::STARTTLS => __('STARTTLS (port 587)'),
        ];
    }

    /** Is a mail server filled in in the admin? */
    public static function configured(): bool
    {
        return filled(Setting::get('mail', 'host'));
    }

    /** Are mails really going out, via the admin or via .env? */
    public static function sends(): bool
    {
        return self::configured() || ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public static function apply(?MailManager $manager = null): void
    {
        // The sender name is the company name, also when the mailbox itself
        // is in the .env: otherwise mails come from APP_NAME ("Laravel").
        if (filled($company = Setting::get('company', 'name'))) {
            config(['mail.from.name' => $company]);
        }

        if (! self::configured()) {
            return;
        }

        $ssl = Setting::get('mail', 'security', self::SSL) !== self::STARTTLS;
        $user = Setting::get('mail', 'user');

        config([
            'mail.default' => 'smtp',
            // smtps: encrypted from the start (465). smtp: STARTTLS, which Symfony enables itself (587).
            'mail.mailers.smtp.scheme' => $ssl ? 'smtps' : 'smtp',
            // STARTTLS must succeed: a server (or someone on the way) that
            // does not offer it would otherwise get the password unencrypted.
            'mail.mailers.smtp.require_tls' => ! $ssl,
            'mail.mailers.smtp.host' => Setting::get('mail', 'host'),
            'mail.mailers.smtp.port' => (int) (Setting::get('mail', 'port') ?: ($ssl ? 465 : 587)),
            'mail.mailers.smtp.username' => $user,
            'mail.mailers.smtp.password' => Setting::get('mail', 'password'),
            'mail.from.address' => Setting::get('mail', 'sender') ?: $user,
            'mail.from.name' => Setting::get('company', 'name') ?: config('mail.from.name'),
        ]);

        // Already created a mailer earlier in this request (e.g. the test mail
        // after saving)? Then forget it, so the new details apply.
        $manager?->purge('smtp');
    }
}
