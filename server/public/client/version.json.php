<?php
declare(strict_types=1);

require_once __DIR__ . '/../_init.php';

require_once INCLUDES_DIR . '/config.php';
require_once INCLUDES_DIR . '/helpers.php';

$cfg = config();

header('Content-Type: application/json');

// Read version from the installer that lives at ../../client/dist/ArmaLogsClientSetup.exe metadata.
// For now the admin uploads the installer; we just return the configured version and URL.
$version = $cfg['client']['version'] ?? '1.0.0';
$url = $cfg['client']['download_url'] ?? '';
if ($url === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'armalogs.reichel.network';
    $url = "{$scheme}://{$host}/client/ArmaLogsClientSetup.exe";
}

json_response([
    'version' => $version,
    'url'     => $url,
]);
