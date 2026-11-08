<?php

namespace App\Filament\Concerns;

use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Str;

/**
 * For a form page with switches that save immediately (see
 * App\Filament\Support\Switches::direct). Belongs with a page that has
 * Filament's unsaved-changes warning.
 */
trait SwitchesDirect
{
    /**
     * After an immediate save the unsaved-changes warning should stay silent,
     * unless something else is also still unsaved. To check that, we compare the
     * form with this one field's old value against what was there at the last save.
     */
    #[Unexposed]
    public function rememberAfterDirectSave(string $statePath, mixed $previous): void
    {
        if (! $this->hasUnsavedDataChangesAlert()) {
            return;
        }

        $path = Str::after($statePath, 'data.');
        $new = data_get($this->data, $path);
        $saved = $this->savedDataHash;

        data_set($this->data, $path, $previous);
        $this->rememberData();
        $furtherUnchanged = $this->savedDataHash === $saved;
        data_set($this->data, $path, $new);

        $furtherUnchanged ? $this->rememberData() : $this->savedDataHash = $saved;
    }
}
