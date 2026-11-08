<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Puts the element picker of the visual style editor on the site, but only
 * if the page is requested with ?style-editor=1 and an administrator is
 * logged in. This way the editor works on every layout (including an uploaded
 * template) without it having to do anything: the script is appended to the
 * HTML response.
 */
class InjectStyleEditor
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($request->query('style-editor') !== '1' || ! (auth()->user()?->isAdministrator() ?? false)) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || ! str_contains($content, '</body>')) {
            return $response;
        }

        $type = (string) $response->headers->get('Content-Type');

        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }

        // Send the menu along, so the picker can recognise menu items:
        // blue = comes from Pages (editable via the CMS), orange = fixed in the layout.
        $menu = [];

        try {
            foreach (\App\Models\Page::menuItems() as $page) {
                $menu[] = [
                    'id' => $page->id,
                    'title' => $page->localized('title'),
                    'url' => url('/'.$page->slug),
                ];
            }
        } catch (\Throwable) {
            // no menu available: the editor simply works without highlighting
        }

        $tag = '<script>window.__swMenu = '.json_encode($menu, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).';</script>'
            .'<script src="'.e(asset('js/style-editor.js')).'?v='.filemtime(public_path('js/style-editor.js')).'" defer></script>';
        $response->setContent(substr_replace($content, $tag.'</body>', strrpos($content, '</body>'), strlen('</body>')));

        return $response;
    }
}
