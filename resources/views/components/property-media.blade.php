{{-- A photo or a video of an accommodation. A photo in a resized version
     ($conversion); a video has none and plays the original. #t=0.1 makes the
     browser show the first frame as the still image. With controls = false
     (the tiles in the mosaic) the video stands still with a play icon and a
     click opens the lightbox, where it plays with controls. --}}
@props(['media', 'conversion' => 'large', 'alt' => '', 'controls' => false])

@if (\App\Models\Property::isVideo($media))
    @if ($controls)
        <video src="{{ $media->getUrl() }}#t=0.1" controls preload="metadata" playsinline aria-label="{{ $alt }}" {{ $attributes }}></video>
    @else
        <span class="relative block h-full w-full">
            <video src="{{ $media->getUrl() }}#t=0.1" preload="metadata" muted playsinline aria-label="{{ $alt }}" {{ $attributes->merge(['class' => 'bg-stone-900']) }}></video>
            <span class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <span class="rounded-full bg-black/55 p-3 text-white">
                    <x-site-icon name="play" class="h-6 w-6" />
                </span>
            </span>
        </span>
    @endif
@else
    <img src="{{ $media->getUrl($conversion) }}" alt="{{ $alt }}" {{ $attributes }}>
@endif
