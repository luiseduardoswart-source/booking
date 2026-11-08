<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit">
                {{ __('Save') }}
            </x-filament::button>

            {{ $this->translateAction }}
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
