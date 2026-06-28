<?php
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'armalogs',
        'user'    => 'armalogs',
        'pass'    => 'CHANGEME',
        'charset' => 'utf8mb4',
    ],
    'paths' => [
        'storage'  => '/var/lib/armalogs/logs',
        'includes' => __DIR__,
    ],
    'limits' => [
        'uploads_per_hour' => 50,
        'bytes_per_day'    => 500 * 1024 * 1024,
        'max_file_bytes'   => 100 * 1024 * 1024,
    ],
    'session' => [
        'name'            => 'armalogs_admin',
        'lifetime_s'      => 60 * 60 * 8,
        'cookie_secure'   => true,
        'cookie_httponly' => true,
    ],
];
