{{-- Most viewed photos as photos: rank, the photo, views (with a bar relative
     to the most viewed), description and accommodation. Inline styles: the
     admin panel uses Filament's own stylesheet. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" icon="heroicon-o-photo">
        @if ($photos->isEmpty())
            <p style="opacity: .7;">{{ __('No photo views in the last 30 days.') }}</p>
        @else
            <ol style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); list-style: none; margin: 0; padding: 0;">
                @foreach ($photos as $rank => $photo)
                    <li data-views="{{ $photo['views'] }}" style="min-width: 0;">
                        <div style="position: relative; aspect-ratio: 4 / 3; overflow: hidden; border-radius: .5rem; background: rgba(127, 127, 127, .15);">
                            @if ($photo['video'])
                                <video src="{{ $photo['src'] }}" muted preload="metadata" playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                            @else
                                <img src="{{ $photo['src'] }}" alt="{{ $photo['label'] ?? '' }}" loading="lazy" style="width: 100%; height: 100%; object-fit: cover;">
                            @endif
                            <span style="position: absolute; left: .4rem; top: .4rem; border-radius: 9999px; background: rgba(0, 0, 0, .65); color: #fff; font-size: .75rem; font-weight: 600; padding: .1rem .5rem;">#{{ $rank + 1 }}</span>
                        </div>

                        <p style="margin-top: .5rem; font-weight: 600;">
                            {{ trans_choice('{1} 1 view|[2,*] :count views', $photo['views']) }}
                        </p>
                        <div style="margin-top: .25rem; height: .3rem; border-radius: 9999px; background: rgba(127, 127, 127, .2);">
                            <div style="height: 100%; width: {{ round($photo['views'] / $most * 100) }}%; border-radius: 9999px; background: var(--primary-500, #3b82f6);"></div>
                        </div>

                        @if ($photo['label'])
                            <p style="margin-top: .4rem; font-size: .85rem; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;" title="{{ $photo['label'] }}">{{ $photo['label'] }}</p>
                        @endif

                        @if ($photo['property'])
                            @if ($photo['link'])
                                <a href="{{ $photo['link'] }}" style="display: block; margin-top: .2rem; font-size: .8rem; opacity: .7; text-decoration: underline; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $photo['property'] }}</a>
                            @else
                                <p style="margin-top: .2rem; font-size: .8rem; opacity: .7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $photo['property'] }}</p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
