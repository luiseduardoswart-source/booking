<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Google Gemini, with the same key as the AI images (Nano Banana). */
class GeminiTextGenerator implements AiTextGenerator
{
    /** The setting (group ai) with the API key. */
    public const KEY = 'gemini_api_key';

    public const DEFAULT_MODEL = 'gemini-2.5-flash';

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
                __('No Google (Gemini) API key has been set. Create a key at aistudio.google.com and enter it under Settings → Keys.')
            );
        }

        $model = $this->model ?: AiServices::defaultModel('gemini');

        $settings = array_filter([
            'maxOutputTokens' => $maxTokens,
            // A Flash model can run without thinking; that is faster and cheaper.
            'thinkingConfig' => $effort === 'low' && str_contains($model, 'flash') ? ['thinkingBudget' => 0] : null,
        ], fn ($value) => $value !== null);

        $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(60)
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', array_filter([
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => array_map(fn (array $message) => [
                    'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $message['content']]],
                ], $messages),
                'generationConfig' => $settings ?: null,
            ], fn ($value) => $value !== null))
            ->throw();

        $text = collect($response->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode("\n");

        if (trim($text) === '') {
            throw new RuntimeException(__('The AI did not return a usable answer. Please try again.'));
        }

        // Gemini counts cached tokens in the prompt; thinking counts as output.
        $offCache = (int) $response->json('usageMetadata.cachedContentTokenCount', 0);

        return new AiAnswer(
            $text,
            $model,
            (int) $response->json('usageMetadata.promptTokenCount', 0) - $offCache,
            (int) $response->json('usageMetadata.candidatesTokenCount', 0) + (int) $response->json('usageMetadata.thoughtsTokenCount', 0),
            0,
            $offCache,
        );
    }
}
