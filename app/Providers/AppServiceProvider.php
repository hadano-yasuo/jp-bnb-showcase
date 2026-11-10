<?php

namespace App\Providers;

use App\Services\AI\ClaudeClient;
use App\Services\AI\ProofreadService;
use App\Services\AI\TranslationService;
use App\Services\Beds24\Beds24Client;
use App\Services\Beds24\Beds24PriceFetcher;
use App\Services\Stripe\StripeCheckoutService;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Beds24Client::class, function ($app) {
            $config = $app['config']['beds24'];

            return new Beds24Client(
                apiBase: $config['api_base'],
                refreshToken: $config['refresh_token'] ?? '',
                accessTokenCacheKey: $config['access_token_cache_key'],
                accessTokenTtlSeconds: $config['access_token_ttl_seconds'],
                httpTimeout: $config['http_timeout'],
            );
        });

        $this->app->singleton(Beds24PriceFetcher::class, function ($app) {
            return new Beds24PriceFetcher(
                client: $app->make(Beds24Client::class),
            );
        });

        $this->app->singleton(StripeClient::class, function ($app) {
            return new StripeClient((string) $app['config']['services.stripe.secret']);
        });

        $this->app->singleton(StripeCheckoutService::class, function ($app) {
            return new StripeCheckoutService(client: $app->make(StripeClient::class));
        });

        $this->app->singleton(ClaudeClient::class, function ($app) {
            $config = $app['config']['anthropic'];

            return new ClaudeClient(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) $config['base_url'],
                model: (string) $config['model'],
                apiVersion: (string) $config['api_version'],
                httpTimeout: (int) $config['http_timeout'],
            );
        });

        $this->app->singleton(TranslationService::class, function ($app) {
            $config = $app['config']['anthropic'];

            return new TranslationService(
                client: $app->make(ClaudeClient::class),
                translationModel: (string) $config['translation_model'],
            );
        });

        $this->app->singleton(ProofreadService::class, function ($app) {
            $config = $app['config']['anthropic'];

            return new ProofreadService(
                client: $app->make(ClaudeClient::class),
                model: (string) $config['translation_model'],
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
