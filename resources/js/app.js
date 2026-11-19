import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// ---------------------------------------------------------------------------
// Guest picker (Airbnb style) — reusable module. Every [data-guests-popover]
// inside a form is initialized on its own.
// ---------------------------------------------------------------------------
function initGuestPicker(popover) {
    const form = popover.closest('form');
    if (!form) return;

    const field = form.querySelector('[data-guests-field]');
    const text = form.querySelector('[data-guests-text]');
    const maxGuests = parseInt(popover.dataset.max, 10);
    const petsAllowed = popover.dataset.pets === '1';
    // Limits from the settings (per accommodation or the default); the server checks them again.
    const maxBabies = parseInt(popover.dataset.maxBabies, 10) || 0;
    const maxPets = petsAllowed ? (parseInt(popover.dataset.maxPets, 10) || 0) : 0;

    const input = {
        adults: form.querySelector('input[name="adults"]'),
        children: form.querySelector('input[name="children"]'),
        infants: form.querySelector('input[name="infants"]'),
        pets: form.querySelector('input[name="pets"]'),
    };
    const inputGuests = form.querySelector('input[name="guests"]');

    const state = {
        adults: Math.max(1, parseInt(input.adults.value, 10) || 2),
        children: parseInt(input.children.value, 10) || 0,
        infants: Math.min(maxBabies, parseInt(input.infants.value, 10) || 0),
        pets: Math.min(maxPets, parseInt(input.pets.value, 10) || 0),
    };

    const limits = {
        adults: () => ({ min: 1, max: maxGuests - state.children }),
        children: () => ({ min: 0, max: maxGuests - state.adults }),
        infants: () => ({ min: 0, max: maxBabies }),
        pets: () => ({ min: 0, max: maxPets }),
    };

    function summary() {
        const guests = state.adults + state.children;
        const d = popover.dataset;
        const share = [`${guests} ${guests === 1 ? d.tGuest : d.tGuests}`];
        if (state.infants > 0) share.push(`${state.infants} ${state.infants === 1 ? d.tBaby : d.tBabies}`);
        if (state.pets > 0) share.push(`${state.pets} ${state.pets === 1 ? d.tPet : d.tPets}`);
        return share.join(', ');
    }

    function refresh() {
        popover.querySelectorAll('[data-counter]').forEach((row) => {
            const key = row.dataset.counter;
            const { min, max } = limits[key]();
            row.querySelector('[data-value]').textContent = state[key];
            row.querySelector('[data-step="-1"]').disabled = state[key] <= min;
            row.querySelector('[data-step="1"]').disabled = state[key] >= max;
        });

        Object.keys(state).forEach((k) => { input[k].value = state[k]; });
        inputGuests.value = state.adults + state.children;
        text.textContent = summary();
    }

    popover.querySelectorAll('[data-counter]').forEach((row) => {
        const key = row.dataset.counter;
        row.querySelectorAll('[data-step]').forEach((button) => {
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                const { min, max } = limits[key]();
                state[key] = Math.min(max, Math.max(min, state[key] + parseInt(button.dataset.step, 10)));
                refresh();
                inputGuests.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    field.addEventListener('click', (e) => {
        e.stopPropagation();
        popover.classList.toggle('hidden');
    });
    popover.querySelector('[data-guests-close]')?.addEventListener('click', (e) => {
        e.stopPropagation();
        popover.classList.add('hidden');
    });
    document.addEventListener('click', (e) => {
        if (!popover.contains(e.target) && !field.contains(e.target)) {
            popover.classList.add('hidden');
        }
    });

    refresh();
}

// ---------------------------------------------------------------------------
// Double-month availability calendar (Airbnb style) — reusable module.
// Without data-unavailable-url (e.g. on the homepage) all future dates are
// selectable; with a URL, occupied nights are struck through.
// ---------------------------------------------------------------------------
function initCalendar(popover) {
    const form = popover.closest('form');
    if (!form) return;

    const inputArrival = form.querySelector('input[name="arrival"]');
    const inputDeparture = form.querySelector('input[name="departure"]');
    const locale = popover.dataset.locale || 'nl';

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let firstMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    let arrival = inputArrival.value ? parseISO(inputArrival.value) : null;
    let departure = inputDeparture.value ? parseISO(inputDeparture.value) : null;
    let occupied = new Set();
    let prices = {};
    let minNights = {};      // arrival date → minimum number of nights
    let maxDate = null;      // last bookable date (booking horizon)
    let minText = 'Minimum :nights nights';

    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    // Read "YYYY-MM-DD" as a local date. new Date(string) reads it as
    // UTC midnight, which shifts a day in time zones behind UTC.
    const parseISO = (s) => {
        const [year, month, day] = s.split('-').map(Number);
        return new Date(year, month - 1, day);
    };
    const monthName = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' });
    const shortFormat = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' });

    if (popover.dataset.unavailableUrl) {
        fetch(popover.dataset.unavailableUrl)
            .then((r) => r.json())
            .then((json) => {
                occupied = new Set(json.dates);
                prices = json.prices || {};
                minNights = json.min_nights || {};
                maxDate = json.max_date ? parseISO(json.max_date) : null;
                if (json.min_nights_text) minText = json.min_nights_text;
                render();
            })
            .catch(() => {});
    }

    function weekdays() {
        const fmt = new Intl.DateTimeFormat(locale, { weekday: 'short' });
        const monday = new Date(2026, 5, 1); // a Monday
        return [...Array(7)].map((_, i) => fmt.format(new Date(monday.getTime() + i * 864e5)).slice(0, 2));
    }

    function monthGrid(start) {
        const year = start.getFullYear(), month = start.getMonth();
        const days = new Date(year, month + 1, 0).getDate();
        const offset = (new Date(year, month, 1).getDay() + 6) % 7; // Monday first

        let cells = '';
        for (let i = 0; i < offset; i++) cells += '<div></div>';

        // Minimum stay for the chosen arrival (for the tooltip).
        const minForArrival = arrival && !departure ? (minNights[iso(arrival)] || 1) : 1;
        const minLimit = minForArrival > 1
            ? new Date(arrival.getFullYear(), arrival.getMonth(), arrival.getDate() + minForArrival)
            : null;

        for (let day = 1; day <= days; day++) {
            const date = new Date(year, month, day);
            const key = iso(date);
            const past = date < today || (maxDate && date > maxDate);
            const isOccupied = occupied.has(key);
            const isArrival = arrival && key === iso(arrival);
            const isDeparture = departure && key === iso(departure);
            const inRange = arrival && departure && date > arrival && date < departure;

            // Departure too close: fewer nights than the minimum for this arrival.
            const toShort = minLimit && date > arrival && date < minLimit;
            const minTooltip = minText.replace(':nights', minForArrival);

            const price = prices[key];
            const withPrice = price !== undefined && !past && !(isOccupied && !isDeparture);
            const height = withPrice ? 'h-12 w-12 flex-col gap-0.5' : 'h-10 w-10';

            let cls = `flex ${height} items-center justify-center rounded-full text-sm transition `;
            if (past || (isOccupied && !isDeparture)) {
                cls += 'text-stone-300 line-through cursor-default';
            } else if (toShort) {
                cls += 'text-stone-300 cursor-not-allowed';
            } else if (isArrival || isDeparture) {
                cls += 'bg-stone-900 font-semibold text-white cursor-pointer';
            } else if (inRange) {
                cls += 'bg-stone-100 cursor-pointer hover:ring-1 hover:ring-stone-900';
            } else {
                cls += 'cursor-pointer hover:ring-1 hover:ring-stone-900';
            }

            const clickable = !past && (!isOccupied || isDeparture || !arrival || departure);
            const priceHtml = withPrice && !toShort
                ? `<span class="text-[9px] font-medium leading-none ${isArrival || isDeparture ? 'text-white/80' : 'text-stone-400'}">${price}</span>`
                : '';
            cells += `<div class="flex justify-center"><button type="button" ${clickable ? `data-date="${key}"` : 'disabled'} ${toShort ? `data-to-short="${minForArrival}" title="${minTooltip}"` : ''} class="${cls}"><span>${day}</span>${priceHtml}</button></div>`;
        }

        return `
            <div class="w-full">
                <p class="mb-3 text-center font-semibold capitalize">${monthName.format(start)}</p>
                <div class="grid grid-cols-7 gap-y-1 text-center text-xs font-medium uppercase text-stone-400">
                    ${weekdays().map((d) => `<div>${d}</div>`).join('')}
                </div>
                <div class="mt-1 grid grid-cols-7 gap-y-1">${cells}</div>
            </div>`;
    }

    function render() {
        const secondMonth = new Date(firstMonth.getFullYear(), firstMonth.getMonth() + 1, 1);
        const canGoBack = firstMonth > today;

        // The months slide under the arrows with -mt-9, next to the month name.
        // Without a z-index they lie over the arrows and catch the click.
        popover.innerHTML = `
            <div class="flex items-center justify-between">
                <button type="button" data-kal-previous ${canGoBack ? '' : 'disabled'}
                        class="relative z-10 rounded-full p-2 transition hover:bg-stone-100 disabled:opacity-30">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </button>
                <button type="button" data-kal-next class="relative z-10 rounded-full p-2 transition hover:bg-stone-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
            </div>
            <div class="-mt-9 flex flex-col gap-8 sm:flex-row">${monthGrid(firstMonth)}${monthGrid(secondMonth)}</div>
            <div class="mt-4 flex items-center justify-between gap-3 border-t border-stone-100 pt-3">
                <p data-kal-hint class="text-sm font-medium text-amber-600 ${arrival && !departure && (minNights[iso(arrival)] || 1) > 1 ? '' : 'hidden'}">
                    ${minText.replace(':nights', arrival ? (minNights[iso(arrival)] || 1) : 1)}
                </p>
                <button type="button" data-kal-clear class="ml-auto text-sm font-medium underline hover:text-rose-600">
                    ${popover.dataset.textClear}
                </button>
            </div>`;

        popover.querySelector('[data-kal-previous]')?.addEventListener('click', (e) => {
            e.stopPropagation();
            firstMonth = new Date(firstMonth.getFullYear(), firstMonth.getMonth() - 1, 1);
            render();
        });
        popover.querySelector('[data-kal-next]')?.addEventListener('click', (e) => {
            e.stopPropagation();
            firstMonth = new Date(firstMonth.getFullYear(), firstMonth.getMonth() + 1, 1);
            render();
        });
        popover.querySelector('[data-kal-clear]')?.addEventListener('click', (e) => {
            e.stopPropagation();
            arrival = departure = null;
            setValues();
            render();
        });
        popover.querySelectorAll('[data-date]').forEach((button) => {
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                choose(parseISO(button.dataset.date));
            });
        });
    }

    function freeBetween(of, until) {
        for (let d = new Date(of); d < until; d = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1)) {
            if (occupied.has(iso(d))) return false;
        }
        return true;
    }

    function choose(date) {
        if (!arrival || (arrival && departure)) {
            if (occupied.has(iso(date))) return;
            arrival = date;
            departure = null;
        } else if (date > arrival && freeBetween(arrival, date)) {
            // Minimum stay: do not accept a departure that is too early, show the hint.
            const min = minNights[iso(arrival)] || 1;
            const nights = Math.round((date - arrival) / 864e5);
            if (nights < min) {
                const hint = popover.querySelector('[data-kal-hint]');
                if (hint) hint.classList.remove('hidden');
                return;
            }
            departure = date;
            closes();
        } else {
            if (occupied.has(iso(date))) return;
            arrival = date;
            departure = null;
        }

        setValues();
        render();
    }

    function setValues() {
        inputArrival.value = arrival ? iso(arrival) : '';
        inputDeparture.value = departure ? iso(departure) : '';

        const fieldArrival = form.querySelector('[data-calendar-text="arrival"]');
        const fieldDeparture = form.querySelector('[data-calendar-text="departure"]');
        if (fieldArrival) fieldArrival.textContent = arrival ? shortFormat.format(arrival) : popover.dataset.textChoose;
        if (fieldDeparture) fieldDeparture.textContent = departure ? shortFormat.format(departure) : popover.dataset.textChoose;

        inputArrival.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function open() { render(); popover.classList.remove('hidden'); }
    function closes() { popover.classList.add('hidden'); }

    form.querySelectorAll('[data-calendar-field]').forEach((field) => {
        field.addEventListener('click', (e) => {
            e.stopPropagation();
            if (popover.classList.contains('hidden')) open();
        });
    });

    document.addEventListener('click', (e) => {
        if (!popover.contains(e.target) && !e.target.closest('[data-calendar-field]')) closes();
    });

    if (arrival || departure) setValues();
}

document.querySelectorAll('[data-guests-popover]').forEach(initGuestPicker);
document.querySelectorAll('[data-calendar-popover]').forEach(initCalendar);

// ---------------------------------------------------------------------------
// Map on the accommodation detail page: a circle around the area (the centre is
// not the real spot), or a pin when the owner shows the exact location.
// ---------------------------------------------------------------------------
const mapElement = document.getElementById('map');

if (mapElement) {
    const lat = parseFloat(mapElement.dataset.lat);
    const lng = parseFloat(mapElement.dataset.lng);

    if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
        const map = L.map(mapElement, {
            scrollWheelZoom: false,
        }).setView([lat, lng], 14);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(map);

        const exact = mapElement.dataset.exact === '1';

        if (!exact) {
            L.circle([lat, lng], {
                radius: parseInt(mapElement.dataset.radius, 10) || 300,
                color: '#e11d48',
                fillColor: '#e11d48',
                fillOpacity: 0.2,
                weight: 2,
            }).addTo(map);
        }

        const houseIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px">'
            + '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>';

        // The pin only on the exact spot; on a circle it would give the spot away.
        if (exact) {
            L.marker([lat, lng], {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="background:#e11d48;color:#fff;border-radius:9999px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.3)">'
                        + houseIcon + '</div>',
                    iconSize: [36, 36],
                    iconAnchor: [18, 18],
                }),
            }).addTo(map);
        }
    }
}

// ---------------------------------------------------------------------------
// Photo viewer (site/properties/partials/photo-lightbox.blade.php): one photo
// at a time, opened at the photo that was clicked. Arrows, arrow keys, swipe
// and thumbnails move through the photos; Escape closes (native <dialog>).
//
// Performance tracking: a photo counts as viewed once it has been shown for
// at least VIEW_MS, once per photo per visit. Clicking quickly past a photo
// does not count it.
// ---------------------------------------------------------------------------
(function initPhotoViewer() {
    const dialog = document.querySelector('[data-photo-viewer]');
    if (!dialog) return;

    const VIEW_MS = 1000;
    const SWIPE_PX = 50;
    const slides = [...dialog.querySelectorAll('[data-photo-slide]')];
    const thumbs = [...dialog.querySelectorAll('[data-photo-thumb]')];
    const caption = dialog.querySelector('[data-photo-caption]');
    const counter = dialog.querySelector('[data-photo-counter]');
    const strip = dialog.querySelector('[data-photo-thumbs]');
    const stripPrev = dialog.querySelector('[data-photo-thumbs-prev]');
    const stripNext = dialog.querySelector('[data-photo-thumbs-next]');
    const propertyId = dialog.dataset.property;
    const tracked = new Set();
    let current = 0;
    let viewTimer = null;

    const track = (slide) => {
        clearTimeout(viewTimer);
        const id = slide.dataset.photoTrack;
        if (!id || tracked.has(id) || !propertyId || !navigator.sendBeacon) return;

        viewTimer = setTimeout(() => {
            if (!dialog.open || slides[current] !== slide) return;
            tracked.add(id);
            const data = new FormData();
            data.append('property_id', propertyId);
            data.append('media_id', id);
            navigator.sendBeacon('/track/photo', data);
        }, VIEW_MS);
    };

    // The photo shown loads at once, and its neighbours too, so the next
    // photo is there immediately; the rest waits until it is shown.
    const preload = (index) => {
        slides[index]?.querySelectorAll('img[loading="lazy"]').forEach((img) => { img.loading = 'eager'; });
    };

    const show = (index) => {
        const total = slides.length;
        if (total === 0) return;
        index = (index + total) % total;

        slides[current]?.querySelectorAll('video').forEach((video) => video.pause());
        slides.forEach((slide, i) => { slide.hidden = i !== index; });
        thumbs.forEach((thumb, i) => {
            thumb.toggleAttribute('data-active', i === index);
            if (i === index) thumb.setAttribute('aria-current', 'true'); else thumb.removeAttribute('aria-current');
        });
        thumbs[index]?.scrollIntoView({ block: 'nearest', inline: 'center' });

        current = index;
        caption.textContent = slides[index].dataset.caption || '';
        counter.textContent = `${index + 1} / ${total}`;
        preload(index);
        preload((index + 1) % total);
        preload((index - 1 + total) % total);

        if (dialog.open) track(slides[index]);
    };

    // The arrows beside the thumbnails: each one only while there is more
    // to see on its side.
    const updateStrip = () => {
        if (!strip) return;
        const end = strip.scrollWidth - strip.clientWidth;
        stripPrev.disabled = strip.scrollLeft <= 1;
        stripNext.disabled = strip.scrollLeft >= end - 1;
    };
    const scrollStrip = (direction) => strip.scrollBy({ left: direction * strip.clientWidth * 0.8, behavior: 'smooth' });

    // The page behind does not scroll while the viewer is open: see
    // html:has([data-photo-viewer][open]) in resources/css/app.css.
    const open = (index) => {
        dialog.showModal();
        show(index);
        updateStrip();
    };

    document.querySelectorAll('[data-photo-open]').forEach((button) => {
        button.addEventListener('click', () => open(Number(button.dataset.photoOpen) || 0));
    });
    dialog.querySelector('[data-photo-close]')?.addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-photo-prev]')?.addEventListener('click', () => show(current - 1));
    dialog.querySelector('[data-photo-next]')?.addEventListener('click', () => show(current + 1));
    thumbs.forEach((thumb) => thumb.addEventListener('click', () => show(Number(thumb.dataset.index))));
    stripPrev?.addEventListener('click', () => scrollStrip(-1));
    stripNext?.addEventListener('click', () => scrollStrip(1));
    strip?.addEventListener('scroll', updateStrip, { passive: true });
    window.addEventListener('resize', () => { if (dialog.open) updateStrip(); });

    dialog.addEventListener('close', () => {
        clearTimeout(viewTimer);
        slides[current]?.querySelectorAll('video').forEach((video) => video.pause());
    });

    dialog.addEventListener('keydown', (event) => {
        if (event.target.closest('video')) return; // the video uses the arrows to seek
        if (event.key === 'ArrowRight') { event.preventDefault(); show(current + 1); }
        if (event.key === 'ArrowLeft') { event.preventDefault(); show(current - 1); }
    });

    // Swipe on the photo: horizontal movement only, so scrolling still works.
    let touchX = null;
    let touchY = null;
    const stage = slides[0]?.parentElement;
    stage?.addEventListener('touchstart', (event) => {
        // On a video a swipe is dragging its timeline; the arrows still work.
        if (event.target.closest('video')) { touchX = null; return; }
        touchX = event.touches[0].clientX;
        touchY = event.touches[0].clientY;
    }, { passive: true });
    stage?.addEventListener('touchend', (event) => {
        if (touchX === null) return;
        const dx = event.changedTouches[0].clientX - touchX;
        const dy = event.changedTouches[0].clientY - touchY;
        touchX = null;
        if (Math.abs(dx) > SWIPE_PX && Math.abs(dx) > Math.abs(dy)) show(current + (dx < 0 ? 1 : -1));
    }, { passive: true });
})();

// ---------------------------------------------------------------------------
// List/Map tabs on the homepage. The map ("explore the map") shows all
// filtered accommodations as price labels with a photo popup, and is only
// built once the map tab is opened for the first time (Leaflet cannot
// render in a hidden panel).
// ---------------------------------------------------------------------------
function buildExploreMap() {
    const el = document.getElementById('explore-map');
    const dataEl = document.getElementById('explore-map-data');
    if (!el || !dataEl || el.dataset.built) return;

    let points = [];
    try { points = JSON.parse(dataEl.textContent); } catch { return; }
    if (!points.length) return;

    el.dataset.built = '1';

    const map = L.map(el, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 19,
    }).addTo(map);

    const text = {
        price: el.dataset.textPrice,
        extra: el.dataset.textExtra,
        button: el.dataset.textButton,
    };

    const pinIcon = '<svg style="width:16px;height:16px;flex:none" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>';

    const pricePill = (p, active) => `<div class="map-price-pill" style="background:${active ? '#1c1917' : '#fff'};color:${active ? '#fff' : '#1c1917'};border:1px solid ${active ? '#1c1917' : '#d6d3d1'};border-radius:9999px;padding:5px 11px;font-weight:600;font-size:13px;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.2)">${p.price}</div>`;

    const markers = points.map((p) => {
        const marker = L.marker([p.lat, p.lng], {
            icon: L.divIcon({ className: '', html: pricePill(p, false), iconSize: null }),
        }).addTo(map);

        marker.bindPopup(
            '<div style="font-family:inherit">'
            + (p.photo ? `<img src="${p.photo}" style="display:block;width:100%;height:150px;object-fit:cover" alt="">` : '')
            + '<div style="padding:14px 16px 16px">'
            + `<strong style="display:block;font-size:15px;line-height:1.3;color:#1c1917">${p.name}</strong>`
            + (p.city
                ? `<span style="display:flex;align-items:center;gap:5px;margin-top:6px;font-size:13px;color:#57534e">${pinIcon}<span>${p.city}</span></span>`
                : '')
            + `<span style="display:block;margin-top:12px;font-size:12px;color:#78716c">${text.price}</span>`
            + `<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:10px;margin-top:2px">`
            + `<span><span style="display:block;font-size:20px;font-weight:700;color:#1c1917">${p.price_full}</span>`
            + `<span style="display:block;margin-top:2px;font-size:11px;color:#a8a29e">${text.extra}</span></span>`
            + `<a href="${p.url}" class="button-accent" style="flex:none;background:#e11d48;color:#fff;border-radius:10px;padding:9px 14px;font-weight:600;font-size:13px;text-decoration:none;white-space:nowrap">${text.button}</a>`
            + '</div></div></div>',
            { className: 'map-popup', maxWidth: 272, minWidth: 272, offset: [0, -6] }
        );

        // The pill of the opened home turns dark, as on Airbnb.
        marker.on('popupopen', () => marker.setIcon(L.divIcon({ className: '', html: pricePill(p, true), iconSize: null })));
        marker.on('popupclose', () => marker.setIcon(L.divIcon({ className: '', html: pricePill(p, false), iconSize: null })));

        return marker;
    });

    map.fitBounds(L.featureGroup(markers).getBounds().pad(0.25), { maxZoom: 14 });
}

(function initViewTabs() {
    const tabs = document.querySelector('[data-view-tabs]');
    if (!tabs) return;

    const activeClasses = ['bg-stone-900', 'text-white'];
    const inactiveClasses = ['text-stone-700'];

    tabs.querySelectorAll('[data-view]').forEach((button) => {
        button.addEventListener('click', () => {
            const choice = button.dataset.view;

            tabs.querySelectorAll('[data-view]').forEach((other) => {
                const active = other === button;
                other.classList.toggle('bg-stone-900', active);
                other.classList.toggle('text-white', active);
                other.classList.toggle('text-stone-700', !active);
            });

            document.querySelectorAll('[data-panel]').forEach((panel) => {
                panel.classList.toggle('hidden', panel.dataset.panel !== choice);
            });

            if (choice === 'map') buildExploreMap();
        });
    });
})();

// ---------------------------------------------------------------------------
// Autocomplete for the "Where to" field: places fill in the field, an
// accommodation links straight through. With keyboard control (↑ ↓ Enter Esc).
// ---------------------------------------------------------------------------
function initWhereSuggestions(input) {
    const popover = input.parentElement.querySelector('[data-where-suggestions]');
    if (!popover) return;

    const pinIcon = '<svg class="h-4 w-4 text-stone-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>';
    const houseIcon = '<svg class="h-4 w-4 text-stone-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/></svg>';

    let timer = null;
    let items = [];
    let active = -1;

    const closes = () => { popover.classList.add('hidden'); active = -1; };

    const choose = (suggestion) => {
        if (suggestion.url) {
            window.location.href = suggestion.url;
            return;
        }
        input.value = suggestion.text;
        closes();
    };

    const highlight = () => {
        popover.querySelectorAll('[data-suggestion]').forEach((button, i) => {
            button.classList.toggle('bg-stone-100', i === active);
        });
    };

    const render = () => {
        if (!items.length) { closes(); return; }

        popover.innerHTML = items.map((s, i) => `
            <button type="button" data-suggestion="${i}" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-stone-100">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-stone-100">${s.type === 'city' ? pinIcon : houseIcon}</span>
                <span class="min-w-0"><span class="block truncate font-medium text-stone-900">${s.text}</span>${s.detail ? `<span class="block truncate text-xs text-stone-500">${s.detail}</span>` : ''}</span>
            </button>`).join('');
        popover.classList.remove('hidden');
        active = -1;

        popover.querySelectorAll('[data-suggestion]').forEach((button) => {
            // mousedown instead of click: before the field's blur.
            button.addEventListener('mousedown', (e) => {
                e.preventDefault();
                choose(items[parseInt(button.dataset.suggestion, 10)]);
            });
        });
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const search = input.value.trim();
        if (search.length < 2) { closes(); return; }

        timer = setTimeout(() => {
            fetch(`${input.dataset.suggestionsUrl}?q=${encodeURIComponent(search)}`)
                .then((r) => r.json())
                .then((json) => { items = json.suggestions || []; render(); })
                .catch(() => closes());
        }, 250);
    });

    input.addEventListener('keydown', (e) => {
        if (popover.classList.contains('hidden')) return;

        if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); highlight(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, -1); highlight(); }
        else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(items[active]); }
        else if (e.key === 'Escape') { closes(); }
    });

    input.addEventListener('blur', () => setTimeout(closes, 150));
}

document.querySelectorAll('[data-where-input]').forEach(initWhereSuggestions);
