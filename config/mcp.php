<?php

/*
| The Claude connection (App\Mcp): only the settings that differ from the
| package defaults (vendor/laravel/mcp/config/mcp.php). The rest is merged.
*/

return [

    /*
    | Where an OAuth client may send the owner back after "Allow". Only
    | Claude (the website, the apps and Claude Code on the owner's own
    | computer), so nobody can register a look-alike app that collects the
    | access code on another website.
    */
    'redirect_domains' => [
        'https://claude.ai',
        'https://claude.com',
        'http://localhost',
    ],

    // No app-specific schemes such as cursor:// or vscode://.
    'custom_schemes' => [],

];
