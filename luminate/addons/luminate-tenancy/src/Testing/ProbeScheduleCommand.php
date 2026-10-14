<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Testing;

use Illuminate\Console\Command;

/**
 * TEST FIXTURE (registered only when LUMINATE_TENANCY_TEST_FIXTURES=true). Scheduled as a `command()` event, which Laravel runs
 * in a separate child process: proves the tenant reaches grandchild processes through LUMINATE_TENANT.
 */
final class ProbeScheduleCommand extends Command
{
    protected $signature = 'luminate:tenancy:probe-schedule';
    protected $description = 'Test fixture: record the tenant context of a scheduled command (child process).';

    public function handle(): int
    {
        ProbeSnapshot::record('schedule-command');

        return self::SUCCESS;
    }
}
