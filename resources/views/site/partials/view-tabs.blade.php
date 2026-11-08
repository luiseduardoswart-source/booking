{{-- Wissel tussen lijst- en kaartweergave (app.js: initWeergaveTabs) --}}
<div class="inline-flex rounded-full border border-stone-300 p-1" data-view-tabs>
    <button type="button" data-view="list"
            class="flex items-center gap-1.5 rounded-full bg-stone-900 px-4 py-1.5 text-sm font-medium text-white">
        <x-site-icon name="photo" class="h-4 w-4" />
        {{ __('List') }}
    </button>
    <button type="button" data-view="map"
            class="flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium text-stone-700">
        <x-site-icon name="map-pin" class="h-4 w-4" />
        {{ __('Map') }}
    </button>
</div>
