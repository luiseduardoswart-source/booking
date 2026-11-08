<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Default when no AI service is chosen: gives a clear explanation.
 */
class NullAiTextGenerator implements AiTextGenerator
{
    public function generate(string $systemPrompt, string $userPrompt): string
    {
        return $this->conversation($systemPrompt, [['role' => 'user', 'content' => $userPrompt]])->text;
    }

    public function conversation(string $system, array $messages, ?int $maxTokens = null, ?string $effort = null): AiAnswer
    {
        throw new RuntimeException(
            __('First choose an AI service under Settings → Website → AI texts (OpenAI or Claude) and enter the matching API key.')
        );
    }
}
