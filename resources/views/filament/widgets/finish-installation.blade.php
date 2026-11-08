<x-filament-widgets::widget>
    <x-filament::section
        :heading="$heading"
        :description="$description"
        icon="heroicon-o-clipboard-document-check"
        icon-color="warning"
    >
        <ol style="display: grid; gap: 1.25rem; list-style: decimal; padding-inline-start: 1.25rem;">
            @foreach ($steps as $step)
                <li>
                    <p style="font-weight: 600;">{{ $step['title'] }}</p>
                    <p style="margin-top: .25rem; opacity: .8;">{{ $step['text'] }}</p>

                    @isset($step['code'])
                        <code
                            style="display: block; margin-top: .5rem; padding: .5rem .75rem; border-radius: .5rem; background: rgba(127, 127, 127, .15); font-size: .85rem; overflow-wrap: anywhere; user-select: all;"
                        >{{ $step['code'] }}</code>
                    @endisset

                    @isset($step['action'])
                        <x-filament::button
                            size="sm"
                            style="margin-top: .5rem;"
                            wire:click="{{ $step['action'] }}"
                            wire:confirm="{{ __('Update the database now? Make a backup first if your hosting provider does not do this automatically.') }}"
                            wire:loading.attr="disabled"
                            wire:target="{{ $step['action'] }}"
                        >
                            <span wire:loading.remove wire:target="{{ $step['action'] }}">{{ $step['button'] }}</span>
                            <span wire:loading wire:target="{{ $step['action'] }}">{{ __('Updating…') }}</span>
                        </x-filament::button>
                    @elseif (isset($step['link']))
                        <x-filament::button tag="a" :href="$step['link']" size="sm" color="gray" style="margin-top: .5rem;">
                            {{ $step['button'] }}
                        </x-filament::button>
                    @endisset
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-widgets::widget>
