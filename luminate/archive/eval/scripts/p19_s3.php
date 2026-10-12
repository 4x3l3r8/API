<?php
foreach (['pooled-c.localhost', 'pooled-d.localhost'] as $t) {
    config(['filesystems.disks.s3.root' => 'tenants/' . $t]);
    \Illuminate\Support\Facades\Storage::forgetDisk('s3');
    $disk = \Illuminate\Support\Facades\Storage::disk('s3');
    $path = 'course/5/topic/9/video/file.mp4';
    $disk->put($path, 'x-' . $t);
    echo $t . ": put('$path') url=" . $disk->url($path) . "  readback=" . $disk->get($path) . PHP_EOL;
}
