<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

/**
 * Verifies the compact JWS (HS256) carried in X-Tenant-Context.
 *
 * Deliberately small and dependency-free. Rules: exactly three canonical base64url segments, header alg
 * must be exactly "HS256" (never "none", never anything else), signature valid under the current OR the
 * previous key, strict claim types, exp - iat <= max lifetime, not expired, status "active".
 * It does not look anything up: the token is authoritative (contract §A.1).
 */
final class ContextTokenVerifier
{
    public const ALGORITHM = 'HS256';
    private const MIN_KEY_BYTES = 32;

    /** @param list<string> $keys current key first, then the optional previous key */
    public function __construct(
        private readonly array $keys,
        private readonly int $maxLifetime = 300,
        private readonly int $leeway = 30,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function verify(string $token): TenantContext
    {
        $this->assertConfigured();

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw ContextRejected::malformed();
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = $this->decodeObject($headerB64);
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw ContextRejected::badAlgorithm();
        }

        $signature = $this->base64UrlDecode($signatureB64);
        $signed = $headerB64 . '.' . $payloadB64;
        $valid = false;
        foreach ($this->keys as $key) {
            // evaluate every key (no short-circuit) so timing does not reveal which one matched
            $valid = hash_equals(hash_hmac('sha256', $signed, $key, true), $signature) || $valid;
        }
        if (!$valid) {
            throw ContextRejected::badSignature();
        }

        $claims = $this->decodeObject($payloadB64);
        $context = $this->claimsToContext($claims);

        $now = $this->now();
        if ($context->expiresAt <= $context->issuedAt || $context->expiresAt - $context->issuedAt > $this->maxLifetime) {
            throw ContextRejected::invalidClaims('lifetime');
        }
        if ($now >= $context->expiresAt) {
            throw ContextRejected::expired();
        }
        if ($context->issuedAt > $now + $this->leeway) {
            throw ContextRejected::notYetValid();
        }
        if ($context->status !== 'active') {
            throw ContextRejected::tenantInactive();
        }

        return $context;
    }

    private function claimsToContext(array $c): TenantContext
    {
        $tid = $c['tid'] ?? null;
        $status = $c['status'] ?? null;
        $jti = $c['jti'] ?? null;
        $iat = $c['iat'] ?? null;
        $exp = $c['exp'] ?? null;
        $placement = $c['placement'] ?? null;

        if (!is_string($tid) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $tid) !== 1) {
            throw ContextRejected::invalidClaims('tid');
        }
        if (!is_string($status) || $status === '' || strlen($status) > 32) {
            throw ContextRejected::invalidClaims('status');
        }
        if (!is_string($jti) || $jti === '' || strlen($jti) > 128) {
            throw ContextRejected::invalidClaims('jti');
        }
        if (!is_int($iat) || !is_int($exp)) {
            throw ContextRejected::invalidClaims('iat/exp');
        }
        if (!is_array($placement) || array_is_list($placement)) {
            throw ContextRejected::invalidClaims('placement');
        }

        $mode = $placement['mode'] ?? null;
        $connection = $placement['connection'] ?? null;
        $bucket = $placement['bucket'] ?? null;
        $cachePrefix = $placement['cache_prefix'] ?? null;

        if (!in_array($mode, ['pooled', 'dedicated'], true)) {
            throw ContextRejected::invalidClaims('placement.mode');
        }
        // Opaque logical name only. The charset excludes ':', '/', '@' so a DSN cannot be smuggled in.
        if (!is_string($connection) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $connection) !== 1) {
            throw ContextRejected::invalidClaims('placement.connection');
        }
        // "<bucket>[/<key-prefix>]"; no traversal, no leading/trailing slash.
        if (!is_string($bucket) || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,254}$#', $bucket) !== 1
            || str_contains($bucket, '..') || str_contains($bucket, '//') || str_ends_with($bucket, '/')) {
            throw ContextRejected::invalidClaims('placement.bucket');
        }
        if (!is_string($cachePrefix) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $cachePrefix) !== 1) {
            throw ContextRejected::invalidClaims('placement.cache_prefix');
        }

        return new TenantContext($tid, $status, $mode, $connection, $bucket, $cachePrefix, $jti, $iat, $exp);
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $segment): array
    {
        $json = $this->base64UrlDecode($segment);
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ContextRejected::malformed();
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ContextRejected::malformed();
        }

        return $data;
    }

    private function base64UrlDecode(string $segment): string
    {
        // canonical base64url only: no padding, no standard-alphabet characters, no whitespace
        if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1 || strlen($segment) % 4 === 1) {
            throw ContextRejected::malformed();
        }
        $decoded = base64_decode(strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4), true);
        if ($decoded === false) {
            throw ContextRejected::malformed();
        }

        return $decoded;
    }

    private function assertConfigured(): void
    {
        if ($this->keys === []) {
            throw ContextRejected::misconfigured();
        }
        foreach ($this->keys as $key) {
            if (!is_string($key) || strlen($key) < self::MIN_KEY_BYTES) {
                throw ContextRejected::misconfigured();
            }
        }
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }
}
