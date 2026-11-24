<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use App\Support\AdminLanguages;
use App\Support\Access;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'locale', 'password', 'role', 'host_id', 'permissions'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, \Laravel\Sanctum\HasApiTokens, LogsActivity, Notifiable;

    /**
     * Who may do what is security: every change to an account is in the log
     * (Logboek), never the password or the two-factor secret themselves.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role', 'permissions', 'host_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::updated(function (self $user): void {
            // Two-factor on or off: that it happened, not the secret.
            if ($user->wasChanged('app_authentication_secret')) {
                activity()->performedOn($user)
                    ->event($user->hasTwoFactor() ? 'two_factor_on' : 'two_factor_off')
                    ->log($user->hasTwoFactor() ? 'Two-factor authentication turned on' : 'Two-factor authentication turned off');
            }

            // A new password ends the AI-app connections made with the old one.
            if ($user->wasChanged('password')) {
                \App\Support\McpConnection::disconnect($user);
                activity()->performedOn($user)->event('password_changed')->log('Password changed');
            }
        });
    }

    // Two-factor authentication with an authenticator app, with recovery codes.
    // Secret and codes are stored encrypted and never serialized.
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    /** Turns two-factor authentication off, e.g. when the phone and recovery codes are lost. */
    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();
    }

    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, UserRole::admin(), true);
    }

    /** The owner of the platform. */
    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Administrator;
    }

    public function isHost(): bool
    {
        return $this->role === UserRole::Host;
    }

    /** May this user perform this action on this screen? See App\Support\Access. */
    public function may(string $screen, string $action): bool
    {
        return Access::may($this, $screen, $action);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /**
     * This user's language: Laravel automatically sends them mails and
     * notifications in this language, and the admin opens in it (SetLocale).
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? AdminLanguages::default();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_set_at' => 'datetime',
            'role' => UserRole::class,
            'permissions' => 'array',
            'menu_order' => 'array',
        ];
    }
}
