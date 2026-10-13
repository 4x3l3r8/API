<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

/** Mints context tokens the way the Luminate proxy would (test helper). */
final class TokenFactory
{
    public const KEY = 'current-key-current-key-current-key-0123456789';
    public const PREVIOUS_KEY = 'previous-key-previous-key-previous-key-0123456';
    public const NOW = 1_800_000_000;

    public static function claims(array $override = []): array
    {
        return array_replace_recursive([
            'tid' => 'tenant-alpha',
            'status' => 'active',
            'placement' => ['mode' => 'pooled', 'connection' => 'shard-1', 'bucket' => 'wellms', 'cache_prefix' => 'alpha'],
            'iat' => self::NOW - 10,
            'exp' => self::NOW + 290,
            'jti' => 'jti-0001',
        ], $override);
    }

    public static function mint(array $claims, string $key = self::KEY, array $header = ['alg' => 'HS256', 'typ' => 'JWT']): string
    {
        $h = self::b64(json_encode($header, JSON_THROW_ON_ERROR));
        $p = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        return $h . '.' . $p . '.' . self::b64(hash_hmac('sha256', $h . '.' . $p, $key, true));
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
