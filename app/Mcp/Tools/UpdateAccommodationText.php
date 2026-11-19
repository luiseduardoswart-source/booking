<?php

namespace App\Mcp\Tools;

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

#[Name('update_accommodation_text')]
#[Description('Changes one text of an accommodation in one language: its name, short description (one line on the overview), description (the main text, Markdown allowed: paragraphs, **bold**, lists, links) or house rules (plain text). The source language must always have a name; an empty text in another language makes that language show the source text again. The web address does not change with the name.')]
#[IsIdempotent]
class UpdateAccommodationText extends BookingTool
{
    /** Longest text per field (the same limits as the admin). */
    private const MAX = ['name' => 255, 'short_description' => 255, 'description' => 20000, 'house_rules' => 5000];

    protected function permission(): ?array
    {
        return ['accommodations', Access::EDIT];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation' => $this->accommodationField($schema)->required(),
            'field' => $schema->string()->enum(GetAccommodation::TEXTS)->required(),
            'language' => $schema->string()->enum(Languages::available())
                ->description('Language code. '.Languages::SOURCE.' is the source language the others fall back to.')->required(),
            'text' => $schema->string()->description('The new text. Empty = no own text in this language.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([
            'field' => ['required', Rule::in(GetAccommodation::TEXTS)],
            'language' => ['required', Rule::in(Languages::available())],
            'text' => ['present', 'nullable', 'string'],
        ]);

        $property = $this->accommodation($user, $request->get('accommodation'));
        $field = (string) $request->get('field');
        $language = (string) $request->get('language');
        $text = self::prepare($field, (string) $request->get('text'));

        if ($text !== null && mb_strlen($text) > self::MAX[$field]) {
            throw new ToolFailure("The {$field} may be at most ".self::MAX[$field].' characters.');
        }

        if ($field === 'description' && RichText::hasMedia($property->ownText($field, $language))) {
            throw new ToolFailure('This text contains photos or videos, which would be lost. Change it in the admin.');
        }

        if ($field === 'name' && $text === null && $language === Languages::SOURCE) {
            throw new ToolFailure('The name in the source language cannot be empty.');
        }

        $property->setOwnText($field, $language, $text);
        $property->save();

        return Response::structured([
            'accommodation' => $this->summaryOf($property),
            'field' => $field,
            'language' => $language,
            'texts' => GetAccommodation::texts($property->refresh())[$field],
        ]);
    }

    /**
     * The description is editor HTML (from Markdown, without HTML of its own);
     * the other texts are plain text.
     */
    private static function prepare(string $field, string $text): ?string
    {
        if ($field === 'description') {
            return RichText::fromMarkdown($text);
        }

        $text = trim(strip_tags($text));

        return $field === 'house_rules' ? ($text ?: null) : (trim((string) preg_replace('/\s+/', ' ', $text)) ?: null);
    }
}
