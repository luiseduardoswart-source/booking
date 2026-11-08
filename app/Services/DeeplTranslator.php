<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeeplTranslator implements MachineTranslator
{
    public function translate(string $text, string $from, string $to): string
    {
        $apiKey = Setting::get('translations', 'deepl_api_key');

        if ($apiKey === null || $apiKey === '') {
            throw new RuntimeException(
                __('No DeepL API key is configured. Create a free account at deepl.com/pro-api and enter the key in the settings.')
            );
        }

        // Gratis keys eindigen op ":fx" en gebruiken een ander endpoint.
        $endpoint = str_ends_with($apiKey, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $response = Http::withHeaders(['Authorization' => "DeepL-Auth-Key {$apiKey}"])
            ->asForm()
            ->post($endpoint, array_filter([
                'text' => $text,
                'source_lang' => strtoupper($from),
                'target_lang' => strtoupper($to === 'en' ? 'en-GB' : $to),
                'tag_handling' => $text !== strip_tags($text) ? 'html' : null,
            ]))
            ->throw();

        return $response->json('translations.0.text') ?? $text;
    }
}
