<?php

/*
| The AI connection (App\Mcp): only the settings that differ from the package
| defaults (vendor/laravel/mcp/config/mcp.php). The rest is merged.
*/

return [

    /*
    | Where an app may send the person back after "Allow", and which app links
    | (such as cursor://) are allowed. Filled on every request from the apps
    | the owner allows in Settings → AI connection
    | (App\Support\McpConnection::applyRedirectRules). Empty here, so without
    | that nothing can register.
    */
    'redirect_domains' => [],

    'custom_schemes' => [],

];
