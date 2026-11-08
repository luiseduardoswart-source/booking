<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeTextGenerator implements AiTextGenerator
{
    /** The setting (group ai) with the API key. */
    public const KEY = 'claude_api_key';

    public const DEFAULT_MODEL = 'claude-opus-4-8';

    /** @param  ?string  $model  empty = the model under Settings → Keys */
    public function __construct(private ?string $model = null) {}

    public function generate(string $systemPrompt, string $userPrompt): string
    {
        return $this->conversation($systemPrompt, [['role' => 'user', 'content' => $userPrompt]], 2000)->text;
    }

    public function conversation(string $system, array $messages, ?int $maxTokens = null, ?string $effort = null): AiAnswer
    {
        $apiKey = Setting::get('ai', self::KEY);

        if (blank($apiKey)) {
            throw new RuntimeException(
                __('No Claude (Anthropic) API key set. Create a key at console.anthropic.com and enter it under Settings → Keys.')
            );
        }

        $model = $this->model ?: AiServices::defaultModel('claude');

        $request = [
            'model' => $model,
            'max_tokens' => $maxTokens ?? 2000,
            // The fixed part in the cache: in a conversation this saves the whole
            // context on every question (a cache hit costs a tenth).
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => $messages,
        ];

        if ($effort !== null && self::knowsEffort($model)) {
            $request['output_config'] = ['effort' => $effort];
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', $request)
            ->throw();

        // The answer is a list of content blocks; take the first text block.
        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';

        if (trim($text) === '') {
            throw new RuntimeException(__('The AI did not return a usable answer. Please try again.'));
        }

        return new AiAnswer(
            $text,
            $model,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            (int) $response->json('usage.cache_creation_input_tokens', 0),
            (int) $response->json('usage.cache_read_input_tokens', 0),
        );
    }

    /** Opus, Sonnet and Fable from 4.6 and Haiku from 5 support 'effort'; older models then reject the request. */
    private static function knowsEffort(string $model): bool
    {
        return (bool) preg_match('/^claude-((opus|sonnet|fable)-(4-[6-9]|[5-9])|haiku-[5-9])/', $model);
    }
}
