<?php
declare(strict_types=1);

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
        'storage'  => __DIR__ . '/../../storage/logs',
        'includes' => __DIR__,
    ],
    'limits' => [
        'uploads_per_hour' => 50,
        'bytes_per_day'    => 2 * 1024 * 1024 * 1024,
        'max_file_bytes'   => 512 * 1024 * 1024,
    ],
    'archive' => [
        'log_retention_days' => 30,
    ],
    'security' => [
        'argon2id_options' => [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ],
    ],
    'session' => [
        'name'            => 'armalogs_admin',
        'lifetime_s'      => 60 * 60 * 8,
        'cookie_secure'   => true,
        'cookie_httponly' => true,
        'samesite'        => 'Lax',
    ],
    'ai' => [
        'enabled'    => false,
        'provider'   => 'anthropic',   // anthropic, ollama, or openai
        'base_url'   => '',            // anthropic: https://openlimits.app ; ollama: https://ollama.com ; openai: https://openrouter.ai/api
        'api_key'    => '',
        'model'      => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 128000,
        'max_chars'  => 800000,
        'num_ctx'    => 8192,          // ollama only
    ],
    'setup' => [
        'enabled' => true,
    ],
    'client' => [
        'version'      => '1.0.0',
        'download_url' => '',  // auto: https://your-domain/client/ArmaLogsClientSetup.exe
    ],
];
