<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = config();
        $d=%s;charset=%s',
            $cfg['db']['host'],
            $cfg['db']['port'],
            $cfg['db']['name'],
            $cfg['db']['charset']
        );
        $pdo = new PDO($dsn, $cfg['db']['use PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
