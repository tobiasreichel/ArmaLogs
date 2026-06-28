<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

require_once INCLUDES_DIR . '/config.php';
require_once INCLUDES_DIR . '/db.php';
require_once INCLUDES_DIR . '/auth.php';
require_once INCLUDES_DIR . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$cfg = config();
$maxBytes = (int)($cfg['limits']['max_file_bytes'] ?? 512 * 1024 * 1024);
$postMax = parse_ini_size(ini_get('post_max_size'));
$uploadMax = parse_ini_size(ini_get('upload_max_filesize'));
$effectiveServerMax = min($postMax, $uploadMax);
if ($effectiveServerMax < $maxBytes) {
    $maxBytes = $effectiveServerMax;
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $postMax) {
    json_error(sprintf(
        'Upload too large: %s exceeds server post_max_size of %s. Reduce zip size or contact admin.',
        fmt_bytes($contentLength),
        fmt_bytes($postMax)
    ), 413);
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '') {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}
if ($authHeader === '') {
    $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
    $authHeader = $headers['Authorization'] ?? '';
}
// Fallback for hosts that strip Authorization headers (e.g. Cloudron LAMP)
if ($authHeader === '') {
    $alt = $_SERVER['HTTP_X_FRIEND_TOKEN'] ?? '';
    if ($alt !== '') {
        $authHeader = 'Bearer ' . $alt;
    }
}
$token = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $token = $m[1];
}
if ($token === '') {
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
}

$friend = authenticate_friend_by_token($token);
if (!$friend) {
    // Debug hint: log stripped headers without leaking tokens in production
    if (empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        error_log('ArmaLogs upload: Authorization header missing or stripped by web server');
    }
    json_error('Invalid or inactive friend token', 401);
}

if (empty($_FILES['logs']) || !is_array($_FILES['logs']['tmp_name'])) {
    json_error('No log files received');
}

$cfg = config();
$maxBytes = (int)($cfg['limits']['max_file_bytes'] ?? 100 * 1024 * 1024);
$host = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$sessionName = $_POST['session_id'] ?? $_POST['session'] ?? null;
$clientHostname = $_POST['hostname'] ?? null;

$files = [];
$uploadCount = count($_FILES['logs']['tmp_name']);
for ($i = 0; $i < $uploadCount; $i++) {
    $error = $_FILES['logs']['error'][$i];
    if ($error !== UPLOAD_ERR_OK) {
        json_error('Upload error ' . $error);
    }
    $original = $_FILES['logs']['name'][$i];
    $tmp = $_FILES['logs']['tmp_name'][$i];
    $size = (int)$_FILES['logs']['size'][$i];
    if ($size > $maxBytes) {
        json_error('File too large: ' . $original);
    }
    $sha = hash_file('sha256', $tmp);
    $files[] = [
        'original' => $original,
        'tmp'      => $tmp,
        'size'     => $size,
        'sha'      => $sha,
        'session'  => $sessionName ?? parse_session_id_from_filename($original) ?? parse_session_id_from_path($original) ?? 'unknown',
    ];
}

if (empty($files)) {
    json_error('No usable log files');
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Create or update session record
    $stmtSession = $pdo->prepare(
        'INSERT INTO sessions (friend_id, session_id, client_hostname, uploaded_at, log_count, total_bytes)
         VALUES (:friend_id, :session_id, :hostname, NOW(), 0, 0)
         ON DUPLICATE KEY UPDATE
           client_hostname = COALESCE(VALUES(client_hostname), client_hostname),
           uploaded_at = NOW(),
           log_count = log_count + VALUES(log_count),
           total_bytes = total_bytes + VALUES(total_bytes)'
    );

    $stmtLog = $pdo->prepare(
        'INSERT INTO logs (session_id, friend_id, filename, file_size, content_sha256, client_timestamp, uploaded_at, metadata, storage_path)
         VALUES (:session_id, :friend_id, :filename, :size, :sha, :client_ts, NOW(), :meta, :path)'
    );

    $stmtQueue = $pdo->prepare(
        'INSERT INTO upload_queue (friend_id, session_id, filename, file_size, content_sha256, status, remote_addr, created_at)
         VALUES (:friend_id, :session_id, :filename, :size, :sha, :status, :remote, NOW())'
    );

    $storageBase = $cfg['paths']['storage'];
    if (!is_dir($storageBase) && !mkdir($storageBase, 0750, true) && !is_dir($storageBase)) {
        throw new RuntimeException('Unable to create storage directory');
    }

    $uploaded = [];
    foreach ($files as $f) {
        $sid = $f['session'];

        $stmtSession->execute([
            ':friend_id'  => $friend['id'],
            ':session_id' => $sid,
            ':hostname'   => $clientHostname,
        ]);

        $stmt = $pdo->prepare('SELECT id FROM sessions WHERE friend_id = :f AND session_id = :s LIMIT 1');
        $stmt->execute([':f' => $friend['id'], ':s' => $sid]);
        $sessionRow = $stmt->fetch();
        $sessionId = (int)$sessionRow['id'];

        // Skip if SHA already known for this friend (deduplicate identical logs)
        $check = $pdo->prepare(
            'SELECT 1 FROM logs WHERE friend_id = :f AND content_sha256 = :sha LIMIT 1'
        );
        $check->execute([':f' => $friend['id'], ':sha' => $f['sha']]);
        if ($check->fetch()) {
            $uploaded[] = [
                'filename' => $f['original'],
                'status'   => 'duplicate',
                'sha256'   => $f['sha'],
            ];
            continue;
        }

        $dir = ensure_storage_dir($storageBase, $friend['name'], $sid);
        $dstName = safe_storage_filename($f['original']);
        // If a file with the same name already exists in this session, append a counter
        $dstPath = $dir . '/' . $dstName;
        if (file_exists($dstPath)) {
            $info = pathinfo($dstName);
            $base = $info['filename'];
            $ext = $info['extension'] ?? '';
            $n = 1;
            do {
                $candidate = $base . '_' . $n . ($ext ? '.' . $ext : '');
                $candidatePath = $dir . '/' . $candidate;
                $n++;
            } while (file_exists($candidatePath));
            $dstName = $candidate;
            $dstPath = $candidatePath;
        }
        $relPath = substr($dstPath, strlen($storageBase));

        if (!move_uploaded_file($f['tmp'], $dstPath)) {
            throw new RuntimeException('Failed to store ' . $f['original']);
        }

        $clientTs = null;
        if (preg_match('/logs_([0-9]{4})-([0-9]{2})-([0-9]{2})_([0-9]{2})-([0-9]{2})-([0-9]{2})/', $sid, $m)) {
            $clientTs = vsprintf('%04d-%02d-%02d %02d:%02d:%02d', [
                (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], (int)$m[6],
            ]);
        }

        $stmtLog->execute([
            ':session_id' => $sessionId,
            ':friend_id'  => $friend['id'],
            ':filename'   => $f['original'],
            ':size'       => $f['size'],
            ':sha'        => $f['sha'],
            ':client_ts'   => $clientTs,
            ':meta'       => json_encode(['uploader' => 'api']),
            ':path'       => $relPath,
        ]);

        $stmtQueue->execute([
            ':friend_id'  => $friend['id'],
            ':session_id' => $sid,
            ':filename'   => $f['original'],
            ':size'       => $f['size'],
            ':sha'        => $f['sha'],
            ':status'     => 'completed',
            ':remote'     => $host,
        ]);

        $uploaded[] = [
            'filename' => $f['original'],
            'status'   => 'stored',
            'sha256'   => $f['sha'],
        ];
    }

    // Update last_seen_at
    $pdo
        ->prepare('UPDATE friends SET last_seen_at = NOW() WHERE id = :id')
        ->execute([':id' => $friend['id']]);

    $pdo->commit();

    json_response([
        'ok'       => true,
        'friend'   => $friend['name'],
        'uploaded' => $uploaded,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    error_log(
        'ArmaLogs upload exception: ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine() .
        "\n" . $e->getTraceAsString()
    );
    json_error('Server error: ' . $e->getMessage(), 500);
}
