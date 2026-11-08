<?php

namespace App\Services\Importing;

use App\Support\Languages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Best-effort import based on what a page publicly reveals:
 * Open Graph tags (og:title, og:description, og:image) and schema.org JSON-LD.
 * Not a deep scraper — degrades gracefully if a platform blocks or exposes
 * nothing. The user fills in the rest in the wizard.
 */
class MetadataImporter implements ListingImportProvider
{
    public function import(string $url): ListingDraft
    {
        $language = Languages::default();

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; BookingImporter/1.0)',
            'Accept' => 'text/html',
            // Without a language preference the platform picks one itself — usually that
            // of the country where our server is. Then you get a German or
            // Dutch translation back while your listing is in English.
            'Accept-Language' => $language.','.$language.';q=0.9',
        ])->timeout(15)->get($this->withLanguage($url, $language));

        if (! $response->successful()) {
            throw new ListingImportException(__('The page could not be fetched (status :status).', ['status' => $response->status()]));
        }

        $html = $response->body();

        $draft = new ListingDraft(sourceUrl: $url);
        $draft->name = $this->og($html, 'og:title') ?? $this->titleTag($html);
        $draft->shortDescription = $this->og($html, 'og:description') ?? $this->meta($html, 'description');

        $images = $this->allOg($html, 'og:image');

        // Enrich with schema.org JSON-LD where present.
        $this->applyJsonLd($html, $draft, $images);

        if ($this->isAirbnb($url)) {
            $this->airbnb($html, $draft);
        }

        $draft->photoUrls = array_values(array_unique(array_filter($images)));

        if ($draft->name === null && $draft->photoUrls === []) {
            throw new ListingImportException(__('This page did not expose any usable data. Enter the property manually.'));
        }

        return $draft;
    }

    private function og(string $html, string $property): ?string
    {
        if (preg_match('/<meta[^>]+property=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function allOg(string $html, string $property): array
    {
        preg_match_all('/<meta[^>]+property=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m);

        return array_map(fn ($v) => $this->clean($v), $m[1] ?? []);
    }

    private function meta(string $html, string $name): ?string
    {
        if (preg_match('/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    private function titleTag(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]*)<\/title>/i', $html, $m)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $images
     */
    private function applyJsonLd(string $html, ListingDraft $draft, array &$images): void
    {
        if (! preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            return;
        }

        foreach ($m[1] as $block) {
            $data = json_decode(trim($block), true);

            if (! is_array($data)) {
                continue;
            }

            // Can be a single object or a list/@graph.
            foreach ($this->platwals($data) as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $draft->name ??= $this->text($node['name'] ?? null);
                $draft->description ??= $this->text($node['description'] ?? null);

                foreach ((array) ($node['image'] ?? []) as $img) {
                    $img = is_array($img) ? ($img['url'] ?? null) : $img;
                    if (is_string($img)) {
                        $images[] = $img;
                    }
                }

                $address = $node['address'] ?? null;
                if (is_array($address)) {
                    $draft->city ??= $this->text($address['addressLocality'] ?? null);
                    $draft->country ??= $this->text($address['addressCountry'] ?? null);
                }

                $geo = $node['geo'] ?? null;
                if (is_array($geo)) {
                    $draft->latitude ??= isset($geo['latitude']) ? (float) $geo['latitude'] : null;
                    $draft->longitude ??= isset($geo['longitude']) ? (float) $geo['longitude'] : null;
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<int, mixed>
     */
    private function platwals(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return $data['@graph'];
        }

        // List of objects?
        return array_is_list($data) ? $data : [$data];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $this->clean($value) : null;
    }

    private function isAirbnb(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'airbnb.com' || str_contains($host, '.airbnb.');
    }

    /**
     * Request the page in our own language. Airbnb looks at ?locale=, and it is
     * often already there because you copy the link from your own logged-in
     * session — then we have to overwrite it, not add it.
     */
    private function withLanguage(string $url, string $language): string
    {
        if (! $this->isAirbnb($url)) {
            return $url;
        }

        $share = parse_url($url);
        parse_str($share['query'] ?? '', $query);
        $query['locale'] = $language;

        return ($share['scheme'] ?? 'https').'://'.($share['host'] ?? '')
            .($share['path'] ?? '').'?'.http_build_query($query);
    }

    /**
     * Airbnb fills in the Open Graph tags the other way round compared with most
     * sites: og:title is a summary of the facts ("Appartement ·
     * Cartagena · ★4,85 · 1 slaapkamer · 2 bedden"), and og:description contains the
     * title the host wrote themselves. We want that as the name.
     */
    private function airbnb(string $html, ListingDraft $draft): void
    {
        $summary = $this->og($html, 'og:title');
        $ownTitle = $this->og($html, 'og:description');

        if (filled($ownTitle)) {
            $draft->name = Str::limit($ownTitle, 250, '');
            // The facts line is not a description; we would rather leave it empty than
            // have the host remove it everywhere.
            $draft->shortDescription = null;
        }

        if (filled($summary)) {
            $this->factsFromSummary($summary, $draft);
        }
    }

    /**
     * Extracts counts from the facts line. Set up language-independently: per
     * fragment we look for a number followed by a word, and recognise that word in
     * the languages we support. What we do not recognise we leave alone — the
     * host fills it in themselves.
     */
    private function factsFromSummary(string $summary, ListingDraft $draft): void
    {
        // Bedrooms and sofa beds before beds: "bedroom" and "sofa bed"
        // contain "bed", so the other way round they would be counted as a bed.
        $words = [
            'bedrooms' => ['slaapkamer', 'bedroom', 'schlafzimmer', 'chambre', 'dormitorio', '卧室'],
            'sofaBeds' => ['slaapbank', 'sofa bed', 'sofabed', 'schlafsofa', 'schlafcouch', 'canapé-lit', 'canapé lit', 'sofá cama', 'sofa cama', '沙发床'],
            'bathrooms' => ['badkamer', 'bathroom', 'bath', 'badezimmer', 'bad', 'salle de bain', 'bain', 'baño', 'bano', '浴室'],
            'beds' => ['bedden', 'bed', 'betten', 'bett', 'lits', 'lit', 'camas', 'cama', '床'],
        ];

        foreach (preg_split('/[·•|]/u', $summary) as $share) {
            if (! preg_match('/(\d+(?:[.,]\d+)?)\s*(.+)/u', trim($share), $m)) {
                continue;
            }

            $count = (int) round((float) str_replace(',', '.', $m[1]));
            $rest = mb_strtolower(trim($m[2]));

            foreach ($words as $field => $variants) {
                if ($draft->{$field} !== null) {
                    continue;
                }

                foreach ($variants as $variant) {
                    if (str_contains($rest, $variant)) {
                        $draft->{$field} = $count;

                        continue 3;
                    }
                }
            }
        }
    }

    private function clean(string $value): string
    {
        return trim(html_entity_decode(Str::of($value)->stripTags()->toString(), ENT_QUOTES | ENT_HTML5));
    }
}
