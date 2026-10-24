<?php

return function () {
    $disk = [
        'driver' => 'cos',
        'region' => env('COS_REGION', 'ap-shanghai'),
        'app_id' => env('COS_APP_ID'),
        'secret_id' => env('COS_SECRET_ID'),
        'secret_key' => env('COS_SECRET_KEY'),
        'guzzle' => [
            'timeout' => (float) env('COS_TIMEOUT', 60),
            'connect_timeout' => (float) env('COS_CONNECT_TIMEOUT', 60),
        ],
        'bucket' => env('COS_BUCKET'),
        'cdn' => env('COS_CDN'),
        'use_https' => env('COS_SCHEME', 'https') === 'https',
    ];

    app()->register(Overtrue\LaravelFilesystem\Cos\CosStorageServiceProvider::class);
    config(['filesystems.disks.textures' => $disk]);
};
