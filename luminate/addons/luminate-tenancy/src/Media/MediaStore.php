<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Media;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Luminate\Tenancy\Context\CurrentTenant;

/**
 * Reads a stored topic file for the protected media route, bound to the CURRENT tenant.
 *
 * The tenant's key prefix is applied by the disk (TenantRuntime sets the s3 `root` from the verified context token), and is checked again
 * here on the final key before any byte is requested: the stored path comes from a database column, and a bad row (a path written by a
 * bug, a restore, or an attacker with partial write access) must never reach another tenant's objects. This is the READ-side enforcement;
 * the write side is the same disk. Streaming only: the object is described with a HEAD and opened by byte range, never loaded.
 */
final class MediaStore
{
    private const TYPES = [
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'wmv' => 'video/x-ms-wmv', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    ];

    public function __construct(private readonly CurrentTenant $tenant)
    {
    }

    /** A stored path we are willing to turn into an object key: relative, normalised, no traversal, no scheme. */
    public static function safeRelative(string $path): bool
    {
        return $path !== '' && strlen($path) <= 1024
            && preg_match('/[\x00-\x1f\x7f\\\\]/', $path) !== 1
            && !str_starts_with($path, '/') && !str_contains($path, '//') && !str_contains($path, '://')
            && preg_match('#(^|/)\.{1,2}(/|$)#', $path) !== 1;
    }

    /**
     * @return MediaObject|null null when there is no such object, or the path is not one we may read
     *
     * @throws \RuntimeException when no tenant is active (fail closed) or the resolved key is outside the tenant's prefix
     */
    public function open(string $relative): ?MediaObject
    {
        $tenant = $this->tenant->get() ?? throw new \RuntimeException('Refusing to read media with no active tenant.');
        if (!self::safeRelative($relative)) {
            Log::warning('media: refused a stored path', ['tenant' => $tenant->id()]);

            return null;
        }
        $name = (string) config('filesystems.default');
        $disk = Storage::disk($name);
        $driver = (string) config("filesystems.disks.{$name}.driver");
        $key = $disk->path($relative);

        $inside = $driver === 's3'
            ? str_starts_with($key, $tenant->s3KeyPrefix . '/')
            : str_contains($key, DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenant->id() . DIRECTORY_SEPARATOR);
        if (!$inside) {
            Log::critical('media: resolved key is outside the tenant prefix', ['tenant' => $tenant->id()]);
            throw new \RuntimeException('Resolved media key is outside the tenant prefix.');
        }

        return $driver === 's3' ? $this->openS3($disk, $name, $key, $relative) : $this->openFlysystem($disk, $relative);
    }

    private function openS3(object $disk, string $name, string $key, string $relative): ?MediaObject
    {
        $client = $disk->getClient();
        $bucket = (string) config("filesystems.disks.{$name}.bucket");
        try {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (S3Exception $e) {
            if (in_array($e->getStatusCode(), [403, 404], true) || in_array($e->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true)) {
                return null;
            }
            throw $e;
        }
        $modified = $head['LastModified'] ?? null;

        return new MediaObject(
            (int) $head['ContentLength'],
            (string) ($head['ETag'] ?? '"' . md5($key . $head['ContentLength']) . '"'),
            $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : null,
            self::contentType((string) ($head['ContentType'] ?? ''), $relative),
            self::filename($relative),
            fn (int $start, int $end) => $client->getObject(['Bucket' => $bucket, 'Key' => $key, 'Range' => "bytes={$start}-{$end}"])['Body'],
        );
    }

    private function openFlysystem(object $disk, string $relative): ?MediaObject
    {
        if (!$disk->exists($relative)) {
            return null;
        }
        $size = (int) $disk->size($relative);
        $modified = (int) $disk->lastModified($relative);

        return new MediaObject(
            $size,
            '"' . md5($relative . '|' . $size . '|' . $modified) . '"',
            $modified,
            self::contentType((string) $disk->mimeType($relative), $relative),
            self::filename($relative),
            function (int $start, int $end) use ($disk, $relative) {
                $stream = Utils::streamFor($disk->readStream($relative));
                $stream->seek($start);

                return $stream;
            },
        );
    }

    private static function contentType(string $reported, string $relative): string
    {
        // Luminate overrides the type from the topic kind, so this only has to be sane: prefer what storage reported, else the extension
        if ($reported !== '' && !in_array(strtolower($reported), ['binary/octet-stream', 'application/octet-stream'], true) && preg_match('#^[\w.+-]+/[\w.+-]+$#', $reported) === 1) {
            return $reported;
        }

        return self::TYPES[strtolower(pathinfo($relative, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    private static function filename(string $relative): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($relative)) ?: 'file';

        return substr($name, 0, 120);
    }
}
