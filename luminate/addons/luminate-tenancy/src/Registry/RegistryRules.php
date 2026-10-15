<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

/**
 * Per-tenant ordering of registry updates. The registry is advisory and is fed by two paths that can disagree for a moment
 * (a push and a pull in flight together, a retried push arriving late), so every change carries Luminate's monotonic `rev` and
 * the replica only ever moves forward. A late "active" can therefore never undo a newer "suspended".
 *
 * Contract: `rev` never decreases for a tenant id, including across delete + recreate.
 */
final class RegistryRules
{
    public const APPLIED = 'applied';
    public const UNCHANGED = 'unchanged';
    public const STALE = 'stale';
    public const CONFLICT = 'conflict';

    public const SOURCE_PUSH = 'push';
    public const SOURCE_PULL = 'pull';
    public const SOURCE_SEED = 'seed';
    /** a tenant the authoritative pull no longer lists; kept as a tombstone, never hard-deleted */
    public const SOURCE_ABSENT = 'absent';

    /**
     * @param array{status:string,rev:int,placement:array<string,string>,source?:string}|null $stored
     * @param array{status:string,rev:int,placement:array<string,string>}                     $incoming
     */
    public static function decide(?array $stored, array $incoming): string
    {
        if ($stored === null) {
            return self::APPLIED;
        }

        // An absence tombstone carries no information about Luminate's rev, so the authoritative list re-adding the tenant at the
        // same rev is legitimate; an explicit removal must be superseded by a strictly newer rev.
        if ($stored['status'] === 'removed' && $incoming['status'] !== 'removed') {
            $needed = ($stored['source'] ?? '') === self::SOURCE_ABSENT ? $stored['rev'] : $stored['rev'] + 1;

            return $incoming['rev'] >= $needed ? self::APPLIED : self::STALE;
        }

        if ($incoming['rev'] < $stored['rev']) {
            return self::STALE;
        }
        if ($incoming['rev'] === $stored['rev']) {
            return $stored['status'] === $incoming['status'] && $stored['placement'] === $incoming['placement']
                ? self::UNCHANGED
                : self::CONFLICT; // same rev, different content: a Luminate bug; refuse rather than guess
        }

        return self::APPLIED;
    }
}
