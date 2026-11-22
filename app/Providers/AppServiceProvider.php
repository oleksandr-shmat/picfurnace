<?php

namespace App\Providers;

use App\Billing\Billing;
use App\Billing\NoBalanceBilling;
use App\Generations\GenerationDispatcher;
use App\Generations\GenerationLifecycle;
use App\Generations\GenerationSubmitter;
use App\Generations\Images\ImageArchive;
use App\Pixelforge\Api\Limits;
use App\Pixelforge\Client\CachedPricing;
use App\Pixelforge\Client\PixelforgeClient;
use App\Pixelforge\Webhook\WebhookSignature;
use App\Quoting\Markup;
use App\Quoting\Quoter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Limits::class, fn () => new Limits(
            config('pixelforge.limits.max_prompt_characters'),
            config('pixelforge.limits.max_reference_images'),
        ));
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            (string) config('pixelforge.base_url'),
            (string) config('pixelforge.api_key'),
        ));
        $this->app->singleton(CachedPricing::class, fn ($app) => new CachedPricing(
            $app->make(PixelforgeClient::class),
            config('pixelforge.pricing_fresh_seconds'),
            config('pixelforge.pricing_stale_seconds'),
        ));
        $this->app->singleton(Billing::class, NoBalanceBilling::class);
        $this->app->singleton(GenerationDispatcher::class, fn ($app) => new GenerationDispatcher(
            $app->make(PixelforgeClient::class),
            $app->make(GenerationLifecycle::class),
            config('pixelforge.max_concurrent_generations'),
        ));
        $this->app->singleton(GenerationSubmitter::class, fn ($app) => new GenerationSubmitter(
            $app->make(Quoter::class),
            $app->make(Billing::class),
            config('pixelforge.max_unfinished_generations_per_user'),
        ));
        $this->app->singleton(WebhookSignature::class, fn () => new WebhookSignature((string) config('pixelforge.webhook_secret')));
        $this->app->singleton(ImageArchive::class, fn () => new ImageArchive(config('images.disk'), config('images.retention_days')));
        $this->app->singleton(Markup::class, fn () => new Markup(config('billing.markup_percent')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
