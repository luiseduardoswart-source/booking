{{-- Social-mediaknoppen. Verwacht $links (kanaal => url). --}}
@if (! empty($links))
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($links as $channel => $url)
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
               title="{{ \App\Support\SocialChannels::label($channel) }}"
               aria-label="{{ \App\Support\SocialChannels::label($channel) }}"
               class="flex h-9 w-9 items-center justify-center rounded-full border border-stone-300 text-stone-600 transition hover:border-stone-900 hover:text-stone-900">
                <x-site-icon :name="\App\Support\SocialChannels::icon($channel)" class="h-4 w-4" />
            </a>
        @endforeach
    </div>
@endif
