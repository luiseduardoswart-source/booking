// Reorder the menu on the left yourself: "Order menu" makes the items draggable
// (SortableJS, also with touch), "Done" saves the order to your account.
// "Default order" resets it. The server then puts the menu in that order
// on every page (App\Filament\Navigation\PersonalOrder).
//
// The menu is a Livewire component that can be rebuilt; therefore
// we listen on the document and only look up the elements on a click.
(() => {
    if (window.menuOrderActive) {
        return;
    }

    window.menuOrderActive = true;
    let sorters = [];

    const share = () => {
        const block = document.querySelector('[data-menu-order]');

        return {
            block,
            sidebar: document.querySelector('.fi-sidebar'),
            swap: block?.querySelector('[data-menu-order-switch]'),
            reset: block?.querySelector('[data-menu-order-default]'),
            explanation: block?.querySelector('.menu-order-explanation'),
        };
    };

    const lists = (sidebar) => [...sidebar.querySelectorAll('.fi-sidebar-group-items')];

    // The links in the order they currently appear in the menu.
    const order = (sidebar) => lists(sidebar)
        .flatMap((list) => [...list.querySelectorAll(':scope > .fi-sidebar-item > a')])
        .map((link) => link.getAttribute('href'));

    const save = async (block, links) => {
        const answer = await fetch(block.dataset.save, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ order: links }),
        });

        if (! answer.ok) {
            throw new Error(String(answer.status));
        }
    };

    const start = ({ block, sidebar, swap, explanation }) => {
        sidebar.classList.add('menu-reordering');
        explanation.hidden = false;
        swap.textContent = block.dataset.textDone;
        sorters = lists(sidebar).map((list) => new Sortable(list, {
            draggable: '.fi-sidebar-item',
            animation: 150,
            ghostClass: 'sortable-ghost',
            // Own drag method instead of the browser's: works the same with mouse,
            // touchpad and finger, even inside the narrow menu.
            forceFallback: true,
        }));
    };

    const stop = async ({ block, sidebar, swap, reset, explanation }) => {
        sorters.forEach((sorter) => sorter.destroy());
        sorters = [];
        sidebar.classList.remove('menu-reordering');
        explanation.hidden = true;
        swap.textContent = block.dataset.textReorder;

        try {
            await save(block, order(sidebar));
            reset.hidden = false;
        } catch (e) {
            alert(block.dataset.textFailed);
        }
    };

    document.addEventListener('click', async (e) => {
        const d = share();

        if (! d.block || ! d.sidebar || typeof Sortable === 'undefined') {
            return;
        }

        if (e.target.closest('[data-menu-order-switch]')) {
            sorters.length ? stop(d) : start(d);
        } else if (e.target.closest('[data-menu-order-default]')) {
            try {
                await save(d.block, []);
                location.reload();
            } catch (err) {
                alert(d.block.dataset.textFailed);
            }
        }
    });
})();
