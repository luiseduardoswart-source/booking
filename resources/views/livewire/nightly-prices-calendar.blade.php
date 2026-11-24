{{-- Dragging or Shift+click selects a range of nights. While dragging, the browser draws the range
     itself; on release it goes to the server in one go. Shift+click starts at the previous
     click, even in another month. Keyboard and touch screen only give a click. --}}
<div
    class="space-y-6"
    x-data="{
        dragging: false,
        pressed: false,
        anchor: null,
        of: null,
        until: null,
        on: true,
        begin(date, shift) {
            const extending = shift && this.anchor && this.$wire.selected.length > 0
            this.of = extending ? this.anchor : date
            this.until = date
            this.on = extending || ! this.$wire.selected.includes(date)
            this.dragging = this.pressed = true
        },
        click(date, keyboard) {
            if (keyboard || ! this.pressed) this.$wire.toggle(date)
            this.pressed = false
        },
        async ready() {
            if (! this.dragging) return
            this.dragging = false
            this.anchor = this.until
            await this.$wire.select(this.of, this.until, this.on)
            this.of = this.until = null
        },
        inRange(date) {
            return this.of !== null && date >= (this.of < this.until ? this.of : this.until) && date <= (this.of < this.until ? this.until : this.of)
        },
    }"
    x-on:mouseup.window="ready()"
>
    {{-- Legend + month navigation --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded ring-1 ring-primary-500 bg-primary-500/10"></span>{{ __('Custom price') }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded ring-1 ring-amber-500 bg-amber-500/10"></span>{{ __('PriceLabs') }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block rounded bg-emerald-100 px-1 text-[8px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400">−%</span>{{ __('Discount') }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded bg-rose-100 dark:bg-rose-500/20"></span>{{ __('Blocked') }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded bg-gray-200 dark:bg-gray-700"></span>{{ __('Booked') }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded bg-gray-900 dark:bg-white"></span>{{ __('Selected') }}</span>
        </div>
        <div class="flex items-center gap-2">
            <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" :disabled="$monthOffset <= 0" label="{{ __('Previous') }}" />
            <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" label="{{ __('Next') }}" />
        </div>
    </div>

    {{-- Two months side by side --}}
    <div class="grid gap-8 lg:grid-cols-2">
        @foreach ($this->monthData() as $month)
            <div>
                <p class="mb-3 text-center text-sm font-semibold text-gray-950 dark:text-white">{{ $month['label'] }}</p>
                <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-medium uppercase text-gray-400">
                    @foreach ($this->weekdays() as $wd)
                        <div>{{ $wd }}</div>
                    @endforeach
                </div>
                <div class="mt-1 grid grid-cols-7 gap-1">
                    @foreach ($month['days'] as $cell)
                        @if ($cell === null)
                            <div></div>
                        @else
                            @php
                                $isSelected = in_array($cell['date'], $selected, true);
                                $clickable = ! $cell['past'] && ! $cell['occupied'];
                                $ring = $cell['override']
                                    ? ($cell['source'] === \App\Models\NightlyRate::SOURCE_PRICELABS ? 'ring-1 ring-amber-500 bg-amber-500/10' : 'ring-1 ring-primary-500 bg-primary-500/10')
                                    : '';
                            @endphp
                            <button
                                type="button"
                                {{-- Keyed per date, otherwise after "next month" a button keeps the date of its predecessor. --}}
                                wire:key="night-{{ $cell['date'] }}"
                                @if ($clickable)
                                    x-on:mousedown.prevent="$event.button === 0 && begin('{{ $cell['date'] }}', $event.shiftKey)"
                                    x-on:mouseenter="if (dragging) until = '{{ $cell['date'] }}'"
                                    x-on:click="click('{{ $cell['date'] }}', $event.detail === 0)"
                                    x-bind:style="inRange('{{ $cell['date'] }}') ? `outline: 2px ${on ? 'solid' : 'dashed'} var(--primary-500); outline-offset: -2px` : ''"
                                @else
                                    disabled
                                @endif
                                @class([
                                    'flex h-16 flex-col items-center justify-center gap-0.5 rounded-lg border border-transparent text-sm transition',
                                    'cursor-pointer hover:border-gray-300 dark:hover:border-gray-600' => $clickable,
                                    'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $isSelected,
                                    $ring => ! $isSelected && $ring !== '',
                                    'bg-rose-100 dark:bg-rose-500/20' => $cell['blocked'] && ! $isSelected,
                                    'text-gray-300 line-through dark:text-gray-600' => $cell['past'],
                                    'bg-gray-100 dark:bg-gray-800' => $cell['occupied'] && ! $isSelected,
                                ])
                            >
                                <span class="font-medium">{{ $cell['day'] }}</span>
                                @if ($cell['blocked'])
                                    <span @class([
                                        'text-[10px] font-medium uppercase',
                                        'text-white/80 dark:text-gray-900/70' => $isSelected,
                                        'text-rose-500' => ! $isSelected,
                                    ])>{{ __('block') }}</span>
                                @elseif ($cell['price'] !== null)
                                    <span @class([
                                        'text-[10px] tabular-nums leading-none',
                                        'text-white/80 dark:text-gray-900/70' => $isSelected,
                                        'text-gray-500 dark:text-gray-400' => ! $isSelected,
                                    ])>{{ \App\Support\Money::format($cell['price'], $property->priceCurrency()) }}</span>
                                    @if ($cell['discount'])
                                        <span @class([
                                            'mt-0.5 rounded px-1 text-[8px] font-semibold leading-tight',
                                            'bg-white/25 text-white' => $isSelected,
                                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400' => ! $isSelected,
                                        ])>−{{ $cell['discount'] }}%</span>
                                    @elseif (in_array($cell['origin'], ['season', 'weekend'], true))
                                        <span @class([
                                            'mt-0.5 text-[8px] uppercase leading-none',
                                            'text-white/70 dark:text-gray-900/60' => $isSelected,
                                            'text-amber-600 dark:text-amber-400' => ! $isSelected && $cell['origin'] === 'season',
                                            'text-gray-400' => ! $isSelected && $cell['origin'] === 'weekend',
                                        ])>{{ $cell['origin'] === 'season' ? __('season') : __('weekend') }}</span>
                                    @endif
                                @endif
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Action bar --}}
    <div class="sticky bottom-4 space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-900">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            {{ trans_choice('{0} No nights selected|{1} 1 night selected|[2,*] :count nights selected', count($selected)) }}
            @if (count($selected) > 0)
                · <button type="button" wire:click="clearSelection" class="underline hover:text-primary-600">{{ __('clear') }}</button>
            @endif
        </div>

        <div class="flex flex-wrap items-end gap-x-4 gap-y-3">
            {{-- Price --}}
            <div class="flex items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Price per night (:currency)', ['currency' => \App\Support\Currencies::symbol($property->priceCurrency()) ?? $property->priceCurrency()]) }}</label>
                    <input type="number" step="0.01" min="0" wire:model="priceInput" placeholder="0,00"
                           class="w-28 rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                </div>
                <x-filament::button wire:click="save" icon="heroicon-o-check">{{ __('Apply') }}</x-filament::button>
            </div>

            {{-- Discount --}}
            <div class="flex items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Discount (%)') }}</label>
                    <input type="number" step="1" min="1" max="99" wire:model="discountInput" placeholder="0"
                           class="w-20 rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                </div>
                <x-filament::button wire:click="discount" color="warning" icon="heroicon-o-tag">{{ __('Discount') }}</x-filament::button>
            </div>

            {{-- Block: only with the Calendar right (create / delete). --}}
            @if ($canBlock || $canUnblock)
                <div class="flex items-end gap-2">
                    @if ($canBlock)
                        <x-filament::button wire:click="block" color="danger" icon="heroicon-o-lock-closed">{{ __('Block') }}</x-filament::button>
                    @endif
                    @if ($canUnblock)
                        <x-filament::button wire:click="unblock" color="gray" icon="heroicon-o-lock-open">{{ __('Unblock') }}</x-filament::button>
                    @endif
                </div>
            @endif

            <x-filament::button wire:click="restore" color="gray" icon="heroicon-o-arrow-uturn-left" class="ml-auto">{{ __('Reset to default') }}</x-filament::button>
        </div>
    </div>
</div>
