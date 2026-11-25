<x-site-layout :title="__('Cleaning schedule')">
    <div class="mx-auto max-w-3xl px-4 py-10">
        <h1 class="text-2xl font-bold text-stone-900">{{ __('Cleaning schedule') }}</h1>
        <p class="mt-2 text-stone-600">
            {{ __('Hello :name, these are the changeovers of the coming :days days. This page is always up to date.', ['name' => $cleaner->name, 'days' => $days]) }}
        </p>

        @include('site.cleaning.partials.changeovers', ['changeovers' => $changeovers])
    </div>
</x-site-layout>
