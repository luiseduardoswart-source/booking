<?php

namespace App\Livewire;

use App\Filament\Pages\Settings;
use App\Support\Languages;
use App\Support\WebsiteTexts;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * The Website texts tab under Settings: edit the website's fixed wording per
 * language. Each text is saved immediately, independent of the Save button.
 */
class WebsiteTextsAdmin extends Component
{
    use WithoutUrlPagination, WithPagination;

    private const PER_PAGE = 25;

    public string $search = '';

    public string $kind = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedKind(): void
    {
        $this->resetPage();
    }

    /** @return string|null what is wrong, or null if it was saved */
    #[Renderless]
    public function save(string $hash, string $locale, ?string $text): ?string
    {
        abort_unless(Settings::canAccess(), 403);
        abort_unless(in_array($locale, Languages::available(), true), 404);

        $row = WebsiteTexts::all()[$hash] ?? abort(404);

        return WebsiteTexts::save($row['key'], $locale, $text);
    }

    /** @return array<int, array<string, mixed>> each text with its value per language */
    private function rows(array $languages): array
    {
        $custom = collect($languages)->mapWithKeys(fn (string $locale) => [$locale => WebsiteTexts::custom($locale)]);

        return collect(WebsiteTexts::all())
            ->filter(fn (array $row) => $this->kind === '' || $row['kind'] === $this->kind)
            ->map(fn (array $row, string $hash) => [
                ...$row,
                'hash' => $hash,
                'values' => collect($languages)->mapWithKeys(fn (string $locale) => [
                    $locale => $custom[$locale][$row['key']] ?? WebsiteTexts::default($row['key'], $locale),
                ])->all(),
            ])
            ->filter(fn (array $row) => $this->search === ''
                || str_contains(mb_strtolower($row['key'].' '.implode(' ', $row['values'])), mb_strtolower(trim($this->search))))
            ->values()
            ->all();
    }

    public function render(): View
    {
        abort_unless(Settings::canAccess(), 403);

        $languages = Languages::active();
        $rows = $this->rows($languages);
        $page = $this->getPage();

        return view('livewire.website-texts-admin', [
            'languages' => $languages,
            'kinds' => array_keys(WebsiteTexts::SOURCES),
            'paginator' => new LengthAwarePaginator(
                array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                count($rows),
                self::PER_PAGE,
                $page,
            ),
        ]);
    }
}
