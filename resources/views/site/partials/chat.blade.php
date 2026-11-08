{{-- AI chat bottom right. Self-contained (own css/js, sc- prefix), like the
     cookie notice, so it works in every layout. Only if the chat
     is available (see SiteChat::available()). The conversation stays in the
     browser; every question sends the latest part along. --}}
@if (\App\Services\Ai\SiteChat::available())
    <style>
        .sc-button{position:fixed;right:1rem;bottom:1rem;z-index:9997;width:3.5rem;height:3.5rem;border:0;border-radius:999px;cursor:pointer;display:flex;align-items:center;justify-content:center;background:var(--color-accent,#e11d48);color:#fff;box-shadow:0 12px 30px -12px rgba(0,0,0,.55);transition:transform .15s}
        .sc-button:hover{transform:scale(1.05)}
        .sc-panel{position:fixed;right:1rem;bottom:5.25rem;z-index:9997;width:22rem;max-width:calc(100vw - 2rem);height:30rem;max-height:calc(100vh - 7rem);display:flex;flex-direction:column;overflow:hidden;background:#fff;color:#1c1917;border:1px solid #e7e5e4;border-radius:1rem;box-shadow:0 25px 50px -20px rgba(0,0,0,.45);font-size:.9rem;line-height:1.45}
        .sc-panel[hidden]{display:none}
        .sc-heading{display:flex;align-items:center;gap:.5rem;padding:.8rem 1rem;background:var(--color-accent,#e11d48);color:#fff;font-weight:600}
        .sc-heading button{margin-left:auto;background:transparent;border:0;color:inherit;cursor:pointer;opacity:.85;display:flex}
        .sc-heading button:hover{opacity:1}
        .sc-messages{flex:1;overflow-y:auto;padding:.85rem;display:flex;flex-direction:column;gap:.5rem}
        .sc-message{max-width:85%;padding:.5rem .75rem;border-radius:1rem;white-space:pre-line;overflow-wrap:anywhere}
        .sc-message a{color:inherit;text-decoration:underline}
        .sc-visitor{align-self:flex-end;background:var(--color-accent,#e11d48);color:#fff;border-bottom-right-radius:.3rem}
        .sc-assistant{align-self:flex-start;background:#f5f5f4;border-bottom-left-radius:.3rem}
        .sc-types{padding:0 1rem .4rem;font-size:.78rem;color:#a8a29e}
        .sc-form{display:flex;gap:.5rem;padding:.6rem;border-top:1px solid #f5f5f4}
        .sc-form input{flex:1;min-width:0;border:1px solid #d6d3d1;border-radius:.7rem;padding:.5rem .7rem;font:inherit;color:inherit}
        .sc-form input:focus{outline:2px solid var(--color-accent,#e11d48);outline-offset:-1px}
        .sc-form button{border:0;border-radius:.7rem;padding:.5rem .85rem;font-weight:600;cursor:pointer;background:var(--color-accent,#e11d48);color:#fff}
        .sc-form button:disabled{opacity:.5;cursor:default}
    </style>

    <button type="button" class="sc-button" id="sc-button" aria-controls="sc-panel" aria-expanded="false" aria-label="{{ __('Ask a question') }}">
        <x-site-icon name="chat-bubble" class="h-7 w-7" />
    </button>

    <div class="sc-panel" id="sc-panel" role="dialog" aria-label="{{ __('Ask a question') }}" hidden>
        <div class="sc-heading">
            <x-site-icon name="chat-bubble" class="h-5 w-5" />
            <span>{{ \App\Models\Setting::get('company', 'name', config('app.name')) }}</span>
            <button type="button" id="sc-closes" aria-label="{{ __('Close') }}"><x-site-icon name="x-mark" class="h-5 w-5" /></button>
        </div>
        <div class="sc-messages" id="sc-messages" aria-live="polite"></div>
        <div class="sc-types" id="sc-types" hidden>{{ __('Typing…') }}</div>
        <form class="sc-form" id="sc-form">
            <input type="text" id="sc-input" maxlength="{{ \App\Services\Ai\SiteChat::MAX_CHARACTERS }}" autocomplete="off" placeholder="{{ __('Type your question…') }}" aria-label="{{ __('Type your question…') }}">
            <button type="submit">{{ __('Send') }}</button>
        </form>
    </div>

    <script>
        (function () {
            const button = document.getElementById('sc-button');
            const panel = document.getElementById('sc-panel');
            const list = document.getElementById('sc-messages');
            const types = document.getElementById('sc-types');
            const form = document.getElementById('sc-form');
            const input = document.getElementById('sc-input');
            const send = form.querySelector('button');
            const conversation = [];
            // On the accommodation page (or when booking it) the chat only knows that accommodation.
            const accommodation = @json(($accommodation = request()->route('property')) instanceof \App\Models\Property ? $accommodation->id : null);
            const welcome = @json(\App\Services\Ai\SiteChat::welcome());
            const unavailable = @json(__('The chat is unavailable right now. Please try again later or use the contact form.'));

            // Show text safely; only links to this site become clickable.
            function message(role, text) {
                const div = document.createElement('div');
                div.className = 'sc-message ' + (role === 'user' ? 'sc-visitor' : 'sc-assistant');
                text.split(/(https?:\/\/[^\s<>"')]+)/).forEach(function (piece, i) {
                    // Odd pieces are addresses; a punctuation mark after them does not belong to them.
                    const address = i % 2 ? piece.replace(/[.,;:!?]+$/, '') : '';
                    let url = null;
                    try { url = address ? new URL(address) : null; } catch (e) {}
                    if (url && url.origin === location.origin) {
                        const a = document.createElement('a');
                        a.href = url.href;
                        a.textContent = address;
                        div.appendChild(a);
                        div.appendChild(document.createTextNode(piece.slice(address.length)));
                    } else {
                        div.appendChild(document.createTextNode(piece));
                    }
                });
                list.appendChild(div);
                list.scrollTop = list.scrollHeight;
            }

            function set(open) {
                panel.hidden = !open;
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    if (!list.children.length) message('assistant', welcome);
                    input.focus();
                }
            }

            button.addEventListener('click', function () { set(panel.hidden); });
            document.getElementById('sc-closes').addEventListener('click', function () { set(false); });

            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const text = input.value.trim();
                if (!text || send.disabled) return;
                input.value = '';
                message('user', text);
                conversation.push({ role: 'user', content: text });
                send.disabled = true;
                types.hidden = false;
                let answer = unavailable;
                try {
                    const res = await fetch(@json(route('chat')), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                        body: JSON.stringify({ messages: conversation.slice(-{{ \App\Services\Ai\SiteChat::MAX_MESSAGES }}), accommodation: accommodation }),
                    });
                    const json = await res.json();
                    answer = res.ok && json.reply ? json.reply : (json.message || unavailable);
                    if (res.ok) conversation.push({ role: 'assistant', content: answer });
                    else conversation.pop();
                } catch (err) {
                    conversation.pop();
                }
                message('assistant', answer);
                types.hidden = true;
                send.disabled = false;
                input.focus();
            });
        })();
    </script>
@endif
