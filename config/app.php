<?php

declare(strict_types=1);

// 声明只在构建时解析，env 的实际值在应用启动时取得，不将秘密编入产物。
return [
    'name' => env('APP_NAME', 'type-project'),
    'environment' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'http' => [
        'listen' => env('APP_LISTEN', '127.0.0.1'),
        'port' => env('APP_PORT', 9501),
        'allowed_hosts' => env('APP_ALLOWED_HOSTS', ''),
        'trusted_proxies' => env('APP_TRUSTED_PROXIES', ''),
        'api_token' => env('APP_API_TOKEN', ''),
        'upload_temp' => env('APP_UPLOAD_TEMP', ''),
    ],
];
