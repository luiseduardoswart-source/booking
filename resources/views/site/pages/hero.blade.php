{{-- Hero slider (ported from devsite): one or more photos with a title overlay at a configurable position --}}
@php
    $heroes = collect($page->hero_images ?? [])->filter(fn ($h) => ! empty($h['image']))->values();
    $posMap = [
        'top-left' => 'items-start justify-start text-left',
        'top-center' => 'items-start justify-center text-center',
        'top-right' => 'items-start justify-end text-right',
        'center-left' => 'items-center justify-start text-left',
        'center' => 'items-center justify-center text-center',
        'center-right' => 'items-center justify-end text-right',
        'bottom-left' => 'items-end justify-start text-left',
        'bottom-center' => 'items-end justify-center text-center',
        'bottom-right' => 'items-end justify-end text-right',
    ];
@endphp

@if ($heroes->isNotEmpty())
    <div class="relative h-80 overflow-hidden md:h-[28rem]" data-hero-slider>
        @foreach ($heroes as $i => $hero)
            @php
                $pos = $posMap[$hero['position'] ?? 'center'] ?? $posMap['center'];
                // no fallback to the page title: nothing filled in = no text over the photo
                $title = \App\Models\Page::heroTitle($hero);
                $link = trim($hero['link'] ?? '');
            @endphp
            <div class="hero-slide absolute inset-0 transition-opacity duration-700 {{ $i === 0 ? 'opacity-100' : 'pointer-events-none opacity-0' }}">
                <picture>
                    @if (! empty($hero['image_mobile']))
                        <source media="(max-width: 767px)" srcset="{{ media_url($hero['image_mobile']) }}">
                    @endif
                    <img src="{{ media_url($hero['image']) }}" class="absolute inset-0 h-full w-full object-cover" alt="{{ media_alt($hero['image'], $title) }}">
                </picture>
                <div class="absolute inset-0 bg-gray-950/40"></div>
                @if ($link !== '' && ! $title)
                    <a href="{{ $link }}" class="absolute inset-0" aria-label="hero"></a>
                @endif
                @if ($title)
                    <div class="absolute inset-0 flex p-8 md:p-14 {{ $pos }}">
                        @if ($link !== '')
                            <a href="{{ $link }}" class="max-w-2xl text-3xl font-bold text-white drop-shadow hover:underline md:text-5xl">{{ $title }}</a>
                        @else
                            <h1 class="max-w-2xl text-3xl font-bold text-white drop-shadow md:text-5xl">{{ $title }}</h1>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach

        @if ($heroes->count() > 1)
            <div class="absolute bottom-4 left-1/2 z-10 flex -translate-x-1/2 gap-2">
                @foreach ($heroes as $i => $hero)
                    <button type="button" class="hero-dot h-2.5 w-2.5 rounded-full transition {{ $i === 0 ? 'bg-white' : 'bg-white/40' }}" data-slide="{{ $i }}" aria-label="slide {{ $i + 1 }}"></button>
                @endforeach
            </div>
            <script>
                (function () {
                    const slider = document.querySelector('[data-hero-slider]');
                    if (!slider) return;
                    const slides = slider.querySelectorAll('.hero-slide');
                    const dots = slider.querySelectorAll('.hero-dot');
                    let current = 0, timer;
                    function show(i) {
                        current = i;
                        slides.forEach((s, j) => {
                            s.classList.toggle('opacity-100', j === i);
                            s.classList.toggle('opacity-0', j !== i);
                            s.classList.toggle('pointer-events-none', j !== i);
                        });
                        dots.forEach((d, j) => {
                            d.classList.toggle('bg-white', j === i);
                            d.classList.toggle('bg-white/40', j !== i);
                        });
                    }
                    function start() { timer = setInterval(() => show((current + 1) % slides.length), 5000); }
                    dots.forEach(d => d.addEventListener('click', () => { clearInterval(timer); show(+d.dataset.slide); start(); }));
                    start();
                })();
            </script>
        @endif
    </div>
@endif
