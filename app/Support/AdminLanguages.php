<?php

namespace App\Support;

use App\Models\AdminLanguage;
use Illuminate\Support\Collection;

/**
 * Which languages the admin panel knows, and which of them is the default. Managed
 * under Settings → Admin, separate from the website languages (Languages).
 */
class AdminLanguages extends Languages
{
    /**
     * @return Collection<int, AdminLanguage>
     */
    protected static function table(): Collection
    {
        return AdminLanguage::allCached();
    }
}
