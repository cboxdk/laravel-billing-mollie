<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Contracts\ProcessedEventStore;
use Cbox\Billing\Mollie\Contracts\SettledPaymentStore;
use Cbox\Billing\Mollie\Contracts\WebhookVerifier;
use Cbox\Billing\Mollie\Database\DatabaseProcessedEventStore;
use Cbox\Billing\Mollie\Database\DatabaseSettledPaymentStore;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * Binds the Mollie gateway as billing's PaymentGateway when an API key is configured,
 * and the idempotent webhook handler when both an API key (needed to fetch payment
 * status) and a webhook signing secret are configured. Without a key the provider
 * stays out of the way and billing keeps its default. The dedup/settlement stores
 * default to durable database implementations so idempotency survives across
 * processes and retries.
 */
class MollieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing-mollie.php', 'billing-mollie');

        $config = $this->app->make(Config::class);

        $this->registerStores();
        $this->registerGateway($config);
        $this->registerWebhook($config);
    }

    private function registerStores(): void
    {
        $this->app->singleton(ProcessedEventStore::class, static fn (Application $app): DatabaseProcessedEventStore => new DatabaseProcessedEventStore(
            $app->make(DatabaseManager::class)->connection(),
        ));

        $this->app->singleton(SettledPaymentStore::class, static fn (Application $app): DatabaseSettledPaymentStore => new DatabaseSettledPaymentStore(
            $app->make(DatabaseManager::class)->connection(),
        ));
    }

    private function registerGateway(Config $config): void
    {
        $key = $config->get('billing-mollie.key');

        if (! is_string($key) || $key === '') {
            return;
        }

        $redirectUrl = $config->get('billing-mollie.redirect_url');
        $redirectUrl = is_string($redirectUrl) ? $redirectUrl : '';

        $this->app->singleton(MollieApiClient::class, static function () use ($key): MollieApiClient {
            $client = new MollieApiClient;
            $client->setApiKey($key);

            return $client;
        });

        $this->app->singleton(MollieIntentCreator::class, static fn (Application $app): MollieApiIntentCreator => new MollieApiIntentCreator(
            $app->make(MollieApiClient::class),
            $redirectUrl,
        ));

        $this->app->singleton(PaymentFetcher::class, static fn (Application $app): MollieApiPaymentFetcher => new MollieApiPaymentFetcher(
            $app->make(MollieApiClient::class),
        ));

        $this->app->singleton(PaymentGateway::class, static fn (Application $app): MolliePaymentGateway => new MolliePaymentGateway(
            $app->make(MollieIntentCreator::class),
            $app->make(SettledPaymentStore::class),
        ));
    }

    private function registerWebhook(Config $config): void
    {
        $key = $config->get('billing-mollie.key');
        $webhookSecret = $config->get('billing-mollie.webhook_secret');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return;
        }

        $this->app->singleton(WebhookVerifier::class, static fn (): MollieApiWebhookVerifier => new MollieApiWebhookVerifier(
            new SignatureValidator($webhookSecret),
        ));

        // The handler needs to fetch payment status, which requires an API key.
        if (! is_string($key) || $key === '') {
            return;
        }

        $this->app->singleton(MollieWebhookHandler::class, static fn (Application $app): MollieWebhookHandler => new MollieWebhookHandler(
            $app->make(WebhookVerifier::class),
            $app->make(PaymentFetcher::class),
            $app->make(ProcessedEventStore::class),
            $app->make(SettledPaymentStore::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing-mollie.php' => $this->app->configPath('billing-mollie.php'),
            ], 'billing-mollie-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'billing-mollie-migrations');
        }
    }
}
