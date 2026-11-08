/* Hero photos repeater on Pages (ported from devsite's pages/edit.blade.php):
   draggable list with per photo a title (per language), link, position and mobile variant.
   As an Alpine component so it also works in a Filament modal (Livewire morph).
   Requires pickMedia/ImageEditor/toast from media-manager.js and Sortable (CDN). */
window.heroImagesField = function (config) {
    return {
        state: config.state,        // entangled Filament field (array)
        heroes: [],

        init() {
            this.heroes = Array.isArray(this.state) ? JSON.parse(JSON.stringify(this.state)) : [];
            this.render();

            new Sortable(this.$refs.list, {
                animation: 150,
                handle: '.drag-handle',
                onEnd: (evt) => {
                    const moved = this.heroes.splice(evt.oldIndex, 1)[0];
                    this.heroes.splice(evt.newIndex, 0, moved);
                    this.render();
                },
            });
        },

        sync() {
            this.state = JSON.parse(JSON.stringify(this.heroes));
        },

        escapeAttr(value) {
            return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        },

        addHero() {
            pickMedia(f => {
                this.heroes.push({ image: f.path, image_mobile: '', title: {}, link: '', position: 'center' });
                this.render();
            });
        },

        render() {
            const heroList = this.$refs.list;
            const HERO_LOCALES = config.locales;
            const HERO_POSITIONS = config.positions;
            const HERO_I18N = config.i18n;
            const heroes = this.heroes;
            const escapeAttr = this.escapeAttr;

            this.sync();
            heroList.innerHTML = '';
            heroes.forEach((hero, i) => {
                const card = document.createElement('div');
                card.className = 'card p-4 flex flex-wrap gap-4';
                card.dataset.index = i;

                const posOptions = Object.entries(HERO_POSITIONS)
                    .map(([value, label]) => `<option value="${value}" ${hero.position === value ? 'selected' : ''}>${label}</option>`)
                    .join('');
                const titleInputs = HERO_LOCALES.map(loc => `
                    <input type="text" class="input hero-title" data-locale="${loc}"
                           placeholder="${HERO_I18N.title} (${loc.toUpperCase()})" value="${escapeAttr((hero.title || {})[loc] || '')}">`).join('');

                card.innerHTML = `
                    <i class="bi bi-grip-vertical drag-handle self-center"></i>
                    <div class="w-44 shrink-0">
                        <div class="mb-2 flex h-24 items-center justify-center overflow-hidden rounded-lg border border-dashed border-gray-300 bg-gray-50 dark:border-white/10 dark:bg-gray-900 hero-preview">
                            ${hero.image ? `<img src="${config.storageUrl}/${hero.image}" class="h-full w-full object-cover">` : '<i class="bi bi-image text-2xl text-gray-300"></i>'}
                        </div>
                        ${hero.image_mobile ? `
                        <div class="mb-2 flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50 p-1.5 dark:border-blue-500/40 dark:bg-blue-950/40">
                            <img src="${config.storageUrl}/${hero.image_mobile}" class="h-10 w-8 rounded object-cover">
                            <span class="min-w-0 flex-1 truncate text-[11px] text-blue-700 dark:text-blue-300"><i class="bi bi-phone"></i> ${HERO_I18N.mobile}</span>
                            <span class="icon-btn remove-mobile" title="×"><i class="bi bi-x"></i></span>
                        </div>` : ''}
                        <div class="flex flex-col gap-1.5">
                            <button type="button" class="btn-secondary btn-sm pick-desktop"><i class="bi bi-folder2-open"></i> ${HERO_I18N.choose}</button>
                            ${hero.image ? `<button type="button" class="btn-secondary btn-sm edit-image"><i class="bi bi-magic"></i> ${HERO_I18N.edit}</button>` : ''}
                            <button type="button" class="btn-secondary btn-sm pick-mobile">
                                <i class="bi bi-${hero.image_mobile ? 'check-circle-fill text-blue-500' : 'phone'}"></i> ${HERO_I18N.mobile}
                            </button>
                        </div>
                    </div>
                    <div class="min-w-56 flex-1 space-y-2">
                        ${titleInputs}
                        <input type="text" class="input hero-link" placeholder="${HERO_I18N.link}" value="${escapeAttr(hero.link || '')}">
                        <select class="input hero-position">${posOptions}</select>
                    </div>
                    <button type="button" class="btn-danger btn-sm self-start remove-hero"><i class="bi bi-trash"></i></button>`;

                card.querySelector('.pick-desktop').onclick = () => pickMedia(f => { heroes[i].image = f.path; this.render(); });
                card.querySelector('.edit-image')?.addEventListener('click', () =>
                    ImageEditor.open(heroes[i].image, res => { heroes[i].image = res.path; this.render(); }));
                card.querySelector('.pick-mobile').onclick = () => pickMedia(f => { heroes[i].image_mobile = f.path; this.render(); toast(window.I18N.saved); });
                card.querySelector('.remove-mobile')?.addEventListener('click', () => { heroes[i].image_mobile = ''; this.render(); });
                card.querySelector('.remove-hero').onclick = () => { heroes.splice(i, 1); this.render(); };
                card.querySelectorAll('.hero-title').forEach(input =>
                    input.addEventListener('input', () => { heroes[i].title = heroes[i].title || {}; heroes[i].title[input.dataset.locale] = input.value; this.sync(); }));
                card.querySelector('.hero-link').addEventListener('input', e => { heroes[i].link = e.target.value; this.sync(); });
                card.querySelector('.hero-position').addEventListener('change', e => { heroes[i].position = e.target.value; this.sync(); });

                heroList.appendChild(card);
            });
        },
    };
};
