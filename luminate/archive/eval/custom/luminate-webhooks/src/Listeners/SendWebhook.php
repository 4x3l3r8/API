<?php

namespace Luminate\Webhooks\Listeners;

use Illuminate\Support\Str;
use Luminate\Webhooks\Jobs\DeliverWebhook;
use Luminate\Webhooks\LuminateWebhooksServiceProvider;

/**
 * SYNCHRONOUS on purpose. Wellms' Course* events keep $user/$course as private props on an abstract parent
 * and use SerializesModels, so a queued listener receives an uninitialised event. We therefore read the event
 * here (in-request), reduce it to plain values and queue a job that only carries scalars.
 */
class SendWebhook
{
    public function handle(object $event): void
    {
        $cfg = app('luminate.webhooks.config');
        if (empty($cfg['url'])) {
            return; // tenant has no webhook configured
        }

        $name = LuminateWebhooksServiceProvider::EVENTS[get_class($event)] ?? get_class($event);
        $payload = [
            'id' => (string) Str::uuid(),                       // stable delivery id (decided once, reused on retries)
            'type' => $name,
            'occurred_at' => now()->toIso8601String(),
            'tenant' => parse_url(config('app.url'), PHP_URL_HOST),
        ];

        if (method_exists($event, 'getUser')) {
            $u = $event->getUser();
            $payload['user'] = ['id' => $u->getKey(), 'email' => $u->email];
        }
        if (method_exists($event, 'getCourse')) {
            $c = $event->getCourse();
            $payload['course'] = ['id' => $c->getKey(), 'title' => $c->title, 'fields' => $c->fields ?? null];
        }
        if (method_exists($event, 'getTopic')) {
            $t = $event->getTopic();
            $payload['topic'] = ['id' => $t->getKey(), 'title' => $t->title, 'lesson_id' => $t->lesson_id];
        }
        if (method_exists($event, 'getAttempt')) {
            $a = $event->getAttempt();
            $payload['attempt'] = [
                'id' => $a->getKey(), 'quiz_id' => $a->topic_gift_quiz_id,
                'result_score' => $a->result_score, 'max_score' => $a->max_score,
                'result_percent' => $a->result_percent, 'is_passed' => $a->is_passed,
            ];
        }

        DeliverWebhook::dispatch($cfg['url'], $cfg['secret'], $payload);
    }
}
