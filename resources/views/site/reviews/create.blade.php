<x-site-layout :title="__('Leave a review')" :property="$property">
    <div class="mx-auto max-w-2xl px-4 py-12">
        <div class="rounded-2xl border border-stone-200 bg-white p-8 shadow-sm">
            @if (session('review_sent'))
                <div class="text-center">
                    <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-rose-50">
                        <x-site-icon name="check-circle" class="h-10 w-10 text-rose-600" />
                    </span>
                    <h1 class="mt-4 text-2xl font-bold text-stone-900">{{ __('Thank you for your review!') }}</h1>
                    <p class="mt-3 text-stone-600">{{ __('Your review will be shown on the website after approval.') }}</p>
                </div>
            @elseif ($block !== null)
                <p class="text-center text-stone-600">{{ $block }}</p>
            @else
                <h1 class="text-2xl font-bold text-stone-900">{{ __('How was your stay?') }}</h1>
                <p class="mt-2 text-stone-600">
                    {{ $property->localized('name') }} ·
                    {{ $booking->arrival_date->translatedFormat('j M') }} – {{ $booking->departure_date->translatedFormat('j M Y') }}
                </p>

                <form action="{{ \Illuminate\Support\Facades\URL::signedRoute('reviews.store', $booking) }}" method="post" class="mt-6 space-y-6">
                    @csrf

                    @if ($errors->any())
                        <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>
                    @endif

                    <div>
                        <span class="text-sm font-medium text-stone-700">{{ __('Your rating') }} *</span>
                        {{-- Stars: row reversed so "colour all preceding stars" works with pure CSS --}}
                        <div class="mt-2 flex flex-row-reverse justify-end gap-1">
                            @foreach ([5, 4, 3, 2, 1] as $star)
                                <input type="radio" id="star-{{ $star }}" name="rating" value="{{ $star }}"
                                       class="peer sr-only" required @checked(old('rating') == $star)>
                                <label for="star-{{ $star }}"
                                       class="cursor-pointer text-4xl text-stone-300 transition hover:text-amber-400 peer-checked:text-amber-400"
                                       title="{{ $star }}/5">★</label>
                            @endforeach
                        </div>
                    </div>

                    <label class="block">
                        <span class="text-sm font-medium text-stone-700">{{ __('Your review') }}</span>
                        <textarea name="comment" rows="5"
                                  placeholder="{{ __('Tell future guests about your stay...') }}"
                                  class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">{{ old('comment') }}</textarea>
                    </label>

                    <button type="submit"
                            class="w-full rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                        {{ __('Submit review') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-site-layout>
