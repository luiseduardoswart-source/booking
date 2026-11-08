@php($b = \App\Filament\Support\SidebarWidth::class)
{{-- Drag edge to the right of the sidebar. Sits outside the sidebar, so Livewire does not remove it. --}}
<div
    class="sidebar-drag-edge"
    data-sidebar-drag-edge
    data-key="{{ $b::KEY }}"
    data-min="{{ $b::MIN }}"
    data-max="{{ $b::MAX }}"
    data-from="{{ $b::FROM }}"
    role="separator"
    aria-orientation="vertical"
    aria-valuemin="{{ $b::MIN }}"
    aria-valuemax="{{ $b::MAX }}"
    aria-label="{{ __('Menu width') }}"
    title="{{ __('Drag to resize the menu. Double-click to reset.') }}"
    tabindex="0"
></div>
<script src="{{ asset('js/sidebar-width.js') }}?v={{ filemtime(public_path('js/sidebar-width.js')) }}"></script>
<script src="{{ asset('js/copy.js') }}?v={{ filemtime(public_path('js/copy.js')) }}"></script>
