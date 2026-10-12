<?php

namespace Luminate\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/** Carries only scalars/arrays, so serialisation is safe. Retries with backoff on any non-2xx. */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(private string $url, private string $secret, private array $payload)
    {
    }

    public function backoff(): array
    {
        return [5, 20, 60, 300];
    }

    public function handle(): void
    {
        $body = json_encode($this->payload);
        Http::withHeaders([
            'X-Wellms-Event' => $this->payload['type'],
            'X-Wellms-Delivery' => $this->payload['id'],
            'X-Wellms-Signature' => 'sha256=' . hash_hmac('sha256', $body, $this->secret),
        ])->timeout(10)->withBody($body, 'application/json')->post($this->url)->throw();
    }
}
