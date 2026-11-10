<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        {{-- Same sticky save bar as the edit pages (Filament's actions component):
             stays at the bottom of the screen until the end of the form is in view. --}}
        <div x-data="filamentActionsSchemaComponent()"
             x-intersect:enter.half="disableSticky"
             x-intersect:leave="enableSticky"
             x-bind:class="{ 'fi-sticky': isSticky }"
             class="fi-sc-actions">
            <div x-bind:style="isSticky ? `width: ${width}px;` : ''" class="fi-ac fi-align-start">
                <x-filament::button type="submit">
                    {{ __('Save') }}
                </x-filament::button>

                {{ $this->translateAction }}
            </div>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
