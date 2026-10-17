<?php

namespace App\Providers;

use App\Services\SettingsService;
use App\Support\Configs\BookingConfig;
use App\Support\Configs\ConfigFactory;
use App\Support\Configs\MediaConfig;
use App\Support\Configs\NotificationConfig;
use App\Support\Configs\PricingConfig;
use App\Support\Configs\ReferralConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Application\Freelancing\Contracts\AssistantInterface::class,
            \App\Infrastructure\AI\RuleBasedAssistant::class
        );
        // Settings Service - singleton (cached per request lifecycle)
        $this->app->singleton(SettingsService::class, function () {
            return new SettingsService();
        });

        // Config Factory - singleton (reuses config instances per request)
        $this->app->singleton(ConfigFactory::class, function ($app) {
            return new ConfigFactory($app->make(SettingsService::class));
        });

        // Convenience bindings for direct injection
        $this->app->bind(BookingConfig::class, function ($app) {
            return $app->make(ConfigFactory::class)->booking();
        });

        $this->app->bind(MediaConfig::class, function ($app) {
            return $app->make(ConfigFactory::class)->media();
        });

        $this->app->bind(PricingConfig::class, function ($app) {
            return $app->make(ConfigFactory::class)->pricing();
        });

        $this->app->bind(ReferralConfig::class, function ($app) {
            return $app->make(ConfigFactory::class)->referral();
        });

        $this->app->bind(NotificationConfig::class, function ($app) {
            return $app->make(ConfigFactory::class)->notification();
        });

        // Repositories
        $this->app->bind(\App\Repositories\Asset\AssetRepositoryInterface::class, \App\Repositories\Asset\AssetRepository::class);
        $this->app->bind(\App\Repositories\Booking\BookingRepositoryInterface::class, \App\Repositories\Booking\BookingRepository::class);

        // Phase 0.5 — Infrastructure Seams & Adapters
        $this->app->bind(
            \App\Domain\Payment\Contracts\PaymentGatewayInterface::class,
            \App\Infrastructure\Payments\Razorpay\RazorpayPaymentGateway::class
        );
        $this->app->bind(
            \App\Infrastructure\Auth\Contracts\GoogleIdentityProviderInterface::class,
            \App\Infrastructure\Auth\GoogleOAuthAdapter::class
        );
        $this->app->bind(
            \App\Infrastructure\ExchangeRates\Contracts\ExchangeRateApiClientInterface::class,
            \App\Infrastructure\ExchangeRates\ExchangeRateApiClient::class
        );

        // Phase 0.6 — Pricing Domain Boundary
        $this->app->bind(
            \App\Domain\Pricing\Contracts\PricingEngineInterface::class,
            \App\Domain\Pricing\CampaignPricingEngine::class
        );

        // Phase 0.9 — Payment Domain Boundary
        $this->app->bind(
            \App\Domain\Payment\Contracts\PaymentRepositoryInterface::class,
            \App\Infrastructure\Payments\Persistence\EloquentPaymentRepository::class
        );

        // Phase 0.10 — Financial Domain Boundary
        $this->app->bind(
            \App\Domain\Financial\Contracts\SettlementRepositoryInterface::class,
            \App\Infrastructure\Financial\Persistence\EloquentSettlementRepository::class
        );
        $this->app->bind(
            \App\Domain\Financial\Contracts\PartnerCommissionRepositoryInterface::class,
            \App\Infrastructure\Financial\Persistence\EloquentPartnerCommissionRepository::class
        );

        // Phase 0.11 — Reporting Query Layer
        $this->app->bind(
            \App\Application\Reporting\Exports\Contracts\ReportExporter::class,
            \App\Application\Reporting\Exports\CsvReportExporter::class
        );

        // Phase 0.12 — Notification & Media Boundaries
        $this->app->bind(
            \App\Infrastructure\Notifications\Contracts\MailContractInterface::class,
            \App\Infrastructure\Notifications\LaravelMailAdapter::class
        );
        $this->app->bind(
            \App\Infrastructure\Storage\Contracts\StorageContractInterface::class,
            \App\Infrastructure\Storage\LaravelStorageAdapter::class
        );
        $this->app->bind(
            \App\Infrastructure\Media\Contracts\ImageProcessingContractInterface::class,
            \App\Infrastructure\Media\GdImageProcessor::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Screen::observe(\App\Observers\AssetObserver::class);

        // Budget Planner recommendation endpoint (BP6 P0-2). The solver is
        // computationally expensive (full inventory × pricing oracle sweep), so
        // it is throttled per client IP. Public endpoint — no auth required.
        RateLimiter::for('recommendations', function (Request $request) {
            return [
                Limit::perMinute((int) config('app.recommendations_rate_per_minute', 30))
                    ->by('recommendations:' . $request->ip()),
                Limit::perHour((int) config('app.recommendations_rate_per_hour', 300))
                    ->by('recommendations:' . $request->ip()),
            ];
        });
    }
}
