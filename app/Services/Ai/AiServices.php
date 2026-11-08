<?php

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * The AI services for text: which exist and how to create one. Both the
 * AI texts (Settings → Website → AI texts) and the site chat choose
 * from these; the keys are under Settings → Keys.
 */
final class AiServices
{
    /** @var array<string, class-string<AiTextGenerator>> */
    private const CLASSES = [
        'claude' => ClaudeTextGenerator::class,
        'openai' => OpenAiTextGenerator::class,
        'gemini' => GeminiTextGenerator::class,
    ];

    /** @return array<string, string> service => name */
    public static function names(): array
    {
        return [
            'claude' => 'Claude (Anthropic)',
            'openai' => 'OpenAI (ChatGPT)',
            'gemini' => 'Google Gemini',
        ];
    }

    /** An unknown service (or 'none') gives the Null variant, which explains what is missing. */
    public static function make(?string $service, ?string $model = null): AiTextGenerator
    {
        $class = self::CLASSES[$service] ?? null;

        return $class !== null ? new $class(filled($model) ? $model : null) : new NullAiTextGenerator;
    }

    public static function hasKey(?string $service): bool
    {
        $class = self::CLASSES[$service] ?? null;

        return $class !== null && filled(Setting::get('ai', $class::KEY));
    }

    /** The model used when there is no own choice: the one under Keys, otherwise the default. */
    public static function defaultModel(string $service): ?string
    {
        $class = self::CLASSES[$service] ?? null;

        return $class === null ? null : (Setting::get('ai', "{$service}_model") ?: $class::DEFAULT_MODEL);
    }
}
