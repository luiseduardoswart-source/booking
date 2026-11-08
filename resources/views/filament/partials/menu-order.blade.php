{{-- At the bottom of the menu: drag the order yourself (public/js/menu-order.js). --}}
<div class="menu-order"
     data-menu-order
     data-save="{{ route('admin.menu-order') }}"
     data-text-reorder="{{ __('Reorder menu') }}"
     data-text-done="{{ __('Done') }}"
     data-text-failed="{{ __('Saving failed, please try again.') }}">
    <p class="menu-order-explanation" hidden>{{ __('Drag the items into the order that suits you.') }}</p>
    <div class="menu-order-buttons">
        <button type="button" data-menu-order-switch>{{ __('Reorder menu') }}</button>
        <button type="button" data-menu-order-default @if (blank(auth()->user()?->menu_order)) hidden @endif>{{ __('Default order') }}</button>
    </div>
</div>

<style>
    .menu-order { padding: 0.75rem 1.5rem 1rem; font-size: 0.75rem; }
    .menu-order-buttons { display: flex; gap: 0.75rem; flex-wrap: wrap; }
    .menu-order button { color: rgb(120 113 108); text-decoration: underline; text-underline-offset: 2px; cursor: pointer; }
    .menu-order button:hover { color: var(--primary-600); }
    .menu-order-explanation { margin-bottom: 0.5rem; color: rgb(120 113 108); }
    .fi-sidebar.menu-reordering .fi-sidebar-item { cursor: grab; border-radius: 0.5rem; outline: 1px dashed rgb(168 162 158 / 0.6); margin-block: 2px; }
    .fi-sidebar.menu-reordering .fi-sidebar-item a { pointer-events: none; }
    .fi-sidebar.menu-reordering .sortable-ghost { opacity: 0.4; }
</style>

@include('filament.partials.sortablejs')
<script src="{{ asset('js/menu-order.js') }}?v={{ filemtime(public_path('js/menu-order.js')) }}"></script>
