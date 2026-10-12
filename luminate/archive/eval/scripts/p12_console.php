<?php
$C = \EscolaLms\Courses\Models\Course::class;
echo "console, no tenant context : Course::count() = " . $C::count() . "  (fails OPEN: all tenants)" . PHP_EOL;
\Pooled\Spike\TenantContext::$forced = 'pooled-c.localhost';
echo "console, forced tenant C    : Course::count() = " . $C::count() . PHP_EOL;
\Pooled\Spike\TenantContext::$forced = 'pooled-d.localhost';
echo "console, forced tenant D    : Course::count() = " . $C::count() . PHP_EOL;
\Pooled\Spike\TenantContext::$forced = null;
echo "cache prefix in this domain : " . config('cache.prefix') . PHP_EOL;
echo "filesystems s3 root/url/bucket: " . json_encode(['root' => config('filesystems.disks.s3.root'), 'url' => config('filesystems.disks.s3.url'), 'bucket' => config('filesystems.disks.s3.bucket')]) . PHP_EOL;
