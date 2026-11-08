<?php

namespace App\Models;

/**
 * A language of the admin panel. Independent of the website languages: what the
 * owner switches off or adds on the site does not affect the admin, and vice versa.
 */
class AdminLanguage extends Language
{
    protected const CACHE = 'admin_languages.all';

    protected $table = 'admin_languages';
}
