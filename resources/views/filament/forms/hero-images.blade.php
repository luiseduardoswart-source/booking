<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- Hero photos (ported from devsite): draggable list; per photo a title per
         language, link, title position and optional mobile variant. The state is
         entangled with the Filament field, so saving simply goes along with the form. --}}
    <div
        wire:ignore
        x-data="heroImagesField({
            state: $wire.$entangle('{{ $getStatePath() }}'),
            locales: @js(\App\Support\Languages::active()),
            positions: @js([
                'top-left' => __('Top left'), 'top-center' => __('Top centre'), 'top-right' => __('Top right'),
                'center-left' => __('Centre left'), 'center' => __('Centre'), 'center-right' => __('Centre right'),
                'bottom-left' => __('Bottom left'), 'bottom-center' => __('Bottom centre'), 'bottom-right' => __('Bottom right'),
            ]),
            i18n: @js([
                'title' => __('Title on photo'),
                'link' => __('Link (optional, e.g. /contact)'),
                'position' => __('Title position'),
                'mobile' => __('Mobile variant'),
                'choose' => __('Photo (desktop)'),
                'edit' => __('Edit photo'),
            ]),
            storageUrl: @js(asset('storage')),
        })"
    >
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400"><i class="bi bi-info-circle"></i> {{ __('Add one or more large photos (multiple = slider). Per photo you can set a title, link and title position. Drag to change the order.') }}</p>
        <div x-ref="list" class="mb-4 space-y-3"></div>
        <button type="button" class="btn-secondary" x-on:click="addHero()"><i class="bi bi-plus-lg"></i> {{ __('Add photo') }}</button>
    </div>
</x-dynamic-component>
