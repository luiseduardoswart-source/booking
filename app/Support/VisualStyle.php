<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Visual style editor (ported from the devsite project): the admin clicks
 * an element on the site and adjusts its formatting. The rules are stored
 * here as data — not free CSS — and strictly sanitised before they end up
 * as the last block in the <head>. The theme files are left untouched.
 */
class VisualStyle
{
    /**
     * Properties the visual editor may set.
     *
     * @return string[]
     */
    public static function properties(): array
    {
        return [
            'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-align',
            'line-height', 'letter-spacing', 'text-transform', 'text-decoration',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'border-radius', 'border-width', 'border-color', 'border-style',
            'max-width', 'width', 'height', 'min-height', 'opacity', 'display',
            'box-shadow', 'object-fit', 'gap',
            // photo position for a linked background photo (wins over the inline style)
            'background-position', 'background-size',
            // change order (move element up/down) without touching the HTML
            'order', 'flex-direction', 'flex-wrap', 'align-items', 'justify-content',
        ];
    }

    /**
     * The stored rules: [{selector, declarations: {property: value}}].
     *
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    public static function rules(): array
    {
        $raw = json_decode((string) Setting::get('frontend', 'visual_css'), true);

        return is_array($raw) ? self::clean($raw) : [];
    }

    /**
     * Sanitises a list of rules down to safe selectors, properties
     * and values only. A selector may not open/close a block, may not be an
     * at-rule and may not be able to break out of <style>; '>' is allowed (child combinator).
     *
     * @param  mixed  $rules
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    public static function clean($rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $allowed = self::properties();
        $clean = [];

        foreach (array_slice(array_values($rules), 0, 300) as $rule) {
            $selector = trim((string) ($rule['selector'] ?? ''));

            if ($selector === '' || preg_match('/[{}<;@]/', $selector) || mb_strlen($selector) > 300) {
                continue;
            }

            $decls = [];

            foreach ((array) ($rule['declarations'] ?? []) as $prop => $value) {
                $prop = strtolower(trim((string) $prop));

                if (! in_array($prop, $allowed, true)) {
                    continue;
                }

                $value = trim((string) $value);

                if ($value === '' || mb_strlen($value) > 200) {
                    continue;
                }

                if (preg_match('/[{}<>;]/', $value) || preg_match('/(expression|javascript|behavior|url\s*\()/i', $value)) {
                    continue;
                }

                $decls[$prop] = $value;
            }

            if ($decls !== []) {
                $clean[] = ['selector' => $selector, 'declarations' => $decls];
            }
        }

        return $clean;
    }

    /**
     * Stores a (sanitised) rule set; the editor always sends everything.
     *
     * @param  mixed  $rules
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    public static function save($rules): array
    {
        $clean = self::clean($rules);

        Setting::set('frontend', 'visual_css', $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE));

        return $clean;
    }

    /**
     * Converts the rules to CSS text. !important is not laziness here but
     * necessity: the site often styles with utility classes that would otherwise win,
     * and the live preview in the editor uses the same — without this the
     * site looks different from what you saw in the editor.
     */
    public static function css(): string
    {
        $css = '';

        foreach (self::rules() as $rule) {
            $decls = '';

            foreach ($rule['declarations'] as $prop => $value) {
                $value = preg_replace('/\s*!\s*important\s*$/i', '', $value);
                $decls .= $prop.':'.$value.' !important;';
            }

            $css .= $rule['selector'].'{'.$decls.'}';
        }

        return $css;
    }
}
