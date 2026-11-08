// Draggable width of the sidebar in the admin. Dragging or the arrow keys
// adjust --sidebar-width; the width is kept per browser and a
// double click resets it to the default. Limits: App\Filament\Support\SidebarWidth.
(() => {
    const edge = document.querySelector('[data-sidebar-drag-edge]');

    if (! edge) {
        return;
    }

    if (! document.querySelector('.fi-sidebar')) {
        edge.remove(); // e.g. the login page
        return;
    }

    const { key } = edge.dataset;
    const min = Number(edge.dataset.min);
    const max = Number(edge.dataset.max);
    const wideScreen = matchMedia(`(min-width: ${edge.dataset.from}px)`);
    const step = 16;
    const root = document.documentElement;

    const current = () => Math.round(document.querySelector('.fi-sidebar').getBoundingClientRect().width);

    const show = () => edge.setAttribute('aria-valuenow', String(current()));

    const set = (px) => {
        const width = Math.round(Math.min(max, Math.max(min, px)));
        root.style.setProperty('--sidebar-width', `${width}px`);
        show();

        return width;
    };

    const save = (width) => {
        try {
            localStorage.setItem(key, String(width));
        } catch (e) {
            // Private window or blocked storage: then it only works until reload.
        }
    };

    const restore = () => {
        root.style.removeProperty('--sidebar-width');

        try {
            localStorage.removeItem(key);
        } catch (e) {}

        show();
    };

    edge.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || ! wideScreen.matches) {
            return;
        }

        e.preventDefault();
        edge.setPointerCapture(e.pointerId);
        root.classList.add('sidebar-drag');

        const ready = new AbortController();
        const stop = () => {
            ready.abort();
            root.classList.remove('sidebar-drag');
            save(current());
        };

        // The sidebar starts at the left of the screen, so the mouse position is the width.
        edge.addEventListener('pointermove', (ev) => set(ev.clientX), { signal: ready.signal });
        edge.addEventListener('pointerup', stop, { signal: ready.signal });
        edge.addEventListener('pointercancel', stop, { signal: ready.signal });
    });

    edge.addEventListener('dblclick', restore);

    edge.addEventListener('keydown', (e) => {
        const delta = { ArrowLeft: -step, ArrowRight: step }[e.key];

        if (delta !== undefined && wideScreen.matches) {
            e.preventDefault();
            save(set(current() + delta));
        }
    });

    show();
})();
