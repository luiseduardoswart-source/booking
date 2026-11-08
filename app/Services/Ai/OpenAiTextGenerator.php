<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiTextGenerator implements AiTextGenerator
{
    /** The setting (group ai) with the API key. */
    public const KEY = 'openai_api_key';

    public const DEFAULT_MODEL = 'gpt-4o';

    /** @param  ?string  $model  empty = the model under Settings → Keys */
    public function __construct(private ?string $model = null) {}

    public function generate(string $systemPrompt, string $userPrompt): string
    {
        return $this->conversation($systemPrompt, [['role' => 'user', 'content' => $userPrompt]])->text;
    }

    public function conversation(string $system, array $messages, ?int $maxTokens = null, ?string $effort = null): AiAnswer
    {
        $apiKey = Setting::get('ai', self::KEY);

        if (blank($apiKey)) {
            throw new RuntimeException(
                __('No OpenAI API key set. Create a key at platform.openai.com and enter it under Settings → Keys.')
            );
        }

        $model = $this->model ?: AiServices::defaultModel('openai');

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', array_filter([
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ...$messages],
                'max_completion_tokens' => $maxTokens,
            ], fn ($value) => $value !== null))
            ->throw();

        $text = (string) $response->json('choices.0.message.content', '');

        if (trim($text) === '') {
            throw new RuntimeException(__('The AI did not return a usable answer. Please try again.'));
        }

        // OpenAI counts cached tokens in prompt_tokens.
        $offCache = (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0);

        return new AiAnswer(
            $text,
            $model,
            (int) $response->json('usage.prompt_tokens', 0) - $offCache,
            (int) $response->json('usage.completion_tokens', 0),
            0,
            $offCache,
        );
    }
}
