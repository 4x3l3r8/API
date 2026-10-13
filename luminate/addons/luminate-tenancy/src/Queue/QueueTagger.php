<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Queue;

/**
 * Signs and verifies the tenant tag carried in every queued job payload.
 *
 * The signature binds the tenant id to the job's own uuid, so a tag cannot be copied onto another job or re-labelled for
 * another tenant. The key is derived from the tenant-context key (never the key itself) and the previous key is also
 * accepted, so rotation needs no downtime. Redis is internal and password-protected, but a tag that anyone who can write
 * to it could forge would be a cross-tenant execution primitive, so it is authenticated anyway.
 */
final class QueueTagger
{
    public const PAYLOAD_KEY = 'luminate_tenant';
    private const MIN_KEY_BYTES = 32;

    /** @param list<string> $keys current key first, then the optional previous key */
    public function __construct(private readonly array $keys)
    {
    }

    /** @return array{luminate_tenant: array{tid:string, sig:string}} */
    public function tag(string $uuid, string $tid): array
    {
        $this->assertConfigured();

        return [self::PAYLOAD_KEY => ['tid' => $tid, 'sig' => $this->sign($this->keys[0], $uuid, $tid)]];
    }

    /** @param array<string,mixed> $payload decoded job payload; returns the verified tenant id */
    public function verify(array $payload): string
    {
        $this->assertConfigured();

        $uuid = $payload['uuid'] ?? null;
        if (!is_string($uuid) || $uuid === '') {
            throw TagRejected::malformed();
        }
        if (!array_key_exists(self::PAYLOAD_KEY, $payload) || $payload[self::PAYLOAD_KEY] === null) {
            throw TagRejected::missing();
        }
        $tag = $payload[self::PAYLOAD_KEY];
        if (!is_array($tag) || !is_string($tag['tid'] ?? null) || !is_string($tag['sig'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $tag['tid']) !== 1) {
            throw TagRejected::malformed();
        }

        $valid = false;
        foreach ($this->keys as $key) {
            $valid = hash_equals($this->sign($key, $uuid, $tag['tid']), $tag['sig']) || $valid;
        }
        if (!$valid) {
            throw TagRejected::badSignature();
        }

        return $tag['tid'];
    }

    private function sign(string $key, string $uuid, string $tid): string
    {
        $derived = hash_hmac('sha256', 'luminate/queue-tag/v1', $key, true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $uuid . "\n" . $tid, $derived, true)), '+/', '-_'), '=');
    }

    private function assertConfigured(): void
    {
        if ($this->keys === []) {
            throw TagRejected::misconfigured();
        }
        foreach ($this->keys as $key) {
            if (!is_string($key) || strlen($key) < self::MIN_KEY_BYTES) {
                throw TagRejected::misconfigured();
            }
        }
    }
}
