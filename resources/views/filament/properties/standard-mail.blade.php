{{-- The email in a frame of its own, so its styles stay out of the admin. Scripts stay blocked;
     same origin only so the frame loads (a fully sandboxed frame is blocked by some browsers). --}}
<iframe
    src="{{ $url }}"
    sandbox="allow-same-origin"
    title="{{ __('Show the standard email') }}"
    data-standard-mail
    style="width: 100%; height: 70vh; border: 0; border-radius: 0.5rem; background: #fff;"
></iframe>
