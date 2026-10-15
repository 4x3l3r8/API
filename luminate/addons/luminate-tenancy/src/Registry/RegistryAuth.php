<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

/**
 * Authentication of registry traffic, in both directions, with a shared secret that is NOT the tenant-context key
 * (a leaked context key must not let anyone rewrite the registry, and vice versa). `previous` is accepted for rotation.
 *
 *   request  (Luminate -> Wellms push/read, and Wellms -> Luminate pull):
 *     X-Luminate-Timestamp: <unix seconds>
 *     X-Luminate-Signature: sha256=hex( HMAC(key, "{ts}.{METHOD}.{path}.{sha256hex(body)}") )
 *   response of the pull (so a forged registry cannot be injected even over bad transport):
 *     X-Luminate-Timestamp, X-Luminate-Signature: sha256=hex( HMAC(key, "response.{ts}.{sha256hex(body)}") )
 *
 * The method and path are inside the signature, so a captured push for one tenant cannot be replayed against another path.
 */
final class RegistryAuth
{
    public const TIMESTAMP = 'X-Luminate-Timestamp';
    public const SIGNATURE = 'X-Luminate-Signature';

    /** @param list<string> $keys current first, then previous; empty = disabled */
    public function __construct(private readonly array $keys, private readonly int $window = 300, private readonly ?\Closure $clock = null)
    {
    }

    public function enabled(): bool
    {
        return $this->keys !== [];
    }

    public function sign(string $timestamp, string $method, string $path, string $body, ?string $key = null): string
    {
        return 'sha256=' . hash_hmac('sha256', self::requestMessage($timestamp, $method, $path, $body), $key ?? $this->requireKey());
    }

    public function signResponse(string $timestamp, string $body, ?string $key = null): string
    {
        return 'sha256=' . hash_hmac('sha256', self::responseMessage($timestamp, $body), $key ?? $this->requireKey());
    }

    /** @throws RegistryRejected */
    public function verifyRequest(?string $timestamp, ?string $signature, string $method, string $path, string $body): void
    {
        $this->verify($timestamp, $signature, fn (string $key) => $this->sign((string) $timestamp, $method, $path, $body, $key));
    }

    /** @throws RegistryRejected */
    public function verifyResponse(?string $timestamp, ?string $signature, string $body): void
    {
        $this->verify($timestamp, $signature, fn (string $key) => $this->signResponse((string) $timestamp, $body, $key));
    }

    /** @param \Closure(string):string $expected */
    private function verify(?string $timestamp, ?string $signature, \Closure $expected): void
    {
        if (!$this->enabled()) {
            throw RegistryRejected::disabled();
        }
        if ($timestamp === null || $signature === null || $timestamp === '' || $signature === '') {
            throw RegistryRejected::unauthenticated('registry_signature_missing', 'Missing signature headers.');
        }
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            throw RegistryRejected::unauthenticated('registry_timestamp_invalid', 'Malformed timestamp.');
        }
        if (abs($this->now() - (int) $timestamp) > $this->window) {
            throw RegistryRejected::unauthenticated('registry_timestamp_expired', 'Timestamp outside the accepted window.');
        }
        if (preg_match('/^sha256=[0-9a-f]{64}$/', $signature) !== 1) {
            throw RegistryRejected::unauthenticated('registry_signature_invalid', 'Malformed signature.');
        }
        $ok = false;
        foreach ($this->keys as $key) { // no early exit: constant work per configured key
            $ok = hash_equals($expected($key), $signature) || $ok;
        }
        if (!$ok) {
            throw RegistryRejected::unauthenticated('registry_signature_invalid', 'Signature does not match.');
        }
    }

    private function requireKey(): string
    {
        return $this->keys[0] ?? throw RegistryRejected::disabled();
    }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }

    private static function requestMessage(string $ts, string $method, string $path, string $body): string
    {
        return $ts . '.' . strtoupper($method) . '.' . $path . '.' . hash('sha256', $body);
    }

    private static function responseMessage(string $ts, string $body): string
    {
        return 'response.' . $ts . '.' . hash('sha256', $body);
    }
}
