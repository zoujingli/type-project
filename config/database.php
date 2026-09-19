<?php

declare(strict_types=1);

// 驱动在首次安装前由 configure.php 选择，运行环境不能切换成未安装的驱动。
return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 0),
    'database' => env('DB_DATABASE', 'type_project'),
    'username' => env('DB_USERNAME', 'type_project'),
    'password' => env('DB_PASSWORD', ''),
    'sqlite_file' => env('DB_SQLITE_FILE', 'var/app.sqlite'),
    'tls_ca' => env('DB_TLS_CA', ''),
    'budget' => [
        'server' => env('DB_SERVER_BUDGET', 100),
        'replicas' => env('APP_MAX_REPLICAS', 1),
        'surge' => env('APP_ROLLING_SURGE', 1),
        'processes' => env('APP_DATABASE_PROCESSES', 1),
        'threads' => env('APP_DATABASE_THREADS', 1),
        'reserve' => env('DB_ADMIN_RESERVE', 10),
    ],
    'pool' => [
        'waiters' => env('DB_POOL_WAITERS', 64),
        'wait_ms' => env('DB_POOL_WAIT_MS', 1000),
    ],
];
