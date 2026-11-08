<?php

namespace App\Support;

use App\Models\AiImage;
use App\Models\Setting;

/**
 * Settings for AI images (ported from devsite's helpers): active image
 * provider, key, models and the monthly limit. The text AI (OpenAI/Claude) is
 * separate from this — Claude does not generate images, so the image provider
 * is OpenAI or Google (Nano Banana).
 */
class AiImages
{
    /** Active image provider: 'openai' or 'gemini' (Nano Banana). */
    public static function provider(): string
    {
        return Setting::get('ai', 'image_provider', 'openai') === 'gemini' ? 'gemini' : 'openai';
    }

    /** API key of the active image provider. */
    public static function key(): string
    {
        if (self::provider() === 'gemini') {
            return (string) Setting::get('ai', 'gemini_api_key');
        }

        return (string) Setting::get('ai', 'openai_api_key');
    }

    /** Are AI images usable? (key of the active provider present) */
    public static function available(): bool
    {
        return self::key() !== '';
    }

    /** Active model (per provider). */
    public static function model(): string
    {
        if (self::provider() === 'gemini') {
            return (string) (Setting::get('ai', 'image_gemini_model') ?: 'gemini-3.1-flash-image');
        }

        return (string) (Setting::get('ai', 'image_model') ?: 'dall-e-3');
    }

    /** Readable label of provider + model for the admin. */
    public static function label(): string
    {
        return (self::provider() === 'gemini' ? 'Nano Banana' : 'OpenAI').' — '.self::model();
    }

    /** Maximum number of images to generate per month (0 = unlimited). */
    public static function monthlyLimit(): int
    {
        return max(0, (int) Setting::get('ai', 'image_monthly_limit', 50));
    }

    /** Number of images generated in the current month (all statuses — every generation costs money). */
    public static function monthlyUsage(): int
    {
        try {
            return AiImage::where('month', now()->format('Y-m'))->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
