@php($list = $view !== \App\Livewire\AccommodationPhotos::GRID)

<div
    class="space-y-4"
    x-data="{
        busy: false,
        erboven: false,
        async send(files) {
            this.busy = true
            for (const file of [...files]) {
                await new Promise((ready) => this.$wire.upload('upload', file, ready, ready))
            }
            this.busy = false
        },
    }"
>
    {{-- Add photos + list or grid --}}
    <div class="flex items-stretch gap-3">
        <label
            class="flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 transition hover:border-primary-500 dark:border-gray-600 dark:text-gray-400"
            x-bind:class="erboven && 'border-primary-500'"
            x-on:dragover.prevent="erboven = true"
            x-on:dragleave.prevent="erboven = false"
            x-on:drop.prevent="erboven = false; send($event.dataTransfer.files)"
        >
            <input type="file" accept="image/*,{{ implode(',', \App\Models\Property::VIDEO_MIMES) }}" multiple class="sr-only" x-on:change="send($event.target.files); $event.target.value = ''" />
            <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-5 w-5" />
            <span x-show="! busy">{{ __('Drag photos or videos here or click to choose') }}</span>
            <span x-show="busy" x-cloak>{{ __('Working…') }}</span>
        </label>

        <div class="flex items-center gap-1">
            <x-filament::icon-button
                icon="heroicon-o-bars-3"
                :color="$list ? 'primary' : 'gray'"
                :label="__('List')"
                :tooltip="__('List')"
                wire:click="$set('view', '{{ \App\Livewire\AccommodationPhotos::LIST_MODE }}')"
            />
            <x-filament::icon-button
                icon="heroicon-o-squares-2x2"
                :color="$list ? 'gray' : 'primary'"
                :label="__('Grid')"
                :tooltip="__('Grid')"
                wire:click="$set('view', '{{ \App\Livewire\AccommodationPhotos::GRID }}')"
            />
        </div>
    </div>

    @error('upload')
        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror

    @if ($photos->isNotEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ \App\Models\Property::photoHint() }}
            {{ __('Give a photo a label (e.g. Bedroom, Kitchen, Pool). It appears in the top-left corner of the photo on the website. Empty = no label.') }}
        </p>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            @if ($languages !== [])
                <x-filament::link tag="button" type="button" icon="heroicon-o-language" wire:click="translateLabels" wire:target="translateLabels">
                    {{ __('Translate empty languages') }}
                </x-filament::link>
            @endif
            <x-filament::link
                tag="button"
                type="button"
                color="danger"
                icon="heroicon-o-trash"
                wire:click="deleteAll"
                wire:target="deleteAll"
                wire:confirm="{{ __('Delete all :count photos and videos of this accommodation? This cannot be undone.', ['count' => $photos->count()]) }}"
            >
                {{ __('Delete all') }}
            </x-filament::link>
        </div>

        {{-- forceFallback: drag with the mouse instead of browser dragging, otherwise the box above reacts too. --}}
        <div
            wire:sort="move"
            wire:sort:config="{ forceFallback: true }"
            @class(['space-y-2' => $list, 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' => ! $list])
        >
            @foreach ($photos as $photo)
                <div
                    wire:key="photo-{{ $photo->id }}"
                    wire:sort:item="{{ $photo->id }}"
                    @class([
                        'rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-700 dark:bg-gray-900',
                        'flex items-center gap-3' => $list,
                        'space-y-2' => ! $list,
                    ])
                >
                    @php($isVideo = \App\Models\Property::isVideo($photo))
                    <div wire:sort:handle @class(['relative cursor-move', 'shrink-0' => $list])>
                        @if ($isVideo)
                            {{-- No resized version for a video: the browser shows the first frame. --}}
                            <video
                                src="{{ $photo->getUrl() }}#t=0.1"
                                preload="metadata"
                                muted
                                playsinline
                                data-video="{{ $photo->id }}"
                                @class(['pointer-events-none rounded-md bg-gray-900 object-cover', 'h-24 w-36' => $list, 'aspect-[4/3] w-full' => ! $list])
                            ></video>
                            <span class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                <x-filament::icon icon="heroicon-s-play-circle" class="h-10 w-10 text-white drop-shadow" />
                            </span>
                        @else
                            <img
                                src="{{ $photo->getUrl('thumb') }}?v={{ $photo->updated_at?->timestamp }}"
                                alt=""
                                @class(['rounded-md object-cover', 'h-24 w-36' => $list, 'aspect-[4/3] w-full' => ! $list])
                            />
                        @endif
                    </div>

                    <div @class(['space-y-2', 'min-w-0 flex-1' => $list]) x-data="{ translate: false }">
                    <div class="flex items-center gap-1">
                        @include('livewire.partials.photo-label-field', [
                            'locale' => null,
                            'value' => $photo->getCustomProperty('label', ''),
                            'placeholder' => __('Label…'),
                        ])

                        @if ($languages !== [])
                            <x-filament::icon-button
                                icon="heroicon-o-language"
                                color="gray"
                                :label="__('Translations')"
                                :tooltip="__('Translations')"
                                x-on:click="translate = ! translate"
                            />
                        @endif

                        @if ($mayPhotosEdit && ! $isVideo)
                            <x-filament::icon-button
                                icon="heroicon-o-pencil-square"
                                color="gray"
                                :label="__('Edit photo')"
                                :tooltip="__('Edit photo')"
                                :data-path="\App\Http\Controllers\Admin\MediaController::accommodationPhotoPath($photo)"
                                :data-url="$photo->getUrl()"
                                x-on:click="ImageEditor.open($el.dataset.path, () => $wire.$refresh(), $el.dataset.url)"
                            />
                        @endif

                        <x-filament::icon-button
                            icon="heroicon-o-trash"
                            color="danger"
                            :label="__('Remove')"
                            :tooltip="__('Remove')"
                            wire:click="delete({{ $photo->id }})"
                            wire:confirm="{{ __('Are you sure you want to delete this?') }}"
                        />
                    </div>

                    <div x-show="translate" x-cloak class="grid gap-2 sm:grid-cols-2">
                        @foreach ($languages as $locale)
                            @include('livewire.partials.photo-label-field', [
                                'value' => $photo->getCustomProperty("label_translations.{$locale}", ''),
                                'placeholder' => \App\Support\Languages::name($locale),
                            ])
                        @endforeach
                    </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
