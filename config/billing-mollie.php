<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mollie API key + redirect URL
    |--------------------------------------------------------------------------
    |
    | When a key is set, the Mollie gateway is bound as the billing PaymentGateway.
    | Mollie is redirect-based, so a redirect URL is required for the customer to
    | return after paying. Without a key, billing keeps its default gateway.
    |
    */

    'key' => env('MOLLIE_KEY'),

    'redirect_url' => env('MOLLIE_REDIRECT_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook signing secret
    |--------------------------------------------------------------------------
    |
    | The webhook signing secret used to verify incoming webhook signatures. When set
    | (together with an API key, which is needed to fetch the payment's status), the
    | webhook verifier and handler are bound; without it the handler is unavailable
    | and unsigned payloads are rejected by default.
    |
    */

    'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'),

];
