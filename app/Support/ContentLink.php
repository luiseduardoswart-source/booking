<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Setting;
use Masterminds\HTML5;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Links between elements on the site and CMS content (ported from devsite,
 * adapted: here pages are the only source — modules do not exist).
 *
 * A link = {selector, page_id, field}. The middleware fills the linked
 * elements server-side in the HTML response, so the content is correct even
 * without JavaScript and for search engines. Useful for text that is fixed in a
 * layout template: it thus becomes manageable via Pages after all.
 */
class ContentLink
{
    /**
     * Fields of a page that can be linked; content is formatted
     * HTML, hero shows the page's hero photos (several = css carousel).
     */
    public const FIELDS = ['title', 'content', 'hero'];

    /**
     * @return array<int, array{selector: string, page_id: int, field: string}>
     */
    public static function all(): array
    {
        $raw = json_decode((string) Setting::get('frontend', 'content_links'), true);

        return is_array($raw) ? self::clean($raw) : [];
    }

    /**
     * @param  mixed  $links
     * @return array<int, array{selector: string, page_id: int, field: string}>
     */
    public static function clean($links): array
    {
        if (! is_array($links)) {
            return [];
        }

        $out = [];

        foreach (array_slice(array_values($links), 0, 200) as $k) {
            $selector = trim((string) ($k['selector'] ?? ''));

            // selector must be usable as CSS and must not contain HTML/an at-rule
            if ($selector === '' || preg_match('/[{}<;@]/', $selector) || mb_strlen($selector) > 300) {
                continue;
            }

            // fixed image from the media manager (no page as source)
            if (($k['field'] ?? '') === 'image') {
                $value = trim((string) ($k['value'] ?? ''));
                if ($value !== '' && ! str_contains($value, '..') && mb_strlen($value) <= 2000) {
                    $out[] = ['selector' => $selector, 'field' => 'image', 'value' => $value];
                }

                continue;
            }

            $field = in_array($k['field'] ?? '', self::FIELDS, true) ? $k['field'] : 'title';
            $pageId = (int) ($k['page_id'] ?? 0);

            if ($pageId > 0) {
                $out[] = ['selector' => $selector, 'page_id' => $pageId, 'field' => $field];
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $links
     * @return array<int, array{selector: string, page_id: int, field: string}>
     */
    public static function save($links): array
    {
        $clean = self::clean($links);

        Setting::set('frontend', 'content_links', $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE));

        return $clean;
    }

    /**
     * @return array{selector: string, page_id: int, field: string}|null
     */
    public static function beforeSelector(string $selector): ?array
    {
        foreach (self::all() as $k) {
            if ($k['selector'] === $selector) {
                return $k;
            }
        }

        return null;
    }

    public static function link(string $selector, int $pageId, string $field): void
    {
        $rest = array_values(array_filter(self::all(), fn ($k) => $k['selector'] !== $selector));
        $rest[] = ['selector' => $selector, 'page_id' => $pageId, 'field' => $field];

        self::save($rest);
    }

    /** Link a fixed image from the media manager to an element. */
    public static function linkImage(string $selector, string $path): void
    {
        $rest = array_values(array_filter(self::all(), fn ($k) => $k['selector'] !== $selector));
        $rest[] = ['selector' => $selector, 'field' => 'image', 'value' => $path];

        self::save($rest);
    }

    public static function disconnect(string $selector): void
    {
        self::save(array_values(array_filter(self::all(), fn ($k) => $k['selector'] !== $selector)));
    }

    /** Readable description for the editor. */
    public static function label(array $link): string
    {
        if ($link['field'] === 'image') {
            return __('Image').' "'.basename((string) ($link['value'] ?? '')).'"';
        }

        $page = Page::find($link['page_id']);
        $fieldName = match ($link['field']) {
            'content' => __('Content'),
            'hero' => __("Hero photos (carousel)"),
            default => __('Title'),
        };

        return ($page?->localized('title') ?? __('page').' #'.$link['page_id']).' — '.$fieldName;
    }

    /** All hero photos of a page as full URLs, in the configured order. */
    private static function heroUrls(Page $page): array
    {
        $urls = [];
        foreach (($page->hero_images ?? []) as $hero) {
            $path = trim((string) ($hero['image'] ?? ''));
            if ($path !== '') {
                $urls[] = media_url($path) ?: $path;
            }
        }

        return $urls;
    }

    /**
     * Applies all links to an HTML response.
     * Without links nothing is parsed, so there is no cost at all.
     */
    public static function apply(string $html): string
    {
        $links = self::all();

        if ($links === [] || ! str_contains($html, '</body>')) {
            return $html;
        }

        try {
            $parser = new HTML5(['disable_html_ns' => true]);
            $dom = $parser->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $converter = new CssSelectorConverter;
            $changed = false;
            $carousels = [];   // hero links with several photos: [selector, urls]

            foreach ($links as $link) {
                if ($link['field'] === 'image') {
                    $mode = 'image';
                    $value = $link['value'];
                } elseif ($link['field'] === 'hero') {
                    // the page's hero photos: the first photo is the value; switching
                    // between several photos happens below with css keyframes
                    $mode = 'image';
                    $page = Page::find($link['page_id']);
                    $urls = $page ? self::heroUrls($page) : [];
                    $value = $urls[0] ?? null;
                    if (count($urls) > 1) {
                        $carousels[] = ['selector' => $link['selector'], 'urls' => $urls];
                    }
                } else {
                    $mode = $link['field'] === 'content' ? 'html' : 'text';
                    $page = Page::find($link['page_id']);
                    $value = $page?->localized($link['field']);
                }

                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    $nodes = $xpath->query($converter->toXPath($link['selector']));
                } catch (\Throwable) {
                    continue;   // unusable selector: skip it, do not break the page
                }

                if (! $nodes || $nodes->length === 0) {
                    continue;
                }

                foreach ($nodes as $node) {
                    if (self::fill($dom, $node, $mode, $value)) {
                        $changed = true;
                    }
                }
            }

            // rotating hero backgrounds: pure css keyframes, so without javascript
            // and also working for visitors. background-image switches hard per keyframe.
            if ($carousels !== []) {
                $css = '';
                foreach ($carousels as $i => $c) {
                    $name = 'sw-hero-'.$i;
                    $n = count($c['urls']);
                    $step = 100 / $n;
                    $frames = '';
                    foreach ($c['urls'] as $k => $url) {
                        $of = round($k * $step, 2);
                        $until = round(($k + 1) * $step - 1, 2);
                        $frames .= $of.'%,'.$until."%{background-image:url('".addslashes($url)."')}";
                    }
                    $frames .= "100%{background-image:url('".addslashes($c['urls'][0])."')}";
                    $css .= '@keyframes '.$name.'{'.$frames.'}'
                        .$c['selector'].'{animation:'.$name.' '.($n * 6).'s infinite;}';
                }
                $head = $dom->getElementsByTagName('head')->item(0);
                if ($head) {
                    $styleEl = $dom->createElement('style');
                    $styleEl->appendChild($dom->createTextNode($css));
                    $head->appendChild($styleEl);
                    $changed = true;
                }
            }

            return $changed ? $parser->saveHTML($dom) : $html;
        } catch (\Throwable $e) {
            report($e);

            return $html;   // better the original page than a broken one
        }
    }

    private static function fill(\DOMDocument $dom, \DOMNode $node, string $mode, string $value): bool
    {
        if (! $node instanceof \DOMElement) {
            return false;
        }

        if ($mode === 'image') {
            $url = media_url($value) ?: $value;
            if (strtolower($node->tagName) === 'img') {
                $node->setAttribute('src', $url);
                $node->removeAttribute('srcset');
            } else {
                // background image on e.g. a hero div
                $style = $node->getAttribute('style');
                // The container is normally as tall as its content, so a background photo
                // would inevitably be cropped. That is why it gets the photo's aspect
                // ratio (aspect-ratio): it then grows with the photo and shows it
                // in full. If there is more content than the photo is tall, the
                // container simply stretches further — cover fills in, and the Photo
                // position button in the editor determines which part stays in view.
                $ratio = '';
                $path = ltrim((string) $value, '/');
                if (! preg_match('#^https?://#i', $path)) {
                    try {
                        $abs = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
                        $dim = is_file($abs) ? @getimagesize($abs) : false;
                        if ($dim && $dim[0] > 0 && $dim[1] > 0) {
                            $ratio = ';aspect-ratio:'.$dim[0].'/'.$dim[1];
                        }
                    } catch (\Throwable) {
                        // dimensions not readable: then without ratio, as before
                    }
                }

                $style = rtrim(preg_replace('/(background(-image|-size|-position|-repeat)?|aspect-ratio)\s*:[^;]*;?/i', '', $style), '; ');
                $node->setAttribute('style', trim(
                    $style.";background-image:url('".addslashes($url)."');"
                    .'background-size:cover;background-position:center top;background-repeat:no-repeat'.$ratio,
                    '; '
                ));
            }

            return true;
        }

        if ($mode === 'html') {
            // cleanHtml removes scripts and handlers; the fragment is read with the
            // HTML5 parser so stray <br> and unclosed tags just work.
            try {
                $fragment = (new HTML5(['disable_html_ns' => true]))->loadHTMLFragment(self::cleanHtml($value));

                // Block content (div, heading, list, …) may not go inside e.g. a <p> or <a>: the
                // browser breaks it open again when parsing, so the page looks
                // different from how we built it and selectors start to shift.
                // In that case we replace the element itself with the block.
                if (self::fragmentHasBlock($fragment) && self::isOnlyInline($node) && $node->parentNode) {
                    foreach (iterator_to_array($fragment->childNodes) as $child) {
                        $node->parentNode->insertBefore($dom->importNode($child, true), $node);
                    }
                    $node->parentNode->removeChild($node);

                    return true;
                }

                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
                foreach (iterator_to_array($fragment->childNodes) as $child) {
                    $node->appendChild($dom->importNode($child, true));
                }

                return true;
            } catch (\Throwable) {
                // fall back to plain text
            }
        }

        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }
        $node->appendChild($dom->createTextNode(strip_tags($value)));

        return true;
    }

    /**
     * Makes admin HTML safe to insert: removes script/iframe/
     * object/embed/link/meta tags, all on* handlers and javascript: URLs.
     */
    public static function cleanHtml(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|link|meta|base|form)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|link|meta|base)\b[^>]*/?>#is', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#\b(href|src)\s*=\s*("|\')\s*javascript:[^"\']*(\2)#i', '$1=$2#$3', $html);

        return (string) $html;
    }

    /** Does the fragment contain block elements (which do not fit in a <p>/<a>/heading)? */
    private static function fragmentHasBlock(\DOMDocumentFragment $fragment): bool
    {
        $block = ['div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'form',
            'table', 'ul', 'ol', 'figure', 'blockquote', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre'];

        foreach ($fragment->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), $block, true)) {
                return true;
            }
        }

        return false;
    }

    /** Per HTML, may this element not contain block content? */
    private static function isOnlyInline(\DOMElement $node): bool
    {
        return in_array(strtolower($node->tagName), [
            'p', 'span', 'a', 'em', 'strong', 'small', 'b', 'i', 'u', 'mark',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'label', 'sub', 'sup',
        ], true);
    }
}
