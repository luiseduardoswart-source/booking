<?php

namespace App\Filament\Support;

/**
 * Limits for the draggable width of the sidebar in the admin. The chosen
 * width is stored by the browser itself (localStorage); the server knows nothing about it.
 *
 * See resources/views/filament/partials/sidebar-width-*.blade.php and
 * public/js/sidebar-width.js.
 */
final class SidebarWidth
{
    /** Key in localStorage. */
    public const KEY = 'admin.sidebar-width';

    /** Narrowest and widest sidebar in pixels. */
    public const MIN = 200;

    public const MAX = 480;

    /** From this screen width the sidebar sits fixed next to the content (Tailwind lg). */
    public const FROM = 1024;
}
