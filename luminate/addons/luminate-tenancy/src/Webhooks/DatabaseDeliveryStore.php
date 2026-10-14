<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

use Illuminate\Support\Facades\DB;

/**
 * Postgres implementation. The table is tenant-owned (tenant_id + forced-by-trigger RLS), so every statement here is
 * scoped to the active tenant by the connection; there is no tenant filter to forget.
 */
final class DatabaseDeliveryStore implements DeliveryStore
{
    public const TABLE = 'luminate_webhook_deliveries';

    public function insert(string $deliveryId, string $event, string $body, int $now): int
    {
        return (int) DB::table(self::TABLE)->insertGetId([
            'delivery_id' => $deliveryId,
            'event' => $event,
            'body' => $body,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'id');
    }

    public function claim(int $id, int $leaseSeconds, int $now): ?Delivery
    {
        $rows = DB::select(
            'update ' . self::TABLE . ' set attempts = attempts + 1, next_attempt_at = ?, '
            . 'first_sent_at = coalesce(first_sent_at, ?), updated_at = ? '
            . "where id = ? and status = 'pending' and next_attempt_at <= ? "
            . 'returning id, delivery_id, event, body, attempts, first_sent_at, created_at',
            [$now + $leaseSeconds, $now, $now, $id, $now]
        );
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];

        return new Delivery((int) $r->id, (string) $r->delivery_id, (string) $r->event, (string) $r->body, (int) $r->attempts, (int) $r->first_sent_at, (int) $r->created_at);
    }

    public function markDelivered(int $id, int $httpStatus, int $now): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => 'delivered', 'last_status' => $httpStatus, 'last_error' => null, 'delivered_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function markFailed(int $id, ?int $httpStatus, string $error, int $nextAttemptAt, int $now): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'last_status' => $httpStatus, 'last_error' => mb_substr($error, 0, 300), 'next_attempt_at' => $nextAttemptAt, 'updated_at' => $now,
        ]);
    }

    public function markDead(int $id, ?int $httpStatus, string $error, int $now): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => 'dead', 'last_status' => $httpStatus, 'last_error' => mb_substr($error, 0, 300), 'updated_at' => $now,
        ]);
    }

    public function dueIds(int $now, int $limit): array
    {
        return DB::table(self::TABLE)
            ->where('status', 'pending')->where('next_attempt_at', '<=', $now)
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function prune(int $deliveredBefore, int $deadBefore): int
    {
        $a = DB::table(self::TABLE)->where('status', 'delivered')->where('delivered_at', '<', $deliveredBefore)->delete();
        $b = DB::table(self::TABLE)->where('status', 'dead')->where('updated_at', '<', $deadBefore)->delete();

        return $a + $b;
    }
}
