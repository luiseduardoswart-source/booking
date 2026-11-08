<x-filament-panels::page>
    @php
        $aiKey = \App\Support\AiImages::available();
        $aiLimit = \App\Support\AiImages::monthlyLimit();
        $aiUsage = \App\Support\AiImages::monthlyUsage();
    @endphp

    {{-- AI images: generate + approval queue (ported from devsite).
         The panel is always visible, so the admin sees that it exists
         and where the key goes. --}}
    <div class="card" id="ai-images-panel">
        <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-white/10">
            <i class="bi bi-stars text-blue-500"></i> {{ __('AI images') }}
            <span class="text-xs font-normal text-gray-400" id="ai-usage">
                @if ($aiKey && $aiLimit){{ __('this month: :used / :limit used', ['used' => $aiUsage, 'limit' => $aiLimit]) }}@endif
            </span>
            <span class="ml-auto flex items-center gap-2">
                <span class="text-xs font-normal text-gray-500 dark:text-gray-400"><i class="bi bi-robot"></i> {{ \App\Support\AiImages::label() }}</span>
                <button type="button" class="btn-primary btn-sm" onclick="openModal('ai-image-modal')" @unless ($aiKey) disabled @endunless>
                    <i class="bi bi-magic"></i> {{ __('Generate with AI') }}
                </button>
            </span>
        </div>
        <div class="card-body">
            @unless ($aiKey)
                <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/50 dark:text-amber-300">{{ __('No API key for the image provider yet. Enter it under Settings → API keys to be able to generate.') }}</p>
            @endunless
            <p class="form-text mb-3 !mt-0">{{ __('Generated images land here first for approval. Approve an image and it moves into the current media folder.') }}</p>
            <div id="ai-queue" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"></div>
            <p id="ai-queue-empty" class="hidden py-4 text-center text-sm text-gray-400">{{ __('No images in the queue.') }}</p>
        </div>
    </div>

    {{-- generate modal --}}
    <div class="modal" id="ai-image-modal">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h5 class="text-sm font-semibold"><i class="bi bi-magic mr-1"></i> {{ __('Generate with AI') }}</h5>
                <button type="button" class="icon-btn" data-modal-close><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-body">
                <div class="mb-4 flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/5">
                    <span class="text-gray-500 dark:text-gray-400"><i class="bi bi-robot"></i> {{ __('Model') }}: <span class="font-medium text-gray-700 dark:text-gray-200">{{ \App\Support\AiImages::label() }}</span></span>
                    <span class="text-gray-400">{{ __('change under Settings') }}</span>
                </div>
                <div class="mb-4">
                    <label class="label">{{ __('Describe the image') }}</label>
                    <textarea id="ai-prompt" class="input" rows="3" placeholder="{{ __('e.g. a cosy wood stove in a modern living room, warm light') }}"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('Size') }}</label>
                        <select id="ai-format" class="input">
                            <option value="square" selected>{{ __('Square') }}</option>
                            <option value="landscape">{{ __('Landscape') }}</option>
                            <option value="portrait">{{ __('Portrait') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">{{ __('Count') }}</label>
                        <select id="ai-count" class="input">
                            @for ($n = 1; $n <= 4; $n++)<option value="{{ $n }}">{{ $n }}</option>@endfor
                        </select>
                    </div>
                </div>
                <p class="form-text mt-3">{{ __('Generating takes a few seconds per image. More images = higher cost.') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-modal-close>{{ __('Cancel') }}</button>
                <button type="button" class="btn-primary" id="ai-gen-btn" onclick="generateAiImages()">
                    <i class="bi bi-magic"></i> {{ __('Generate with AI') }}
                </button>
            </div>
        </div>
    </div>

    <div class="card card-body" id="media-inline">
        @include('filament.partials.media-browser')
    </div>

    @include('filament.partials.media-assets')

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const browser = new MediaBrowser(document.getElementById('media-inline'));
            window.mediaBrowser = browser;
            browser.load('');
            loadAiQueue();
        });

        // ---- AI images: queue + generate ----
        const aiBase = @json(url('admin-media/ai'));

        function aiThumb(img) {
            return `<div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10" data-ai-item="${img.id}">
                <img src="${img.url}" class="aspect-square w-full object-cover" alt="" title="${(img.prompt || '').replace(/"/g, '&quot;')}">
                <div class="flex gap-1 p-1.5">
                    <button type="button" class="btn-success btn-sm flex-1" onclick="approveAiImage(${img.id})" title="@js(__('Approve (to media)'))"><i class="bi bi-check-lg"></i></button>
                    <button type="button" class="btn-danger btn-sm flex-1" onclick="rejectAiImage(${img.id})" title="@js(__('Reject'))"><i class="bi bi-trash"></i></button>
                </div>
            </div>`;
        }

        function renderAiQueue(images) {
            const box = document.getElementById('ai-queue');
            const empty = document.getElementById('ai-queue-empty');
            box.innerHTML = images.map(aiThumb).join('');
            empty.classList.toggle('hidden', images.length > 0);
        }

        let aiQueue = [];
        async function loadAiQueue() {
            try {
                const json = await api(aiBase + '/pending', { method: 'GET' });
                aiQueue = json.images || [];
                renderAiQueue(aiQueue);
            } catch (e) { /* silent */ }
        }

        async function generateAiImages() {
            const prompt = document.getElementById('ai-prompt').value.trim();
            if (!prompt) { document.getElementById('ai-prompt').focus(); return; }
            const btn = document.getElementById('ai-gen-btn');
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> ' + @js(__('Working…'));
            try {
                const json = await api(aiBase + '/generate', { data: {
                    prompt,
                    format: document.getElementById('ai-format').value,
                    count: parseInt(document.getElementById('ai-count').value, 10),
                } });
                aiQueue = (json.images || []).concat(aiQueue);
                renderAiQueue(aiQueue);
                document.getElementById('ai-prompt').value = '';
                if (typeof json.limit === 'number' && json.limit > 0) {
                    document.getElementById('ai-usage').textContent = @js(__('this month: :used / :limit used', ['used' => '%u', 'limit' => '%l'])).replace('%u', json.usage).replace('%l', json.limit);
                }
                closeModal('ai-image-modal');
                toast(@js(__('Done — review and approve in the queue.')));
            } catch (e) {
                // api() shows the error message itself (e.g. limit reached)
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }

        async function approveAiImage(id) {
            try {
                await api(aiBase + '/' + id + '/approve', { data: { folder: window.mediaBrowser.path } });
                aiQueue = aiQueue.filter(i => i.id !== id);
                renderAiQueue(aiQueue);
                window.mediaBrowser.load(window.mediaBrowser.path); // show the new image
                toast(@js(__('Added to the media.')));
            } catch (e) { /* silent */ }
        }

        async function rejectAiImage(id) {
            try {
                await api(aiBase + '/' + id + '/reject', { data: {} });
                aiQueue = aiQueue.filter(i => i.id !== id);
                renderAiQueue(aiQueue);
            } catch (e) { /* silent */ }
        }
    </script>
</x-filament-panels::page>
