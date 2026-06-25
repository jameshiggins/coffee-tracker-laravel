<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    // Q18: Resend transactional driver (config/mail.php → 'resend' mailer).
    // resend-laravel resolves its key as config('resend.api_key') (env
    // RESEND_API_KEY) ?? config('services.resend.key'). The app standardizes
    // on RESEND_KEY (see docs/deploy.md), so map it here — otherwise the
    // transport finds no key and throws ApiKeyIsMissing on every send.
    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/google/callback'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5174'),
    ],

    // Q-AR step 4: Google Places "Find Place from Text" for address fallback.
    // Leave the key unset to skip Google Places in the cascade; see
    // app/Services/Scraping/Address/GooglePlacesAddressResolver.php for the
    // exact provisioning steps.
    'google_places' => [
        'key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    // Ops monitoring: healthchecks.io-style ping URLs for the scheduled jobs
    // (see app/Console/Kernel.php). Each scheduled command pings its URL on
    // success and {url}/fail on failure, so a missed run alerts you even if
    // mail is down. Leave unset to disable per-job pings — the in-app
    // scheduler.tick heartbeat behind GET /up still covers liveness.
    'healthchecks' => [
        'import' => env('HEALTHCHECK_IMPORT_URL'),
        'digest' => env('HEALTHCHECK_DIGEST_URL'),
    ],

];
