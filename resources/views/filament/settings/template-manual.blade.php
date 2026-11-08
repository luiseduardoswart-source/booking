<div class="space-y-4 text-sm text-gray-600 dark:text-gray-300">
    <p>{{ __('A layout template is a zip file you upload above. The system installs everything automatically. This is how the zip is structured:') }}</p>

    <div class="rounded-xl bg-gray-50 p-4 font-mono text-xs leading-6 dark:bg-white/5">
        my-template/<br>
        ├── template.json<br>
        ├── assets/template.css<br>
        ├── site/home.blade.php<br>
        └── site/properties/partials/hero.blade.php
    </div>

    <ul class="list-disc space-y-2 pl-5">
        <li><span class="font-mono text-xs">template.json</span> — {{ __('the name and description of the template, e.g.') }} <span class="font-mono text-xs">{"name": "Mijn template", "description": "…"}</span></li>
        <li><span class="font-mono text-xs">assets/template.css</span> — {{ __('the template\'s own styling, as ready-made CSS. Loaded automatically on every page while the template is active.') }}</li>
        <li>{{ __('Besides that, only the pages you want to replace, using exactly the same file paths as the default. Anything you leave out simply stays the built-in version — a template with only its own homepage works too.') }}</li>
    </ul>

    <p class="font-medium text-gray-900 dark:text-white">{{ __('Commonly replaced paths:') }}</p>
    <ul class="space-y-1.5 pl-1">
        <li><span class="font-mono text-xs">site/home.blade.php</span> — {{ __('the homepage') }}</li>
        <li><span class="font-mono text-xs">site/properties/partials/hero.blade.php</span> — {{ __('the photo presentation at the top of the accommodation page') }}</li>
        <li><span class="font-mono text-xs">site/properties/show.blade.php</span> — {{ __('the full accommodation page') }}</li>
        <li><span class="font-mono text-xs">site/bookings/create.blade.php</span> — {{ __('the booking page') }}</li>
        <li><span class="font-mono text-xs">site/contact.blade.php</span> — {{ __('the contact page') }}</li>
        <li><span class="font-mono text-xs">components/site-layout.blade.php</span> — {{ __('the header and footer (visible on every page)') }}</li>
    </ul>

    <p>{{ __('Fastest start: download the working example below, adjust it (or hand it to your designer), re-zip the folder and upload it above.') }}</p>
</div>
