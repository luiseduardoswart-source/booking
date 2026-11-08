{{-- Kaart met alle (gefilterde) accommodaties. Verwacht $kaartData. --}}
<div>
    <h2 class="text-2xl font-bold text-stone-900">{{ __('Explore the map') }}</h2>
    <div id="explore-map"
         data-text-price="{{ __('Price per night') }}"
         data-text-extra="{{ __('Excluding any additional charges') }}"
         data-text-button="{{ __('View & book') }}"
         class="z-0 mt-4 h-[28rem] w-full overflow-hidden rounded-2xl border border-stone-200 lg:h-[calc(100vh-10rem)]"></div>
    <script type="application/json" id="explore-map-data">@json($mapData)</script>
</div>
