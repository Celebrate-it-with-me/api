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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN')
    ],

    'hcaptcha' => [
        'enabled' => (bool) env('HCAPTCHA_ENABLED', false),
        'site_key' => env('HCAPTCHA_SITE_KEY'),
        'secret' => env('HCAPTCHA_SECRET'),
    ],

    'google' => [
        'url' => env('GOOGLE_MAPS_API_URL'),
        'map_key' => env('GOOGLE_MAPS_API_KEY'),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URL'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URL'),
    ],

    // Spotify catalogue search (client-credentials). No defaults: without both the endpoint answers 503.
    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
    ],

    // Payments: `fake` until the Stripe account exists. The webhook secret signs the calls the gateway makes and has no
    // default: without it the webhook refuses everything. Production refuses to run with `fake` or without the secret.
    'payments' => [
        'gateway' => env('PAYMENTS_GATEWAY', 'fake'),
        'webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET'),
    ],

    // WhatsApp (Meta Cloud API): `fake` until the Meta account exists (docs/plans/communications.md).
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'fake'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),
        // Daily caps (rolling 24 h, audit W-01). Event: null = 3 x guests with a phone (min 100). Account = owner of the events.
        'max_daily_per_event' => env('WHATSAPP_MAX_DAILY_PER_EVENT'),
        'max_daily_per_account' => env('WHATSAPP_MAX_DAILY_PER_ACCOUNT', 1000),
    ],

    // Where suspended users and the "Necesito ayuda" button send people, and the admin panel the support mail links to.
    'support' => [
        'email' => env('SUPPORT_EMAIL', 'soporte@quinvite.com'),
        'admin_url' => env('ADMIN_URL'),
    ],

];
