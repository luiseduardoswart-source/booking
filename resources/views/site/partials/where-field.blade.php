{{-- "Where to"-veld met autocomplete (plaatsen + accommodatienamen; app.js: initWaarSuggesties) --}}
<label class="relative flex-1 cursor-text border-stone-200 px-5 py-2 transition hover:bg-stone-50 sm:rounded-l-full sm:border-r">
    <span class="block text-[10px] font-bold uppercase tracking-wide text-stone-800">{{ __('Where to') }}</span>
    <input type="text" name="where" value="{{ $filters['where'] }}"
           autocomplete="off"
           data-where-input
           data-suggestions-url="{{ route('api.search_suggestions') }}"
           placeholder="{{ __('Search by place or name') }}"
           class="w-full border-0 bg-transparent p-0 text-sm text-stone-800 placeholder-stone-500 focus:ring-0">
    <div data-where-suggestions
         class="absolute left-0 top-full z-50 mt-3 hidden w-80 max-w-[90vw] overflow-hidden rounded-2xl border border-stone-200 bg-white py-2 text-left shadow-xl"></div>
</label>
