<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDES_DIR . '/config.php';
require_once INCLUDES_DIR . '/db.php';
require_once INCLUDES_DIR . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
$name = trim((string)($input['name'] ?? ''));
$hostname = trim((string)($input['hostname'] ?? ''));

if ($name === '') {
    json_error('Name is required');
}
if (!preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $name)) {
    json_error('Name must be 2-32 alphanumeric characters, dashes, or underscores');
}

try {
    $pdo = db();

    // Check for existing approved friend with same name
    $check = $pdo->prepare('SELECT 1 FROM friends WHERE name = :n LIMIT 1');
    $check->execute([':n' => $name]);
    if ($check->fetch()) {
        json_error('That name is already taken', 409);
    }

    // Check for existing pending/approved request
    $checkReq = $pdo->prepare('SELECT id, status, token_hash FROM friend_requests WHERE name = :n LIMIT 1');
    $checkReq->execute([':n' => $name]);
    $existing = $checkReq->fetch();

    if ($existing) {
        if ($existing['status'] === 'approved') {
            json_response([
                'ok' => true,
                'status' => 'approved',
                'token' => '', // token is not returned after approval for security
                'message' => 'Your request was approved. Contact the admin for your token.',
            ]);
        }
        if ($existing['status'] === 'pending') {
            json_response([
                'ok' => true,
                'status' => 'pending',
                'message' => 'Request already submitted. Waiting for admin approval.',
            ]);
        }
        // rejected: allow re-request
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        'INSERT INTO friend_requests (name, hostname, token_hash, status, created_at)
         VALUES (:n, :h, :t, "pending", NOW())
         ON DUPLICATE KEY UPDATE
           hostname = VALUES(hostname),
           token_hash = VALUES(token_hash),
           status = "pending",
           decided_at = NULL'
    );
    $stmt->execute([':n' => $name, ':h' => $hostname, ':t' => $tokenHash]);

    json_response([
        'ok' => true,
        'status' => 'pending',
        'token' => $token,
        'message' => 'Request submitted. Wait for admin approval before uploading.',
    ]);
} catch (Throwable $e) {
    error_log('ArmaLogs request-token exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    json_error('Server error', 500);
}
