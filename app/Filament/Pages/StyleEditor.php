<?php

namespace App\Filament\Pages;

use App\Support\ContentLink;
use App\Support\VisualStyle;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Visual style editor (ported from the devsite project): the site is shown in
 * an iframe, you click an element and adjust its styling. The rules are
 * stored as data (setting frontend/visual_css) and placed as the last block in
 * the <head> of the site — the layout files stay untouched.
 */
class StyleEditor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    // No menu item of its own: you open the editor via the button under
    // Settings → Layout & style.
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.style-editor';

    public static function getNavigationLabel(): string
    {
        return __('Style editor');
    }

    public function getTitle(): string
    {
        return __('Style editor');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'rules' => VisualStyle::rules(),
            'previewUrl' => route('home', ['style-editor' => 1]),
            'links' => array_map(fn (array $k) => [
                'selector' => $k['selector'],
                'label' => ContentLink::label($k),
                'field' => $k['field'],
            ], ContentLink::all()),
            'linkPages' => \App\Models\Page::query()->orderBy('title')->get()
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->localized('title')])->all(),
        ];
    }

    /**
     * Saves the full rule set (the editor always sends everything) and
     * returns the cleaned set + the CSS for the iframe.
     *
     * @return array{rules: array<int, array{selector: string, declarations: array<string, string>}>, css: string}
     */
    public function save(array $rules): array
    {
        abort_unless(static::canAccess(), 403);

        $clean = VisualStyle::save($rules);

        return ['rules' => $clean, 'css' => VisualStyle::css()];
    }

    /**
     * New page that goes straight into the menu (via the + button in the menu
     * or "add as page" on a fixed menu item). Returns the site link and the
     * link to the Pages admin.
     *
     * @return array{url: string, beheer: string}
     */
    public function quickPage(string $title): array
    {
        abort_unless(static::canAccess(), 403);

        $title = trim($title);
        abort_if($title === '', 422);

        $page = \App\Models\Page::create([
            'title' => \Illuminate\Support\Str::limit($title, 120, ''),
            'in_menu' => true,
        ]);

        return [
            'url' => url('/'.$page->slug),
            'admin' => \App\Filament\Resources\Pages\PageResource::getUrl('index'),
        ];
    }

    /**
     * Links an element to page content: the element from now on shows the
     * title or the content of that page (filled server-side, multilingual).
     *
     * @return array{label: string}
     */
    public function link(string $selector, int $pageId, string $field): array
    {
        abort_unless(static::canAccess(), 403);

        $selector = trim($selector);
        abort_if($selector === '' || preg_match('/[{}<;@]/', $selector) || mb_strlen($selector) > 300, 422);
        abort_unless(in_array($field, ContentLink::FIELDS, true), 422);

        $page = \App\Models\Page::findOrFail($pageId);

        ContentLink::link($selector, $page->id, $field);

        return ['label' => ContentLink::label(['selector' => $selector, 'page_id' => $page->id, 'field' => $field])];
    }

    public function disconnect(string $selector): void
    {
        abort_unless(static::canAccess(), 403);

        ContentLink::disconnect(trim($selector));
    }

    /**
     * Link a fixed image from the media manager to an element: an <img>
     * gets the image as src, any other element as background (cover).
     *
     * @return array{label: string}
     */
    public function linkImage(string $selector, string $path): array
    {
        abort_unless(static::canAccess(), 403);

        $selector = trim($selector);
        abort_if($selector === '' || preg_match('/[{}<;@]/', $selector) || mb_strlen($selector) > 300, 422);

        $path = trim($path);
        abort_if($path === '' || str_contains($path, '..') || mb_strlen($path) > 2000, 422);

        ContentLink::linkImage($selector, $path);

        return ['label' => ContentLink::label(['selector' => $selector, 'field' => 'image', 'value' => $path])];
    }
}
