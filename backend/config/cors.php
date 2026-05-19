<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Front Nuxt local. Phase 4-bis : tout passe par Nginx (port host =
    // NGINX_PORT, défaut 8080) ; Nuxt direct sur :3000 reste autorisé pour
    // debug. La var d'env `FRONTEND_URL` est l'équivalent côté Laravel de
    // `RESONANCE_BASE_URL` côté outils de mesure : elle surcharge l'origin
    // autorisée si l'app est déployée derrière un host différent.
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:8080'),
        'http://localhost:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Sanctum en mode token (Bearer dans Authorization header) → pas de
    // cookies cross-site, donc credentials inutiles. Cf. spec §2 / §6.
    'supports_credentials' => false,

];
