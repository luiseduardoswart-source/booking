<div class="space-y-4 text-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="font-semibold text-gray-950 dark:text-white">{{ $review->guest_name }}</p>
            <p class="text-gray-500 dark:text-gray-400">
                {{ $review->property?->name }}
                &middot; {{ $review->created_at?->format('d-m-Y') }}
                @if ($review->booking?->booking_number)
                    &middot; {{ $review->booking->booking_number }}
                @endif
            </p>
        </div>
        <p class="text-lg text-amber-500">
            {{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}
        </p>
    </div>

    <div class="whitespace-pre-line rounded-xl bg-gray-50 p-4 leading-6 text-gray-800 dark:bg-white/5 dark:text-gray-200">
        {{ $review->comment ?: __('(No text left)') }}
    </div>

    @if ($review->host_reply)
        <div>
            <p class="mb-1 font-medium text-gray-950 dark:text-white">{{ __('Host reply') }}</p>
            <div class="whitespace-pre-line rounded-xl border border-gray-200 p-4 leading-6 text-gray-800 dark:border-white/10 dark:text-gray-200">
                {{ $review->host_reply }}
            </div>
        </div>
    @endif
</div>
