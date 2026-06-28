<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = config();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['db']['host'],
            $cfg['db']['port'],
            $cfg['db']['name'],
            $cfg['db']['charset']
        );
        $pdo = new PDO(
            $dsn,
            $cfg['db']['user'],
            $cfg['db']['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}
