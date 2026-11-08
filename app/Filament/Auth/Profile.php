<?php

namespace App\Filament\Auth;

use App\Filament\Resources\Hosts\HostResource;
use App\Filament\Resources\Users\UserResource;
use App\Support\Access;
use Filament\Auth\Pages\EditProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The profile page (top right): own details, language, password and 2FA, and for
 * a host with the "Own host profile" permission also their name, photo and text
 * as guests see them on the site. The block is bound to the logged-in user's
 * host profile, so a host can never touch another host's profile here.
 */
class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                UserResource::languageField(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                Section::make(__('My host profile'))
                    ->description(__('This is how you appear on the website next to your accommodations.'))
                    ->relationship('host')
                    ->visible(fn (): bool => Access::mayOwnHostProfile(auth()->user()))
                    ->components(HostResource::profileFields()),
            ]);
    }
}
