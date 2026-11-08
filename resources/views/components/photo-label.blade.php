@props(['photo'])

@php $label = $photo->getCustomProperty('label_translations.'.app()->getLocale()) ?: $photo->getCustomProperty('label'); @endphp

@if (filled($label))
    <span class="pointer-events-none absolute left-2 top-2 rounded-md px-2 py-0.5 text-xs font-medium text-white backdrop-blur-sm"
          style="background-color: var(--color-label, rgba(0,0,0,0.55));">
        {{ $label }}
    </span>
@endif
