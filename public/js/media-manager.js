/* Media manager (ported from devsite's admin.js): toast, modals, AJAX, media browser,
   photo editor and the picker (pickMedia). The page sets window.ROUTES, window.I18N
   and window.STORAGE_URL beforehand. Requires (CDN): FilePond, Cropper.js, Sortable, mammoth. */
(function () {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    // ---------- safe Word preview ----------
    // mammoth turns a .docx into HTML without cleaning it: a link in the
    // document can be "javascript:...". Only text and plain markup stay, links
    // only to http(s)/mailto, images only as embedded pictures; no element
    // runs code, and a link opens apart from the admin.
    const PREVIEW_DROP = 'script, style, iframe, frame, object, embed, link, meta, base, form, input, button, textarea, select, svg, math';
    function safePreview(html) {
        const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
        doc.querySelectorAll(PREVIEW_DROP).forEach(el => el.remove());
        doc.body.querySelectorAll('*').forEach(el => {
            [...el.attributes].forEach(attr => {
                const name = attr.name.toLowerCase();
                const value = attr.value.trim().toLowerCase();
                const keep = (name === 'href' && /^(https?:|mailto:|#)/.test(value))
                    || (name === 'src' && el.tagName === 'IMG' && /^data:image\/(png|jpe?g|gif|webp);/.test(value))
                    || ['alt', 'colspan', 'rowspan', 'id'].includes(name);
                if (!keep) el.removeAttribute(attr.name);
            });
            if (el.tagName === 'A' && el.hasAttribute('href')) {
                el.setAttribute('target', '_blank');
                el.setAttribute('rel', 'noopener noreferrer');
            }
        });
        const fragment = document.createDocumentFragment();
        fragment.append(...doc.body.childNodes);
        return fragment;
    }

    // ---------- toasts ----------
    window.toast = function (message, type = 'success') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container fixed bottom-4 right-4 z-[100] flex flex-col gap-2';
            document.body.appendChild(container);
        }
        const colors = {
            success: 'bg-emerald-600',
            danger: 'bg-red-600',
            warning: 'bg-amber-500',
        };
        const el = document.createElement('div');
        el.className = `${colors[type] || colors.success} text-white text-sm rounded-lg px-4 py-2.5 shadow-lg transition-opacity`;
        el.textContent = message;
        container.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 2500);
    };

    // ---------- modal ----------
    window.openModal = function (id) {
        const modal = typeof id === 'string' ? document.getElementById(id) : id;
        if (modal) modal.classList.add('open');
        return modal;
    };
    window.closeModal = function (el) {
        const modal = typeof el === 'string' ? document.getElementById(el) : el.closest('.modal');
        if (modal) modal.classList.remove('open');
    };
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-modal-close]')) {
            closeModal(e.target.closest('.modal'));
        } else if (e.target.classList.contains('modal-backdrop')) {
            closeModal(e.target.closest('.modal'));
        }
        const opener = e.target.closest('[data-modal-open]');
        if (opener) openModal(opener.dataset.modalOpen);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
    });

    // ---------- fetch wrapper ----------
    window.api = async function (url, { method = 'POST', data = null, form = null } = {}) {
        const opts = { method, headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (form) {
            if (method !== 'POST' && method !== 'GET') {
                form.append('_method', method);
                opts.method = 'POST';
            }
            opts.body = form;
        } else if (data && method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(data);
        }
        const res = await fetch(url, opts);
        let json = {};
        try { json = await res.json(); } catch (e) { /* empty */ }
        if (!res.ok) {
            const msg = json.message || (json.errors ? Object.values(json.errors)[0][0] : null) || 'Error ' + res.status;
            toast(msg, 'danger');
            throw Object.assign(new Error(msg), { response: json, status: res.status });
        }
        return json;
    };

    // ---------- media browser (reusable: modal picker and inline on the media page) ----------
    class MediaBrowser {
        constructor(root) {
            this.root = root;
            this.path = '';
            this.pickCb = null;
            this.multiple = false;
            this.search = '';
            this.typeFilter = 'all';
            this.view = 'grid';
            this.lastData = null;
            this.selection = window.MediaSelection ? new window.MediaSelection() : null;
            this.visible = [];   // what is currently on screen, in screen order
            root.querySelector('.mm-upload input').addEventListener('change', e => this.upload(e.target.files));
            root.querySelector('.mm-new-folder').addEventListener('click', () => this.newFolder());
            root.querySelector('.mm-select-btn')?.addEventListener('click', () => this.confirmPick());
            root.querySelector('.mm-search')?.addEventListener('input', e => { this.search = e.target.value.toLowerCase().trim(); this.renderItems(); });
            root.querySelector('.mm-type')?.addEventListener('change', e => { this.typeFilter = e.target.value; this.renderItems(); });
            root.querySelector('.mm-view-grid')?.addEventListener('click', () => this.setView('grid'));
            root.querySelector('.mm-view-list')?.addEventListener('click', () => this.setView('list'));
            this.initPond();
            this.initBulk();
        }

        /** Checkboxes + button bar: only in the media manager, not in the image picker. */
        bulkMode() {
            return !!this.selection && !this.pickCb && !!this.root.querySelector('.mm-bulk');
        }

        initBulk() {
            const bar = this.root.querySelector('.mm-bulk');
            if (!bar || !this.selection) return;

            bar.querySelector('.mm-bulk-all').addEventListener('change', e => {
                this.selection.setAll(this.visible, e.target.checked);
                this.renderItems();
            });
            bar.querySelector('.mm-bulk-clear').addEventListener('click', () => {
                this.selection.clear();
                this.renderItems();
            });
            bar.querySelector('.mm-bulk-delete').addEventListener('click', () => this.deleteSelected());
        }

        /** Puts the checkbox on a tile or row. */
        addCheckbox(item, info) {
            const wrap = document.createElement('label');
            wrap.className = 'm-check';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'checkbox';
            box.checked = this.selection.has(info.path);
            wrap.appendChild(box);

            // do not click through to open/preview
            wrap.addEventListener('click', e => e.stopPropagation());
            box.addEventListener('click', e => {
                e.stopPropagation();
                this.selection.click(info.path, this.visible, e.shiftKey);
                this.renderItems();
            });

            item.classList.toggle('checked', box.checked);
            if (this.view === 'grid') item.prepend(wrap); else item.prepend(wrap);
        }

        updateBulkBar() {
            const bar = this.root.querySelector('.mm-bulk');
            if (!bar) return;
            if (!this.bulkMode()) { bar.classList.add('hidden'); return; }

            const n = this.selection.count;
            bar.classList.remove('hidden');
            bar.classList.add('flex');
            bar.querySelector('.mm-bulk-count').textContent = n
                ? (window.I18N.n_selected || '%n selected').replace('%n', n)
                : '';
            bar.querySelector('.mm-bulk-actions').classList.toggle('hidden', n === 0);
            bar.querySelector('.mm-bulk-all').checked = this.selection.allSelected(this.visible);
        }

        async deleteSelected() {
            const items = this.selection.items();
            if (!items.length) return;
            const question = (window.I18N.confirm_delete_selected || 'Delete?').replace('%n', items.length);
            if (!confirm(question)) return;

            const btn = this.root.querySelector('.mm-bulk-delete');
            btn.disabled = true;
            try {
                await api(window.ROUTES.mediaDeleteMany, { data: { items } });
                this.selection.clear();
                toast(window.I18N.saved);
                await this.load(this.path);
            } catch (e) { /* api() shows the message */ }
            btn.disabled = false;
        }

        setView(view) {
            this.view = view;
            this.root.querySelector('.mm-view-grid')?.classList.toggle('!bg-blue-600', view === 'grid');
            this.root.querySelector('.mm-view-grid')?.classList.toggle('!text-white', view === 'grid');
            this.root.querySelector('.mm-view-list')?.classList.toggle('!bg-blue-600', view === 'list');
            this.root.querySelector('.mm-view-list')?.classList.toggle('!text-white', view === 'list');
            this.renderItems();
        }

        static fileMeta(name) {
            const ext = (name.split('.').pop() || '').toLowerCase();
            const icons = {
                pdf: 'bi-file-earmark-pdf text-red-500',
                doc: 'bi-file-earmark-word text-blue-600', docx: 'bi-file-earmark-word text-blue-600',
                xls: 'bi-file-earmark-spreadsheet text-emerald-600', xlsx: 'bi-file-earmark-spreadsheet text-emerald-600',
                mp4: 'bi-file-earmark-play text-purple-500', webm: 'bi-file-earmark-play text-purple-500',
                zip: 'bi-file-earmark-zip text-amber-500',
            };
            const kind = ['pdf', 'doc', 'docx', 'xls', 'xlsx'].includes(ext) ? 'doc'
                : ['mp4', 'webm'].includes(ext) ? 'video' : 'other';
            return { ext, icon: icons[ext] || 'bi-file-earmark', kind };
        }

        static fmtSize(bytes) {
            if (!bytes && bytes !== 0) return '';
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
            return bytes.toFixed(i ? 1 : 0) + ' ' + units[i];
        }

        matchesFilter(f) {
            const meta = MediaBrowser.fileMeta(f.name);
            if (this.search && !f.name.toLowerCase().includes(this.search)) return false;
            if (this.typeFilter === 'image') return f.is_image;
            if (this.typeFilter === 'doc') return meta.kind === 'doc';
            if (this.typeFilter === 'video') return meta.kind === 'video';
            return true;
        }

        // preview modal: large image, video player, pdf viewer or file info
        preview(f) {
            const modal = document.getElementById('media-preview-modal');
            if (!modal) return;
            const meta = MediaBrowser.fileMeta(f.name);
            const content = modal.querySelector('#mp-content');
            const fallback = `<div class="py-16 text-center"><i class="bi ${meta.icon} text-6xl"></i>
                <p class="mt-3 text-sm text-gray-400">${window.I18N.preview_unavailable || ''}</p></div>`;
            if (f.is_image) {
                content.innerHTML = `<img src="${f.url}" class="mx-auto max-h-[62vh] rounded-lg" alt="">`;
            } else if (meta.kind === 'video') {
                content.innerHTML = `<video src="${f.url}" controls class="mx-auto max-h-[62vh] rounded-lg"></video>`;
            } else if (meta.ext === 'pdf') {
                // object + iframe fallback: some browsers/settings block one or the other
                content.innerHTML = `<object data="${f.url}#view=FitH" type="application/pdf" class="h-[62vh] w-full rounded-lg">
                    <iframe src="${f.url}" class="h-[62vh] w-full rounded-lg border-0"></iframe>
                </object>`;
            } else if (meta.ext === 'docx' && window.mammoth) {
                // Word preview: mammoth.js converts docx to HTML in the browser
                content.innerHTML = `<div class="py-16 text-center text-sm text-gray-400"><i class="bi bi-hourglass-split text-3xl"></i></div>`;
                fetch(f.url)
                    .then(r => { if (!r.ok) throw new Error('fetch'); return r.arrayBuffer(); })
                    .then(buffer => mammoth.convertToHtml({ arrayBuffer: buffer }))
                    .then(result => {
                        content.innerHTML = `<div class="page-content max-h-[62vh] overflow-y-auto rounded-lg border border-gray-200 bg-white p-6 text-gray-800 dark:border-white/10"></div>`;
                        content.firstElementChild.append(safePreview(result.value));
                    })
                    .catch(() => { content.innerHTML = fallback; });
            } else {
                content.innerHTML = fallback;
            }
            modal.querySelector('#mp-name').textContent = f.name;
            modal.querySelector('#mp-meta').textContent = [MediaBrowser.fmtSize(f.size), f.modified].filter(Boolean).join(' · ');
            modal.querySelector('#mp-open').href = f.url;
            openModal(modal);
        }

        // file details: rename the file and (for images) edit the alt text
        openDetails(info, currentName) {
            const modal = document.getElementById('media-details-modal');
            if (!modal) return;
            const isImage = !!info.image;
            const isDir = info.type === 'dir';
            const dot = currentName.lastIndexOf('.');
            const base = (isDir || dot <= 0) ? currentName : currentName.slice(0, dot);
            const ext = (isDir || dot <= 0) ? '' : currentName.slice(dot);

            const nameInput = modal.querySelector('#md-name');
            const altBox = modal.querySelector('#md-alt-box');
            const altInput = modal.querySelector('#md-alt');
            const altHint = modal.querySelector('#md-alt-hint');

            nameInput.value = base;
            modal.querySelector('#md-ext').textContent = ext;
            modal.querySelector('#md-thumb').innerHTML = isImage
                ? `<img src="${info.file.url}" class="h-20 w-20 rounded-lg object-cover" alt="">`
                : `<i class="bi ${isDir ? 'bi-folder-fill text-amber-400' : MediaBrowser.fileMeta(currentName).icon} text-3xl"></i>`;

            altBox.classList.toggle('hidden', !isImage);
            if (isImage) {
                altInput.value = info.file.alt || '';
                altHint.textContent = info.file.alt_custom
                    ? (window.I18N.alt_custom || '')
                    : (window.I18N.alt_from_filename || '');
            }

            modal.querySelector('#md-save').onclick = async () => {
                const newName = nameInput.value.trim();
                let path = info.path;
                try {
                    // rename first: the alt moves along, then we set it on the new path
                    if (newName && newName !== base) {
                        const r = await api(window.ROUTES.mediaRename, { data: { path, name: newName + ext } });
                        path = r.path || path;
                    }
                    if (isImage) {
                        await api(window.ROUTES.mediaAlt, { data: { path, alt: altInput.value } });
                    }
                } catch (e) {
                    return;   // api() already shows the error message
                }
                closeModal(modal);
                toast(window.I18N.saved);
                this.load(this.path);
            };

            openModal(modal);
            setTimeout(() => nameInput.focus(), 50);
        }

        // FilePond: drag & drop multi-upload with progress bars
        initPond() {
            const input = this.root.querySelector('.mm-pond input');
            if (!input || !window.FilePond) return;
            const browser = this;
            this.pond = FilePond.create(input, {
                allowMultiple: true,
                credits: false,
                maxParallelUploads: 3,
                labelIdle: window.I18N.upload_drop || 'Drop files here',
                server: {
                    process(fieldName, file, metadata, load, error, progress, abort) {
                        const fd = new FormData();
                        fd.append('path', browser.path);
                        fd.append('files[]', file, file.name);
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.ROUTES.mediaUpload);
                        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                        xhr.setRequestHeader('Accept', 'application/json');
                        xhr.upload.onprogress = e => progress(e.lengthComputable, e.loaded, e.total);
                        xhr.onload = () => {
                            if (xhr.status < 300) {
                                load(file.name);
                                browser.load(browser.path);
                            } else {
                                let msg = 'Upload failed';
                                try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) { /* empty */ }
                                error(msg);
                                toast(msg, 'danger');
                            }
                        };
                        xhr.onerror = () => error('Upload failed');
                        xhr.send(fd);
                        return { abort: () => { xhr.abort(); abort(); } };
                    },
                },
                onprocessfile: (err, file) => {
                    if (!err) setTimeout(() => this.pond.removeFile(file.id), 1200);
                },
            });
        }

        async load(path = '') {
            // other folder = new selection; refreshing the same folder keeps the checkmarks
            if (this.selection && path !== this.path) this.selection.clear();
            this.path = path;
            const json = await api(window.ROUTES.mediaList + '?path=' + encodeURIComponent(path), { method: 'GET' });
            this.render(json);
        }

        render(json) {
            this.lastData = json;
            const bc = this.root.querySelector('.mm-breadcrumbs');
            bc.innerHTML = '';
            const mk = (name, path) => {
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = name;
                a.className = 'text-blue-600 dark:text-blue-400 hover:underline';
                a.onclick = e => { e.preventDefault(); this.load(path); };
                return a;
            };
            bc.appendChild(mk('media', ''));
            (json.breadcrumbs || []).forEach(b => {
                bc.insertAdjacentText('beforeend', ' / ');
                bc.appendChild(mk(b.name, b.path));
            });
            this.renderItems();
        }

        // redraws the folder with the active search term, type filter and view (grid/list)
        renderItems() {
            const json = this.lastData;
            if (!json) return;
            const grid = this.root.querySelector('.media-grid');
            grid.innerHTML = '';
            grid.classList.toggle('media-grid-view', this.view === 'grid');
            grid.classList.toggle('media-list-view', this.view === 'list');

            const dirs = json.dirs.filter(d => !this.search || d.name.toLowerCase().includes(this.search));
            const files = json.files.filter(f => this.matchesFilter(f));

            // order on screen: folders first, then files — needed for shift-select
            this.visible = dirs.map(d => ({ path: 'media/' + d.path, type: 'dir' }))
                .concat(files.map(f => ({ path: f.path, type: 'file' })));
            if (this.selection) this.selection.prune(this.visible);

            dirs.forEach(d => {
                const item = this.view === 'grid'
                    ? this.makeItem(`<div class="thumb"><i class="bi bi-folder-fill text-amber-400"></i></div><span class="m-label"></span>`, d.name)
                    : this.makeRow(`<i class="bi bi-folder-fill shrink-0 text-lg text-amber-400"></i><span class="m-label min-w-0 flex-1 truncate"></span><span class="w-40"></span>`, d.name);
                item.querySelector('.m-label').textContent = d.name;
                item.addEventListener('click', () => this.load(d.path));
                this.addActions(item, { path: 'media/' + d.path, type: 'dir' });
                if (this.bulkMode()) this.addCheckbox(item, { path: 'media/' + d.path, type: 'dir' });
                grid.appendChild(item);
            });

            files.forEach(f => {
                const meta = MediaBrowser.fileMeta(f.name);
                let item;
                if (this.view === 'grid') {
                    const thumb = f.is_image
                        ? `<div class="thumb"><img src="${f.url}" loading="lazy" alt=""></div>`
                        : `<div class="thumb"><i class="bi ${meta.icon}"></i></div>`;
                    item = this.makeItem(thumb + '<span class="m-label"></span>', f.name);
                } else {
                    const thumb = f.is_image
                        ? `<img src="${f.url}" loading="lazy" class="h-8 w-8 shrink-0 rounded object-cover" alt="">`
                        : `<i class="bi ${meta.icon} shrink-0 text-lg"></i>`;
                    // alt column: italic as long as it comes automatically from the file name
                    const altCell = f.is_image
                        ? `<span class="m-alt hidden w-56 shrink-0 truncate text-xs ${f.alt_custom ? 'text-gray-500 dark:text-gray-400' : 'italic text-gray-400'} lg:block"></span>`
                        : `<span class="hidden w-56 shrink-0 lg:block"></span>`;
                    item = this.makeRow(`${thumb}<span class="m-label min-w-0 flex-1 truncate"></span>${altCell}
                        <span class="w-40 shrink-0 text-xs text-gray-400">${MediaBrowser.fmtSize(f.size)} · ${f.modified || ''}</span>`, f.name);
                }
                item.querySelector('.m-label').textContent = f.name;
                const altCell = item.querySelector('.m-alt');
                if (altCell) {
                    altCell.textContent = f.alt || '';
                    altCell.title = (window.I18N.alt_text || 'Alt') + ': ' + (f.alt || '');
                }
                item.dataset.url = f.url;
                item.dataset.path = f.path;
                item.dataset.alt = f.alt || '';
                item.addEventListener('click', () => {
                    if (this.pickCb) {
                        if (!this.multiple) grid.querySelectorAll('.selected').forEach(i => i.classList.remove('selected'));
                        item.classList.toggle('selected');
                    } else {
                        this.preview(f);
                    }
                });
                item.addEventListener('dblclick', () => {
                    if (this.pickCb) { item.classList.add('selected'); this.confirmPick(); }
                    else window.open(f.url, '_blank');
                });
                this.addActions(item, { path: f.path, type: 'file', image: f.is_image, file: f });
                if (this.bulkMode()) this.addCheckbox(item, { path: f.path, type: 'file' });
                grid.appendChild(item);
            });

            this.root.querySelector('.mm-empty').classList.toggle('hidden', dirs.length + files.length > 0);
            this.updateBulkBar();
        }

        makeItem(html, title) {
            const div = document.createElement('div');
            div.className = 'media-item';
            div.title = title;
            div.innerHTML = html;
            return div;
        }

        makeRow(html, title) {
            const div = document.createElement('div');
            div.className = 'media-row';
            div.title = title;
            div.innerHTML = html;
            return div;
        }

        addActions(item, info) {
            const actions = document.createElement('div');
            actions.className = 'item-actions';
            actions.innerHTML = `${info.file ? '<span class="icon-btn act-preview" title="' + (window.I18N.preview || 'Preview') + '"><i class="bi bi-eye"></i></span>' : ''}
                ${info.image ? '<span class="icon-btn act-edit" title="' + (window.I18N.edit_image || 'Edit') + '"><i class="bi bi-magic"></i></span>' : ''}
                <span class="icon-btn act-rename"><i class="bi bi-pencil"></i></span>
                <span class="icon-btn act-delete"><i class="bi bi-trash"></i></span>`;
            if (info.file) {
                actions.querySelector('.act-preview').onclick = (e) => {
                    e.stopPropagation();
                    this.preview(info.file);
                };
            }
            if (info.image) {
                actions.querySelector('.act-edit').onclick = (e) => {
                    e.stopPropagation();
                    ImageEditor.open(info.path, () => this.load(this.path), info.file?.url);
                };
            }
            actions.querySelector('.act-delete').onclick = async (e) => {
                e.stopPropagation();
                if (!confirm(window.I18N.confirm_delete)) return;
                await api(window.ROUTES.mediaDelete, { data: info });
                this.load(this.path);
            };
            actions.querySelector('.act-rename').onclick = (e) => {
                e.stopPropagation();
                this.openDetails(info, item.title);
            };
            item.appendChild(actions);
        }

        async upload(files) {
            if (!files.length) return;
            const fd = new FormData();
            fd.append('path', this.path);
            [...files].forEach(f => fd.append('files[]', f));
            await api(window.ROUTES.mediaUpload, { form: fd });
            toast(window.I18N.saved);
            this.root.querySelector('.mm-upload input').value = '';
            this.load(this.path);
        }

        async newFolder() {
            const name = prompt(window.I18N.folder_name);
            if (!name) return;
            await api(window.ROUTES.mediaFolder, { data: { path: this.path, name } });
            this.load(this.path);
        }

        confirmPick() {
            // note: in grid view these are .media-item's, in list view .media-row's
            const sel = '.media-item.selected, .media-row.selected';
            const selected = [...this.root.querySelectorAll(sel)]
                .map(i => ({ url: i.dataset.url, path: i.dataset.path, alt: i.dataset.alt || '' }))
                .filter(i => i.url);
            if (!selected.length) return;
            const cb = this.pickCb;
            closeModal(this.root);
            if (cb) cb(this.multiple ? selected : selected[0]);
            this.root.querySelectorAll(sel).forEach(i => i.classList.remove('selected'));
        }
    }
    window.MediaBrowser = MediaBrowser;

    // modal picker (globally available throughout the admin)
    let mediaModal = null;
    window.pickMedia = function (cb, { multiple = false } = {}) {
        if (!mediaModal) return;
        mediaModal.pickCb = cb;
        mediaModal.multiple = multiple;
        mediaModal.root.querySelector('.mm-select-btn')?.classList.remove('hidden');
        openModal('media-modal');
        mediaModal.load(mediaModal.path);
    };
    window.openMediaModal = function () {
        if (!mediaModal) return;
        mediaModal.pickCb = null;
        mediaModal.root.querySelector('.mm-select-btn')?.classList.add('hidden');
        openModal('media-modal');
        mediaModal.load(mediaModal.path);
    };

    // ---------- photo editor (crop, aspect ratios, effects, flipping) ----------
    const ImageEditor = {
        cropper: null,
        path: null,
        cb: null,
        scaleX: 1,
        scaleY: 1,
        size: null,          // output size, see image-resize.js

        modal() { return document.getElementById('image-editor-modal'); },

        // url: for an accommodation photo, whose path is virtual and does not point to a file
        open(path, cb = null, url = null) {
            const modal = this.modal();
            if (!modal || !window.Cropper) return;
            this.path = path.replace(/^\/?/, '');
            this.cb = cb;
            this.scaleX = 1;
            this.scaleY = 1;
            this.size = null;
            this.resetFilters();
            openModal(modal);

            const img = modal.querySelector('#ie-image');
            if (this.cropper) { this.cropper.destroy(); this.cropper = null; }
            img.onload = () => {
                this.cropper = new Cropper(img, {
                    viewMode: 1,
                    autoCropArea: 1,
                    background: false,
                    responsive: true,
                    // also fires on open, so the size fields are correct right away
                    crop: () => this.onCrop(),
                });
            };
            img.src = (url || window.STORAGE_URL + '/' + this.path) + '?t=' + Date.now();
        },

        resetFilters() {
            this.modal().querySelectorAll('.ie-fx').forEach(s => { s.value = s.dataset.default; });
            this.applyPreview();
        },

        filterString() {
            const value = fx => this.modal().querySelector(`.ie-fx[data-fx="${fx}"]`).value;
            const parts = [];
            if (value('brightness') !== '100') parts.push(`brightness(${value('brightness')}%)`);
            if (value('contrast') !== '100') parts.push(`contrast(${value('contrast')}%)`);
            if (value('saturate') !== '100') parts.push(`saturate(${value('saturate')}%)`);
            if (value('grayscale') !== '0') parts.push(`grayscale(${value('grayscale')}%)`);
            if (value('sepia') !== '0') parts.push(`sepia(${value('sepia')}%)`);
            if (value('blur') !== '0') parts.push(`blur(${value('blur')}px)`);
            return parts.join(' ');
        },

        applyPreview() {
            this.modal().querySelector('.ie-stage').style.setProperty('--fx', this.filterString() || 'none');
        },

        setAspect(value) {
            if (!this.cropper) return;
            this.cropper.setAspectRatio(value === 'free' ? NaN : parseFloat(value));
        },

        action(name) {
            if (!this.cropper) return;
            if (name === 'rotate-left') this.cropper.rotate(-90);
            if (name === 'rotate-right') this.cropper.rotate(90);
            if (name === 'flip-h') { this.scaleX *= -1; this.cropper.scaleX(this.scaleX); }
            if (name === 'flip-v') { this.scaleY *= -1; this.cropper.scaleY(this.scaleY); }
            if (name === 'reset') {
                this.cropper.reset();
                this.scaleX = 1;
                this.scaleY = 1;
                this.resetFilters();
                const crop = this.cropSize();
                if (crop && window.ImageResize) {
                    this.size = window.ImageResize.start(crop.width, crop.height);
                    this.showSize(true);
                }
            }
        },

        // ---------- size ----------
        sizeFields() {
            const m = this.modal();
            return {
                width: m.querySelector('#ie-width'),
                height: m.querySelector('#ie-height'),
                lock: m.querySelector('#ie-lock'),
                hint: m.querySelector('#ie-size-hint'),
            };
        },

        /** The crop in real pixels of the source photo. */
        cropSize() {
            if (!this.cropper) return null;
            const data = this.cropper.getData(true);
            return data && data.width > 0 && data.height > 0
                ? { width: data.width, height: data.height }
                : null;
        },

        /**
         * Puts the calculated size in the fields. A field the user is currently
         * typing in is left alone (force = update that field too, after blur).
         */
        showSize(force) {
            const f = this.sizeFields();
            if (!f.width || !this.size) return;
            if (force || document.activeElement !== f.width) f.width.value = this.size.width || '';
            if (force || document.activeElement !== f.height) f.height.value = this.size.height || '';

            const locked = !!this.size.locked;
            f.lock.setAttribute('aria-pressed', locked ? 'true' : 'false');
            f.lock.querySelector('i').className = locked ? 'bi bi-lock-fill' : 'bi bi-unlock';
            f.lock.classList.toggle('!bg-blue-600', locked);
            f.lock.classList.toggle('!text-white', locked);

            const crop = this.cropSize();
            if (f.hint && crop) {
                f.hint.textContent = (window.I18N.crop_size || '') + ' ' + crop.width + ' × ' + crop.height;
            }
        },

        changeSize(change) {
            if (!this.size || !window.ImageResize) return;
            this.size = window.ImageResize.apply(this.size, change);
            this.showSize(change.type === 'lock' || change.type === 'reset');
        },

        /** Crop changed (also on open, rotate and choosing an aspect ratio). */
        onCrop() {
            const crop = this.cropSize();
            if (!crop || !window.ImageResize) return;
            if (!this.size) {
                this.size = window.ImageResize.start(crop.width, crop.height);
                this.showSize(true);
                return;
            }
            this.changeSize({ type: 'crop', cropWidth: crop.width, cropHeight: crop.height });
        },

        async save() {
            if (!this.cropper) return;
            const btn = this.modal().querySelector('#ie-save');
            btn.disabled = true;
            try {
                const cropped = this.cropper.getCroppedCanvas({
                    maxWidth: 4096,
                    maxHeight: 4096,
                    imageSmoothingQuality: 'high',
                });
                const fx = this.filterString();
                const target = this.size && this.size.width && this.size.height
                    ? this.size
                    : { width: cropped.width, height: cropped.height };

                // Draw to the final size ourselves. Cropper's own width/height always keep
                // the crop's aspect ratio, so with the lock off the second
                // size would be ignored. Effects are applied in the same draw pass.
                let out = cropped;
                if (fx || target.width !== cropped.width || target.height !== cropped.height) {
                    out = document.createElement('canvas');
                    out.width = target.width;
                    out.height = target.height;
                    const ctx = out.getContext('2d');
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                    if (fx) ctx.filter = fx;
                    ctx.drawImage(cropped, 0, 0, out.width, out.height);
                }
                const ext = this.path.split('.').pop().toLowerCase();
                const mime = { jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp' }[ext] || 'image/png';
                const blob = await new Promise(resolve => out.toBlob(resolve, mime, 0.9));
                const fd = new FormData();
                fd.append('path', this.path);
                fd.append('overwrite', this.modal().querySelector('#ie-overwrite').checked ? '1' : '0');
                fd.append('file', blob, this.path.split('/').pop());
                const json = await api(window.ROUTES.mediaEdit, { form: fd });
                toast(window.I18N.saved);
                closeModal(this.modal());
                if (this.cb) this.cb(json);
            } catch (e) { /* toast already shown */ }
            btn.disabled = false;
        },

        init() {
            const modal = this.modal();
            if (!modal) return;
            modal.querySelectorAll('.ie-aspect').forEach(b => b.addEventListener('click', () => this.setAspect(b.dataset.aspect)));
            modal.querySelectorAll('[data-ie]').forEach(b => b.addEventListener('click', () => this.action(b.dataset.ie)));
            modal.querySelectorAll('.ie-fx').forEach(s => s.addEventListener('input', () => this.applyPreview()));

            ['width', 'height'].forEach(axis => {
                const field = modal.querySelector('#ie-' + axis);
                if (!field) return;
                field.addEventListener('input', () => this.changeSize({ type: axis, value: field.value }));
                // after blur/enter also set the field itself neatly (rounded, within the limits)
                field.addEventListener('change', () => {
                    this.changeSize({ type: axis, value: field.value });
                    this.showSize(true);
                });
            });

            const lock = modal.querySelector('#ie-lock');
            if (lock) {
                lock.addEventListener('click', () => this.changeSize({
                    type: 'lock',
                    value: !(this.size && this.size.locked),
                }));
            }

            modal.querySelector('#ie-save').addEventListener('click', () => this.save());
        },
    };
    window.ImageEditor = ImageEditor;

    document.addEventListener('DOMContentLoaded', function () {
        const modalRoot = document.getElementById('media-modal');
        if (modalRoot) mediaModal = new MediaBrowser(modalRoot);
        ImageEditor.init();
    });
})();
