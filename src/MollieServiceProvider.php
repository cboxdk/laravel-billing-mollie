<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Database\DatabaseProcessedEventStore;
use Cbox\Billing\Mollie\Database\DatabaseSettledPaymentStore;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * Binds the Mollie gateway as billing's PaymentGateway when an API key is configured,
 * and the Mollie-backed webhook verifier when both an API key (needed to fetch payment
 * status) and a webhook signing secret are configured. Without a key the provider stays
 * out of the way and billing keeps its default.
 *
 * The refactor onto the shared webhook seam: this adapter no longer owns a verifier
 * contract, dedup/settle stores, or ingest logic. It overrides the engine's shared
 * {@see ProcessedEventStore} and {@see SettledPaymentStore} with durable database
 * implementations (so idempotency survives across processes and retries) and binds the
 * gateway-specific {@see WebhookVerifier}, which fetches the payment through the
 * {@see PaymentFetcher} seam; the engine's own {@see WebhookIngest} then applies the paid
 * effect exactly once over those durable stores.
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

        $setupAmount = $config->get('billing-mollie.setup_amount');
        $setupAmount = is_string($setupAmount) ? $setupAmount : '0.00';

        $setupCurrency = $config->get('billing-mollie.setup_currency');
        $setupCurrency = is_string($setupCurrency) ? $setupCurrency : 'EUR';

        $profileId = $config->get('billing-mollie.profile_id');
        $profileId = is_string($profileId) ? $profileId : '';

        $this->app->singleton(MollieApiClient::class, static function () use ($key): MollieApiClient {
            $client = new MollieApiClient;
            $client->setApiKey($key);

            return $client;
        });

        $this->app->singleton(MollieIntentCreator::class, static fn (Application $app): MollieApiIntentCreator => new MollieApiIntentCreator(
            $app->make(MollieApiClient::class),
            $redirectUrl,
            $setupAmount,
            $setupCurrency,
        ));

        $this->app->singleton(PaymentFetcher::class, static fn (Application $app): MollieApiPaymentFetcher => new MollieApiPaymentFetcher(
            $app->make(MollieApiClient::class),
        ));

        $this->app->singleton(PaymentGateway::class, static fn (Application $app): MolliePaymentGateway => new MolliePaymentGateway(
            $app->make(MollieIntentCreator::class),
            $app->make(SettledPaymentStore::class),
            $profileId,
        ));
    }

    private function registerWebhook(Config $config): void
    {
        $key = $config->get('billing-mollie.key');
        $webhookSecret = $config->get('billing-mollie.webhook_secret');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return;
        }

        // The verifier fetches payment status through the PaymentFetcher seam, which
        // needs an API key; without one the webhook cannot be normalised.
        if (! is_string($key) || $key === '') {
            return;
        }

        $this->app->singleton(WebhookVerifier::class, static fn (Application $app): MollieApiWebhookVerifier => new MollieApiWebhookVerifier(
            new SignatureValidator($webhookSecret),
            $app->make(PaymentFetcher::class),
        ));

        $this->app->singleton(MollieWebhookHandler::class, static fn (Application $app): MollieWebhookHandler => new MollieWebhookHandler(
            $app->make(WebhookVerifier::class),
            $app->make(WebhookIngest::class),
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
