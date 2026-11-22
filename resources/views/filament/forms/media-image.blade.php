<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- One photo from the media library (window.pickMedia, see media-manager.js):
         the state is its path. New photos are uploaded in the library itself,
         so a photo is never stored twice. --}}
    <div
        data-media-image
        wire:ignore
        x-data="{
            state: $wire.$entangle('{{ $getStatePath() }}'),
            storageUrl: @js(asset('storage')),
            url() {
                if (! this.state) return '';
                return /^(https?:)?\//.test(this.state) ? this.state : this.storageUrl + '/' + this.state.replace(/^\/+/, '');
            },
            choose() {
                window.pickMedia && window.pickMedia((file) => { this.state = file.path; });
            },
        }"
        class="flex flex-wrap items-center gap-4"
    >
        <template x-if="state">
            <img :src="url()" alt="" class="h-24 w-40 rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10">
        </template>
        <div class="flex flex-wrap gap-2">
            <x-filament::button type="button" color="gray" icon="heroicon-o-photo" x-on:click="choose()">
                {{ __('Choose from the media library') }}
            </x-filament::button>
            <x-filament::button type="button" color="danger" outlined icon="heroicon-o-x-mark" x-show="state" x-on:click="state = null">
                {{ __('Remove') }}
            </x-filament::button>
        </div>
    </div>
</x-dynamic-component>
