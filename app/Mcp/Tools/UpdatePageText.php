<?php

namespace App\Mcp\Tools;

use App\Models\Page;
use App\Models\User;
use App\Support\Access;
use App\Support\Languages;
use App\Support\RichText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_page_text')]
#[Description('Changes the title or text of a content page in one language (the id comes from list_pages). The text may use Markdown: paragraphs, **bold**, lists, links. The source language must keep a title; an empty text in another language makes that language show the source text again. The web address does not change. Photos and forms on the page stay as they are.')]
#[IsIdempotent]
class UpdatePageText extends BookingTool
{
    protected function permission(): ?array
    {
        return ['pages', Access::EDIT];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->description('The id of the page, from list_pages.')->required(),
            'field' => $schema->string()->enum(['title', 'text'])->required(),
            'language' => $schema->string()->enum(Languages::available())->description('Language code. '.Languages::SOURCE.' is the source language.')->required(),
            'text' => $schema->string()->description('The new title or text. Empty = no own text in this language.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([
            'page_id' => ['required', 'integer'],
            'field' => ['required', Rule::in(['title', 'text'])],
            'language' => ['required', Rule::in(Languages::available())],
            'text' => ['present', 'nullable', 'string', 'max:50000'],
        ]);

        $page = Page::query()->find((int) $request->get('page_id')) ?? throw new ToolFailure('No page with that id found.');
        $field = $request->get('field') === 'title' ? 'title' : 'content';
        $language = (string) $request->get('language');
        $raw = (string) $request->get('text');
        $text = $field === 'title'
            ? (trim((string) preg_replace('/\s+/', ' ', strip_tags($raw))) ?: null)
            : RichText::fromMarkdown($raw);

        if ($field === 'title' && $text !== null && mb_strlen($text) > 255) {
            throw new ToolFailure('The title may be at most 255 characters.');
        }

        if ($field === 'content' && RichText::hasMedia($page->ownText($field, $language))) {
            throw new ToolFailure('This text contains photos or videos, which would be lost. Change it in the admin.');
        }

        if ($field === 'title' && $text === null && $language === Languages::SOURCE) {
            throw new ToolFailure('The title in the source language cannot be empty.');
        }

        $page->setOwnText($field, $language, $text);
        $page->save();
        $page->refresh();

        return Response::structured([
            'page_id' => $page->id,
            'url' => url($page->slug),
            'title' => self::textPerLanguage($page, 'title'),
            'text' => self::textPerLanguage($page, 'content'),
        ]);
    }
}
