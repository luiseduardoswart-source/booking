<?php

namespace App\Livewire;

use App\Filament\Pages\Settings;
use App\Filament\Support\Switches;
use App\Filament\Support\TranslationFields;
use App\Models\KnowledgeDocument;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The knowledge base in the AI chat tab of Settings: frequently asked questions,
 * terms, directions… As plain text or from a PDF, Word or text file, for all
 * accommodations or for one. Everything is saved immediately, independent of
 * the Save button.
 */
class KnowledgeBaseAdmin extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    /** On every request, including actions and toggles: only those allowed into Settings. */
    public function boot(): void
    {
        abort_unless(Settings::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(KnowledgeDocument::query()->with('property'))
            ->modelLabel(__('document'))
            ->pluralModelLabel(__('documents'))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (KnowledgeDocument $record) => str($record->content)->squish()->limit(70)),
                TextColumn::make('property.name')
                    ->label(__('Accommodation'))
                    ->placeholder(__('All accommodations'))
                    ->wrap(),
                ToggleColumn::make('active')
                    ->label(__('Use in the chat'))
                    ->afterStateUpdated(fn () => Switches::notifySaved()),
            ])
            ->filters([
                SelectFilter::make('property_id')
                    ->label(__('Accommodation'))
                    ->relationship('property', 'name'),
            ])
            ->headerActions([
                CreateAction::make()->schema(self::fields()),
            ])
            ->recordActions([
                EditAction::make()->schema(self::fields()),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No documents yet'))
            ->emptyStateDescription(__('Add text or upload a PDF, Word or text file, for all accommodations or for one.'));
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    private static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Name'))
                ->placeholder(__('For example: Frequently asked questions'))
                ->required()
                ->maxLength(150),
            Select::make('property_id')
                ->label(__('Accommodation'))
                ->relationship('property', 'name')
                ->placeholder(__('All accommodations'))
                ->helperText(__('For one accommodation, the chat only reads this document on that accommodation\'s page and in the overview.'))
                ->searchable()
                ->preload(),
            Toggle::make('active')
                ->label(__('Use in the chat'))
                ->default(true),
            Textarea::make('content')
                ->label(__('Content'))
                ->helperText(__('The chat uses this text to answer visitors’ questions. Write in one language; the chat replies in the visitor’s language.'))
                ->hintAction(TranslationFields::uploadAction('content', perLanguage: false))
                ->rows(14)
                ->required()
                ->maxLength(KnowledgeDocument::MAX_CHARACTERS),
        ];
    }

    public function render(): View
    {
        return view('livewire.knowledge-base-admin');
    }
}
