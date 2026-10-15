<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Console;

use Illuminate\Console\Command;
use Luminate\Tenancy\Registry\Reconciler;
use Luminate\Tenancy\Registry\RegistryRejected;

/**
 * The reconciliation pull (contract A.2). Tenant-less by nature (it runs OUTSIDE any tenant); the per-tenant scheduler runs it once
 * per `registry.reconcile_interval`, and an operator can run it by hand.
 */
final class ReconcileRegistryCommand extends Command
{
    protected $signature = 'luminate:registry:reconcile {--allow-empty : accept an empty authoritative list (removes every tenant)}';
    protected $description = 'Pull the signed tenant list from Luminate and converge the local registry replica on it.';

    public function handle(Reconciler $reconciler): int
    {
        try {
            $report = $reconciler->run((bool) $this->option('allow-empty'));
        } catch (RegistryRejected $e) {
            $this->line(json_encode(['event' => 'registry.reconcile.failed', 'code' => $e->reason, 'error' => $e->getMessage()]));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->line(json_encode(['event' => 'registry.reconcile.failed', 'error' => $e->getMessage()]));

            return self::FAILURE;
        }
        $this->line(json_encode(['event' => 'registry.reconcile.ok'] + $report));

        return self::SUCCESS;
    }
}
