<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Log;
use Luminate\Tenancy\Context\CurrentTenant;
use Luminate\Tenancy\Context\TenantRuntime;

/**
 * Framework glue for queue isolation. Two halves:
 *
 *  1. DISPATCH: every job payload is stamped with a signed tenant tag taken from the tenant that is active when the job is
 *     created. A job created with no active tenant gets NO tag (and a warning), so a worker will refuse it.
 *  2. WORK: before a worker deserialises or runs anything (JobProcessing fires first in Worker::process), the tag is
 *     verified and the tenant checked against the local replica. Refused jobs are written to failed_jobs, deleted, and
 *     never run. Admitted jobs run with the tenant active; context is torn down after every job and every loop iteration.
 *
 * The refusal happens before the job class is unserialised, so no model is restored and no tenant query is made.
 */
final class TenantQueueGuard
{
    public function __construct(
        private readonly QueueTagger $tagger,
        private readonly QueueTenantGate $gate,
        private readonly TenantRuntime $runtime,
        private readonly CurrentTenant $current,
    ) {
    }

    /** Stamp the tenant tag on every job payload (HTTP, console, scheduler). */
    public function registerPayloadHook(): void
    {
        Queue::createPayloadUsing(function ($connection, $queue, $payload): array {
            $tid = $this->current->id();
            if ($tid === null) {
                Log::warning('queued job created with no active tenant; a worker will refuse it', [
                    'job' => $payload['displayName'] ?? ($payload['job'] ?? 'unknown'),
                    'queue' => $queue,
                ]);

                return [];
            }

            return $this->tagger->tag((string) $payload['uuid'], $tid);
        });
    }

    /** Worker side. Console only: HTTP never processes jobs, and sync jobs already run inside the caller's tenant. */
    public function registerWorkerHooks(Dispatcher $events, QueueManager $queue): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->job instanceof SyncJob) {
                return;
            }
            $this->runtime->deactivate(longLivedProcess: true);

            $admission = $this->gate->admit($event->job->payload());
            if (!$admission->admitted()) {
                $this->deadLetter($event, $admission->refusal);
            }
            $this->runtime->activate($admission->tenant, longLivedProcess: true);
        });

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $eventClass) {
            $events->listen($eventClass, function ($event): void {
                if (!$event->job instanceof SyncJob) {
                    $this->runtime->deactivate(longLivedProcess: true);
                }
            });
        }

        // nothing carries over between iterations of the worker loop
        $queue->looping(fn () => $this->runtime->deactivate(longLivedProcess: true));
    }

    private function deadLetter(JobProcessing $event, TagRejected $why): never
    {
        Log::critical('queue job refused: ' . $why->getMessage(), [
            'code' => $why->code(),
            'job' => $event->job->resolveName(),
            'id' => $event->job->getJobId(),
            'queue' => $event->job->getQueue(),
        ]);

        // Record FIRST: if the failer is down the job stays reserved and is handled by the normal retry path instead of vanishing.
        /** @var FailedJobProviderInterface $failer */
        $failer = app('queue.failer');
        $failer->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $why);
        $event->job->delete();

        // Aborts Worker::process before $job->fire(); the job is already deleted so it is not released.
        throw $why;
    }
}
