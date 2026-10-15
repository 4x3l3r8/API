<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Provisioning;

/**
 * The per-tenant machine-admin secret (contract A.2.2): generation and the rules a Luminate-supplied one must meet.
 * Pure: no framework, no I/O, so the policy is unit-tested exhaustively.
 */
final class AdminSecret
{
    public const LENGTH = 48;
    public const MIN = 32;
    public const MAX = 128;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** 48 characters of base 62 from the CSPRNG, one unbiased draw per character (about 285 bits). */
    public static function generate(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Why a Luminate-supplied secret is refused, or null when it is acceptable. A machine credential has no excuse for being short or
     * guessable, and the rules are checkable by the caller from this description alone.
     */
    public static function problem(mixed $secret): ?string
    {
        if (!is_string($secret)) {
            return 'secret must be a string';
        }
        $len = strlen($secret);
        if ($len < self::MIN || $len > self::MAX) {
            return 'secret must be ' . self::MIN . '-' . self::MAX . ' characters';
        }
        // \A..\z, not ^..$: PHP's $ also matches just before a trailing newline, which would let "secret\n" through
        if (preg_match('/\A[\x21-\x7e]+\z/', $secret) !== 1) {
            return 'secret must be printable ASCII with no spaces';
        }
        if (count(array_unique(str_split($secret))) < 10) {
            return 'secret must use at least 10 distinct characters';
        }

        return null;
    }
}
