<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * English becomes the source language: the main column now holds the English text and
 * Dutch is an ordinary translation that can be turned off.
 *
 * Nothing is lost: the Dutch text moves to the translations. If
 * there is no English text yet, the main column stays as it is until there is;
 * an empty field would break the site.
 *
 * Amenities and accommodation types do not take part: their default names are the
 * keys in the language files.
 */
return new class extends Migration
{
    private const FIELDS = [
        'properties' => ['name', 'short_description', 'description', 'meta_title', 'meta_description', 'house_rules', 'rental_terms', 'cancellation_policy_text'],
        'pages' => ['title', 'content', 'meta_title', 'meta_description'],
        'hosts' => ['bio'],
        'booking_rules' => ['name'],
    ];

    public function up(): void
    {
        $this->swap(of: 'nl', to: 'en');
    }

    public function down(): void
    {
        $this->swap(of: 'en', to: 'nl');
    }

    private function swap(string $of, string $to): void
    {
        foreach (self::FIELDS as $table => $fields) {
            foreach (DB::table($table)->get() as $row) {
                $change = [];

                foreach ($fields as $field) {
                    [$text, $translations] = $this->switched($row->{$field}, $this->read($row->{"{$field}_translations"}), $of, $to);
                    $change[$field] = $text;
                    $change["{$field}_translations"] = $this->write($translations);
                }

                if (in_array($table, ['properties', 'pages'], true)) {
                    $change = [...$change, ...$this->slugs($row, $of, $to)];
                }

                if ($table === 'properties') {
                    $change['highlights'] = $this->highlights($row->highlights, $of, $to);
                }

                DB::table($table)->where('id', $row->id)->update($change);
            }
        }

        foreach (DB::table('media')->where('custom_properties', 'like', '%label%')->get() as $media) {
            $properties = $this->read($media->custom_properties);
            [$label, $translations] = $this->switched($properties['label'] ?? null, $properties['label_translations'] ?? [], $of, $to);

            DB::table('media')->where('id', $media->id)->update([
                'custom_properties' => json_encode(['label' => $label, 'label_translations' => $translations] + $properties, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function switched(?string $text, array $translations, string $of, string $to): array
    {
        $new = filled($translations[$to] ?? null) ? $translations[$to] : $text;
        unset($translations[$to]);

        if (filled($text)) {
            $translations[$of] = $text;
        }

        return [$new, $translations];
    }

    /**
     * A slug without a translation stays: the address must not be empty, and
     * this way the old address keeps working.
     *
     * @return array<string, mixed>
     */
    private function slugs(object $row, string $of, string $to): array
    {
        $slugs = $this->read($row->slug_translations);

        if (blank($slugs[$to] ?? null)) {
            return [];
        }

        [$slug, $slugs] = $this->switched($row->slug, $slugs, $of, $to);

        return ['slug' => $slug, 'slug_translations' => $this->write($slugs)];
    }

    private function highlights(?string $json, string $of, string $to): ?string
    {
        $rows = $this->read($json);

        foreach ($rows as $i => $row) {
            foreach (['title', 'text'] as $field) {
                [$rows[$i][$field], $rows[$i]["{$field}_translations"]] = $this->switched($row[$field] ?? null, $row["{$field}_translations"] ?? [], $of, $to);
            }
        }

        return $rows === [] ? $json : $this->write($rows);
    }

    /** @return array<mixed> */
    private function read(?string $json): array
    {
        return json_decode((string) $json, true) ?: [];
    }

    /** @param array<mixed> $value */
    private function write(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
};
