<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Auth;

use RuntimeException;

/**
 * Tiny RS256 JWT helpers for the tenant claim on Wellms-issued tokens (contract §D.5).
 *
 *  - add():  take a token Passport already built, put one more claim in its payload and re-sign it with the same key.
 *            Every claim the library produced is preserved exactly, so upgrading Passport/league never drops one.
 *  - peek(): read the claims WITHOUT verifying the signature. Only ever used to reject early; the signature is still
 *            verified by Passport afterwards, so a forged claim can never authenticate anything.
 */
final class JwtClaims
{
    /** @return array<string,mixed>|null null when $jwt is not shaped like a JWT with a JSON-object payload */
    public static function peek(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $json = self::decode($parts[1]);
        if ($json === null) {
            return null;
        }
        try {
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($claims) && ($claims === [] || !array_is_list($claims)) ? $claims : null;
    }

    /**
     * @param string      $privateKeyPem PEM contents (not a path)
     * @param string|null $passphrase
     */
    public static function add(string $jwt, string $claim, string $value, string $privateKeyPem, ?string $passphrase = null): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Not a JWT; refusing to add a claim.');
        }
        $header = self::decode($parts[0]);
        $headerData = $header === null ? null : json_decode($header, true);
        if (!is_array($headerData) || ($headerData['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('Only RS256 tokens can be re-signed.');
        }
        $claims = self::peek($jwt);
        if ($claims === null) {
            throw new RuntimeException('Token payload is not a JSON object.');
        }

        $claims[$claim] = $value;
        $payload = self::encode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $input = $parts[0] . '.' . $payload;

        $key = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($key === false) {
            throw new RuntimeException('Cannot load the token signing key.');
        }
        if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign the token.');
        }

        return $input . '.' . self::encode($signature);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $segment): ?string
    {
        if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1 || strlen($segment) % 4 === 1) {
            return null;
        }
        $raw = base64_decode(strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4), true);

        return $raw === false ? null : $raw;
    }
}
