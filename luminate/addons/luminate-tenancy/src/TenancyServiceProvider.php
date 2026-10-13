<?php

declare(strict_types=1);

namespace Luminate\Tenancy;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Luminate\Tenancy\Console\ProvisionTenantCommand;
use Luminate\Tenancy\Console\StatusCommand;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use Luminate\Tenancy\Context\CurrentTenant;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Database\TenantPostgresConnection;
use Luminate\Tenancy\Http\Middleware\VerifyTenantContext;
use Luminate\Tenancy\Replica\TenantReplica;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/luminate-tenancy.php', 'luminate-tenancy');

        $this->app->singleton(CurrentTenant::class);
        $this->app->singleton(TenantRuntime::class);

        // No tenant => an isolated cache namespace no tenant uses. Set in register() so it is in place before any provider's
        // boot() reads the cache (e.g. escolalms/settings loading DB-backed config at boot, which runs before the middleware).
        $this->app['config']->set('cache.prefix', TenantRuntime::UNTENANTED);

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

        $this->app->singleton(TenantReplica::class, fn ($app) => new TenantReplica(
            (string) $app['config']['luminate-tenancy.replica_path'],
            $app->make(PlacementResolver::class)
        ));

        $this->app->singleton(VerifyTenantContext::class);

        // Every pgsql connection scopes its statements to the current tenant with a transaction-local setting.
        // Registered in register() so it is in place before any other provider resolves a connection.
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return (new TenantPostgresConnection($connection, $database, $prefix, $config))
                ->setTenantResolver(fn (): ?string => app(CurrentTenant::class)->id());
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/luminate-tenancy.php' => config_path('luminate-tenancy.php')], 'luminate-tenancy-config');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([StatusCommand::class, ProvisionTenantCommand::class]);

            return;
        }

        // HTTP only: artisan/queue/scheduler get their tenant from the job payload and the local replica (contract §A.2).
        $this->app->make(HttpKernel::class)->prependMiddleware(VerifyTenantContext::class);
    }
}
