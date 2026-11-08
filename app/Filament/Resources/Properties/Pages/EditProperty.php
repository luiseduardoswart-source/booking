<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Concerns\SwitchesDirect;
use App\Filament\Resources\Properties\PropertyResource;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;

class EditProperty extends EditRecord
{
    use SwitchesDirect;

    protected static string $resource = PropertyResource::class;

    /**
     * Only "Save changes" at the bottom — no cancel (useless) and
     * no delete (that is possible on the accommodation overview).
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Only relevant for deleted (trashed) accommodations.
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Hide the bottom save bar as soon as the Calendar or Photos tab is active;
     * those tabs save everything directly. We follow the active tab via the
     * data-tab-key attribute of the tab buttons (reliable, without
     * timing dependency).
     *
     * The starting tab is read in the browser from the address bar, not on the
     * server: a Livewire update request has no ?tab=, so a server-side value
     * would change the attribute on every update and the morph would reset
     * the state (the bar came back on the Photos tab).
     */
    public function getFormActionsContentComponent(): Component
    {
        return parent::getFormActionsContentComponent()->extraAttributes([
            'x-data' => "{ active: new URLSearchParams(window.location.search).get('tab') }",
            'x-on:click.window' => "let t = \$event.target.closest ? \$event.target.closest('[data-tab-key]') : null; if (t) active = t.dataset.tabKey",
            'x-show' => "! ['calendar', 'photos'].includes(active)",
        ]);
    }
}
