<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Media\ByteRange;
use Luminate\Tenancy\Media\MediaStore;
use PHPUnit\Framework\TestCase;

final class ByteRangeTest extends TestCase
{
    private const SIZE = 1000;
    private const ETAG = '"abc123"';
    private const MOD = 1_800_000_000;

    private static function r(?string $range, ?string $ifRange = null, int $size = self::SIZE): array
    {
        return ByteRange::resolve($range, $ifRange, $size, self::ETAG, self::MOD);
    }

    /** @dataProvider ranges */
    public function testRanges(?string $range, int $status, int $start, int $end): void
    {
        $this->assertSame(['status' => $status, 'start' => $start, 'end' => $end], self::r($range));
    }

    public static function ranges(): array
    {
        return [
            'no range' => [null, 200, 0, 999],
            'empty range' => ['', 200, 0, 999],
            'first 100' => ['bytes=0-99', 206, 0, 99],
            'middle' => ['bytes=500-599', 206, 500, 599],
            'open ended' => ['bytes=900-', 206, 900, 999],
            'whole object as a range' => ['bytes=0-', 206, 0, 999],
            'last byte' => ['bytes=999-999', 206, 999, 999],
            'end past the object is clamped' => ['bytes=900-5000', 206, 900, 999],
            'suffix 50' => ['bytes=-50', 206, 950, 999],
            'suffix larger than the object is the whole object' => ['bytes=-5000', 206, 0, 999],
            'suffix of 1' => ['bytes=-1', 206, 999, 999],
            'case and spaces' => ['Bytes = 10 - 19', 206, 10, 19],
            'start at the size is unsatisfiable' => ['bytes=1000-', 416, 0, 0],
            'start past the size is unsatisfiable' => ['bytes=1500-1600', 416, 0, 0],
            'suffix 0 is unsatisfiable' => ['bytes=-0', 416, 0, 0],
            'absurdly large start is unsatisfiable, not an overflow' => ['bytes=99999999999999999999999-', 416, 0, 0],
            'absurdly large end is clamped' => ['bytes=5-99999999999999999999999', 206, 5, 999],
            'absurdly large suffix is the whole object' => ['bytes=-99999999999999999999999', 206, 0, 999],
            'last before first is invalid: ignored' => ['bytes=500-100', 200, 0, 999],
            'multiple ranges are served whole' => ['bytes=0-10,20-30', 200, 0, 999],
            'unknown unit is ignored' => ['items=0-5', 200, 0, 999],
            'no digits is ignored' => ['bytes=-', 200, 0, 999],
            'garbage is ignored' => ['bytes=abc-def', 200, 0, 999],
        ];
    }

    public function testAnEmptyObjectCannotBeRanged(): void
    {
        $this->assertSame(416, self::r('bytes=0-10', null, 0)['status']);
        $this->assertSame(['status' => 200, 'start' => 0, 'end' => 0], self::r(null, null, 0));
    }

    public function testIfRangeWithTheCurrentEtagKeepsTheRange(): void
    {
        $this->assertSame(206, self::r('bytes=0-9', '"abc123"')['status']);
    }

    public function testIfRangeWithAStaleEtagGivesTheWholeObject(): void
    {
        $this->assertSame(['status' => 200, 'start' => 0, 'end' => 999], self::r('bytes=0-9', '"old"'));
    }

    public function testIfRangeNeverMatchesAWeakTag(): void
    {
        $this->assertSame(200, self::r('bytes=0-9', 'W/"abc123"')['status']);
    }

    public function testIfRangeWithTheCurrentDateKeepsTheRangeAndAnOldOneDoesNot(): void
    {
        $this->assertSame(206, self::r('bytes=0-9', gmdate('D, d M Y H:i:s', self::MOD) . ' GMT')['status']);
        $this->assertSame(200, self::r('bytes=0-9', gmdate('D, d M Y H:i:s', self::MOD - 60) . ' GMT')['status']);
        $this->assertSame(200, self::r('bytes=0-9', 'not a date')['status']);
    }

    public function testAnUnsatisfiableRangeWithAStaleIfRangeIsTheWholeObject(): void
    {
        // the client's copy is stale: do not tell it the range is bad, give it the new object
        $this->assertSame(200, self::r('bytes=5000-', '"old"')['status']);
    }

    /** @dataProvider unsafePaths */
    public function testStoredPathsThatCouldEscapeAreNeverRead(string $path): void
    {
        $this->assertFalse(MediaStore::safeRelative($path), $path);
    }

    public static function unsafePaths(): array
    {
        return [
            'empty' => [''], 'absolute' => ['/etc/passwd'], 'traversal' => ['../t-beta/course/1/x.pdf'], 'embedded traversal' => ['course/1/../../x'],
            'dot segment' => ['course/./1'], 'trailing dot-dot' => ['course/1/..'], 'double slash' => ['course//1/x.pdf'], 'a url' => ['http://evil/x.pdf'],
            'another scheme' => ['s3://other-bucket/x'], 'backslash' => ['course\\1\\x.pdf'], 'nul byte' => ["course/1/x.pdf\0.png"], 'newline' => ["course/1/x\n.pdf"],
            'too long' => [str_repeat('a/', 600)],
        ];
    }

    /** @dataProvider safePaths */
    public function testOrdinaryStoredPathsAreFine(string $path): void
    {
        $this->assertTrue(MediaStore::safeRelative($path), $path);
    }

    public static function safePaths(): array
    {
        return [['course/65/topic/100/pdf/file.pdf'], ['topic-content/7/pdf/My report (final).pdf'], ['course/1/topic/2/video/a.b.c.mp4'], ['a']];
    }
}
