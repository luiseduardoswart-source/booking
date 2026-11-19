<?php

namespace App\Mcp\Tools;

use App\Models\Page;
use App\Models\User;
use App\Support\Access;
use App\Support\Languages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_pages')]
#[Description('Lists the content pages of the website (such as About us or Contact) with their title and text per language. Change them with update_page_text.')]
#[IsReadOnly]
class ListPages extends BookingTool
{
    protected function permission(): ?array
    {
        return ['pages', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $pages = Page::query()->orderBy('sort')->orderBy('title')->get()->map(fn (Page $page) => [
            'id' => $page->id,
            'url' => url($page->slug),
            'published' => $page->published,
            'title' => self::textPerLanguage($page, 'title'),
            'text' => self::textPerLanguage($page, 'content'),
        ])->all();

        if ($pages === []) {
            return Response::text('The website has no content pages.');
        }

        return Response::structured(['source_language' => Languages::SOURCE, 'pages' => $pages]);
    }
}
