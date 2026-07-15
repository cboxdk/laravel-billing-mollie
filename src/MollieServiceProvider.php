<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;

/**
 * Binds the Mollie gateway as billing's PaymentGateway when an API key is
 * configured. Without a key it stays out of the way and billing keeps its default.
 */
class MollieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing-mollie.php', 'billing-mollie');

        $config = $this->app->make(Config::class);
        $key = $config->get('billing-mollie.key');

        if (! is_string($key) || $key === '') {
            return;
        }

        $redirectUrl = $config->get('billing-mollie.redirect_url');
        $redirectUrl = is_string($redirectUrl) ? $redirectUrl : '';

        $this->app->singleton(MollieIntentCreator::class, static function () use ($key, $redirectUrl): MollieApiIntentCreator {
            $client = new MollieApiClient;
            $client->setApiKey($key);

            return new MollieApiIntentCreator($client, $redirectUrl);
        });

        $this->app->singleton(PaymentGateway::class, static fn (Application $app): MolliePaymentGateway => new MolliePaymentGateway(
            $app->make(MollieIntentCreator::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing-mollie.php' => $this->app->configPath('billing-mollie.php'),
            ], 'billing-mollie-config');
        }
    }
}
