<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Testing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Queue\QueueTagger;
use Luminate\Tenancy\Replica\TenantReplica;

/**
 * TEST FIXTURE (registered only when LUMINATE_TENANCY_TEST_FIXTURES=true). Dispatches a ProbeJob either properly (as a tenant,
 * or with NO tenant) or with a deliberately bad tenant tag, so the isolation suite can prove workers refuse them.
 */
final class ProbeJobCommand extends Command
{
    protected $signature = 'luminate:tenancy:probe-job
        {label : identifies this job in the results}
        {tid? : dispatch while this tenant is active; omit to dispatch with NO tenant}
        {--forge=none : none|bad-sig|swap-tid|replay|unknown|inactive|signed|no-tag|garbage-tag}';
    protected $description = 'Test fixture: dispatch a ProbeJob, optionally with a forged tenant tag.';

    public function handle(TenantReplica $replica, TenantRuntime $runtime, QueueTagger $tagger): int
    {
        $label = (string) $this->argument('label');
        $tid = $this->argument('tid');
        $forge = (string) $this->option('forge');

        if ($forge === 'none') {
            if ($tid !== null) {
                $runtime->activate($replica->resolve((string) $tid, ['active']), longLivedProcess: true);
            }
            ProbeJob::dispatch($label);
            $runtime->deactivate(longLivedProcess: true);
            $this->info("dispatched {$label} as " . ($tid ?? 'NO TENANT'));

            return self::SUCCESS;
        }

        // Forged jobs are pushed raw so the dispatch-time tagging cannot "fix" them.
        $uuid = (string) Str::uuid();
        // Exactly what RedisQueue builds. `attempts` is NOT optional: Redis' pop script does `attempts + 1`, and a payload
        // without it makes the script error AFTER the pop, silently destroying the job before any worker code sees it.
        $payload = [
            'uuid' => $uuid,
            'id' => Str::random(32),
            'attempts' => 0,
            'displayName' => ProbeJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'retryUntil' => null,
            'data' => ['commandName' => ProbeJob::class, 'command' => serialize(new ProbeJob($label))],
        ];
        $victim = (string) ($tid ?? 't-alpha');

        switch ($forge) {
            case 'bad-sig':
                $payload[QueueTagger::PAYLOAD_KEY] = ['tid' => $victim, 'sig' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'];
                break;
            case 'swap-tid': // valid signature for tenant X, relabelled as tenant Y
                $tag = $tagger->tag($uuid, 't-alpha')[QueueTagger::PAYLOAD_KEY];
                $payload[QueueTagger::PAYLOAD_KEY] = ['tid' => 't-beta', 'sig' => $tag['sig']];
                break;
            case 'replay': // a genuine tag copied from ANOTHER job
                $payload[QueueTagger::PAYLOAD_KEY] = $tagger->tag((string) Str::uuid(), $victim)[QueueTagger::PAYLOAD_KEY];
                break;
            case 'unknown': // correctly signed, but the tenant is not in the replica
                $payload[QueueTagger::PAYLOAD_KEY] = $tagger->tag($uuid, 't-nobody')[QueueTagger::PAYLOAD_KEY];
                break;
            case 'inactive': // correctly signed, tenant exists but is suspended
                $payload[QueueTagger::PAYLOAD_KEY] = $tagger->tag($uuid, 't-gamma')[QueueTagger::PAYLOAD_KEY];
                break;
            case 'signed': // correctly signed for {tid}, whatever the replica says about it now (a tenant suspended after dispatch)
                $payload[QueueTagger::PAYLOAD_KEY] = $tagger->tag($uuid, $victim)[QueueTagger::PAYLOAD_KEY];
                break;
            case 'no-tag':
                break;
            case 'garbage-tag':
                $payload[QueueTagger::PAYLOAD_KEY] = 'not-an-object';
                break;
            default:
                $this->error("unknown --forge={$forge}");

                return self::FAILURE;
        }

        Queue::connection()->pushRaw(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->info("pushed forged job {$label} ({$forge})");

        return self::SUCCESS;
    }
}
