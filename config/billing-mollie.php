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
    | Mollie profile id
    |--------------------------------------------------------------------------
    |
    | The website profile id (`pfl_…`) a product's frontend loads Mollie Components
    | with. It is returned in the PaymentIntent / SetupIntent result as the publishable
    | key so the client can mount the card component (Strong Customer Authentication is
    | completed on Mollie's checkout). Safe to expose to the browser; when unset the
    | intent result carries no publishable key.
    |
    */

    'profile_id' => env('MOLLIE_PROFILE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Off-session setup (mandate) first-payment amount
    |--------------------------------------------------------------------------
    |
    | Saving a card for off-session renewals is a Mollie `first`-sequence payment that
    | establishes a mandate. Mollie may require a non-zero amount for that first payment;
    | set the amount (a decimal string, e.g. "0.01") and its currency here. The engine's
    | SetupIntent request carries no amount, so it is configured at the adapter.
    |
    */

    'setup_amount' => env('MOLLIE_SETUP_AMOUNT', '0.00'),

    'setup_currency' => env('MOLLIE_SETUP_CURRENCY', 'EUR'),

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
