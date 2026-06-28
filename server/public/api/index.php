<?php
declare(strict_types=1);

require_once __DIR__ . '/../_init.php';

require_once INCLUDES_DIR . '/db.php';
require_once INCLUDES_DIR . '/auth.php';
require_once INCLUDES_DIR . '/helpers.php';

// Bootstrap admin API endpoints
$path = $_GET['path'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

require_admin();

if ($path === 'friends') {
    handle_friends($method);
} elseif ($path === 'friend-requests') {
    handle_friend_requests($method);
} elseif ($path === 'friend-requests-approve') {
    handle_friend_request_approve();
} elseif ($path === 'friend-requests-reject') {
    handle_friend_request_reject();
} elseif ($path === 'logs') {
    handle_logs($method);
} elseif ($path === 'stats') {
    handle_stats();
} else {
    json_error('Unknown endpoint', 404);
}

function handle_friends(string $method): void {
    $pdo = db();
    if ($method === 'GET') {
        $stmt = $pdo->query(
            'SELECT f.id, f.name, f.is_active, f.created_at, f.last_seen_at, f.note,
                    COUNT(DISTINCT s.id) AS session_count,
                    COUNT(DISTINCT l.id) AS log_count
             FROM friends f
             LEFT JOIN sessions s ON s.friend_id = f.id
             LEFT JOIN logs l ON l.friend_id = f.id
             GROUP BY f.id
             ORDER BY f.name'
        );
        json_response(['ok' => true, 'friends' => $stmt->fetchAll()]);
    }
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($input['name'] ?? '');
        $note = trim($input['note'] ?? '');
        if ($name === '') {
            json_error('Name is required');
        }
        $token = generate_friend_token();
        $hash = hash('sha256', $token);
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO friends (name, token_hash, note) VALUES (:n, :t, :note)'
            );
            $stmt->execute([':n' => $name, ':t' => $hash, ':note' => $note === '' ? null : $note]);
            $id = (int)$pdo->lastInsertId();
            json_response([
                'ok'    => true,
                'id'    => $id,
                'token' => $token,
                'note'  => 'This token is shown only once. Copy it now.',
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                json_error('Friend name already exists');
            }
            throw $e;
        }
    }
    if ($method === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($input['id'] ?? 0);
        $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : null;
        $note = $input['note'] ?? null;
        if ($id <= 0) {
            json_error('ID required');
        }
        $fields = [];
        $params = [];
        if ($isActive !== null) {
            $fields[] = 'is_active = :a';
            $params[':a'] = $isActive ? 1 : 0;
        }
        if ($note !== null) {
            $fields[] = 'note = :n';
            $params[':n'] = trim($note) === '' ? null : trim($note);
        }
        if (empty($fields)) {
            json_error('Nothing to update');
        }
        $params[':id'] = $id;
        $sql = 'UPDATE friends SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['ok' => true, 'updated' => $stmt->rowCount()]);
    }
    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_error('ID required');
        }
        $stmt = $pdo->prepare('DELETE FROM friends WHERE id = :id');
        $stmt->execute([':id' => $id]);
        json_response(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }
    json_error('Method not allowed', 405);
}

function handle_friend_requests(string $method): void {
    if ($method !== 'GET') {
        json_error('Method not allowed', 405);
    }
    $pdo = db();
    $status = $_GET['status'] ?? 'pending';
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
        json_error('Invalid status');
    }
    $sql = 'SELECT id, name, hostname, status, created_at, decided_at FROM friend_requests';
    if ($status !== 'all') {
        $sql .= ' WHERE status = :s';
    }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    if ($status !== 'all') {
        $stmt->execute([':s' => $status]);
    } else {
        $stmt->execute();
    }
    json_response(['ok' => true, 'requests' => $stmt->fetchAll()]);
}

function handle_friend_request_approve(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('POST required', 405);
    }
    $pdo = db();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        json_error('ID required');
    }

    $stmt = $pdo->prepare('SELECT * FROM friend_requests WHERE id = :id AND status = "pending" LIMIT 1');
    $stmt->execute([':id' => $id]);
    $req = $stmt->fetch();
    if (!$req) {
        json_error('Request not found or already decided', 404);
    }

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO friends (name, token_hash, note) VALUES (:n, :t, NULL)');
        $ins->execute([':n' => $req['name'], ':t' => $req['token_hash']]);
        $upd = $pdo->prepare('UPDATE friend_requests SET status = "approved", decided_at = NOW() WHERE id = :id');
        $upd->execute([':id' => $id]);
        $pdo->commit();
        json_response(['ok' => true, 'friend_id' => (int)$pdo->lastInsertId()]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) {
            json_error('A friend with that name already exists');
        }
        throw $e;
    }
}

function handle_friend_request_reject(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('POST required', 405);
    }
    $pdo = db();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        json_error('ID required');
    }
    $stmt = $pdo->prepare('UPDATE friend_requests SET status = "rejected", decided_at = NOW() WHERE id = :id AND status = "pending"');
    $stmt->execute([':id' => $id]);
    json_response(['ok' => true, 'updated' => $stmt->rowCount()]);
}

function handle_logs(string $method): void {
    if ($method === 'GET') {
        serve_logs_list();
        return;
    }
    if ($method === 'POST') {
        serve_logs_zip();
        return;
    }
    json_error('Method not allowed', 405);
}

function serve_logs_list(): void {
    $pdo = db();
    $friendId = isset($_GET['friend_id']) ? (int)$_GET['friend_id'] : null;
    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $sql = 'SELECT l.id, l.friend_id, l.session_id AS session_db_id, s.session_id,
                   f.name AS friend_name, l.filename, l.file_size, l.content_sha256,
                   l.client_timestamp, l.uploaded_at, l.storage_path
            FROM logs l
            JOIN friends f ON f.id = l.friend_id
            LEFT JOIN sessions s ON s.id = l.session_id';
    $where = [];
    $params = [];
    if ($friendId) {
        $where[] = 'l.friend_id = :fid';
        $params[':fid'] = $friendId;
    }
    if ($sessionId) {
        $where[] = 'l.session_id = :sid';
        $params[':sid'] = $sessionId;
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY l.uploaded_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    json_response(['ok' => true, 'logs' => $stmt->fetchAll()]);
}

function serve_logs_zip(): void {
    $pdo = db();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = array_map('intval', (array)($input['ids'] ?? []));
    if (empty($ids)) {
        json_error('No log IDs selected');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT l.id, l.filename, f.name AS friend_name, s.session_id, l.storage_path
         FROM logs l
         JOIN friends f ON f.id = l.friend_id
         LEFT JOIN sessions s ON s.id = l.session_id
         WHERE l.id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        json_error('Logs not found', 404);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'armalogs_zip_');
    if ($tmp === false) {
        json_error('Failed to create temp file', 500);
    }
    unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        json_error('Failed to create zip', 500);
    }

    $base = config('storage_dir', '/app/data/storage/logs');
    foreach ($rows as $row) {
        $path = rtrim($base, '/') . '/' . ltrim($row['storage_path'], '/');
        if (!file_exists($path)) {
            continue;
        }
        $arcName = ($row['friend_name'] ?? 'unknown') . '/' . ($row['session_id'] ?? 'unknown') . '/' . $row['filename'];
        $zip->addFile($path, $arcName);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="armalogs_' . date('Y-m-d_H-i-s') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function handle_stats(): void {
    $pdo = db();
    $friends = $pdo->query('SELECT COUNT(*) FROM friends')->fetchColumn();
    $sessions = $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    $logs = $pdo->query('SELECT COUNT(*), COALESCE(SUM(file_size), 0) FROM logs')->fetch(PDO::FETCH_NUM);
    $latest = $pdo->query(
        'SELECT f.name, l.filename, l.uploaded_at FROM logs l
         JOIN friends f ON f.id = l.friend_id
         ORDER BY l.uploaded_at DESC LIMIT 1'
    )->fetch();
    json_response([
        'ok'         => true,
        'friends'    => (int)$friends,
        'sessions'   => (int)$sessions,
        'logs'       => (int)$logs[0],
        'bytes'      => (int)$logs[1],
        'latest_log' => $latest ?: null,
    ]);
}
