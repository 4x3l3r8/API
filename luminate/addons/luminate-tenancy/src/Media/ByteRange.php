<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Media;

/**
 * HTTP range negotiation for the protected media route (RFC 9110 14): what to send for a given Range / If-Range against an object of known
 * size, ETag and modification time. Pure: no framework, no I/O.
 *
 *  - no Range, a malformed Range, or a multi-range request  -> 200, the whole object (ignoring a Range is always allowed);
 *  - a satisfiable single range                              -> 206 with the clamped [start, end];
 *  - a well-formed range that starts past the end            -> 416;
 *  - If-Range that does not match the current object         -> 200, the whole object (the client's copy is stale: never splice).
 */
final class ByteRange
{
    public const FULL = 200;
    public const PARTIAL = 206;
    public const UNSATISFIABLE = 416;

    /** @return array{status:int,start:int,end:int} inclusive byte offsets; for 416 start/end are 0 */
    public static function resolve(?string $range, ?string $ifRange, int $size, string $etag, ?int $lastModified): array
    {
        $full = ['status' => self::FULL, 'start' => 0, 'end' => max(0, $size - 1)];
        if ($range === null || trim($range) === '') {
            return $full;
        }
        if ($ifRange !== null && trim($ifRange) !== '' && !self::ifRangeMatches(trim($ifRange), $etag, $lastModified)) {
            return $full;
        }
        // only "bytes=<first>-<last>", "bytes=<first>-" and "bytes=-<suffix>"; several ranges are served as the whole object
        if (preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $range, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return $full;
        }
        [$first, $last] = [$m[1], $m[2]];
        if ($size === 0) {
            return ['status' => self::UNSATISFIABLE, 'start' => 0, 'end' => 0];
        }

        if ($first === '') { // suffix: the last N bytes
            if (self::isZero($last)) {
                return ['status' => self::UNSATISFIABLE, 'start' => 0, 'end' => 0];
            }
            $n = self::toIntClamped($last, $size);

            return ['status' => self::PARTIAL, 'start' => max(0, $size - $n), 'end' => $size - 1];
        }
        if (strlen(ltrim($first, '0')) > 15) { // absurdly large first byte: certainly past the end, and never an int overflow
            return ['status' => self::UNSATISFIABLE, 'start' => 0, 'end' => 0];
        }
        $start = (int) $first;
        if ($last !== '' && strlen(ltrim($last, '0')) <= 15 && (int) $last < $start) {
            return $full; // last < first is not a valid range: ignore it
        }
        if ($start >= $size) {
            return ['status' => self::UNSATISFIABLE, 'start' => 0, 'end' => 0];
        }
        $end = $last === '' ? $size - 1 : min(self::toIntClamped($last, $size), $size - 1);

        return ['status' => self::PARTIAL, 'start' => $start, 'end' => $end];
    }

    /** If-Range holds either a (strong) entity tag or an HTTP date. Weak tags never match for range requests. */
    private static function ifRangeMatches(string $value, string $etag, ?int $lastModified): bool
    {
        if ($value[0] === '"' || str_starts_with($value, 'W/')) {
            return !str_starts_with($value, 'W/') && $value === $etag;
        }
        $ts = strtotime($value);

        return $ts !== false && $lastModified !== null && $ts === $lastModified;
    }

    private static function isZero(string $digits): bool
    {
        return ltrim($digits, '0') === '';
    }

    private static function toIntClamped(string $digits, int $cap): int
    {
        $d = ltrim($digits, '0');

        return $d === '' ? 0 : (strlen($d) > 15 ? $cap : min((int) $d, $cap));
    }
}
