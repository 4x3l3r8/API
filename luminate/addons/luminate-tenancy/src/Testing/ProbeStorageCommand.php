<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Testing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Replica\TenantReplica;

/**
 * TEST FIXTURE (registered only when LUMINATE_TENANCY_TEST_FIXTURES=true). Writes a small object through the default (S3) disk and
 * the local disk, with the given tenant active or with NO tenant, and prints where the bytes went.
 */
final class ProbeStorageCommand extends Command
{
    protected $signature = 'luminate:tenancy:probe-storage {label} {tid? : omit to run with NO tenant}';
    protected $description = 'Test fixture: write through the S3 and local disks and report the resulting locations.';

    public function handle(TenantReplica $replica, TenantRuntime $runtime): int
    {
        if (($tid = $this->argument('tid')) !== null) {
            $runtime->activate($replica->resolve((string) $tid, ['active']), longLivedProcess: true);
        } else {
            $runtime->deactivate(longLivedProcess: true);
        }

        $key = 'luminate-probe/' . $this->argument('label') . '.txt';
        Storage::put($key, (string) $this->argument('label'));
        Storage::disk('local')->put($key, (string) $this->argument('label'));

        $this->line(json_encode([
            'tenant' => $tid,
            's3_bucket' => config('filesystems.disks.s3.bucket'),
            's3_key' => Storage::path($key),
            'url' => Storage::url($key),
            'local_path' => Storage::disk('local')->path($key),
        ]));

        return self::SUCCESS;
    }
}
