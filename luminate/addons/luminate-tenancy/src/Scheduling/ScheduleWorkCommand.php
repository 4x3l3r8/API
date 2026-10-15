<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Scheduling;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Registry\RegistryStore;
use Luminate\Tenancy\Replica\ReplicaSource;
use Luminate\Tenancy\Replica\TenantReplica;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Per-tenant replacement for Wellms' stock scheduler (`while true; schedule:run; sleep 60`), which runs with NO tenant and
 * whose static MULTI_DOMAINS-style iteration does not exist here. Every minute, for each ACTIVE tenant in the local replica
 * (contract §A.2), it runs `artisan schedule:run` in a FRESH child process with LUMINATE_TENANT=<tid>.
 *
 * Why a child process per tenant per tick rather than looping in this process:
 *  - schedule:run keeps state in its command object (startedAt, eventsRan) and is not meant to be re-entered;
 *  - scheduled `command()` events spawn further processes, which inherit LUMINATE_TENANT, so they run as the tenant too;
 *  - nothing from one tenant's run (config, cache prefix, open handles, memory) can reach the next.
 *
 * Suspended/provisioning/unknown tenants are skipped with a logged reason. An unreadable replica skips the whole tick
 * (fail closed): there is never a tenant-less run. Cost is one PHP boot per tenant per minute, which is fine for tens of
 * tenants; a pooled executor would replace this for thousands.
 */
final class ScheduleWorkCommand extends Command
{
    protected $signature = 'luminate:schedule:work
        {--once : run one tick and exit; the exit code is non-zero if the tick was skipped or any tenant failed}
        {--concurrency=4 : tenants run in parallel}
        {--timeout=55 : seconds before one tenant\'s schedule:run is killed}
        {--heartbeat=/tmp/luminate-scheduler.heartbeat : file touched after every tick (used by the container healthcheck)}';
    protected $description = 'Run the Laravel scheduler once a minute for every active tenant in the replica.';

    public function handle(): int
    {
        while (true) {
            $exit = $this->tick();
            $this->maintain();
            @touch((string) $this->option('heartbeat'));

            if ($this->option('once')) {
                return $exit;
            }
            sleep(max(1, 60 - (int) date('s'))); // next minute boundary
        }
    }

    private ?int $lastMaintenance = null;

    /**
     * Global housekeeping (failed_jobs retention), once an hour and always on --once. It runs OUTSIDE any tenant on purpose: the
     * table it prunes is global. A failure here never fails the tick: tenants' scheduled work must not depend on it.
     */
    private function maintain(): void
    {
        $now = time();
        if (!$this->option('once') && $this->lastMaintenance !== null && $now - $this->lastMaintenance < 3600) {
            return;
        }
        $this->lastMaintenance = $now;

        $process = new Process([PHP_BINARY, base_path('artisan'), 'luminate:maintenance', '--no-interaction'], base_path(), ['LUMINATE_TENANT' => false], null, 120);
        $process->run();
        $this->line(trim($process->getOutput()) ?: json_encode(['event' => 'maintenance.no_output']));
        if (!$process->isSuccessful()) {
            Log::error('scheduler: luminate:maintenance failed', ['output' => substr($process->getErrorOutput() . $process->getOutput(), -1500)]);
        }
    }

    private ?int $lastReconcile = null;

    /** The reconciliation pull (contract A.2): safety net behind push. A child process, no tenant, never fails the tick. */
    private function reconcileIfDue(): void
    {
        if ((string) config('luminate-tenancy.registry.pull_url') === '' || config('luminate-tenancy.replica_driver') !== 'database') {
            return;
        }
        $now = time();
        $interval = max(10, (int) config('luminate-tenancy.registry.reconcile_interval'));
        if (!$this->option('once') && $this->lastReconcile !== null && $now - $this->lastReconcile < $interval) {
            return;
        }
        $this->lastReconcile = $now;

        $process = new Process([PHP_BINARY, base_path('artisan'), 'luminate:registry:reconcile', '--no-interaction'], base_path(), ['LUMINATE_TENANT' => false], null, 60);
        $process->run();
        $this->line(trim($process->getOutput()) ?: json_encode(['event' => 'registry.reconcile.no_output']));
        if (!$process->isSuccessful()) {
            Log::error('scheduler: registry reconcile failed', ['output' => substr($process->getErrorOutput() . $process->getOutput(), -1500)]);
        }
    }

    /** "Add a tenant = a row": a tenant that appears in the registry gets its baseline data within a tick, with no operator step. */
    private function provisionPending(): void
    {
        if (config('luminate-tenancy.replica_driver') !== 'database') {
            return;
        }
        try {
            $pending = app(RegistryStore::class)->pendingProvision();
        } catch (\Throwable $e) {
            Log::error('scheduler: could not read the registry for pending provisioning: ' . $e->getMessage());

            return;
        }
        foreach ($pending as $tid) {
            $process = new Process([PHP_BINARY, base_path('artisan'), 'luminate:tenant:provision', $tid, '--no-interaction'], base_path(), ['LUMINATE_TENANT' => false], null, 300);
            $process->run();
            $ok = $process->isSuccessful();
            $this->line(json_encode(['event' => $ok ? 'scheduler.provisioned' : 'scheduler.provision_failed', 'tenant' => $tid]));
            if (!$ok) {
                Log::error("scheduler: provisioning {$tid} failed", ['output' => substr($process->getErrorOutput() . $process->getOutput(), -1500)]);
            }
        }
    }

    private function tick(): int
    {
        // Registry housekeeping first, so a tenant that just appeared is provisioned and one just suspended is skipped this tick.
        // Both are outside any tenant and neither can fail the tick: bounded staleness is allowed (contract A.2).
        $this->reconcileIfDue();
        $this->provisionPending();

        // a fresh replica object every tick: status changes and new tenants apply without a restart
        $replica = new TenantReplica(app(ReplicaSource::class), app(PlacementResolver::class));

        try {
            $selection = (new ActiveTenantSelector())->select($replica);
        } catch (\Throwable $e) {
            Log::critical('scheduler tick skipped: tenant replica unreadable: ' . $e->getMessage());
            $this->error('scheduler tick skipped (replica unreadable): ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($selection['skipped'] as $tid => $reason) {
            $this->line(json_encode(['event' => 'scheduler.skip', 'tenant' => $tid, 'reason' => $reason]));
        }

        $failed = 0;
        $queue = $selection['run'];
        /** @var array<string,array{0:Process,1:float}> $running */
        $running = [];
        $concurrency = max(1, (int) $this->option('concurrency'));

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $concurrency) {
                $tid = array_shift($queue);
                $process = new Process(
                    [PHP_BINARY, base_path('artisan'), 'schedule:run', '--no-interaction'],
                    base_path(),
                    ['LUMINATE_TENANT' => $tid],
                    null,
                    (float) $this->option('timeout')
                );
                $process->start();
                $running[$tid] = [$process, microtime(true)];
            }

            foreach ($running as $tid => [$process, $started]) {
                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException) {
                    $failed++;
                    $this->line(json_encode(['event' => 'scheduler.timeout', 'tenant' => $tid, 'seconds' => (int) $this->option('timeout')]));
                    Log::error("scheduler: schedule:run for {$tid} timed out and was killed");
                    unset($running[$tid]);
                    continue;
                }
                if (!$process->isRunning()) {
                    $ok = $process->isSuccessful();
                    $failed += $ok ? 0 : 1;
                    $this->line(json_encode([
                        'event' => $ok ? 'scheduler.ran' : 'scheduler.failed',
                        'tenant' => $tid,
                        'exit' => $process->getExitCode(),
                        'ms' => (int) round((microtime(true) - $started) * 1000),
                    ]));
                    if (!$ok) {
                        Log::error("scheduler: schedule:run for {$tid} failed", ['output' => substr($process->getErrorOutput() . $process->getOutput(), -1500)]);
                    }
                    unset($running[$tid]);
                }
            }
            usleep(100_000);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
