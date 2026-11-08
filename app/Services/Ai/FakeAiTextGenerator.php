<?php

namespace App\Services\Ai;

/**
 * Fake generator for tests: returns a fixed, valid JSON answer without an
 * external call.
 */
class FakeAiTextGenerator implements AiTextGenerator
{
    public function generate(string $systemPrompt, string $userPrompt): string
    {
        return $this->conversation($systemPrompt, [['role' => 'user', 'content' => $userPrompt]])->text;
    }

    public function conversation(string $system, array $messages, ?int $maxTokens = null, ?string $effort = null): AiAnswer
    {
        return new AiAnswer(json_encode([
            'short_description' => 'Test korte omschrijving',
            'description' => "Test beschrijving alinea één.\n\nTest beschrijving alinea twee.",
            'meta_title' => 'Test meta-titel',
            'meta_description' => 'Test meta-omschrijving',
        ], JSON_UNESCAPED_UNICODE), 'fake', 100, 20);
    }
}
