<x-site-layout
    :translated="$page"
    :title="$page->localized('meta_title') ?? $page->localized('title')"
    :metaDescription="$page->localized('meta_description')">

    @include('site.pages.hero', ['page' => $page])

    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        {{-- With hero photos no double heading: the title is already (optionally) on the photo. --}}
        @if (collect($page->hero_images ?? [])->filter(fn ($h) => ! empty($h['image']))->isEmpty())
            <h1 class="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl">
                {{ $page->localized('title') }}
            </h1>
        @endif

        @if ($page->localized('content'))
            {{-- Content comes from the RichEditor in the admin (trusted admin). --}}
            <div class="prose prose-stone mt-8 max-w-none">{!! $page->localized('content') !!}</div>
        @endif

        {{-- A form from the form builder (Pages → Form) --}}
        @if ($page->form?->active)
            @include('site.forms.form', ['form' => $page->form])
        @endif
    </div>
</x-site-layout>
