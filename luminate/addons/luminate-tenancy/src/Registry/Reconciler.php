<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

/**
 * The periodic reconciliation pull (contract §A.2): the safety net behind push. Fetches Luminate's full, signed tenant list and
 * converges the local replica on it.
 *
 * It is deliberately all-or-nothing and conservative, because a wrong "authoritative" list is the one input that could take every
 * tenant down (or resurrect a suspended one):
 *  - the response must be 200, correctly signed (so a forged list is not trusted even over bad transport), and well formed;
 *  - ONE malformed entry rejects the whole pull: without a trustworthy full list "absent" means nothing;
 *  - an empty list is refused unless the operator asks for it explicitly;
 *  - entries move forward only (RegistryRules), so a pull cut a moment before a push never undoes that push;
 *  - tenants absent from the list are tombstoned ("removed", never hard-deleted), except those touched after the snapshot was cut.
 */
final class Reconciler
{
    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistryStore $store,
        private readonly RegistryAuth $auth,
        private readonly int $skew = 120,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @return array{applied:list<string>,unchanged:list<string>,stale:list<string>,conflict:list<string>,removed:list<string>,as_of:int}
     *
     * @throws RegistryRejected|\RuntimeException when the pull was not applied (the failure is recorded, the replica is untouched)
     */
    public function run(bool $allowEmpty = false): array
    {
        $now = $this->now();
        try {
            $report = $this->pull($allowEmpty, $now);
        } catch (\Throwable $e) {
            try {
                $this->store->recordPull(false, $e->getMessage(), null, $now);
            } catch (\Throwable) {
                // the original failure is the one worth reporting (e.g. the database itself is down)
            }
            throw $e;
        }
        $this->store->recordPull(true, null, $report['as_of'], $now);

        return $report;
    }

    private function pull(bool $allowEmpty, int $now): array
    {
        $r = $this->fetcher->fetch();
        if ($r['status'] !== 200) {
            throw new \RuntimeException("registry pull answered HTTP {$r['status']}");
        }
        $this->auth->verifyResponse($r['timestamp'], $r['signature'], $r['body']);

        try {
            $doc = json_decode($r['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw RegistryRejected::invalid('registry pull body is not JSON');
        }
        if (!is_array($doc) || !is_int($doc['as_of'] ?? null) || !is_array($doc['tenants'] ?? null) || !array_is_list($doc['tenants'])) {
            throw RegistryRejected::invalid('registry pull must be {"as_of": <unix seconds>, "tenants": [...]}');
        }
        $asOf = $doc['as_of'];
        if ($asOf > $now + $this->skew) {
            throw RegistryRejected::invalid('registry pull as_of is in the future');
        }

        $entries = [];
        foreach ($doc['tenants'] as $i => $t) {
            $tid = is_array($t) ? ($t['tenant_id'] ?? null) : null;
            if (!RegistryPayload::validTenantId($tid)) {
                throw RegistryRejected::invalid("registry pull entry #{$i} has no valid tenant_id");
            }
            if (isset($entries[$tid])) {
                throw RegistryRejected::invalid("registry pull lists '{$tid}' twice");
            }
            try {
                $entries[$tid] = RegistryPayload::parse($t);
            } catch (RegistryRejected $e) {
                throw RegistryRejected::invalid("registry pull entry '{$tid}': " . $e->getMessage());
            }
        }
        if ($entries === [] && !$allowEmpty) {
            throw RegistryRejected::invalid('registry pull lists no tenants; refusing to treat that as "remove everything" (use --allow-empty if intended)');
        }

        $report = ['applied' => [], 'unchanged' => [], 'stale' => [], 'conflict' => [], 'removed' => [], 'as_of' => $asOf];
        foreach ($entries as $tid => $entry) {
            $report[$this->store->apply((string) $tid, $entry, RegistryRules::SOURCE_PULL, $now)][] = (string) $tid;
        }
        $report['removed'] = $this->store->tombstoneAbsent(array_map('strval', array_keys($entries)), $asOf - $this->skew, $now);

        return $report;
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }
}
