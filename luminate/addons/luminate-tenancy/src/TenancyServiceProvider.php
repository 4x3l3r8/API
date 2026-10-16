<?php

declare(strict_types=1);

namespace Luminate\Tenancy;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Luminate\Tenancy\Auth\TenantAccessToken;
use Luminate\Tenancy\Auth\TenantTokenBinding;
use Luminate\Tenancy\Console\ImportRegistryCommand;
use Luminate\Tenancy\Console\MaintenanceCommand;
use Luminate\Tenancy\Console\ReconcileRegistryCommand;
use Luminate\Tenancy\Console\ProvisionTenantCommand;
use Luminate\Tenancy\Http\Middleware\AddFullyGraded;
use Luminate\Tenancy\Scheduling\ScheduleGuard;
use Luminate\Tenancy\Webhooks\Backoff;
use Luminate\Tenancy\Webhooks\Console\DispatchDueWebhooksCommand;
use Luminate\Tenancy\Webhooks\Console\PruneWebhooksCommand;
use Luminate\Tenancy\Webhooks\DatabaseDeliveryStore;
use Luminate\Tenancy\Webhooks\DeliveryService;
use Luminate\Tenancy\Webhooks\DeliveryStore;
use Luminate\Tenancy\Webhooks\EventMapper;
use Luminate\Tenancy\Webhooks\GuzzleSender;
use Luminate\Tenancy\Webhooks\RecordWebhookEvent;
use Luminate\Tenancy\Webhooks\Sender;
use Luminate\Tenancy\Webhooks\WebhookSettings;
use Luminate\Tenancy\Console\StatusCommand;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use Luminate\Tenancy\Context\CurrentTenant;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Database\AppRoleGuard;
use Luminate\Tenancy\Database\PgsqlOptions;
use Luminate\Tenancy\Database\TenantPostgresConnection;
use Luminate\Tenancy\Http\Middleware\VerifyTenantContext;
use Luminate\Tenancy\Queue\QueueTagger;
use Luminate\Tenancy\Queue\QueueTenantGate;
use Luminate\Tenancy\Queue\TenantQueueGuard;
use Luminate\Tenancy\Registry\DatabaseRegistryStore;
use Luminate\Tenancy\Registry\GuzzleRegistryFetcher;
use Luminate\Tenancy\Registry\Reconciler;
use Luminate\Tenancy\Registry\RegistryAuth;
use Luminate\Tenancy\Registry\RegistryFetcher;
use Luminate\Tenancy\Registry\RegistryStore;
use Luminate\Tenancy\Replica\DatabaseReplicaSource;
use Luminate\Tenancy\Replica\FileReplicaSource;
use Luminate\Tenancy\Replica\ReplicaSource;
use Luminate\Tenancy\Replica\TenantReplica;
use Luminate\Tenancy\Scheduling\ScheduleWorkCommand;
use Luminate\Tenancy\Testing\ProbeJobCommand;
use Luminate\Tenancy\Testing\ProbeScheduleCommand;
use Luminate\Tenancy\Testing\ProbeSnapshot;
use Luminate\Tenancy\Testing\ProbeStorageCommand;

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

        // Registry replica (contract §A.2). The source is NOT a singleton: the scheduler builds a fresh one per tick on purpose.
        $this->app->singleton(RegistryStore::class, DatabaseRegistryStore::class);
        $this->app->bind(ReplicaSource::class, function ($app) {
            $cfg = $app['config']['luminate-tenancy'];

            return match ((string) $cfg['replica_driver']) {
                'database' => new DatabaseReplicaSource($app->make(RegistryStore::class), (int) $cfg['replica_cache_ttl']),
                'file' => new FileReplicaSource((string) $cfg['replica_path']),
                default => throw new \RuntimeException("Unknown luminate-tenancy.replica_driver '{$cfg['replica_driver']}' (database|file)"),
            };
        });
        $this->app->singleton(TenantReplica::class, fn ($app) => new TenantReplica(
            $app->make(ReplicaSource::class),
            $app->make(PlacementResolver::class)
        ));
        $this->app->singleton(RegistryAuth::class, fn ($app) => new RegistryAuth(
            $this->registryKeys((array) $app['config']['luminate-tenancy']),
            (int) $app['config']['luminate-tenancy.registry.window']
        ));
        $this->app->singleton(RegistryFetcher::class, fn ($app) => new GuzzleRegistryFetcher(
            [
                'url' => $app['config']['luminate-tenancy.registry.pull_url'],
                'allow_http' => (bool) $app['config']['luminate-tenancy.registry.allow_http'],
                'timeout' => (int) $app['config']['luminate-tenancy.registry.timeout'],
                'connect_timeout' => (int) $app['config']['luminate-tenancy.registry.connect_timeout'],
            ],
            $app->make(RegistryAuth::class)
        ));
        $this->app->singleton(Reconciler::class, fn ($app) => new Reconciler(
            $app->make(RegistryFetcher::class),
            $app->make(RegistryStore::class),
            $app->make(RegistryAuth::class),
            (int) $app['config']['luminate-tenancy.registry.skew']
        ));

        $this->app->singleton(QueueTagger::class, fn ($app) => new QueueTagger($this->signingKeys($app['config']['luminate-tenancy'])));
        $this->app->singleton(QueueTenantGate::class, fn ($app) => new QueueTenantGate(
            $app->make(QueueTagger::class),
            $app->make(TenantReplica::class),
            (array) $app['config']['luminate-tenancy.denied_jobs']
        ));

        // Signed webhooks (contract §F). The outbox row is authoritative; queue jobs and the sweeper only move it along.
        $this->app->singleton(WebhookSettings::class, fn ($app) => WebhookSettings::fromConfig((array) $app['config']['luminate-tenancy.webhooks']));
        $this->app->singleton(EventMapper::class, fn ($app) => new EventMapper((array) $app['config']['luminate-tenancy.webhooks.events']));
        $this->app->singleton(DeliveryStore::class, DatabaseDeliveryStore::class);
        $this->app->singleton(Sender::class, GuzzleSender::class);
        $this->app->singleton(Backoff::class, fn ($app) => new Backoff(
            array_values((array) $app['config']['luminate-tenancy.webhooks.backoff']),
            (int) $app['config']['luminate-tenancy.webhooks.max_age']
        ));
        $this->app->singleton(DeliveryService::class, fn ($app) => new DeliveryService(
            $app->make(DeliveryStore::class),
            $app->make(Sender::class),
            $app->make(WebhookSettings::class),
            $app->make(Backoff::class),
            static fn (): int => time(),
            (int) $app['config']['luminate-tenancy.webhooks.lease']
        ));
        $this->app->singleton(RecordWebhookEvent::class);
        $this->app->singleton(TenantQueueGuard::class);

        $this->app->singleton(AppRoleGuard::class, function ($app) {
            $conn = $app['config']['database.default'];

            return new AppRoleGuard(
                (array) $app['config']['luminate-tenancy.forbidden_db_users'],
                (string) $app['config']["database.connections.{$conn}.username"]
            );
        });
        $this->app->singleton(TenantTokenBinding::class);
        $this->app->singleton(VerifyTenantContext::class);

        // The registry writer connection (contract A.2.3): the default connection's settings, a different login.
        $writer = (string) $this->app['config']['luminate-tenancy.registry.db.username'];
        if ($writer !== '') {
            $default = (string) $this->app['config']['database.default'];
            $this->app['config']->set('database.connections.' . DatabaseRegistryStore::WRITER, array_merge(
                (array) $this->app['config']["database.connections.{$default}"],
                ['username' => $writer, 'password' => (string) $this->app['config']['luminate-tenancy.registry.db.password']]
            ));
        }

        // Behind PgBouncer in transaction mode, pdo_pgsql's named server-side prepared statements abort the transaction (see PgsqlOptions).
        // Applied before any connection exists. Identical behaviour over a direct connection, so it is on in every topology.
        if ((bool) $this->app['config']['luminate-tenancy.pooler_safe_prepares']) {
            $this->app['config']->set('database.connections', PgsqlOptions::forTransactionPooling((array) $this->app['config']['database.connections']));
        }

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

        // Wellms domain events -> webhook outbox. Synchronous listeners (see RecordWebhookEvent); a no-op until a receiver is configured.
        foreach (array_keys((array) $this->app['config']['luminate-tenancy.webhooks.events']) as $eventClass) {
            $this->app['events']->listen($eventClass, [RecordWebhookEvent::class, 'handle']);
        }

        if ($this->app->runningInConsole()) {
            // Workers have no signed request: they verify the job's tag and read status/placement from the local replica (§A.2).
            $guard->registerWorkerHooks($this->app['events'], $this->app['queue']);

            $commands = [
                StatusCommand::class, ProvisionTenantCommand::class, ScheduleWorkCommand::class, MaintenanceCommand::class,
                DispatchDueWebhooksCommand::class, PruneWebhooksCommand::class, ReconcileRegistryCommand::class, ImportRegistryCommand::class,
            ];
            if ((bool) $this->app['config']['luminate-tenancy.fixtures']) {
                $commands[] = ProbeJobCommand::class;
                $commands[] = ProbeStorageCommand::class;
                $commands[] = ProbeScheduleCommand::class;

                // one in-process event and one child-process event, so the suite can prove both run as the tenant
                $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                    $schedule->call(fn () => ProbeSnapshot::record('schedule-closure'))->everyMinute()->name('luminate-probe-closure');
                    $schedule->command('luminate:tenancy:probe-schedule')->everyMinute();
                });
            }
            $this->commands($commands);

            // Self-healing delivery + retention, run for each ACTIVE tenant by the per-tenant scheduler.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if ($this->app->make(WebhookSettings::class)->enabled()) {
                    $schedule->command('luminate:webhooks:dispatch-due')->everyMinute();
                    $schedule->command('luminate:webhooks:prune')->dailyAt('03:17');
                }
            });

            // Contract §H: Wellms never autonomously moves money. Runs AFTER the framework has built the schedule (package
            // providers and app/Console/Kernel.php), so every event exists to be skipped. See ScheduleGuard.
            $this->app->booted(function (): void {
                $patterns = array_values((array) $this->app['config']['luminate-tenancy.disabled_schedules']);
                if ($patterns !== []) {
                    (new ScheduleGuard())->disable($this->app->make(Schedule::class), $patterns);
                }
            });

            // LUMINATE_TENANT=<tid> makes ANY artisan command start as that tenant (scheduled command() events are child
            // processes and inherit it). Fail closed: an unknown or non-active tenant aborts the command.
            $this->app->booted(fn () => $this->activateTenantFromEnvironment());

            return;
        }

        // Registry push endpoints (contract §A.2): no tenant context, authenticated by their own HMAC.
        $this->loadRoutesFrom(__DIR__ . '/../routes/registry.php');
        // Protected media delivery: stream a topic's file from the tenant's storage (contract D.6 fallback).
        $this->loadRoutesFrom(__DIR__ . '/../routes/media.php');

        $kernel = $this->app->make(HttpKernel::class);
        $kernel->prependMiddleware(VerifyTenantContext::class);
        $kernel->pushMiddleware(AddFullyGraded::class); // contract v5 §F: is_fully_graded on the attempts list/detail
    }

    private function activateTenantFromEnvironment(): void
    {
        $tid = getenv('LUMINATE_TENANT');
        if (!is_string($tid) || $tid === '') {
            return;
        }

        try {
            $resolved = $this->app->make(TenantReplica::class)->resolve($tid, ['active']);
        } catch (\Throwable $e) {
            throw new \RuntimeException("LUMINATE_TENANT={$tid} refused: tenant is unknown, not active, or its placement is unsupported here (" . $e::class . ').');
        }
        $this->app->make(TenantRuntime::class)->activate($resolved, longLivedProcess: true);
    }

    /**
     * The registry key(s). A registry key that equals a tenant-context key is refused outright (registry sync then answers 503):
     * a leaked context key must not be able to rewrite the registry.
     *
     * @return list<string>
     */
    private function registryKeys(array $cfg): array
    {
        $keys = array_values(array_filter(
            [$cfg['registry']['keys']['current'] ?? null, $cfg['registry']['keys']['previous'] ?? null],
            static fn ($k) => is_string($k) && $k !== ''
        ));

        return array_intersect($keys, $this->signingKeys($cfg)) === [] ? $keys : [];
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
