{{-- A single label field. $locale is null for the source text. Saves on leaving the field or on Enter; Enter must not submit the surrounding form. --}}
<div class="relative min-w-0 flex-1" x-data="{ saved: false }">
    <input
        type="text"
        value="{{ $value }}"
        x-on:change="$wire.saveLabel({{ $photo->id }}, $event.target.value, @js($locale)).then(() => { saved = true; setTimeout(() => saved = false, 2000) })"
        x-on:keydown.enter.prevent="$el.blur()"
        placeholder="{{ $placeholder }}"
        maxlength="40"
        class="w-full min-w-0 rounded-lg border-gray-300 py-2.5 pl-3 pr-9 text-sm leading-6 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
    />
    <span x-show="saved" x-cloak class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-success-600 dark:text-success-400">
        <x-filament::icon icon="heroicon-o-check" class="h-5 w-5" />
    </span>
</div>
