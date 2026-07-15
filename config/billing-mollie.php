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

];
