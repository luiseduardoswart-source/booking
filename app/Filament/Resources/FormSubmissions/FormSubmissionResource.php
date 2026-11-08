<?php

namespace App\Filament\Resources\FormSubmissions;

use App\Filament\Concerns\WithAccess;
use App\Filament\Resources\FormSubmissions\Pages\ManageFormSubmissions;
use App\Filament\Support\Switches;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Support\FormAnswers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * What visitors sent with the contact form and the own forms. The booking
 * form is not here: its answers are with the booking.
 */
class FormSubmissionResource extends Resource
{
    use WithAccess;

    protected static ?string $model = FormSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('submission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('submissions');
    }

    public static function getNavigationLabel(): string
    {
        return __('Submissions');
    }

    /** The number of submissions nobody has handled yet. */
    public static function getNavigationBadge(): ?string
    {
        $open = FormSubmission::query()->where('handled', false)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Received'))
                    ->dateTime('j M Y H:i')
                    ->sortable(),
                TextColumn::make('form.name')
                    ->label(__('Form'))
                    ->formatStateUsing(fn (FormSubmission $record) => $record->form?->isSystem() ? __($record->form->name) : $record->form?->name),
                TextColumn::make('summary')
                    ->label(__('Message'))
                    ->state(fn (FormSubmission $record): string => Str::limit(collect($record->data)
                        ->reject(fn (array $answer) => ($answer['type'] ?? null) === 'heading')
                        ->map(fn (array $answer) => FormAnswers::value($answer, $record->form))
                        ->filter()
                        ->implode(' · '), 100))
                    ->wrap(),
                Switches::column('handled', __('Handled'), static::class),
            ])
            ->filters([
                SelectFilter::make('form')
                    ->label(__('Form'))
                    ->relationship('form', 'name', fn ($query) => $query->where(fn ($q) => $q->whereNull('system')->orWhere('system', '!=', Form::BOOKING)))
                    ->getOptionLabelFromRecordUsing(fn (Form $record) => $record->isSystem() ? __($record->name) : $record->name),
                TernaryFilter::make('handled')
                    ->label(__('Handled')),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (FormSubmission $record) => ($record->form?->isSystem() ? __($record->form->name) : $record->form?->name).' — '.$record->created_at->translatedFormat('j M Y H:i'))
                    ->modalContent(fn (FormSubmission $record) => view('filament.form-submission', ['submission' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFormSubmissions::route('/'),
        ];
    }
}
