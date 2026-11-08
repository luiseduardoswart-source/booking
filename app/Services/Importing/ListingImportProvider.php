<?php

namespace App\Services\Importing;

/**
 * Fetches a listing via a URL and returns a normalised ListingDraft.
 * Implementations: public metadata (safe), Apify (buyer's own key), ...
 */
interface ListingImportProvider
{
    /**
     * @throws ListingImportException if nothing usable could be retrieved
     */
    public function import(string $url): ListingDraft;
}
