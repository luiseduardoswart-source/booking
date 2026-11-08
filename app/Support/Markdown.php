<?php

namespace App\Support;

/**
 * Helper for Markdown mails. Blade's {{ }} protects against HTML, not against
 * Markdown: without this step a guest name like "[Click here](https://...)"
 * becomes a clickable link in an email from the company.
 */
final class Markdown
{
    /**
     * Disable Markdown characters, so the value appears as plain text in the
     * mail. < and > are left out: Blade's {{ }} already neutralises those,
     * and a backslash before them would then become visible.
     */
    public static function asText(?string $value): string
    {
        return addcslashes((string) $value, '\\`*_{}[]()#+!|~');
    }
}
