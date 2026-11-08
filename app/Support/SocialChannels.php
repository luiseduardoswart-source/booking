<?php

namespace App\Support;

use App\Models\Property;

/**
 * The social media channels the site supports. General links are set in the
 * default accommodation settings; an accommodation can override them per
 * channel with its own link (empty = the general link).
 */
class SocialChannels
{
    /**
     * key => [label, icon (site icon), example URL]
     */
    public const CHANNELS = [
        'facebook' => ['Facebook', 'facebook', 'https://facebook.com/...'],
        'instagram' => ['Instagram', 'instagram', 'https://instagram.com/...'],
        'x' => ['X (Twitter)', 'x-twitter', 'https://x.com/...'],
        'tiktok' => ['TikTok', 'tiktok', 'https://tiktok.com/@...'],
        'youtube' => ['YouTube', 'youtube', 'https://youtube.com/@...'],
        'pinterest' => ['Pinterest', 'pinterest', 'https://pinterest.com/...'],
        'linkedin' => ['LinkedIn', 'linkedin', 'https://linkedin.com/company/...'],
        'whatsapp' => ['WhatsApp', 'whatsapp', 'https://wa.me/31612345678'],
    ];

    /**
     * @return string[] keys of all channels
     */
    public static function keys(): array
    {
        return array_keys(self::CHANNELS);
    }

    public static function label(string $channel): string
    {
        return self::CHANNELS[$channel][0] ?? ucfirst($channel);
    }

    public static function icon(string $channel): string
    {
        return self::CHANNELS[$channel][1] ?? 'link';
    }

    public static function example(string $channel): string
    {
        return self::CHANNELS[$channel][2] ?? 'https://...';
    }

    /**
     * The general links from the defaults (only filled-in ones).
     *
     * @return array<string, string> channel => url
     */
    public static function general(): array
    {
        return collect(Property::master()->social_links ?? [])
            ->only(self::keys())
            ->filter(fn (?string $url) => filled($url))
            ->all();
    }

    /**
     * Only http(s) links make it into the site — this prevents javascript: or
     * data: from ending up in a link by accident (or on purpose).
     */
    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
