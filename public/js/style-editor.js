/**
 * Element picker of the visual style editor (ported from the devsite project).
 *
 * Runs on the SITE, only when the page is opened with ?style-editor=1 by a
 * logged-in admin (see InjectStyleEditor). The page sits in an iframe in the admin;
 * communication goes via postMessage:
 *
 *   to the admin:   {type:'sw-picked', selector, tag, label, computed, canMove,
 *                    text, path, menuKind, menuHref}              everything in one message
 *                   {type:'sw-ready', url, fonts}
 *                   {type:'sw-order', rules, warning}             after a move
 *   to this script: {type:'sw-preview', selector, declarations}   live preview
 *                   {type:'sw-css', css}                          all saved rules
 *                   {type:'sw-move', selector, dir}               up (-1) / down (1)
 */
(function () {
    'use strict';
    if (window.__styleEditorLoaded) return;
    window.__styleEditorLoaded = true;

    // One mode: clicking picks an element and the panel shows everything (styling, text).
    // You navigate by double-clicking a link.
    var picking = true;
    var hovered = null;
    var selectedEl = null;   // stays highlighted until you pick something else
    var editingEl = null;    // element that is contenteditable at the moment
    var toolsEl = null;      // element the action bar is attached to (hover or selection)
    var editingOriginal = '';

    // ---- own styles (overlay + live preview) ----
    var overlay = document.createElement('div');
    overlay.id = 'sw-overlay';
    var badge = document.createElement('div');
    badge.id = 'sw-badge';
    var selBox = document.createElement('div');
    selBox.id = 'sw-selected';
    var selBadge = document.createElement('div');
    selBadge.id = 'sw-selected-badge';
    // floating action bar at the top right of the picked element; the buttons report their click
    // to the admin (sw-action), where the existing logic performs the action.
    // Inline SVGs: the layout does not necessarily have an icon font loaded.
    var tools = document.createElement('div');
    tools.id = 'sw-tools';
    [
        ['up', '<svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L7.5 2.707V14.5a.5.5 0 0 0 .5.5"/></svg>'],
        ['down', '<svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 1a.5.5 0 0 1 .5.5v11.793l3.146-3.147a.5.5 0 0 1 .708.708l-4 4a.5.5 0 0 1-.708 0l-4-4a.5.5 0 0 1 .708-.708L7.5 13.293V1.5A.5.5 0 0 1 8 1"/></svg>'],
        ['reset', '<svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 3a5 5 0 1 1-4.546 2.914.5.5 0 0 0-.908-.417A6 6 0 1 0 8 2z"/><path d="M8 4.466V.534a.25.25 0 0 0-.41-.192L5.23 2.308a.25.25 0 0 0 0 .384l2.36 1.966A.25.25 0 0 0 8 4.466"/></svg>'],
        ['delete', '<svg viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/></svg>'],
    ].forEach(function (def) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('data-sw-act', def[0]);
        b.innerHTML = def[1];
        b.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (toolsEl && toolsEl !== selectedEl) pick(toolsEl);
            send({ type: 'sw-action', action: def[0] });
        });
        tools.appendChild(b);
    });

    var previewStyle = document.createElement('style');
    previewStyle.id = 'sw-preview-style';
    var savedStyle = document.createElement('style');
    savedStyle.id = 'sw-saved-style';

    var own = document.createElement('style');
    own.textContent =
        '#sw-overlay{position:absolute;z-index:2147483646;pointer-events:none;border:2px solid #2563eb;' +
        'background:rgba(37,99,235,.12);border-radius:3px;display:none;transition:all .06s ease-out}' +
        '#sw-badge{position:absolute;z-index:2147483647;pointer-events:none;display:none;' +
        'background:#2563eb;color:#fff;font:600 11px/1.4 ui-sans-serif,system-ui,sans-serif;' +
        'padding:2px 7px;border-radius:4px;white-space:nowrap;transform:translateY(-100%)}' +
        // the PICKED element keeps its orange outline, even when the mouse moves away
        '#sw-selected{position:absolute;z-index:2147483644;pointer-events:none;border:2px solid #f59e0b;' +
        'background:rgba(245,158,11,.10);border-radius:3px;display:none}' +
        '#sw-selected-badge{position:absolute;z-index:2147483645;pointer-events:none;display:none;' +
        'background:#f59e0b;color:#3a2a06;font:600 11px/1.4 ui-sans-serif,system-ui,sans-serif;' +
        'padding:2px 7px;border-radius:4px;white-space:nowrap}' +
        'html.sw-picking *{cursor:crosshair !important}' +
        // menu marking: blue = from the CMS (Pages), orange = hardcoded in the layout
        '[data-sw-menu="cms"]{outline:2px solid rgba(37,99,235,.75) !important;outline-offset:2px;border-radius:3px}' +
        '[data-sw-menu="hard"]{outline:2px solid rgba(245,158,11,.85) !important;outline-offset:2px;border-radius:3px}' +
        '.sw-menu-add{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;' +
        'margin-left:8px;border:0;border-radius:50%;background:#2563eb;color:#fff;font:700 15px/1 ui-sans-serif,system-ui;' +
        'cursor:pointer;vertical-align:middle;box-shadow:0 1px 4px rgba(0,0,0,.25)}' +
        '.sw-menu-add:hover{background:#1d4ed8}' +
        // the element you are editing gets a green border and a text cursor
        '[data-sw-editing]{outline:2px solid #16a34a !important;outline-offset:2px;' +
        'background:rgba(22,163,74,.06) !important;cursor:text !important}' +
        // the action bar on the picked element
        '#sw-tools{position:absolute;z-index:2147483647;display:none;gap:2px;padding:3px;' +
        'border-radius:8px;background:#111827;box-shadow:0 4px 14px rgba(0,0,0,.35)}' +
        '#sw-tools button{display:flex;align-items:center;justify-content:center;width:26px;height:26px;' +
        'border:0;border-radius:6px;background:transparent;color:#e5e7eb;cursor:pointer;padding:0;margin:0}' +
        '#sw-tools button:hover{background:#374151;color:#fff}' +
        '#sw-tools button[data-sw-act="delete"]:hover{background:#dc2626}' +
        '#sw-tools svg{width:14px;height:14px;fill:currentColor;pointer-events:none}';

    document.addEventListener('DOMContentLoaded', mount);
    if (document.readyState !== 'loading') mount();

    function mount() {
        if (overlay.isConnected) return;
        document.head.appendChild(own);
        document.head.appendChild(savedStyle);
        document.head.appendChild(previewStyle);
        document.body.appendChild(selBox);
        document.body.appendChild(selBadge);
        document.body.appendChild(overlay);
        document.body.appendChild(badge);
        document.body.appendChild(tools);
        document.documentElement.classList.add('sw-picking');
        classifyMenus();
        send({ type: 'sw-ready', url: location.pathname, fonts: themeFonts() });
        // webfonts load asynchronously; once they are in, send the final list
        try {
            document.fonts.ready.then(function () {
                send({ type: 'sw-fonts', fonts: themeFonts() });
            });
        } catch (err) { /* no FontFaceSet */ }
    }

    /**
     * The fonts this layout really uses: the @font-face fonts the page has
     * loaded, plus the families body and headings are actually styled with.
     * Generic names (sans-serif, system-ui) do not belong there.
     */
    function themeFonts() {
        var set = {};
        var generic = /^(-apple-system|blinkmacsystemfont|system-ui|ui-[a-z-]+|sans-serif|serif|monospace|cursive|fantasy|math|emoji)$/i;
        var icons = /icon/i;
        try {
            document.fonts.forEach(function (f) {
                var name = String(f.family).replace(/^['"]|['"]$/g, '').trim();
                if (name && !generic.test(name) && !icons.test(name)) set[name] = true;
            });
        } catch (err) { /* FontFaceSet not available */ }
        ['body', 'h1', 'h2', 'h3', 'p', 'a'].forEach(function (tag) {
            var el = document.querySelector(tag);
            if (!el) return;
            (getComputedStyle(el).fontFamily || '').split(',').forEach(function (name) {
                name = name.trim().replace(/^['"]|['"]$/g, '');
                if (name && !generic.test(name) && !icons.test(name)) set[name] = true;
            });
        });
        return Object.keys(set).sort();
    }

    // ---- recognise menu items: CMS (blue) vs hardcoded in the layout (orange) ----
    function normalizePath(href) {
        try {
            var u = new URL(href, location.origin);
            if (u.origin !== location.origin) return null;   // external link
            return (u.pathname.replace(/\/+$/, '') || '/');
        } catch (err) {
            return null;
        }
    }

    function classifyMenus() {
        var menu = window.__swMenu || [];
        var byPath = {};
        menu.forEach(function (m) {
            var p = normalizePath(m.url);
            if (p) byPath[p] = m;
        });

        document.querySelectorAll('nav').forEach(function (nav) {
            var links = nav.querySelectorAll('a');
            // the site menu also gets the + button when it is still empty (data-sw-nav)
            if (!links.length && nav.getAttribute('data-sw-nav') !== 'menu') return;
            var hasCms = false;
            links.forEach(function (a) {
                var p = normalizePath(a.getAttribute('href') || '');
                var item = p !== null ? byPath[p] : null;
                if (item) {
                    a.setAttribute('data-sw-menu', 'cms');
                    a.setAttribute('data-sw-page', String(item.id));
                    hasCms = true;
                } else {
                    a.setAttribute('data-sw-menu', 'hard');
                }
            });
            // + after the last CMS item: new menu item = new page.
            // In the (still empty) site menu the + simply goes at the end.
            if ((hasCms || nav.getAttribute('data-sw-nav') === 'menu') && !nav.querySelector('.sw-menu-add')) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sw-menu-add';
                btn.textContent = '+';
                btn.title = '+';
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    send({ type: 'sw-menu-add' });
                });
                var cmsLinks = nav.querySelectorAll('a[data-sw-menu="cms"]');
                var last = cmsLinks[cmsLinks.length - 1];
                if (last) last.insertAdjacentElement('afterend', btn);
                else nav.appendChild(btn);
            }
        });
    }

    function send(msg) {
        if (window.parent !== window) window.parent.postMessage(msg, location.origin);
    }

    // ---- determine selector: short, readable and stable ----
    function isOwn(el) {
        return el === overlay || el === badge || el === selBox || el === selBadge || el === tools
            || (el.closest && el.closest('#sw-tools'))
            || /^sw-(overlay|badge|selected|selected-badge|tools)$/.test(el.id || '')
            || (el.classList && el.classList.contains('sw-menu-add'));
    }

    // we leave out classes that can differ per visit/state
    function usableClasses(el) {
        return Array.prototype.filter.call(el.classList, function (c) {
            return c && !/^(is-|has-|active|open|selected|hover|sw-)/.test(c) && !/\d{4,}/.test(c)
                // Tailwind variants with a colon cannot go in a selector
                && c.indexOf(':') === -1 && !/[\[\]\/]/.test(c);
        });
    }

    function selectorFor(el) {
        if (!el || el === document.body) return 'body';
        if (el.id && /^[a-zA-Z][\w-]*$/.test(el.id)) return '#' + el.id;

        // first try a readable candidate; if it is not unique, we refine
        // it below with the exact path — a selector ALWAYS matches exactly one element
        var cls = usableClasses(el);
        if (cls.length) {
            var sel = el.tagName.toLowerCase() + '.' + cls.slice(0, 2).join('.');
            if (matchesOnly(sel, el)) return sel;
            var parent = el.parentElement;
            if (parent && parent !== document.body) {
                var pcls = usableClasses(parent);
                var psel = pcls.length ? parent.tagName.toLowerCase() + '.' + pcls[0] : parent.tagName.toLowerCase();
                var combo = psel + ' > ' + sel;
                if (matchesOnly(combo, el)) return combo;
            }
        }

        return uniquePath(el);
    }

    function matchesOnly(sel, el) {
        try {
            var list = document.querySelectorAll(sel);
            return list.length === 1 && list[0] === el;
        } catch (err) {
            return false;
        }
    }

    // Exact path with :nth-child from the nearest unique anchor (id or body).
    // :nth-child counts ALL siblings, so this is guaranteed to point at one element.
    function uniquePath(el) {
        var parts = [], node = el;
        while (node && node !== document.body) {
            var parent = node.parentElement;
            if (!parent) break;
            if (node.id && /^[a-zA-Z][\w-]*$/.test(node.id)) {
                parts.unshift('#' + node.id);
                return parts.join(' > ');
            }
            var idx = Array.prototype.indexOf.call(parent.children, node) + 1;
            var tag = node.tagName.toLowerCase();
            var cls = usableClasses(node);
            parts.unshift((cls.length ? tag + '.' + cls[0] : tag) + ':nth-child(' + idx + ')');
            node = parent;
        }
        parts.unshift('body');
        return parts.join(' > ');
    }

    function label(el) {
        var t = (el.textContent || '').trim().replace(/\s+/g, ' ');
        return t.length > 40 ? t.slice(0, 40) + '…' : t;
    }

    function place(el) {
        var r = el.getBoundingClientRect();
        var top = r.top + window.scrollY, left = r.left + window.scrollX;
        overlay.style.display = 'block';
        overlay.style.top = top + 'px';
        overlay.style.left = left + 'px';
        overlay.style.width = r.width + 'px';
        overlay.style.height = r.height + 'px';
        badge.style.display = 'block';
        badge.style.top = (top - 4) + 'px';
        badge.style.left = left + 'px';
        badge.textContent = selectorFor(el);
    }

    function hide() {
        overlay.style.display = 'none';
        badge.style.display = 'none';
    }

    // ---- the picked element stays highlighted ----
    function placeSelected() {
        if (!selectedEl || !selectedEl.isConnected) {
            selBox.style.display = 'none';
            selBadge.style.display = 'none';
            placeTools();
            return;
        }
        var r = selectedEl.getBoundingClientRect();
        var top = r.top + window.scrollY, left = r.left + window.scrollX;
        selBox.style.display = 'block';
        selBox.style.top = top + 'px';
        selBox.style.left = left + 'px';
        selBox.style.width = r.width + 'px';
        selBox.style.height = r.height + 'px';
        // label below the top left corner, so it does not overlap the hover badge above
        selBadge.style.display = 'block';
        selBadge.style.top = (top + 2) + 'px';
        selBadge.style.left = (left + 2) + 'px';
        selBadge.textContent = selectorFor(selectedEl);
        placeTools();
    }

    // action bar at the top right of the element it is attached to (hovered or picked);
    // arrows only if that element has siblings
    function placeTools() {
        if (!toolsEl || !toolsEl.isConnected) {
            tools.style.display = 'none';
            return;
        }
        var r = toolsEl.getBoundingClientRect();
        var top = r.top + window.scrollY, left = r.left + window.scrollX;
        var arrow = siblingsOf(toolsEl).length > 1 ? '' : 'none';
        tools.querySelector('[data-sw-act="up"]').style.display = arrow;
        tools.querySelector('[data-sw-act="down"]').style.display = arrow;
        tools.style.display = 'flex';
        var tw = tools.offsetWidth || 140;
        // at the top of the page it does not fit above; then just inside the element
        tools.style.top = (r.top < 42 ? top + 4 : top - 34) + 'px';
        tools.style.left = Math.max(left, left + r.width - tw) + 'px';
    }

    // After a style change the geometry changes. requestAnimationFrame is the clean way,
    // but it does not fire when the page is not rendering (background tab, hidden panel).
    // So always also a short timer, so the highlight catches up regardless.
    function refreshSelected() {
        requestAnimationFrame(placeSelected);
        setTimeout(placeSelected, 60);
        setTimeout(placeSelected, 250);
    }

    var sizeWatcher = null;
    function markSelected(el) {
        selectedEl = el || null;
        toolsEl = selectedEl;
        if (sizeWatcher) { sizeWatcher.disconnect(); sizeWatcher = null; }
        if (selectedEl && window.ResizeObserver) {
            // follow along when the element changes size due to a style change
            sizeWatcher = new ResizeObserver(function () { placeSelected(); });
            sizeWatcher.observe(selectedEl);
        }
        placeSelected();
    }

    window.addEventListener('resize', placeSelected);
    window.addEventListener('scroll', placeSelected, true);

    document.addEventListener('mouseover', function (e) {
        if (!picking || isOwn(e.target)) return;
        hovered = e.target;
        place(hovered);
        toolsEl = hovered;
        placeTools();
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (!picking) return;
        hide();
        // mouse leaves the window: bar back to the picked element (or gone)
        if (!e.relatedTarget) {
            toolsEl = selectedEl;
            placeTools();
        }
    }, true);

    // clicking picks the element instead of following the link
    document.addEventListener('click', function (e) {
        if (!picking || isOwn(e.target)) return;
        // in the element you are editing you can simply place the cursor —
        // but if you are editing a LINK, that click must not navigate after all
        if (editingEl && editingEl.contains(e.target)) {
            if (e.target.closest && e.target.closest('a')) e.preventDefault();
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        pick(e.target);
    }, true);

    // double-click on a link = simply follow the link
    document.addEventListener('dblclick', function (e) {
        if (isOwn(e.target)) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a) return;
        e.preventDefault();
        e.stopPropagation();
        stopEditing(true);
        location.href = a.href;
    }, true);

    /**
     * One click yields everything: styling (always) and text (if the element carries text,
     * inline editing starts too). The panel in the admin then shows
     * only the sections that apply.
     */
    function pick(el) {
        stopEditing(true);
        var textTarget = el.tagName === 'IMG' ? null : textElement(el);
        var target = textTarget || el;

        markSelected(target);
        if (textTarget) startEditing(textTarget);

        var cs = getComputedStyle(target);
        var menuLink = target.closest ? target.closest('a[data-sw-menu]') : null;
        send({
            type: 'sw-picked',
            selector: selectorFor(target),
            tag: target.tagName.toLowerCase(),
            label: label(target),
            canMove: siblingsOf(target).length > 1,
            computed: {
                'color': cs.color,
                'background-color': cs.backgroundColor,
                'font-size': cs.fontSize,
                'font-weight': cs.fontWeight,
                'text-align': cs.textAlign,
                'padding': cs.padding,
                'margin': cs.margin,
                'border-radius': cs.borderRadius,
                'max-width': cs.maxWidth,
                'border-color': cs.borderTopColor,
            },
            text: textTarget ? editingOriginal : null,
            path: location.pathname,
            menuKind: menuLink ? menuLink.getAttribute('data-sw-menu') : null,
            menuHref: menuLink ? (menuLink.getAttribute('href') || '') : null,
        });
    }

    // picking the parent with the up arrow is handy for nested elements
    document.addEventListener('keydown', function (e) {
        if (!picking || e.key !== 'ArrowUp' || !hovered || !hovered.parentElement) return;
        if (editingEl) return;
        e.preventDefault();
        hovered = hovered.parentElement;
        place(hovered);
        toolsEl = hovered;
        placeTools();
    });

    // ---- change order without touching the HTML ----
    // We move nothing in the DOM (that would invalidate selectors with :nth-child).
    // Instead the parent gets flex/grid and each child an `order` value. Pure CSS,
    // so it also works on the real site and can be undone with one click.
    function siblingsOf(el) {
        if (!el || !el.parentElement || el === document.body) return [];
        return Array.prototype.filter.call(el.parentElement.children, function (c) {
            if (isOwn(c)) return false;
            var t = c.tagName;
            if (t === 'SCRIPT' || t === 'STYLE' || t === 'LINK' || t === 'TEMPLATE') return false;
            return true;
        });
    }

    function currentOrder(el) {
        var v = parseInt(getComputedStyle(el).order, 10);
        return isNaN(v) ? 0 : v;
    }

    // For reordering we need a UNIQUE selector per sibling; classes can
    // hit multiple siblings, so here we use the position: "parent > :nth-child(n)".
    function childSelector(parentSel, parent, kid) {
        var pos = Array.prototype.indexOf.call(parent.children, kid) + 1;
        return parentSel + ' > :nth-child(' + pos + ')';
    }

    function move(selector, dir) {
        var el;
        try { el = document.querySelector(selector); } catch (err) { el = null; }
        if (!el) return;

        var parent = el.parentElement;
        var kids = siblingsOf(el);
        if (kids.length < 2) return;

        // current visual order = by order, within that by DOM position
        var sorted = kids.slice().sort(function (a, b) {
            var d = currentOrder(a) - currentOrder(b);
            return d !== 0 ? d : kids.indexOf(a) - kids.indexOf(b);
        });

        var i = sorted.indexOf(el);
        var j = i + dir;
        if (j < 0 || j >= sorted.length) return;
        sorted.splice(j, 0, sorted.splice(i, 1)[0]);

        var rules = [];
        var parentSel = parent === document.body ? 'body' : selectorFor(parent);
        var display = getComputedStyle(parent).display;
        var isFlexOrGrid = /(^|\s)(flex|grid|inline-flex|inline-grid)$/.test(display);

        // a normal block parent must become flex, otherwise `order` does nothing
        if (!isFlexOrGrid) {
            rules.push({
                selector: parentSel,
                declarations: { 'display': 'flex', 'flex-direction': 'column' },
            });
        }
        sorted.forEach(function (kid, index) {
            rules.push({
                selector: childSelector(parentSel, parent, kid),
                declarations: { 'order': String(index) },
            });
        });

        send({ type: 'sw-order', rules: rules, warning: !isFlexOrGrid });
    }

    // ---- edit text inline (live preview) ----
    // Only elements that contain text themselves (no wrappers): otherwise you would accidentally
    // turn a whole section into one editable block.
    function textElement(el) {
        if (!el || el === document.body) return null;
        var direct = '';
        Array.prototype.forEach.call(el.childNodes, function (n) {
            if (n.nodeType === 3) direct += n.textContent;
        });
        if (direct.trim() !== '') return el;
        // click on a <span>/<em> inside: take the nearest text carrier above it
        var p = el.parentElement;
        return p && p !== document.body ? textElement(p) : null;
    }

    function stopEditing(silent) {
        if (!editingEl) return;
        editingEl.removeAttribute('contenteditable');
        editingEl.removeAttribute('data-sw-editing');
        var el = editingEl;
        editingEl = null;
        if (!silent) send({ type: 'sw-text-blur', text: (el.innerText || '').trim() });
    }

    function startEditing(el) {
        stopEditing(true);
        editingEl = el;
        editingOriginal = (el.innerText || '').trim();
        el.setAttribute('contenteditable', 'plaintext-only');
        el.setAttribute('data-sw-editing', '1');
        el.focus();
    }

    // pass on typing so the panel follows along
    document.addEventListener('input', function (e) {
        if (editingEl && e.target === editingEl) {
            send({ type: 'sw-text-input', text: (editingEl.innerText || '').trim() });
            refreshSelected();
        }
    }, true);

    // Esc = undo the change
    document.addEventListener('keydown', function (e) {
        if (!editingEl || e.key !== 'Escape') return;
        e.preventDefault();
        editingEl.innerText = editingOriginal;
        send({ type: 'sw-text-input', text: editingOriginal });
        stopEditing();
    }, true);

    function declsToCss(selector, decls) {
        var body = '';
        Object.keys(decls || {}).forEach(function (p) {
            if (decls[p] !== '' && decls[p] != null) body += p + ':' + decls[p] + ' !important;';
        });
        return body ? selector + '{' + body + '}' : '';
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin || !e.data || typeof e.data !== 'object') return;
        var d = e.data;
        if (d.type === 'sw-preview') {
            previewStyle.textContent = declsToCss(d.selector, d.declarations);
            refreshSelected();   // move the highlight along
        } else if (d.type === 'sw-css') {
            savedStyle.textContent = d.css || '';
            previewStyle.textContent = '';
            refreshSelected();
        } else if (d.type === 'sw-select') {
            // picked from the panel (e.g. via the list of customised elements)
            var target = null;
            try { target = d.selector ? document.querySelector(d.selector) : null; } catch (err) { target = null; }
            markSelected(target);
            if (target) target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } else if (d.type === 'sw-pick') {
            // full reselection after a reload: as if the user clicks the element
            // again, so the panel fills up again
            var repick = null;
            try { repick = d.selector ? document.querySelector(d.selector) : null; } catch (err) { repick = null; }
            if (repick) {
                repick.scrollIntoView({ block: 'center' });
                pick(repick);
            }
        } else if (d.type === 'sw-clear-select') {
            markSelected(null);
        } else if (d.type === 'sw-labels') {
            Object.keys(d.labels || {}).forEach(function (k) {
                var b = tools.querySelector('[data-sw-act="' + k + '"]');
                if (b) b.title = d.labels[k];
            });
        } else if (d.type === 'sw-text-set') {
            // update text from the panel (typing in the text field)
            if (editingEl) { editingEl.innerText = d.text || ''; refreshSelected(); }
        } else if (d.type === 'sw-text-revert') {
            if (editingEl) { editingEl.innerText = editingOriginal; refreshSelected(); }
        } else if (d.type === 'sw-move') {
            move(d.selector, d.dir);
        }
    });
})();
