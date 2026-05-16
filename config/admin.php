<?php

// Credentials for the Blade operator console (HTTP Basic).
// Set ADMIN_USER / ADMIN_PASS as environment variables (Fly secrets in
// prod). Read via config() — NOT env() in the middleware — because the
// production entrypoint runs `php artisan config:cache`, after which
// env() returns null outside config files. Caching happens at boot when
// the Fly secrets are already present, so they get baked in correctly.

return [
    'user' => env('ADMIN_USER'),
    'pass' => env('ADMIN_PASS'),
];
