<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Media;

use Psr\Http\Message\StreamInterface;

/** One stored object, described and openable by byte range. Never holds the bytes. */
final class MediaObject
{
    /** @param \Closure(int,int):StreamInterface $reader opens bytes [start, end] (inclusive) as a stream */
    public function __construct(
        public readonly int $size,
        public readonly string $etag,          // quoted, e.g. "abc123"
        public readonly ?int $lastModified,
        public readonly string $contentType,
        public readonly string $filename,
        private readonly \Closure $reader,
    ) {
    }

    /** @return \Closure():?string each call returns the next chunk of [start, end], or null at the end */
    public function chunks(int $start, int $end, int $chunk = 65536): \Closure
    {
        $stream = ($this->reader)($start, $end);
        $remaining = $end - $start + 1;

        return function () use ($stream, &$remaining, $chunk): ?string {
            if ($remaining <= 0) {
                return null;
            }
            $data = $stream->read(min($chunk, $remaining));
            if ($data === '' || $data === false) {
                return null;
            }
            $remaining -= strlen($data);

            return $data;
        };
    }
}
