<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Code from another server runs with the rights of whoever is signed in
 * (often the owner, in the admin). A script from a CDN therefore carries an
 * integrity hash: when the file there changes, the browser refuses it.
 */
class ThirdPartyCodeTest extends TestCase
{
    public function test_every_script_from_another_server_carries_an_integrity_hash(): void
    {
        $missing = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('~<script\b[^>]*\bsrc="https?://[^"]+"[^>]*>~i', $file->getContents(), $tags);

            foreach ($tags[0] as $tag) {
                if (! preg_match('~\bintegrity="sha(256|384|512)-[A-Za-z0-9+/=]+"~', $tag) || ! str_contains($tag, 'crossorigin="anonymous"')) {
                    $missing[] = $file->getRelativePathname().': '.$tag;
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
