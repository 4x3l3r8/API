<?php

declare(strict_types=1);

namespace Luminate\Tenancy;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use Luminate\Tenancy\Context\CurrentTenant;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Http\Middleware\VerifyTenantContext;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/luminate-tenancy.php', 'luminate-tenancy');

        $this->app->singleton(CurrentTenant::class);

        $this->app->singleton(ContextTokenVerifier::class, function ($app) {
            $cfg = $app['config']['luminate-tenancy'];
            $keys = array_values(array_filter(
                [$cfg['keys']['current'] ?? null, $cfg['keys']['previous'] ?? null],
                static fn ($k) => is_string($k) && $k !== ''
            ));

            return new ContextTokenVerifier($keys, (int) $cfg['max_lifetime'], (int) $cfg['leeway']);
        });

        $this->app->singleton(PlacementResolver::class, function ($app) {
            $cfg = $app['config']['luminate-tenancy'];

            return new PlacementResolver($cfg['connections'], $cfg['allowed_buckets']);
        });

        $this->app->singleton(VerifyTenantContext::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/luminate-tenancy.php' => config_path('luminate-tenancy.php')], 'luminate-tenancy-config');

        // HTTP only: artisan/queue/scheduler get their tenant from the job payload and the local replica (contract §A.2).
        if (!$this->app->runningInConsole()) {
            $this->app->make(HttpKernel::class)->prependMiddleware(VerifyTenantContext::class);
        }
    }
}
