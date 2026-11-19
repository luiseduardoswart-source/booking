<?php

namespace App\Filament\Support;

use App\Support\DocumentNotReadable;
use App\Support\DocumentText;
use App\Support\Slugs;
use App\Support\Languages;
use App\Support\RichText;
use App\Support\Translations;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Form components for translatable fields (see HasTranslations): the
 * "Translations" block and the buttons that fill in empty languages. One place
 * for what used to be copied per admin screen.
 */
class TranslationFields
{
    public const RESULT = '{0} Nothing to translate: the source text is empty or all languages are already filled in.|{1} 1 language translated — check the text and save.|[2,*] :count languages translated — check the texts and save.';

    /**
     * One tab per language: pick a language and all its texts are together, with
     * the source language as the first tab. Same layout everywhere in the admin.
     *
     * @param  Closure(string $locale): array<int, Component>  $content  the fields of that language
     */
    public static function languageTabs(string $key, Closure $content, string $source = Languages::SOURCE, int $columns = 1): Tabs
    {
        return Tabs::make("languages_{$key}")
            ->contained(false)
            ->columnSpanFull()
            ->tabs(static::visibleLanguages($source)->map(fn (string $locale) => Tab::make(Languages::name($locale))
                ->id("{$key}_{$locale}")
                ->key("{$key}_{$locale}")
                ->columns($columns)
                ->components($content($locale)))
                ->all());
    }

    /**
     * Block with language tabs for the fields of a model: the source language in
     * the column itself, the other languages in the translations next to it. The
     * translate button in the header fills the empty languages in the form.
     *
     * @param  array<string, Closure(string $path, string $locale): Field>  $fields  field => builds the input field for that language
     * @param  array<int, string>  $translatable  what the translate button may fill (a slug is not translated)
     */
    public static function languageSection(string $title, ?string $description, array $fields, array $translatable, int $columns = 1, string $source = Languages::SOURCE): Section
    {
        $key = implode('_', $translatable);
        $paths = fn (string $locale) => array_map(fn (string $field) => static::path($field, $locale, $source), array_keys($fields));

        return Section::make($title)
            // Fixed key, unique per form: needed to address the button.
            ->key("translations_{$key}")
            ->description($description)
            ->headerActions([static::fillFormAction($translatable, $source)])
            ->components([
                static::languageTabs($key, fn (string $locale) => collect($fields)
                    ->map(fn (Closure $make, string $field) => static::emptyAsNull($make(static::path($field, $locale, $source), $locale)))
                    ->values()
                    ->all(), $source, $columns),
                ...static::hidden($paths, $source),
            ]);
    }

    /**
     * An emptied text editor stores "<p></p>"; save it as empty, so the
     * English text (or the default's) keeps applying in that language.
     */
    private static function emptyAsNull(Field $field): Field
    {
        return $field instanceof RichEditor
            ? $field->dehydrateStateUsing(fn ($state) => static::textOrNull($state))
            : $field;
    }

    /**
     * A disabled language stays along as hidden fields: otherwise saving would wipe its texts.
     *
     * @param  Closure(string $locale): array<int, string>  $paths  the form paths of that language
     * @return array<int, Hidden>
     */
    public static function hidden(Closure $paths, string $source = Languages::SOURCE): array
    {
        $visible = static::visibleLanguages($source);

        return collect(Languages::available())
            ->reject(fn (string $locale) => $visible->contains($locale))
            ->flatMap(fn (string $locale) => array_map(fn (string $path) => Hidden::make($path), $paths($locale)))
            ->all();
    }

    /** @return Collection<int, string> the source language and the languages enabled on the site */
    private static function visibleLanguages(string $source): Collection
    {
        return collect(Languages::active())->reject(fn (string $locale) => $locale === $source)->prepend($source)->values();
    }

    /** The slug field in a language; only the source language's slug is required. */
    public static function slugField(string $path, string $locale): TextInput
    {
        return TextInput::make($path)
            ->label(__('Slug (URL)'))
            ->maxLength(255)
            ->rule('regex:/^[a-z0-9-]+$/')
            ->rule(fn (?Model $record) => Slugs::rule($record))
            ->when($locale === Languages::SOURCE, fn (TextInput $field) => $field->required()->unique(ignoreRecord: true));
    }

    /** Fills a language's slug from the translated name, if that language has none yet. */
    public static function fillEmptySlug(string $locale, mixed $text, callable $get, callable $set): void
    {
        $path = static::path('slug', $locale);

        if (blank($get($path))) {
            $set($path, Str::slug((string) $text, '-', $locale));
        }
    }

    /**
     * Button above an overview: fills the empty languages for all records and
     * saves them straight away.
     *
     * @param  Closure(): iterable<Model>  $records
     * @param  array<int, string>  $fields
     * @param  string  $result  trans_choice text ({0}…|{1}…|[2,*]…), not yet translated
     */
    public static function fillEmptyLanguagesAction(string $explanation, Closure $records, array $fields, string $result): Action
    {
        return Action::make('translate')
            ->label(__('Translate empty languages automatically'))
            ->icon(Heroicon::OutlinedLanguage)
            // It writes into every record of the list: only with the right to edit them.
            ->authorize(fn ($livewire): bool => method_exists($livewire, 'getResource')
                && \App\Support\Access::may(auth()->user(), (string) \App\Support\Access::screenOf($livewire::getResource()), \App\Support\Access::EDIT))
            ->requiresConfirmation()
            ->modalDescription($explanation)
            ->action(function () use ($records, $fields, $result) {
                static::notify(fn () => Translations::fillEmptyLanguages($records(), $fields), $result);
            });
    }

    /**
     * Button in a form: fills the empty languages in the form from the
     * source text currently there. Does not save, so you can review the
     * translation first.
     *
     * @param  array<int, string>  $fields
     */
    public static function fillFormAction(array $fields, string $source = Languages::SOURCE): Action
    {
        return Action::make('translate_'.implode('_', $fields))
            ->label(__('Translate empty languages'))
            ->icon(Heroicon::OutlinedLanguage)
            ->link()
            ->action(function (Get $get, Set $set) use ($fields, $source) {
                static::notify(function () use ($fields, $source, $get, $set) {
                    $count = 0;

                    foreach ($fields as $field) {
                        $set("{$field}_translations", Translations::fillIn(
                            (string) $get($field),
                            $get("{$field}_translations"),
                            null,
                            $count,
                            $source,
                        ));
                    }

                    return $count;
                }, self::RESULT);
            });
    }

    /**
     * Same button for fields stored as language => text in a single list, such as
     * the mail texts: the source text is in the list itself.
     *
     * @param  array<int, string>  $paths  form path of each list
     */
    public static function fillLanguageListsAction(string $name, array $paths): Action
    {
        return Action::make($name)
            ->label(__('Translate empty languages'))
            ->icon(Heroicon::OutlinedLanguage)
            ->link()
            ->action(function (Get $get, Set $set) use ($paths) {
                static::notify(function () use ($paths, $get, $set) {
                    $count = 0;

                    foreach ($paths as $path) {
                        $texts = array_map(static::textOrNull(...), (array) $get($path));
                        $set($path, Translations::fillIn((string) ($texts[Languages::SOURCE] ?? ''), $texts, null, $count));
                    }

                    return $count;
                }, self::RESULT);
            });
    }

    /**
     * The content of a text box or text editor as text, or null if it is
     * empty. In the form a text editor gives a document structure and stores
     * empty content as "<p></p>"; neither counts as text. Merge fields
     * without any other text do count.
     */
    public static function textOrNull(mixed $content): ?string
    {
        if (is_array($content)) {
            $content = RichContentRenderer::make($content)->toUnsafeHtml();
        }

        if (! is_string($content)) {
            return null;
        }

        return RichText::filled($content) ? $content : null;
    }

    /**
     * Button in a form: puts the text from an uploaded document (PDF, Word,
     * .txt) into the box of the chosen language. Replaces what was there and does
     * not save; the file itself is not kept. Without $perLanguage there is no
     * language choice and the text goes into the field itself (as with the knowledge base).
     */
    public static function uploadAction(string $field, bool $perLanguage = true): Action
    {
        $languages = collect(Languages::active())
            ->reject(fn (string $locale) => $locale === Languages::SOURCE)
            ->prepend(Languages::SOURCE)
            ->mapWithKeys(fn (string $locale) => [$locale => Languages::name($locale)])
            ->all();

        return Action::make("upload_{$field}")
            ->label(__('Upload document'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->link()
            ->modalHeading(__('Import text from a document'))
            ->modalDescription($perLanguage
                ? __('Upload a PDF, Word file (.docx) or text file (.txt). The text replaces what is currently in the field for the chosen language. Nothing is saved yet and the file itself is not kept.')
                : __('Upload a PDF, Word file (.docx) or text file (.txt). The text replaces what is in the box now. Nothing is saved yet and the file itself is not kept.'))
            ->modalSubmitActionLabel(__('Use text'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('Document'))
                    ->acceptedFileTypes(DocumentText::MIME_TYPES)
                    ->maxSize(DocumentText::MAX_BYTES / 1024)
                    ->storeFiles(false)
                    ->required(),
                Select::make('language')
                    ->label(__('Language of the document'))
                    ->options($languages)
                    ->default(Languages::SOURCE)
                    ->native(false)
                    ->visible($perLanguage)
                    ->required(),
            ])
            ->action(function (array $data, Set $set) use ($field, $languages, $perLanguage) {
                $file = $data['file'] ?? null;
                $locale = $perLanguage && array_key_exists($data['language'] ?? '', $languages) ? $data['language'] : Languages::SOURCE;

                try {
                    $text = DocumentText::fromPath($file instanceof TemporaryUploadedFile ? $file->getRealPath() : '');
                } catch (DocumentNotReadable $e) {
                    Notification::make()->title(__('Import failed'))->body($e->getMessage())->danger()->send();

                    return;
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title(__('Import failed'))->body(__('Something went wrong while reading this file.'))->danger()->send();

                    return;
                } finally {
                    if ($file instanceof TemporaryUploadedFile) {
                        $file->delete();
                    }
                }

                $set(static::path($field, $locale), $text);

                Notification::make()
                    ->title($locale === Languages::SOURCE
                        ? __('Text imported. Check it and save.')
                        : __('Text imported into :language under Translations. Check it and save.', ['language' => $languages[$locale]]))
                    ->success()
                    ->send();
            });
    }

    /** The form path of a translatable field in a language; the source language is in the field itself. */
    private static function path(string $field, string $locale, string $source = Languages::SOURCE): string
    {
        return $locale === $source ? $field : "{$field}_translations.{$locale}";
    }

    /**
     * Runs the translation and reports the result; an error from the
     * translation service becomes a notification instead of an error page.
     *
     * @param  Closure(): int  $translate
     */
    public static function notify(Closure $translate, string $result): void
    {
        try {
            $count = $translate();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Translation failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans_choice($result, $count))
            ->success()
            ->send();
    }
}
