<?php

declare(strict_types=1);

namespace Luminate\Tenancy;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Luminate\Tenancy\Auth\TenantAccessToken;
use Luminate\Tenancy\Auth\TenantTokenBinding;
use Luminate\Tenancy\Console\ProvisionTenantCommand;
use Luminate\Tenancy\Console\StatusCommand;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use Luminate\Tenancy\Context\CurrentTenant;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Database\TenantPostgresConnection;
use Luminate\Tenancy\Http\Middleware\VerifyTenantContext;
use Luminate\Tenancy\Queue\QueueTagger;
use Luminate\Tenancy\Queue\QueueTenantGate;
use Luminate\Tenancy\Queue\TenantQueueGuard;
use Luminate\Tenancy\Replica\TenantReplica;
use Luminate\Tenancy\Testing\ProbeJobCommand;

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

        // Wellms ships `queue.failed` without a driver, so Laravel falls back to the legacy provider that never writes the
        // `uuid` column, while the failed_jobs table declares it NOT NULL: stock Wellms silently loses EVERY failed job.
        // Dead-lettering refused jobs depends on this working, so select the uuid-aware provider.
        $this->app['config']->set('queue.failed.driver', 'database-uuids');

        $this->app->singleton(ContextTokenVerifier::class, function ($app) {
            $cfg = $app['config']['luminate-tenancy'];

            return new ContextTokenVerifier($this->signingKeys($cfg), (int) $cfg['max_lifetime'], (int) $cfg['leeway']);
        });

        $this->app->singleton(PlacementResolver::class, function ($app) {
            $cfg = $app['config']['luminate-tenancy'];

            return new PlacementResolver($cfg['connections'], $cfg['allowed_buckets']);
        });

        $this->app->singleton(TenantReplica::class, fn ($app) => new TenantReplica(
            (string) $app['config']['luminate-tenancy.replica_path'],
            $app->make(PlacementResolver::class)
        ));

        $this->app->singleton(QueueTagger::class, fn ($app) => new QueueTagger($this->signingKeys($app['config']['luminate-tenancy'])));
        $this->app->singleton(QueueTenantGate::class, fn ($app) => new QueueTenantGate($app->make(QueueTagger::class), $app->make(TenantReplica::class)));
        $this->app->singleton(TenantQueueGuard::class);

        $this->app->singleton(TenantTokenBinding::class);
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

        // Every Passport access token (login, impersonate, personal tokens) is stamped with the issuing tenant (contract §D.5).
        if (class_exists(Passport::class)) {
            Passport::useAccessTokenEntity(TenantAccessToken::class);
        }

        // Every job created anywhere (request, command, scheduler) is stamped with the active tenant.
        $guard = $this->app->make(TenantQueueGuard::class);
        $guard->registerPayloadHook();

        if ($this->app->runningInConsole()) {
            // Workers have no signed request: they verify the job's tag and read status/placement from the local replica (§A.2).
            $guard->registerWorkerHooks($this->app['events'], $this->app['queue']);

            $commands = [StatusCommand::class, ProvisionTenantCommand::class];
            if ((bool) $this->app['config']['luminate-tenancy.fixtures']) {
                $commands[] = ProbeJobCommand::class;
            }
            $this->commands($commands);

            return;
        }

        $this->app->make(HttpKernel::class)->prependMiddleware(VerifyTenantContext::class);
    }

    /** @return list<string> current key first, then the optional previous key */
    private function signingKeys(array $cfg): array
    {
        return array_values(array_filter(
            [$cfg['keys']['current'] ?? null, $cfg['keys']['previous'] ?? null],
            static fn ($k) => is_string($k) && $k !== ''
        ));
    }
}
