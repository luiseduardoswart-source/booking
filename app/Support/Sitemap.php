<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Property;
use Illuminate\Support\Carbon;
use XMLWriter;

/**
 * The sitemap for search engines: every page meant for search results, in
 * every language, each with its addresses in the other languages
 * (sitemaps.org with xhtml:link, as Google describes for multilingual sites).
 */
final class Sitemap
{
    public static function xml(): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        $properties = Property::query()->where('active', true)->orderBy('id')->get();
        $pages = Page::query()->published()->orderBy('id')->get();

        self::page($xml, fn (string $locale) => LocalizedUrl::home($locale), $properties->max('updated_at'));
        self::page($xml, fn (string $locale) => LocalizedUrl::contact($locale), null);

        foreach ([...$properties, ...$pages] as $record) {
            self::page($xml, fn (string $locale) => LocalizedUrl::of($record, $locale), $record->updated_at);
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * One page: a url element per language, each listing all languages.
     *
     * @param  callable(string): string  $address
     */
    private static function page(XMLWriter $xml, callable $address, ?Carbon $changed): void
    {
        $languages = Languages::active();
        $addresses = array_combine($languages, array_map($address, $languages));

        foreach ($addresses as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url);

            if ($changed !== null) {
                $xml->writeElement('lastmod', $changed->toAtomString());
            }

            foreach ([...$addresses, 'x-default' => $address(Languages::default())] as $language => $alternate) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', (string) $language);
                $xml->writeAttribute('href', $alternate);
                $xml->endElement();
            }

            $xml->endElement();
        }
    }

    /** robots.txt: everything open except the admin, with the address of the sitemap. */
    public static function robots(): string
    {
        return implode("\n", [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /install',
            'Disallow: /livewire',
            '',
            'Sitemap: '.url('sitemap.xml'),
            '',
        ]);
    }
}
