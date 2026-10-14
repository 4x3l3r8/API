<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;

/**
 * Global (un-tenanted) housekeeping, run hourly by the scheduler service OUTSIDE any tenant.
 *
 * `failed_jobs` is a global table: one row per dead-lettered job for every tenant, holding the serialized job (and so
 * tenant data). It must not live forever. Retention is `failed_jobs_retention_hours` (default 14 days): a job that has sat
 * dead that long is stale (see the async-work inventory behind contract §A.2), so an operator who wants to replay one has
 * a bounded window, and tenant data does not accumulate in an un-tenanted table.
 */
final class MaintenanceCommand extends Command
{
    protected $signature = 'luminate:maintenance';
    protected $description = 'Prune old rows from the global failed_jobs table.';

    public function handle(): int
    {
        $hours = (int) config('luminate-tenancy.failed_jobs_retention_hours');
        if ($hours < 1) {
            $this->line(json_encode(['event' => 'maintenance.failed_jobs', 'skipped' => 'retention disabled']));

            return self::SUCCESS;
        }
        $failer = app('queue.failer');
        if (!$failer instanceof PrunableFailedJobProvider) {
            $this->error('failed-job provider cannot be pruned: ' . $failer::class);

            return self::FAILURE;
        }
        $deleted = $failer->prune(Carbon::now()->subHours($hours));
        $this->line(json_encode(['event' => 'maintenance.failed_jobs', 'retention_hours' => $hours, 'deleted' => $deleted]));

        return self::SUCCESS;
    }
}
