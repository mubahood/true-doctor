<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | HMS_PLAN.md constraint C15: locked to known origins, never a wildcard
    | (the legacy audit found `['*']` everywhere). No config/cors.php meant
    | this app was silently running on the framework's own default
    | (`allowed_origins => ['*']`) — this file replaces that with an
    | explicit, env-driven allow-list that defaults to *nothing* until
    | CORS_ALLOWED_ORIGINS is set.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
