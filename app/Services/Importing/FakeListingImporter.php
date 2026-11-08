<?php

namespace App\Services\Importing;

/**
 * Fake importer for tests: returns a preconfigured draft.
 */
class FakeListingImporter implements ListingImportProvider
{
    public ?ListingDraft $draft = null;

    public ?ListingImportException $exception = null;

    public function import(string $url): ListingDraft
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->draft ?? new ListingDraft(name: 'Testwoning', sourceUrl: $url);
    }
}
