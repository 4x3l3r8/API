<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

/**
 * Validation of one registry entry, shared by the push endpoint, the reconcile pull, the snapshot import and the database
 * read path. The shapes are the same ones the signed context token is held to (ContextTokenVerifier): a value that could not
 * ride in a token is not accepted into the replica either.
 *
 *   { "status": "provisioning|active|suspended|removed", "rev": <int>=0, "placement": { mode, connection, bucket, cache_prefix } }
 */
final class RegistryPayload
{
    public const STATUSES = ['provisioning', 'active', 'suspended', 'removed'];

    public static function validTenantId(mixed $tid): bool
    {
        return is_string($tid) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $tid) === 1;
    }

    /**
     * @return array{status:string,rev:int,placement:array{mode:string,connection:string,bucket:string,cache_prefix:string}}
     *
     * @throws RegistryRejected 422 naming the offending field
     */
    public static function parse(mixed $data, bool $requireRev = true): array
    {
        if (!is_array($data) || array_is_list($data)) {
            throw RegistryRejected::invalid('entry must be a JSON object');
        }
        $status = $data['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw RegistryRejected::invalid('status must be one of ' . implode('|', self::STATUSES));
        }
        $rev = $data['rev'] ?? null;
        if ($rev === null && !$requireRev) {
            $rev = 0;
        }
        if (!is_int($rev) || $rev < 0) {
            throw RegistryRejected::invalid('rev must be a non-negative integer');
        }
        $p = $data['placement'] ?? null;
        if (!is_array($p) || array_is_list($p)) {
            throw RegistryRejected::invalid('placement must be an object');
        }
        $mode = $p['mode'] ?? null;
        $connection = $p['connection'] ?? null;
        $bucket = $p['bucket'] ?? null;
        $cachePrefix = $p['cache_prefix'] ?? null;

        if (!in_array($mode, ['pooled', 'dedicated'], true)) {
            throw RegistryRejected::invalid('placement.mode must be pooled|dedicated');
        }
        if (!is_string($connection) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $connection) !== 1) {
            throw RegistryRejected::invalid('placement.connection must be an opaque logical name');
        }
        if (!is_string($bucket) || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,254}$#', $bucket) !== 1
            || str_contains($bucket, '..') || str_contains($bucket, '//') || str_ends_with($bucket, '/')) {
            throw RegistryRejected::invalid('placement.bucket is not a valid bucket[/prefix]');
        }
        if (!is_string($cachePrefix) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $cachePrefix) !== 1) {
            throw RegistryRejected::invalid('placement.cache_prefix is not valid');
        }

        return [
            'status' => $status,
            'rev' => $rev,
            'placement' => ['mode' => $mode, 'connection' => $connection, 'bucket' => $bucket, 'cache_prefix' => $cachePrefix],
        ];
    }
}
