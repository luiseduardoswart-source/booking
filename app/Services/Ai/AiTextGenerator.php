<?php

namespace App\Services\Ai;

interface AiTextGenerator
{
    /**
     * Generate text based on a system prompt and a user prompt.
     *
     * @throws \RuntimeException if no AI service is configured or the answer is unusable
     */
    public function generate(string $systemPrompt, string $userPrompt): string;

    /**
     * Continue a conversation: the messages so far, the last one from the
     * user. $effort 'low' asks for a quick, short answer on models
     * that support it (the site chat); null = the model's default.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     *
     * @throws \RuntimeException if no AI service is configured or the answer is unusable
     */
    public function conversation(string $system, array $messages, ?int $maxTokens = null, ?string $effort = null): AiAnswer;
}
