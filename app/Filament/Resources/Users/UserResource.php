<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Hosts\HostResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\AdminLanguages;
use App\Support\Access;
use App\Support\Invitation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Users of the admin: owners, staff and hosts, with per
 * user the screens and actions they may use (see App\Support\Access).
 * Owner only. Everyone chooses their own password via an
 * invitation by mail.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users');
    }

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    /** Owner only; you cannot delete yourself, so there is always an owner. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $i = auth()->user();
        $actionName = $action instanceof UnitEnum ? $action->name : $action;

        $allowed = ($i?->isAdministrator() ?? false)
            && ! (in_array($actionName, ['delete', 'forceDelete'], true) && $record?->is($i));

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('role', array_map(fn (UserRole $role) => $role->value, UserRole::admin()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('Email address'))
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                static::languageField(),
                Select::make('role')
                    ->label(__('Role'))
                    ->options(collect(UserRole::admin())->mapWithKeys(fn (UserRole $role) => [$role->value => $role->label()]))
                    ->default(UserRole::Host->value)
                    ->required()
                    ->live()
                    // You cannot change your own role: this way there is always an owner.
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (Get $get): ?string => match ($get('role')) {
                        UserRole::Administrator->value => __('Can do everything, including managing users, permissions and settings.'),
                        UserRole::Staff->value => __('Sees all accommodations, within the permissions below.'),
                        UserRole::Host->value => __('Only sees the accommodations of the linked host, within the permissions below.'),
                        default => null,
                    }),
                Select::make('host_id')
                    ->label(__('Host profile'))
                    ->relationship('host', 'name')
                    ->preload()
                    // If the host does not exist yet, create it here without leaving the form.
                    ->createOptionForm(fn (): array => HostResource::profileFields())
                    ->createOptionModalHeading(fn (): string => __('filament-actions::create.single.modal.heading', ['label' => HostResource::getModelLabel()]))
                    ->visible(fn (Get $get): bool => $get('role') === UserRole::Host->value)
                    ->required(fn (Get $get): bool => $get('role') === UserRole::Host->value)
                    ->helperText(__('This host\'s accommodations are what this user gets to see.')),
                Section::make(__('Permissions'))
                    ->key('permissions_section')
                    ->description(__('Tick per screen what this user may do. Settings and Users are always for the owner only.'))
                    ->headerActions(static::allPermissionsButtons())
                    ->visible(fn (Get $get): bool => in_array($get('role'), [UserRole::Staff->value, UserRole::Host->value], true))
                    ->columnSpanFull()
                    ->columns(2)
                    ->components(collect(Access::SCREENS)
                        ->map(fn (array $setting, string $screen) => CheckboxList::make("permissions.{$screen}")
                            ->label(Access::label($screen))
                            ->options(Access::actionLabels($screen))
                            ->helperText(match (true) {
                                $setting['platform'] ?? false => __('Platform-wide: also for a host, with the data of all accommodations.'),
                                $setting['onlyHost'] ?? false => __('In their profile (top right) the host edits their own name, photo and text.'),
                                default => null,
                            })
                            ->visible(fn (Get $get): bool => array_key_exists($screen, static::allPermissions($get('role'))))
                            ->columns(4)
                            ->bulkToggleable())
                        ->values()
                        ->all()),
            ]);
    }

    /** @return array<string, array<int, string>> the permissions that exist for this role */
    private static function allPermissions(?string $role): array
    {
        return array_filter(
            Access::everything(),
            fn (string $screen): bool => ! (Access::SCREENS[$screen]['onlyHost'] ?? false) || $role === UserRole::Host->value,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * All checkboxes on or off at once. Which button you see switches in the
     * browser, like the button per category: single checkboxes do not go via the server.
     *
     * @return array<int, Action>
     */
    private static function allPermissionsButtons(): array
    {
        $allOn = fn (Get $get, Component $component): string => 'Object.entries('.Js::from(static::allPermissions($get('role'))).')'
            .".every(([screen, actions]) => actions.every((action) => (\$wire.\$get('{$component->getStatePath()}.permissions')?.[screen] ?? []).includes(action)))";

        return [
            Action::make('all_permissions')
                ->label(__('filament-forms::components.checkbox_list.actions.select_all.label'))
                ->icon(Heroicon::OutlinedCheck)
                ->link()
                ->extraAttributes(fn (Get $get, Component $component): array => ['x-show' => "! ({$allOn($get, $component)})"])
                ->action(fn (Get $get, Set $set) => $set('permissions', static::allPermissions($get('role')))),
            Action::make('no_permissions')
                ->label(__('filament-forms::components.checkbox_list.actions.deselect_all.label'))
                ->icon(Heroicon::OutlinedXMark)
                ->link()
                ->extraAttributes(fn (Get $get, Component $component): array => ['x-show' => $allOn($get, $component)])
                ->action(fn (Set $set) => $set('permissions', Access::clean([]))),
        ];
    }

    /**
     * The language of a user: their mails and the admin are in this language.
     * Also on the profile page, so people can change it themselves.
     */
    public static function languageField(): Select
    {
        return Select::make('locale')
            ->label(__('Language'))
            ->options(collect(AdminLanguages::active())->mapWithKeys(fn (string $code) => [$code => AdminLanguages::name($code)]))
            ->placeholder(__('Default (:language)', ['language' => AdminLanguages::name(AdminLanguages::default())]))
            ->in(AdminLanguages::active())
            ->helperText(__('Emails are sent and the admin opens in this language.'));
    }

    /**
     * What the form yields before saving: permissions only for
     * staff and host (cleaned), a host profile only for a host.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function storable(array $data): array
    {
        $role = $data['role'] ?? null;

        if (array_key_exists('role', $data)) {
            $data['permissions'] = $role === UserRole::Administrator->value ? null : Access::clean($data['permissions'] ?? []);
            $data['host_id'] = $role === UserRole::Host->value ? ($data['host_id'] ?? null) : null;
        } else {
            unset($data['permissions']); // own account: role and permissions stay as they are
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('Email address'))
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label()),
                TextColumn::make('host.name')
                    ->label(__('Host profile'))
                    ->placeholder(__('—')),
                IconColumn::make('two_step')
                    ->label(__('2FA'))
                    ->state(fn (User $record): bool => $record->hasTwoFactor())
                    ->boolean(),
                TextColumn::make('password_set_at')
                    ->label(__('Account'))
                    ->badge()
                    ->state(fn (User $record): string => $record->password_set_at === null ? __('Invited') : __('Active'))
                    ->color(fn (User $record): string => $record->password_set_at === null ? 'warning' : 'success'),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data): array {
                        // Staff member without set permissions: show what currently applies.
                        if (($data['role'] ?? null) === UserRole::Staff->value && $data['permissions'] === null) {
                            $data['permissions'] = Access::defaultForStaff();
                        }

                        return $data;
                    })
                    ->mutateDataUsing(fn (array $data): array => static::storable($data)),
                Action::make('invite')
                    ->label(__('Send invitation'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->requiresConfirmation()
                    ->modalDescription(__('The user receives an email with a link to choose a (new) password. The link is valid for 7 days.'))
                    ->action(fn (User $record) => static::sendInvitation($record)),
                Action::make('disable_two_factor')
                    ->label(__('Disable 2FA'))
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Only do this if someone has lost both their phone and recovery codes. If 2FA is mandatory, they set it up again at their next login.'))
                    ->visible(fn (User $record): bool => $record->hasTwoFactor())
                    ->action(function (User $record) {
                        $record->disableTwoFactor();
                        Notification::make()->title(__('Two-factor authentication disabled'))->success()->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    /**
     * Sends the invitation. If mailing fails, the owner gets the
     * link to pass on themselves (it is not a password, and it works once).
     */
    public static function sendInvitation(User $user): void
    {
        try {
            Invitation::send($user);

            Notification::make()
                ->title(__('Invitation sent to :email', ['email' => $user->email]))
                ->success()
                ->send();
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title(__('Sending the email failed'))
                ->body(__('Pass this link on to :name yourself (valid for 7 days, works once): :link', ['name' => $user->name, 'link' => Invitation::link($user)]))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /** A new account gets an unknown password until the invitation has been used. */
    public static function newPassword(): string
    {
        return Str::password(64);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
