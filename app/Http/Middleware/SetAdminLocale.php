<?php

namespace App\Http\Middleware;

use App\Support\AdminLanguages;
use Illuminate\Http\Request;

/**
 * The admin language: that of the account, chosen from the admin's
 * languages. What is switched on or off on the website plays no role here.
 */
class SetAdminLocale extends SetLocale
{
    protected string $languages = AdminLanguages::class;

    /**
     * The admin language for a page outside the admin that belongs to it,
     * such as the "Allow" screen of the Claude connection.
     */
    public static function localeFor(Request $request): string
    {
        return (new static)->locale($request);
    }

    protected function chosen(Request $request): ?string
    {
        return $request->user()?->locale;
    }
}
