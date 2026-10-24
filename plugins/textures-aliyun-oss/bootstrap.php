<?php

return function () {
    $disk = [
        'driver' => 'oss',
        'access_key_id' => env('OSS_ACCESS_ID'),
        'access_key_secret' => env('OSS_ACCESS_KEY'),
        'bucket' => env('OSS_BUCKET'),
        'endpoint' => env('OSS_ENDPOINT'),
        'use_ssl' => env('OSS_SSL', true),
        'domain' => env('OSS_CDN_DOMAIN'),
        'is_cname' => env('OSS_IS_CNAME', false),
    ];

    app()->register(AlphaSnow\LaravelFilesystem\Aliyun\AliyunServiceProvider::class);
    config(['filesystems.disks.textures' => $disk]);
};
