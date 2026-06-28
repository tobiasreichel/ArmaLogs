<?php
declare(strict_types=1);

// Locate the includes directory. Cloudron users often upload includes under
// public/includes, while a classic layout has includes as a sibling to public/.
$candidates = [
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/includes/config.php',
];

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        define('INCLUDES_DIR', dirname($candidate));
        require_once $candidate;
        return;
    }
}

http_response_code(500);
header('Content-Type: text/plain');
echo "Server misconfiguration: includes/config.php not found. ";
echo "Tried: " . implode(", ", $candidates) . "\n";
exit;
