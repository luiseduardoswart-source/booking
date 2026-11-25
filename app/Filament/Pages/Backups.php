<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Backups\Backups as BackupFiles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Throwable;

/**
 * Backups (owner only): the list with a download and a delete button per
 * backup, a button to make one now and how long they are kept. The nightly
 * backup itself is the scheduled task backups:make.
 */
class Backups extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'backups';

    protected string $view = 'filament.pages.backups';

    public static function getNavigationLabel(): string
    {
        return __('Backups');
    }

    public function getTitle(): string
    {
        return __('Backups');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    /** On every request, including actions: only the owner. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function lastFailure(): ?string
    {
        return BackupFiles::lastFailure()?->setTimezone(config('app.timezone'))->isoFormat('LLL');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('make_backup')
                ->label(__('Make a backup now'))
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->action(function (): void {
                    try {
                        BackupFiles::run();
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title(__('The backup failed'))->body($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Backup made'))->send();
                }),
            Action::make('backup_settings')
                ->label(__('Backup settings'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->modalSubmitActionLabel(__('Save'))
                ->fillForm(fn (): array => [
                    'keep_days' => BackupFiles::keepDays(),
                    'include_files' => BackupFiles::includesFiles(),
                ])
                ->schema([
                    TextInput::make('keep_days')
                        ->label(__('Keep backups for (days)'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(BackupFiles::MAX_KEEP_DAYS)
                        ->required()
                        ->helperText(__('The newest backup is always kept.')),
                    Toggle::make('include_files')
                        ->label(__('Also back up photos, videos and documents'))
                        ->helperText(__('Switch off when the backups take too much space on your hosting: then only the database is backed up.')),
                ])
                ->action(function (array $data): void {
                    Setting::set('backups', 'keep_days', (string) (int) $data['keep_days']);
                    Setting::set('backups', 'include_files', $data['include_files'] ? '1' : '0');

                    Notification::make()->success()->title(__('Saved'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => BackupFiles::all())
            ->modelLabel(__('backup'))
            ->pluralModelLabel(__('backups'))
            ->paginated(false)
            ->columns([
                TextColumn::make('made_at')
                    ->label(__('Made on'))
                    ->formatStateUsing(fn ($state): string => $state->setTimezone(config('app.timezone'))->isoFormat('LLL')),
                TextColumn::make('size')
                    ->label(__('File size'))
                    ->formatStateUsing(fn ($state): string => Number::fileSize((int) $state, 1)),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (array $record): string => route('admin.backups.download', ['name' => $record['name']])),
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (array $record): void {
                        BackupFiles::delete($record['name'])
                            ? Notification::make()->success()->title(__('Backup deleted'))->send()
                            : Notification::make()->danger()->title(__('The backup could not be deleted'))->send();
                    }),
            ])
            ->emptyStateHeading(__('No backups yet'))
            ->emptyStateDescription(__('A backup is made every night. You can also make one now.'));
    }
}
