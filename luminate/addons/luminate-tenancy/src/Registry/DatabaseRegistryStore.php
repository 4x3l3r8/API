<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

use Illuminate\Database\QueryException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Postgres implementation. WRITES go through a separate connection (`luminate_registry`) that logs in as a dedicated writer role; the
 * application role is read-only on these tables (contract A.2.3). So SQL injection or a mass-assignment bug under any tenant, all of
 * which run as the application role, cannot rewrite tenant status or placement. READS use the ordinary connection.
 *
 * The tables are registered as tenancy-exempt before they are created (see the migration), so the DDL
 * event trigger leaves them tenant-less and these statements run with or without an active tenant.
 */
final class DatabaseRegistryStore implements RegistryStore
{
    public const TABLE = 'luminate_tenant_registry';
    public const META = 'luminate_registry_meta';
    public const WRITER = 'luminate_registry';

    /** @throws RegistryRejected when no writer role is configured: without it nothing can change the registry (fail closed) */
    private function writer(): Connection
    {
        if (config('database.connections.' . self::WRITER) === null) {
            throw RegistryRejected::misconfigured('no registry writer database role is configured (LUMINATE_REGISTRY_DB_USERNAME / _PASSWORD)');
        }

        return DB::connection(self::WRITER);
    }

    public function find(string $tid): ?array
    {
        $r = DB::table(self::TABLE)->where('tenant_id', $tid)->first();

        return $r === null ? null : self::row($r);
    }

    public function all(): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->orderBy('tenant_id')->get() as $r) {
            $out[(string) $r->tenant_id] = self::row($r);
        }

        return $out;
    }

    public function apply(string $tid, array $incoming, string $source, int $now): string
    {
        for ($try = 0; ; $try++) {
            try {
                $w = $this->writer();

                return $w->transaction(function () use ($w, $tid, $incoming, $source, $now): string {
                    $locked = $w->selectOne('select * from ' . self::TABLE . ' where tenant_id = ? for update', [$tid]);
                    $stored = $locked === null ? null : self::row($locked);
                    $outcome = RegistryRules::decide($stored, $incoming);
                    if ($outcome !== RegistryRules::APPLIED) {
                        return $outcome;
                    }
                    $values = [
                        'status' => $incoming['status'],
                        'rev' => $incoming['rev'],
                        'mode' => $incoming['placement']['mode'],
                        'connection' => $incoming['placement']['connection'],
                        'bucket' => $incoming['placement']['bucket'],
                        'cache_prefix' => $incoming['placement']['cache_prefix'],
                        'source' => $source,
                        'updated_at' => $now,
                    ];
                    if ($stored === null) {
                        $w->table(self::TABLE)->insert(['tenant_id' => $tid, 'created_at' => $now] + $values);
                    } else {
                        $w->table(self::TABLE)->where('tenant_id', $tid)->update($values);
                    }

                    return $outcome;
                });
            } catch (QueryException $e) {
                // two first-ever writers for one tenant raced on the insert (23505): the loser re-decides against the winner's row
                if ($e->getCode() !== '23505' || $try >= 2) {
                    throw $e;
                }
            }
        }
    }

    public function tombstoneAbsent(array $presentTids, int $touchedBefore, int $now): array
    {
        $w = $this->writer();
        $q = $w->table(self::TABLE)->where('status', '<>', 'removed')->where('updated_at', '<', $touchedBefore);
        if ($presentTids !== []) {
            $q->whereNotIn('tenant_id', $presentTids);
        }
        $tids = $q->pluck('tenant_id')->map(fn ($t) => (string) $t)->all();
        foreach ($tids as $tid) {
            // re-check inside the update: it is the same predicate, so a push that landed since the select is not overwritten
            $w->table(self::TABLE)->where('tenant_id', $tid)->where('status', '<>', 'removed')->where('updated_at', '<', $touchedBefore)
                ->update(['status' => 'removed', 'source' => RegistryRules::SOURCE_ABSENT, 'updated_at' => $now]);
        }

        return array_values($tids);
    }

    public function markProvisioned(string $tid, int $now): void
    {
        $this->writer()->table(self::TABLE)->where('tenant_id', $tid)->whereNull('provisioned_at')->update(['provisioned_at' => $now]);
    }

    public function pendingProvision(): array
    {
        return DB::table(self::TABLE)->whereIn('status', ['provisioning', 'active'])->whereNull('provisioned_at')
            ->orderBy('tenant_id')->pluck('tenant_id')->map(fn ($t) => (string) $t)->all();
    }

    public function recordPull(bool $ok, ?string $error, ?int $asOf, int $now): void
    {
        $set = ['attempt_at' => (string) $now, 'error' => $ok ? '' : mb_substr((string) $error, 0, 300)];
        if ($ok) {
            $set['ok_at'] = (string) $now;
            $set['as_of'] = (string) ($asOf ?? 0);
        }
        $w = $this->writer();
        foreach ($set as $k => $v) {
            $w->table(self::META)->updateOrInsert(['k' => 'pull_' . $k], ['v' => $v]);
        }
    }

    public function pullState(): array
    {
        $m = DB::table(self::META)->where('k', 'like', 'pull\_%')->pluck('v', 'k')->all();
        $int = static fn (string $k): ?int => isset($m[$k]) && $m[$k] !== '' ? (int) $m[$k] : null;

        return [
            'attempt_at' => $int('pull_attempt_at'),
            'ok_at' => $int('pull_ok_at'),
            'error' => ($m['pull_error'] ?? '') !== '' ? (string) $m['pull_error'] : null,
            'as_of' => $int('pull_as_of'),
        ];
    }

    /** @return array{tenant_id:string,status:string,rev:int,placement:array<string,string>,source:string,provisioned_at:?int,updated_at:int} */
    private static function row(object $r): array
    {
        return [
            'tenant_id' => (string) $r->tenant_id,
            'status' => (string) $r->status,
            'rev' => (int) $r->rev,
            'placement' => ['mode' => (string) $r->mode, 'connection' => (string) $r->connection, 'bucket' => (string) $r->bucket, 'cache_prefix' => (string) $r->cache_prefix],
            'source' => (string) $r->source,
            'provisioned_at' => $r->provisioned_at === null ? null : (int) $r->provisioned_at,
            'updated_at' => (int) $r->updated_at,
        ];
    }
}
