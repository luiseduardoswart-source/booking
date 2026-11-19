<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Undo for layout changes made through the AI connection. Before every such change the
 * whole look of the site (style template, colours, header, font, buttons,
 * layout and the style editor's rules) is remembered; undo() puts the last
 * remembered look back. That restores everything as it was just before that
 * change, including anything changed by hand in between.
 */
final class StyleHistory
{
    /** How many changes can be undone. */
    public const MAX = 20;

    private const MAX_BYTES = 60000;

    /** The frontend settings that together make up the look. */
    public const KEYS = ['template_name', ...SiteTemplates::FIELDS, 'layout', 'visual_css'];

    public static function remember(string $description): void
    {
        $history = self::all();
        $history[] = [
            'at' => now()->toIso8601String(),
            'change' => $description,
            'values' => collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::get('frontend', $key)])->all(),
        ];

        self::store(array_slice($history, -self::MAX));
    }

    /**
     * Puts the look from before the last change back.
     *
     * @return string|null what was undone, or null when there is nothing to undo
     */
    public static function undo(): ?string
    {
        $history = self::all();
        $last = array_pop($history);

        if ($last === null) {
            return null;
        }

        foreach (self::KEYS as $key) {
            $value = $last['values'][$key] ?? null;
            Setting::set('frontend', $key, is_string($value) ? $value : null);
        }

        self::store($history);

        return (string) ($last['change'] ?? '');
    }

    /** What the next undo would reverse, or null. */
    public static function last(): ?string
    {
        $history = self::all();

        return $history === [] ? null : (string) (end($history)['change'] ?? '');
    }

    /**
     * @return list<array{at: string, change: string, values: array<string, ?string>}>
     */
    private static function all(): array
    {
        $history = json_decode((string) Setting::get('mcp', 'style_history'), true);

        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    /**
     * A setting is a TEXT column (64 KB in MySQL): with many style editor
     * rules the oldest steps make room.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private static function store(array $history): void
    {
        $json = json_encode($history, JSON_UNESCAPED_UNICODE);

        while ($history !== [] && strlen((string) $json) > self::MAX_BYTES) {
            array_shift($history);
            $json = json_encode($history, JSON_UNESCAPED_UNICODE);
        }

        Setting::set('mcp', 'style_history', $history === [] ? null : $json);
    }
}
