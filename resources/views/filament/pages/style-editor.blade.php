<x-filament-panels::page>
    {{-- full-screen mode: admin bars gone and the preview as tall as the screen --}}
    <style>
        html.se-full .fi-sidebar, html.se-full .fi-topbar, html.se-full .fi-header { display: none !important; }
        html.se-full .fi-main { max-width: none !important; padding: 0.75rem 1rem !important; }
        html.se-full #se-frame { height: calc(100vh - 9rem) !important; transition: none; }
    </style>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-filament::button tag="a" href="{{ \App\Filament\Pages\Settings::getUrl() }}?tab=-lay-out-style-tab" color="gray" icon="heroicon-o-arrow-left">
            {{ __('Back') }}
        </x-filament::button>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Click an element to edit it. Arrow up = surrounding element, double-click a link = open that page.') }}</span>
        <span class="ml-auto flex gap-2">
            <x-filament::button color="gray" id="se-fullscreen" icon="heroicon-o-arrows-pointing-out" title="{{ __('Fullscreen') }}" />
            <x-filament::button color="danger" outlined id="se-clear-all">{{ __('Clear all') }}</x-filament::button>
            <x-filament::button id="se-save" icon="heroicon-o-check">{{ __('Save') }}</x-filament::button>
        </span>
    </div>

    {{-- site on the left, control panel on the right (stacked on narrow screens) --}}
    <div class="grid gap-4 lg:grid-cols-4">
        {{-- the site itself --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 lg:col-span-3">
            <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <select class="se-input !w-auto !py-1 text-xs" id="se-viewport" style="flex: none;">
                    <option value="100%">{{ __('Desktop') }}</option>
                    <option value="768px">{{ __('Tablet') }}</option>
                    <option value="390px">{{ __('Mobile') }}</option>
                </select>
                <span class="truncate text-xs text-gray-400" id="se-url"></span>
            </div>
            <div class="bg-gray-100 p-3 dark:bg-gray-950">
                <iframe id="se-frame" src="{{ $previewUrl }}" class="mx-auto block h-[70vh] w-full rounded-lg border-0 bg-white transition-all"></iframe>
            </div>
        </div>

        {{-- control panel: sticky with its own scrollbar, so the site stays in view --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900 lg:col-span-1"
             style="position: sticky; top: 1rem; align-self: flex-start; max-height: calc(100vh - 2rem); overflow-y: auto;">
            <div id="se-empty" class="py-10 text-center text-sm text-gray-400">
                <svg class="mx-auto block h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.042 21.672 13.684 16.6m0 0-2.51 2.225.569-9.47 5.227 7.917-3.286-.672ZM12 2.25V4.5m5.834.166-1.591 1.591M20.25 10.5H18M7.757 14.743l-1.59 1.59M6 10.5H3.75m4.007-4.243-1.59-1.59"/></svg>
                <p class="mt-2">{{ __('Click a text, button or block in the preview on the left to adjust it.') }}</p>
            </div>

            <div id="se-panel" class="hidden text-sm">
                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Element (selector)') }}</label>
                <input type="text" id="se-selector" class="se-input mb-1 font-mono !text-xs">
                <p class="mb-4 text-xs text-gray-400" id="se-target"></p>

                {{-- text: only visible when the element carries its own text --}}
                <details id="se-sec-text" class="se-acc hidden" open>
                    <summary>{{ __('Text') }} <span class="se-chev">⌄</span></summary>
                    <div class="se-acc-body">
                        <textarea id="se-text" class="se-input mb-2" rows="4"></textarea>
                        <div class="mb-2 flex gap-2">
                            <x-filament::button color="gray" size="sm" id="se-text-revert" class="flex-1 justify-center">
                                {{ __('Reset text') }}
                            </x-filament::button>
                        </div>
                        <p class="text-xs text-gray-400" id="se-text-hint">{{ __('This is a preview: texts on this site come from the admin (Accommodations, Pages, Settings) and are edited there permanently.') }}</p>

                        {{-- CMS menu item (blue): load the linked page in the editor --}}
                        <div class="mt-3 hidden rounded-lg border border-blue-300 bg-blue-50 p-3 dark:border-blue-500/40 dark:bg-blue-500/10" id="se-cms-menu">
                            <p class="mb-2 text-xs font-medium text-blue-800 dark:text-blue-300">{{ __('This menu item is a page from the CMS.') }}</p>
                            <x-filament::button color="gray" size="sm" id="se-open-page" class="w-full justify-center">
                                {{ __('Open this page in the editor') }}
                            </x-filament::button>
                        </div>

                        {{-- hardcoded menu item (orange): hide it or create it as a page after all --}}
                        <div class="mt-3 hidden rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-500/40 dark:bg-amber-500/10" id="se-hard-menu">
                            <p class="mb-2 text-xs font-medium text-amber-800 dark:text-amber-300">{{ __('This menu item is hardcoded in the layout.') }}</p>
                            <div class="flex gap-2">
                                <x-filament::button color="gray" size="sm" id="se-hard-add" class="flex-1 justify-center">
                                    {{ __('Add as page') }}
                                </x-filament::button>
                                <x-filament::button color="danger" outlined size="sm" id="se-hard-hide" class="flex-1 justify-center">
                                    {{ __('Hide') }}
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                </details>

                {{-- formatting: always --}}
                <details class="se-acc" open>
                    <summary>{{ __('Formatting') }} <span class="se-chev">⌄</span></summary>
                    <div class="se-acc-body">

                        <label class="se-row">
                            <span>{{ __('Font') }}</span>
                            <select class="se-input" data-prop="font-family" id="se-font-family">
                                <option value="">&mdash;</option>
                                <optgroup label="{{ __('From this site') }}" id="se-theme-fonts"></optgroup>
                                <optgroup label="{{ __('Default') }}">
                                    <option value="system-ui, sans-serif">{{ __('System') }}</option>
                                    <option value="Arial, Helvetica, sans-serif" style="font-family: Arial;">Arial</option>
                                    <option value="Verdana, Geneva, sans-serif" style="font-family: Verdana;">Verdana</option>
                                    <option value="Georgia, serif" style="font-family: Georgia;">Georgia</option>
                                    <option value="'Times New Roman', Times, serif" style="font-family: 'Times New Roman';">Times New Roman</option>
                                    <option value="'Courier New', monospace" style="font-family: 'Courier New';">Courier New</option>
                                </optgroup>
                            </select>
                        </label>

                        {{-- colours as in design tools: hex field + colour swatch, synchronised --}}
                        <label class="se-row">
                            <span>{{ __('Text colour') }}</span>
                            <input type="text" class="se-input se-hex" data-hex-for="color" placeholder="#000000" maxlength="7">
                            <input type="color" class="se-swatch" data-prop="color" title="{{ __('Text colour') }}">
                        </label>

                        <div class="se-row">
                            <span>{{ __('Font size') }}</span>
                            <input type="hidden" data-prop="font-size">
                            <input type="text" class="se-input se-dim" data-dim="font-size" placeholder="18" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="font-size">
                                <button type="button" data-val="px" class="active">px</button>
                                <button type="button" data-val="rem">rem</button>
                                <button type="button" data-val="%">%</button>
                            </div>
                        </div>

                        {{-- segment buttons; clicking again clears the choice --}}
                        <div class="se-row">
                            <span>{{ __('Font weight') }}</span>
                            <input type="hidden" data-prop="font-weight">
                            <div class="se-seg se-seg-sm flex-1" data-seg="font-weight">
                                <button type="button" data-val="400">400</button>
                                <button type="button" data-val="600">600</button>
                                <button type="button" data-val="700"><strong>700</strong></button>
                                <button type="button" data-val="800"><strong>800</strong></button>
                            </div>
                        </div>

                        {{-- italic / underline / strikethrough: separate on/off buttons --}}
                        <div class="se-row">
                            <span>{{ __('Font style') }}</span>
                            <input type="hidden" data-prop="font-style">
                            <div class="se-seg se-seg-sm" data-seg="font-style" style="flex: 1 1 0;">
                                <button type="button" data-val="italic"><em>I</em></button>
                            </div>
                            <input type="hidden" data-prop="text-decoration">
                            <div class="se-seg se-seg-sm" data-seg="text-decoration" style="flex: 2 1 0;">
                                <button type="button" data-val="underline"><span style="text-decoration: underline;">U</span></button>
                                <button type="button" data-val="line-through"><span style="text-decoration: line-through;">S</span></button>
                            </div>
                        </div>

                        <div class="se-row">
                            <span>{{ __('Alignment') }}</span>
                            <input type="hidden" data-prop="text-align">
                            <div class="se-seg se-seg-sm flex-1" data-seg="text-align">
                                <button type="button" data-val="left" title="{{ __('Left') }}">⯇</button>
                                <button type="button" data-val="center" title="{{ __('Centre') }}">☰</button>
                                <button type="button" data-val="right" title="{{ __('Right') }}">⯈</button>
                            </div>
                        </div>

                        <div class="se-row">
                            <span>{{ __('Capitalisation') }}</span>
                            <input type="hidden" data-prop="text-transform">
                            <div class="se-seg se-seg-sm flex-1" data-seg="text-transform">
                                <button type="button" data-val="uppercase" title="UPPERCASE">AA</button>
                                <button type="button" data-val="capitalize" title="Capitalize">Aa</button>
                                <button type="button" data-val="lowercase" title="lowercase">aa</button>
                            </div>
                        </div>

                        {{-- line height usually has no unit (1.5), hence the dash as default --}}
                        <div class="se-row">
                            <span>{{ __('Line height') }}</span>
                            <input type="hidden" data-prop="line-height">
                            <input type="text" class="se-input se-dim" data-dim="line-height" placeholder="1.5" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="line-height" data-default="">
                                <button type="button" data-val="" class="active">&ndash;</button>
                                <button type="button" data-val="px">px</button>
                                <button type="button" data-val="rem">rem</button>
                            </div>
                        </div>

                        <div class="se-row">
                            <span>{{ __('Letter spacing') }}</span>
                            <input type="hidden" data-prop="letter-spacing">
                            <input type="text" class="se-input se-dim" data-dim="letter-spacing" placeholder="0.5" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="letter-spacing">
                                <button type="button" data-val="px" class="active">px</button>
                                <button type="button" data-val="rem">rem</button>
                                <button type="button" data-val="em">em</button>
                            </div>
                        </div>

                    </div>
                </details>

                {{-- properties of the block: spacing, size, border and background --}}
                <details class="se-acc" open>
                    <summary>{{ __('Properties') }} <span class="se-chev">⌄</span></summary>
                    <div class="se-acc-body">
                        <div class="se-row">
                            <span>{{ __('Padding') }}</span>
                            <input type="hidden" data-prop="padding">
                            <input type="text" class="se-input se-dim" data-dim="padding" placeholder="16 32" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="padding">
                                <button type="button" data-val="px" class="active">px</button>
                                <button type="button" data-val="rem">rem</button>
                                <button type="button" data-val="%">%</button>
                            </div>
                        </div>
                        <div class="se-row">
                            <span>{{ __('Margin') }}</span>
                            <input type="hidden" data-prop="margin">
                            <input type="text" class="se-input se-dim" data-dim="margin" placeholder="0 0 32" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="margin">
                                <button type="button" data-val="px" class="active">px</button>
                                <button type="button" data-val="rem">rem</button>
                                <button type="button" data-val="%">%</button>
                            </div>
                        </div>
                        <div class="se-row">
                            <span>{{ __('Corner radius') }}</span>
                            <input type="hidden" data-prop="border-radius">
                            <input type="text" class="se-input se-dim" data-dim="border-radius" placeholder="12" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="border-radius">
                                <button type="button" data-val="px" class="active">px</button>
                                <button type="button" data-val="rem">rem</button>
                                <button type="button" data-val="%">%</button>
                            </div>
                        </div>
                        <div class="se-row">
                            <span>{{ __('Maximum width') }}</span>
                            <input type="hidden" data-prop="max-width">
                            <input type="text" class="se-input se-dim" data-dim="max-width" placeholder="960" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="max-width">
                                <button type="button" data-val="px" class="active">px</button>
                                <button type="button" data-val="rem">rem</button>
                                <button type="button" data-val="%">%</button>
                            </div>
                        </div>

                        {{-- border: width + style + colour --}}
                        <div class="se-row">
                            <span>{{ __('Border width') }}</span>
                            <input type="hidden" data-prop="border-width">
                            <input type="text" class="se-input se-dim" data-dim="border-width" placeholder="1" inputmode="decimal">
                            <div class="se-seg se-seg-sm se-units" data-units-for="border-width">
                                <button type="button" data-val="px" class="active">px</button>
                                <button type="button" data-val="rem">rem</button>
                                <button type="button" data-val="%">%</button>
                            </div>
                        </div>
                        <div class="se-row">
                            <span>{{ __('Border style') }}</span>
                            <input type="hidden" data-prop="border-style">
                            <div class="se-seg se-seg-sm flex-1" data-seg="border-style">
                                <button type="button" data-val="solid" title="solid"><span class="se-line" style="border-top-style: solid;"></span></button>
                                <button type="button" data-val="dashed" title="dashed"><span class="se-line" style="border-top-style: dashed;"></span></button>
                                <button type="button" data-val="dotted" title="dotted"><span class="se-line" style="border-top-style: dotted;"></span></button>
                            </div>
                        </div>
                        <label class="se-row">
                            <span>{{ __('Border colour') }}</span>
                            <input type="text" class="se-input se-hex" data-hex-for="border-color" placeholder="#000000" maxlength="7">
                            <input type="color" class="se-swatch" data-prop="border-color" title="{{ __('Border colour') }}">
                        </label>

                        <label class="se-row">
                            <span>{{ __('Background colour') }}</span>
                            <input type="text" class="se-input se-hex" data-hex-for="background-color" placeholder="#ffffff" maxlength="7">
                            <input type="color" class="se-swatch" data-prop="background-color" title="{{ __('Background colour') }}">
                        </label>

                        {{-- position of a background photo: which part stays in view when cropping --}}
                        <div class="se-row">
                            <span>{{ __('Photo position') }}</span>
                            <input type="hidden" data-prop="background-position">
                            <div class="se-seg se-seg-sm flex-1" data-seg="background-position">
                                <button type="button" data-val="center top" title="{{ __('Top in view') }}"><i class="bi bi-align-top"></i></button>
                                <button type="button" data-val="center" title="{{ __('Centre in view') }}"><i class="bi bi-align-middle"></i></button>
                                <button type="button" data-val="center bottom" title="{{ __('Bottom in view') }}"><i class="bi bi-align-bottom"></i></button>
                            </div>
                        </div>

                        <label class="mb-1 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" class="rounded border-gray-300" id="se-hide">
                            {{ __('Hide this element') }}
                        </label>
                    </div>
                </details>

                {{-- linking: makes this element show the content of a page from now on
                     (filled in server-side, so also for search engines and multilingual).
                     Collapsed by default. --}}
                <details class="se-acc" id="se-sec-bind">
                    <summary>{{ __('Link to CMS content') }} <span class="se-chev">⌄</span></summary>
                    <div class="se-acc-body">
                        <p class="mb-2 text-xs text-gray-400">{{ __('Show the title or content of a page in this element from now on. You manage that text under Pages and it appears here automatically — multilingual too.') }}</p>

                        <div class="mb-2 hidden rounded-lg border border-blue-300 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-500/40 dark:bg-blue-500/10 dark:text-blue-300" id="se-bind-active">
                            <span></span>
                            <button type="button" class="ml-1 align-middle text-blue-800/60 hover:text-red-600 dark:text-blue-300/60" id="se-bind-remove" title="{{ __('Remove link') }}">✕</button>
                        </div>

                        <select class="se-input mb-2" id="se-bind-source">
                            <option value="">{{ __('Choose a page…') }}</option>
                            @foreach ($linkPages as $linkPage)
                                <option value="{{ $linkPage['id'] }}">{{ $linkPage['title'] }}</option>
                            @endforeach
                        </select>
                        <select class="se-input mb-2" id="se-bind-field">
                            <option value="title">{{ __('Title') }}</option>
                            <option value="content">{{ __('Content') }}</option>
                            <option value="hero">{{ __("Hero photos (carousel)") }}</option>
                        </select>
                        <x-filament::button color="gray" size="sm" id="se-bind-save" class="w-full justify-center" disabled>
                            {{ __('Link') }}
                        </x-filament::button>

                        {{-- or an image from the media library --}}
                        <x-filament::button color="gray" size="sm" id="se-bind-media" class="mt-2 w-full justify-center">
                            {{ __('Link image from media') }}
                        </x-filament::button>
                        <p class="mt-1 text-xs text-gray-400">{{ __('An image element gets the chosen image; any other element gets it as a background.') }}</p>
                    </div>
                </details>
            </div>

            {{-- overview of all customised elements --}}
            <div class="mt-5 border-t border-gray-200 pt-4 dark:border-white/10">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Customised elements') }} <span id="se-count">0</span>
                </h3>
                <div id="se-list" class="space-y-1 text-xs"></div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const frame = document.getElementById('se-frame');
        const panel = document.getElementById('se-panel');
        const empty = document.getElementById('se-empty');
        const selectorInput = document.getElementById('se-selector');
        const inputs = [...document.querySelectorAll('[data-prop]')];
        const hideBox = document.getElementById('se-hide');
        const secText = document.getElementById('se-sec-text');
        const I18N = {
            saved: @json(__('Saved')),
            saveFailed: @json(__('Saving failed')),
            confirmClear: @json(__('Clear all style editor customisations?')),
            moveFlex: @json(__('The surrounding container was made flex to allow reordering — check that everything still looks right.')),
            pagePrompt: @json(__('Title of the new page:')),
            pageCreated: @json(__('Page created and added to the menu. Fill in the content under Pages.')),
        };

        // rules: { selector: {prop: value} }
        let rules = {};
        @json($rules).forEach(r => { rules[r.selector] = Object.assign({}, r.declarations); });
        let current = null;

        const post = msg => frame.contentWindow?.postMessage(msg, location.origin);

        function notification(text, color = 'success') {
            new FilamentNotification().title(text).status(color).send();
        }

        // Reload without losing your place: after sw-ready the picker selects the element
        // again, so the panel reopens in the state you left it in.
        let restoreSelector = null;
        function reloadPreview() {
            restoreSelector = current;
            try { frame.contentWindow.location.reload(); } catch (e) { frame.src = frame.src; }
        }

        function cssText() {
            return Object.entries(rules).map(([sel, decls]) => {
                const body = Object.entries(decls).map(([p, v]) => `${p}:${v} !important;`).join('');
                return body ? `${sel}{${body}}` : '';
            }).join('');
        }

        function pushCss() {
            post({ type: 'sw-css', css: cssText() });
            renderList();
        }

        function renderList() {
            const list = document.getElementById('se-list');
            const keys = Object.keys(rules);
            document.getElementById('se-count').textContent = keys.length;
            list.innerHTML = '';
            keys.forEach(sel => {
                const row = document.createElement('div');
                row.className = 'flex items-center gap-2 rounded bg-gray-50 px-2 py-1 dark:bg-white/5';
                const name = document.createElement('button');
                name.type = 'button';
                name.className = 'min-w-0 flex-1 truncate text-left font-mono text-gray-600 hover:text-primary-600 dark:text-gray-300';
                name.textContent = sel;
                // also highlight it in the site and scroll it into view
                name.onclick = () => { showStyleOnly(sel); post({ type: 'sw-select', selector: sel }); };
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'shrink-0 text-gray-400 hover:text-red-600';
                del.textContent = '✕';
                del.onclick = () => { delete rules[sel]; if (current === sel) closePanel(); pushCss(); };
                row.append(name, del);
                list.appendChild(row);
            });
        }

        function rgbToHex(v) {
            const m = String(v).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
            if (!m) return /^#[0-9a-f]{6}$/i.test(v) ? v : '#000000';
            return '#' + [1, 2, 3].map(i => (+m[i]).toString(16).padStart(2, '0')).join('');
        }

        /** Fills the formatting fields for a selector; the base of each chosen element. */
        function fillStyle(selector, label, tag, computed) {
            current = selector;
            selectorInput.value = selector;
            document.getElementById('se-target').textContent = tag ? `<${tag}> ${label || ''}` : (label || '');
            panel.classList.remove('hidden');
            empty.classList.add('hidden');

            const saved = rules[selector] || {};
            inputs.forEach(inp => {
                const p = inp.dataset.prop;
                if (saved[p] != null) {
                    inp.value = inp.type === 'color' ? rgbToHex(saved[p]) : saved[p];
                } else if (inp.type === 'color' && computed) {
                    inp.value = rgbToHex(computed[p] || '#000000');
                } else {
                    inp.value = '';
                }
                delete inp.dataset.touched;
            });
            hideBox.checked = saved.display === 'none';
            const fontSel = document.getElementById('se-font-family');
            if (saved['font-family'] && fontSel.value !== saved['font-family']) {
                const o = document.createElement('option');
                o.value = saved['font-family'];
                o.textContent = saved['font-family'].split(',')[0].replace(/['"]/g, '');
                fontSel.appendChild(o);
                fontSel.value = saved['font-family'];
            }
            syncSegs();
            syncHex();
            syncDims();
        }

        /** From the rule list: only show the formatting (we know nothing about text there). */
        function showStyleOnly(sel) {
            fillStyle(sel, sel, '');
            secText.classList.add('hidden');
            textCtx = null;
        }

        function closePanel() {
            current = null;
            textCtx = null;
            panel.classList.add('hidden');
            empty.classList.remove('hidden');
            post({ type: 'sw-clear-select' });
        }

        function collect() {
            const decls = {};
            inputs.forEach(inp => {
                const p = inp.dataset.prop;
                const v = (inp.value || '').trim();
                // a colour field only counts if the user has touched it
                if (v === '' || (inp.type === 'color' && !inp.dataset.touched)) return;
                decls[p] = v;
            });
            if (hideBox.checked) decls['display'] = 'none';
            // a border width without a border style draws nothing; then silently pick a line
            if (decls['border-width'] && !decls['border-style']) decls['border-style'] = 'solid';
            return decls;
        }

        function apply() {
            if (!current) return;
            const sel = selectorInput.value.trim() || current;
            if (sel !== current) {
                delete rules[current];
                current = sel;
                post({ type: 'sw-select', selector: sel });   // highlight follows the new target
            }
            const decls = collect();
            if (Object.keys(decls).length) rules[sel] = decls; else delete rules[sel];
            post({ type: 'sw-preview', selector: sel, declarations: decls });
            renderList();
        }

        // the fonts the site itself uses, supplied by the picker (sw-ready)
        function fillThemeFonts(fonts) {
            const og = document.getElementById('se-theme-fonts');
            if (!og) return;
            const sel = document.getElementById('se-font-family');
            const current = sel.value;
            og.innerHTML = '';
            fonts.forEach(name => {
                const o = document.createElement('option');
                o.value = `'${name}', sans-serif`;
                o.textContent = name;
                o.style.fontFamily = `'${name}'`;
                og.appendChild(o);
            });
            og.hidden = fonts.length === 0;
            sel.value = current;   // rebuilding must not clear the choice
        }

        inputs.forEach(inp => {
            inp.addEventListener('input', () => { if (inp.type === 'color') inp.dataset.touched = '1'; apply(); });
            inp.addEventListener('change', () => { if (inp.type === 'color') inp.dataset.touched = '1'; apply(); });
        });

        // segment buttons (font weight, alignment): clicking again clears the choice
        const segs = [...document.querySelectorAll('[data-seg]')];
        function syncSegs() {
            segs.forEach(seg => {
                const inp = document.querySelector(`[data-prop="${seg.dataset.seg}"]`);
                seg.querySelectorAll('button').forEach(b =>
                    b.classList.toggle('active', inp.value !== '' && b.dataset.val === inp.value));
            });
        }
        segs.forEach(seg => {
            const inp = document.querySelector(`[data-prop="${seg.dataset.seg}"]`);
            seg.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
                inp.value = inp.value === b.dataset.val ? '' : b.dataset.val;
                syncSegs();
                apply();
            }));
        });

        // Size fields: number(s) + unit as segment buttons. "12" with px becomes 12px,
        // "1 2" with rem becomes "1rem 2rem", so you never need to know whether the unit has to
        // be included. A value that cannot be parsed (e.g. "auto") is kept as is,
        // without an active unit, and also goes into the rule as is.
        const dims = [...document.querySelectorAll('.se-dim')];

        function unitOf(prop) {
            const active = document.querySelector(`[data-units-for="${prop}"] button.active`);
            return active ? active.dataset.val : null;
        }

        function composeDim(text, unit) {
            const t = text.trim();
            if (t === '' || !unit) return t;
            return t.split(/\s+/).map(tok =>
                /^-?\d*\.?\d+$/.test(tok) ? (parseFloat(tok) === 0 ? '0' : tok + unit) : tok
            ).join(' ');
        }

        function decomposeDim(value) {
            const t = String(value || '').trim();
            if (t === '') return { nums: '', unit: null };
            let unit = null, clean = true;
            const parts = t.split(/\s+/).map(tok => {
                const m = tok.match(/^(-?\d*\.?\d+)(px|rem|em|%|vh|vw)?$/);
                if (!m) { clean = false; return tok; }
                if (m[2]) {
                    if (unit === null) unit = m[2];
                    else if (unit !== m[2]) clean = false;
                }
                return m[1];
            });
            return clean ? { nums: parts.join(' '), unit: unit } : { raw: t };
        }

        function syncDims() {
            dims.forEach(num => {
                const prop = num.dataset.dim;
                const hidden = document.querySelector(`[data-prop="${prop}"]`);
                const buttons = [...document.querySelectorAll(`[data-units-for="${prop}"] button`)];
                const d = decomposeDim(hidden.value);
                const btn = d.unit ? buttons.find(b => b.dataset.val === d.unit) : null;
                buttons.forEach(b => b.classList.remove('active'));
                if (d.raw !== undefined || (d.unit && !btn)) {
                    num.value = hidden.value;   // unparseable, or a unit without a button (em/vh)
                    return;
                }
                num.value = d.nums;
                // no unit in the value: the default of this row (line height = none)
                const seg = document.querySelector(`[data-units-for="${prop}"]`);
                const defaultUnit = seg.dataset.default !== undefined ? seg.dataset.default : 'px';
                (btn || buttons.find(b => b.dataset.val === defaultUnit)).classList.add('active');
            });
        }

        dims.forEach(num => {
            const hidden = document.querySelector(`[data-prop="${num.dataset.dim}"]`);
            num.addEventListener('input', () => {
                hidden.value = composeDim(num.value, unitOf(num.dataset.dim));
                apply();
            });
        });

        document.querySelectorAll('[data-units-for]').forEach(seg => {
            const prop = seg.dataset.unitsFor;
            const hidden = document.querySelector(`[data-prop="${prop}"]`);
            const num = document.querySelector(`.se-dim[data-dim="${prop}"]`);
            seg.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
                seg.querySelectorAll('button').forEach(x => x.classList.remove('active'));
                b.classList.add('active');
                hidden.value = composeDim(num.value, b.dataset.val);
                apply();
            }));
        });

        // hex field next to each colour swatch: synced both ways
        const hexes = [...document.querySelectorAll('.se-hex')];
        function syncHex() {
            hexes.forEach(hex => {
                hex.value = document.querySelector(`[data-prop="${hex.dataset.hexFor}"]`).value;
            });
        }
        hexes.forEach(hex => {
            const swatch = document.querySelector(`[data-prop="${hex.dataset.hexFor}"]`);
            hex.addEventListener('input', () => {
                const v = hex.value.trim();
                if (/^#[0-9a-fA-F]{6}$/.test(v)) {
                    swatch.value = v;
                    swatch.dataset.touched = '1';
                    apply();
                }
            });
            swatch.addEventListener('input', () => { hex.value = swatch.value; });
        });
        hideBox.addEventListener('change', apply);
        selectorInput.addEventListener('change', apply);

        // The actions sit as a floating bar on the chosen element in the preview;
        // the picker reports the click with sw-action and the real work happens here.
        function resetElement() {
            if (!current) return;
            delete rules[current];
            inputs.forEach(i => { i.value = ''; delete i.dataset.touched; });
            hideBox.checked = false;
            syncSegs();
            syncHex();
            syncDims();
            post({ type: 'sw-preview', selector: current, declarations: {} });
            pushCss();
        }

        // element "delete": hide via a style rule + save immediately. Reversible
        // via the list of customised elements, so nothing can ever break for good.
        async function deleteElement(selector) {
            if (!selector) return;
            rules[selector] = Object.assign({}, rules[selector] || {}, { display: 'none' });
            await save();
            closePanel();
        }

        const actions = {
            up: () => { if (current) post({ type: 'sw-move', selector: current, dir: -1 }); },
            down: () => { if (current) post({ type: 'sw-move', selector: current, dir: 1 }); },
            reset: resetElement,
            'delete': () => deleteElement(selectorInput.value.trim() || current),
        };

        async function save() {
            const payload = Object.entries(rules).map(([selector, declarations]) => ({ selector, declarations }));
            try {
                const json = await @this.save(payload);
                rules = {};
                (json.rules || []).forEach(r => { rules[r.selector] = r.declarations; });
                pushCss();
                notification(I18N.saved);
            } catch (e) {
                notification(I18N.saveFailed, 'danger');
            }
        }

        document.getElementById('se-clear-all').onclick = () => {
            if (!confirm(I18N.confirmClear)) return;
            rules = {};
            closePanel();
            pushCss();
        };
        document.getElementById('se-save').onclick = save;

        // ---------- text (live preview) ----------
        const textArea = document.getElementById('se-text');
        let textCtx = null;   // { original }

        textArea.addEventListener('input', () => {
            post({ type: 'sw-text-set', text: textArea.value });
        });
        document.getElementById('se-text-revert').onclick = () => {
            if (!textCtx) return;
            textArea.value = textCtx.original;
            post({ type: 'sw-text-revert' });
        };

        // ---------- menu items ----------
        // New page that goes straight into the menu, via Livewire (quickPage).
        let menuHref = null;
        async function addMenuPage(presetTitle) {
            const title = presetTitle || prompt(I18N.pagePrompt);
            if (!title || !title.trim()) return;
            try {
                await @this.quickPage(title.trim());
                notification(I18N.pageCreated);
                reloadPreview();
            } catch (e) {
                notification(I18N.saveFailed, 'danger');
            }
        }

        // blue menu item: load the linked page in the editor to edit it visually
        // (the load listener below appends ?style-editor=1 automatically)
        document.getElementById('se-open-page').onclick = () => {
            if (!menuHref) return;
            try { frame.contentWindow.location.href = menuHref; } catch (e) { frame.src = menuHref; }
            closePanel();
        };
        document.getElementById('se-hard-hide').onclick = () => deleteElement(selectorInput.value.trim() || current);
        document.getElementById('se-hard-add').onclick = () => addMenuPage(textArea.value.trim());

        // ---------- linking to CMS content ----------
        // The element shows the title/content of a page from now on (filled in server-side),
        // or a fixed image from the media manager.
        const links = {};   // selector -> { label, field }
        @json($links).forEach(k => { links[k.selector] = { label: k.label, field: k.field }; });
        const bindSec = document.getElementById('se-sec-bind');
        const bindSource = document.getElementById('se-bind-source');
        const bindField = document.getElementById('se-bind-field');
        const bindSave = document.getElementById('se-bind-save');
        const bindActive = document.getElementById('se-bind-active');

        bindSource.addEventListener('change', () => { bindSave.disabled = !bindSource.value; });

        function showLink(selector) {
            const link = links[selector] || null;
            bindActive.classList.toggle('hidden', !link);
            if (link) {
                bindActive.querySelector('span').textContent = link.label;
                bindSec.open = true;
            }
            // linked text is edited under Pages, not in the text box here;
            // an image link says nothing about the text, so that stays editable
            const textLinked = !!link && link.field !== 'image';
            textArea.disabled = textLinked;
            document.getElementById('se-text-revert').disabled = textLinked;
        }

        bindSave.onclick = async () => {
            const selector = selectorInput.value.trim() || current;
            if (!selector || !bindSource.value) return;
            try {
                const json = await @this.link(selector, Number(bindSource.value), bindField.value);
                links[selector] = { label: json.label, field: bindField.value };
                showLink(selector);
                notification(I18N.saved);
                reloadPreview();
            } catch (e) {
                notification(I18N.saveFailed, 'danger');
            }
        };

        // Link an image from the media library to this element. Server-side
        // ContentLink fills it in: an <img> gets a new src, any other element
        // gets the image as background-image (cover).
        document.getElementById('se-bind-media').onclick = () => {
            const selector = selectorInput.value.trim() || current;
            if (!selector) return;
            pickMedia(async (f) => {
                try {
                    const json = await @this.linkImage(selector, f.path);
                    links[selector] = { label: json.label, field: 'image' };
                    showLink(selector);
                    notification(I18N.saved);
                    reloadPreview();
                } catch (e) {
                    notification(I18N.saveFailed, 'danger');
                }
            });
        };

        document.getElementById('se-bind-remove').onclick = async () => {
            const selector = selectorInput.value.trim() || current;
            if (!selector) return;
            try {
                await @this.disconnect(selector);
                delete links[selector];
                showLink(selector);
                notification(I18N.saved);
                reloadPreview();
            } catch (e) {
                notification(I18N.saveFailed, 'danger');
            }
        };

        // If the iframe navigates to a page without ?style-editor=1, the editor
        // is not loaded. That would silently stop working, so we append it.
        frame.addEventListener('load', () => {
            let loc;
            try { loc = frame.contentWindow.location; } catch (e) { return; }   // different origin
            if (loc.origin !== location.origin) return;
            if (!/[?&]style-editor=1\b/.test(loc.search)) {
                const sep = loc.search ? '&' : '?';
                loc.replace(loc.pathname + loc.search + sep + 'style-editor=1' + loc.hash);
            }
        });

        document.getElementById('se-viewport').onchange = e => { frame.style.maxWidth = e.target.value; };

        // ---------- full screen ----------
        const fullBtn = document.getElementById('se-fullscreen');
        function applyFull(on) {
            document.documentElement.classList.toggle('se-full', on);
        }
        fullBtn.onclick = () => {
            const on = !document.documentElement.classList.contains('se-full');
            applyFull(on);
            // also go truly full screen if the browser allows it; refusing is no disaster,
            // the admin bars are gone anyway
            try {
                if (on) document.documentElement.requestFullscreen().catch(() => {});
                else if (document.fullscreenElement) document.exitFullscreen();
            } catch (e) { /* no Fullscreen API */ }
        };
        // Esc exits browser fullscreen: switch the mode off along with it
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) applyFull(false);
        });

        window.addEventListener('message', e => {
            if (e.origin !== location.origin || !e.data || typeof e.data !== 'object') return;
            const d = e.data;
            if (d.type === 'sw-ready') {
                document.getElementById('se-url').textContent = d.url || '';
                fillThemeFonts(d.fonts || []);
                post({ type: 'sw-labels', labels: {
                    up: @json(__('Element up')),
                    down: @json(__('Element down')),
                    reset: @json(__('Reset this element')),
                    'delete': @json(__('Hide element')),
                } });
                pushCss();
                // new page: do not leave an old selection or text behind...
                closePanel();
                // ...unless we just reloaded: then let the element be chosen again
                if (restoreSelector) {
                    post({ type: 'sw-pick', selector: restoreSelector });
                    restoreSelector = null;
                }
            } else if (d.type === 'sw-picked') {
                // one message with everything: formatting always, text if applicable
                fillStyle(d.selector, d.label, d.tag, d.computed);

                const hasText = d.text !== null && d.text !== undefined;
                secText.classList.toggle('hidden', !hasText);
                if (hasText) {
                    secText.open = true;
                    textCtx = { original: d.text };
                    textArea.value = d.text;
                    menuHref = (d.menuKind === 'cms' && d.menuHref) ? d.menuHref : null;
                    document.getElementById('se-cms-menu').classList.toggle('hidden', !menuHref);
                    document.getElementById('se-hard-menu').classList.toggle('hidden', d.menuKind !== 'hard');
                } else {
                    textCtx = null;
                    menuHref = null;
                }
                showLink(d.selector);
            } else if (d.type === 'sw-fonts') {
                // list sent afterwards once the web fonts of the site have really loaded
                fillThemeFonts(d.fonts || []);
            } else if (d.type === 'sw-action') {
                // click on the action bar in the preview
                if (actions[d.action]) actions[d.action]();
            } else if (d.type === 'sw-menu-add') {
                addMenuPage();
            } else if (d.type === 'sw-text-input') {
                if (textCtx) textArea.value = d.text;
            } else if (d.type === 'sw-order') {
                // the page calculated the new order; here only merge and show it
                (d.rules || []).forEach(r => {
                    rules[r.selector] = Object.assign({}, rules[r.selector] || {}, r.declarations);
                });
                pushCss();
                if (d.warning) notification(I18N.moveFlex, 'warning');
            }
        });

        renderList();
    })();
    </script>

    {{-- media picker for "Link image from media" (modals + pickMedia) --}}
    @include('filament.partials.media-assets')
</x-filament-panels::page>
