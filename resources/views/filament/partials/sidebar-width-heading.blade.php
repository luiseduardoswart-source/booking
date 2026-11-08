@php($b = \App\Filament\Support\SidebarWidth::class)
{{-- Apply the saved width before the first render, so the page does not jump. --}}
<script>
    try {
        const width = parseInt(localStorage.getItem(@js($b::KEY)), 10);

        if (width >= {{ $b::MIN }} && width <= {{ $b::MAX }} && matchMedia('(min-width: {{ $b::FROM }}px)').matches) {
            document.documentElement.style.setProperty('--sidebar-width', width + 'px');
        }
    } catch (e) {}
</script>
