<?php
return [
    // 图片服务器的host(无"http://")
    'img_server_host' => app()->bound('request') ? app()->request->getHost() : '', // 空值表示自动探测

    // 图片服务器的host的别名 -- 对于这些别名，在生成URL的时候也会自动做转换
    'img_server_host_aliases' => [],

    // 最大上传的大小
    'max_upload_size' => 200 * 1024,

    // 是否使能所有尺寸：
    'enable_all_sizes' => env('APP_DEBUG') || env('APP_ENABLE_ALL_SIZES'),

    // 所有支持的图像的尺寸
    'image_sizes' => [
        '0x0',   // 原始图像

        // 限定宽度和高度
        '60x60',
        '120x120',
        '140x140',
        '750x400',
        '750x430',
        '240x240',

        // 限宽不限高
        '320x0', // iPhone 4的宽度 1x
        '640x0', // iPhone 4的宽度 2x
        '375x0', // iPhone 6的宽度 1x
        '750x0', // iPhone 6的宽度 2x
        '414x0', // iPhone 6p的宽度 1x
        '828x0', // iPhone 6p的宽度 2x
        '1232x0',// iPhone 6p的宽度 3x
        '1280x0',
        '1080x0',
        '1000x0',
        '960x0',


        // 限高不限宽
        '0x480', // iPhone 4的高度
        '0x960', // iPhone 4的高度 2x

        // ...
    ],


    // 支持的存储协议与处理的类
    'default_storage' => 'file',
    'enabled_storage' => [
        'file' => FlexImage\Storage\LocalFileStorage::class,
        'qiniu-cdn' => FlexImage\Storage\QiniuCdn::class,
    ],

    'qiniu-cdn' => [
        'access_key' => env('QINIU_CDN_ACCESS_KEY'),
        'secret_key' => env('QINIU_CDN_SECRET_KEY'),
        'bucket' => env('QINIU_CDN_DEFAULT_BUCKET'),
        'access_domain' => env('QINIU_CDN_ACCESS_DOMAIN'),
    ],

    'file' => [
        'upload_dir' => app()->basePath() . '/public/uploads',
        'site_base_dir' => app()->basePath() . '/public',
    ]
];
