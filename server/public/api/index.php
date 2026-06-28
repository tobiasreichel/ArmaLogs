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
} elseif ($path === 'analyze') {
    handle_analyze();
} elseif ($path === 'reports') {
    handle_reports($method);
} elseif ($path === 'log-content') {
    handle_log_content();
} elseif ($path === 'session-timeline') {
    handle_session_timeline();
} elseif ($path === 'archive') {
    handle_archive();
} elseif ($path === 'stats') {
    handle_stats();
} else {
    json_error('Unknown endpoint', 404);
}

function handle_log_content(): void {
    $pdo = db();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Log ID required');
    }
    $stmt = $pdo->prepare(
        'SELECT l.id, l.filename, l.file_size, l.content_sha256, l.client_timestamp, l.uploaded_at, l.storage_path, f.name AS friend_name, s.session_id
         FROM logs l
         JOIN friends f ON f.id = l.friend_id
         LEFT JOIN sessions s ON s.id = l.session_id
         WHERE l.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Log not found', 404);
    }
    $base = rtrim(config()['paths']['storage'] ?? '/app/data/storage/logs', '/');
    $path = $base . '/' . ltrim($row['storage_path'], '/');
    if (!file_exists($path)) {
        json_error('Log file missing on disk', 404);
    }
    $text = file_get_contents($path);
    if ($text === false) {
        json_error('Unable to read log file');
    }
    json_response([
        'ok'      => true,
        'log'     => $row,
        'content' => $text,
    ]);
}

function handle_session_timeline(): void {
    $pdo = db();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Log ID required');
    }
    $stmt = $pdo->prepare(
        'SELECT l.id, l.filename, l.storage_path, l.file_size, f.name AS friend_name, s.session_id
         FROM logs l
         JOIN friends f ON f.id = l.friend_id
         LEFT JOIN sessions s ON s.id = l.session_id
         WHERE l.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Log not found', 404);
    }
    $base = rtrim(config()['paths']['storage'] ?? '/app/data/storage/logs', '/');
    $path = $base . '/' . ltrim($row['storage_path'], '/');
    if (!file_exists($path)) {
        json_response(['ok' => true, 'log' => $row, 'events' => []]);
    }
    $text = file_get_contents($path);
    if ($text === false || $text === '') {
        json_response(['ok' => true, 'log' => $row, 'events' => []]);
    }

    $events = parse_log_timeline($text);
    json_response([
        'ok'     => true,
        'log'    => $row,
        'events' => $events,
    ]);
}

function parse_log_timeline(string $text): array {
    $events = [];
    $lines = explode("\n", $text);
    $seenSessionStart = false;
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }
        // Pull HH:MM:SS timestamp from the typical Arma log prefix
        $ts = null;
        if (preg_match('/^([0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]+)\s/', $trim, $m)) {
            $ts = $m[1];
        }
        $lower = strtolower($trim);
        $ev = null;
        $level = 'info';
        if (!$seenSessionStart && preg_match('/START|ENGINE START|Back	have|Game engine init/i', $trim)) {
            $seenSessionStart = true;
            $ev = ['type' => 'start', 'label' => 'Session / engine start'];
        } elseif (preg_match('/scenario_exit|application quit|exiting/i', $lower)) {
            $ev = ['type' => 'end', 'label' => 'Scenario exit / application quit'];
        } elseif (preg_match('/connecting to server|joined server/i', $lower)) {
            $ev = ['type' => 'connect', 'label' => 'Connecting to server'];
        } elseif (preg_match('/mission loaded|loaded mission headers from \d+ addon/i', $lower)) {
            $ev = ['type' => 'mission', 'label' => 'Mission / addon load'];
        } elseif (preg_match('/\.mdmp|ENGINE \(F\): Crashed|crash/i', $lower)) {
            $ev = ['type' => 'crash', 'label' => 'Crash / native error'];
            $level = 'critical';
        } elseif (preg_match('/JWK_ShouldForceFirstPerson|m_iThirdPersonCameraMode/i', $trim)) {
            $ev = ['type' => 'mod_issue', 'label' => 'JWK first-person camera hook'];
            $level = 'warning';
        } elseif (preg_match('/WCS_.*Unknown class|WCS_.*obsolete|WCS_Core_/i', $trim)) {
            $ev = ['type' => 'mod_issue', 'label' => 'WCS mod mismatch'];
            $level = 'warning';
        } elseif (preg_match('/Wrong GUID\/name for resource|Unknown class.*SCR_|Missing material/i', $trim)) {
            $ev = ['type' => 'mod_issue', 'label' => 'Missing / broken resource'];
            $level = 'warning';
        } elseif (preg_match('/Can\'t instantiate class|Wrong GUID.*configs\/factions/i', $trim)) {
            $ev = ['type' => 'mod_issue', 'label' => 'Faction / prefab failure'];
            $level = 'warning';
        }
        if ($ev !== null) {
            $ev['timestamp'] = $ts;
            $ev['level'] = $level;
            $ev['line'] = substr($trim, 0, 240);
            $events[] = $ev;
        }
    }
    return $events;
}

function handle_archive(): void {
    require_admin();
    $cfg = config();
    $days = (int)($cfg['archive']['log_retention_days'] ?? 30);
    if ($days <= 0) {
        json_error('log_retention_days must be > 0');
    }
    $pdo = db();
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $stmt = $pdo->prepare(
        'SELECT id, storage_path, session_id, friend_id FROM logs WHERE uploaded_at < :cutoff ORDER BY id'
    );
    $stmt->execute([':cutoff' => $cutoff]);
    $rows = $stmt->fetchAll();

    $storageBase = rtrim($cfg['paths']['storage'] ?? '/app/data/storage/logs', '/');
    $deletedFiles = 0;
    $deletedBytes = 0;
    $logIds = [];
    foreach ($rows as $row) {
        $path = $storageBase . '/' . ltrim($row['storage_path'], '/');
        if (file_exists($path)) {
            $size = filesize($path);
            if (unlink($path)) {
                $deletedFiles++;
                $deletedBytes += $size;
            }
        }
        $logIds[] = (int)$row['id'];
    }

    if (!empty($logIds)) {
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
        $pdo->prepare("DELETE FROM logs WHERE id IN ($placeholders)")->execute($logIds);
    }

    // Clean up empty session directories older than cutoff if they have no logs left
    $stmt = $pdo->prepare(
        'SELECT s.id, s.friend_id, s.session_id FROM sessions s
         LEFT JOIN logs l ON l.session_id = s.id
         WHERE s.uploaded_at < :cutoff AND l.id IS NULL'
    );
    $stmt->execute([':cutoff' => $cutoff]);
    $emptySessions = $stmt->fetchAll();
    $sessionIds = array_map(fn($r) => (int)$r['id'], $emptySessions);
    if (!empty($sessionIds)) {
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $pdo->prepare("DELETE FROM sessions WHERE id IN ($placeholders)")->execute($sessionIds);
    }

    json_response([
        'ok' => true,
        'cutoff' => $cutoff,
        'deleted_files' => $deletedFiles,
        'deleted_bytes' => $deletedBytes,
        'deleted_log_rows' => count($logIds),
        'deleted_session_rows' => count($sessionIds),
    ]);
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

function handle_archive(): void {
    require_admin();
    $cfg = config();
    $days = (int)($cfg['archive']['log_retention_days'] ?? 30);
    if ($days <= 0) {
        json_error('log_retention_days must be > 0');
    }
    $pdo = db();
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $stmt = $pdo->prepare(
        'SELECT id, storage_path, session_id, friend_id FROM logs WHERE uploaded_at < :cutoff ORDER BY id'
    );
    $stmt->execute([':cutoff' => $cutoff]);
    $rows = $stmt->fetchAll();

    $storageBase = rtrim($cfg['paths']['storage'] ?? '/app/data/storage/logs', '/');
    $deletedFiles = 0;
    $deletedBytes = 0;
    $logIds = [];
    foreach ($rows as $row) {
        $path = $storageBase . '/' . ltrim($row['storage_path'], '/');
        if (file_exists($path)) {
            $size = filesize($path);
            if (unlink($path)) {
                $deletedFiles++;
                $deletedBytes += $size;
            }
        }
        $logIds[] = (int)$row['id'];
    }

    if (!empty($logIds)) {
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
        $pdo->prepare("DELETE FROM logs WHERE id IN ($placeholders)")->execute($logIds);
    }

    $stmt = $pdo->prepare(
        'SELECT s.id, s.friend_id, s.session_id FROM sessions s
         LEFT JOIN logs l ON l.session_id = s.id
         WHERE s.uploaded_at < :cutoff AND l.id IS NULL'
    );
    $stmt->execute([':cutoff' => $cutoff]);
    $emptySessions = $stmt->fetchAll();
    $sessionIds = array_map(fn($r) => (int)$r['id'], $emptySessions);
    if (!empty($sessionIds)) {
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $pdo->prepare("DELETE FROM sessions WHERE id IN ($placeholders)")->execute($sessionIds);
    }

    json_response([
        'ok' => true,
        'cutoff' => $cutoff,
        'deleted_files' => $deletedFiles,
        'deleted_bytes' => $deletedBytes,
        'deleted_log_rows' => count($logIds),
        'deleted_session_rows' => count($sessionIds),
    ]);
}

function serve_logs_list(): void {
    $pdo = db();
    $friendId = isset($_GET['friend_id']) ? (int)$_GET['friend_id'] : null;
    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit < 0) { $limit = 50; }
    if ($limit > 0 && $limit > 10000) { $limit = 10000; }
    $offset = (int)($_GET['offset'] ?? 0);
    $sql = 'SELECT l.id, l.friend_id, l.session_id AS session_db_id, s.session_id, s.workshop_mod_count,
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

    $base = rtrim(config()['paths']['storage'] ?? '/app/data/storage/logs', '/');
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
    $reports = $pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn();
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
        'reports'    => (int)$reports,
        'latest_log' => $latest ?: null,
    ]);
}

function handle_analyze(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('POST required', 405);
    }
    $cfg = config()['ai'] ?? null;
    if (!$cfg || !($cfg['enabled'] ?? false) || ($cfg['api_key'] ?? '') === '') {
        json_error('AI analysis is not configured');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = array_map('intval', (array)($input['ids'] ?? []));
    if (empty($ids)) {
        json_error('No log IDs selected');
    }

    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT l.id, l.filename, f.name AS friend_name, s.session_id, l.storage_path, l.file_size, l.session_id AS session_db_id, l.friend_id
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

    // Sanity-check that selected log IDs actually belong to the requested friend/session scope.
    $friendIds = [];
    $sessionIds = [];
    $rowIdMap = [];
    foreach ($rows as $row) {
        $friendIds[$row['friend_id']] = true;
        $sessionIds[$row['session_db_id'] ?? 0] = true;
        $rowIdMap[$row['id']] = $row;
    }
    if (count($ids) !== count($rowIdMap)) {
        json_error('Some selected log IDs were not found or are not accessible', 400);
    }

    $base = rtrim(config()['paths']['storage'] ?? '/app/data/storage/logs', '/');
    $maxChars = (int)($cfg['max_chars'] ?? 200_000);
    $context = "";
    $read = 0;
    foreach ($rows as $row) {
        $path = $base . '/' . ltrim($row['storage_path'], '/');
        if (!file_exists($path)) {
            continue;
        }
        $text = file_get_contents($path);
        if ($text === false) {
            continue;
        }
        // Trim huge files from the tail; keep start + end
        $remaining = $maxChars - strlen($context);
        if ($remaining <= 0) {
            break;
        }
        $header = "\n\n===== BEGIN LOG: friend=" . $row['friend_name'] . ", session=" . ($row['session_id'] ?? 'unknown') . ", file=" . $row['filename'] . " =====\n";
        $footer = "\n===== END LOG: " . $row['filename'] . " =====\n";
        $budget = $remaining - strlen($header) - strlen($footer);
        if ($budget <= 0) {
            break;
        }
        if (strlen($text) > $budget) {
            $half = (int)($budget / 2);
            $text = substr($text, 0, $half) . "\n\n... [truncated] ...\n\n" . substr($text, -$half);
        }
        $context .= $header . $text . $footer;
        $read++;
    }

    if ($context === '') {
        json_error('No readable log content');
    }

    $report = call_ai($cfg, $rows, $context);
    if ($report === null) {
        json_error('AI analysis failed');
    }

    $first = $rows[0];
    $multiFriend = count($friendIds) > 1;
    $multiSession = count($sessionIds) > 1;
    $title = $multiFriend
        ? ('multi_' . $first['friend_name'] . '_' . $first['session_id'])
        : ($multiSession
            ? ('multi_session_' . $first['friend_name'] . '_' . $first['session_id'])
            : ($first['friend_name'] . '_' . $first['session_id']));
    $ins = $pdo->prepare(
        'INSERT INTO reports (friend_id, session_id, share_token, log_ids, title, summary, findings, model, markdown, is_multi_friend, is_multi_session) VALUES (:fid, :sid, :tok, :lids, :title, :summary, :findings, :model, :markdown, :multi_friend, :multi_session)'
    );
    $ins->execute([
        ':fid'          => $multiFriend ? null : ($first['friend_id'] ?? null),
        ':sid'          => $multiSession ? null : ($first['session_db_id'] ?? null),
        ':tok'          => bin2hex(random_bytes(16)),
        ':lids'         => json_encode($ids),
        ':title'        => $title,
        ':summary'      => $report['summary'],
        ':findings'     => json_encode($report['findings']),
        ':model'        => $cfg['model'],
        ':markdown'     => $report['markdown'] ?? '',
        ':multi_friend'  => $multiFriend ? 1 : 0,
        ':multi_session'=> $multiSession ? 1 : 0,
    ]);
    $reportId = (int)$pdo->lastInsertId();

    json_response([
        'ok'       => true,
        'report'   => $report,
        'report_id' => $reportId,
        'read'     => $read,
        'truncated' => strlen($context) >= $maxChars,
    ]);
}

function call_ai(array $cfg, array $rows, string $context): ?array {
    $provider = $cfg['provider'] ?? 'anthropic';
    if ($provider === 'ollama') {
        return call_ollama($cfg, $rows, $context);
    }
    if ($provider === 'openai') {
        return call_openai($cfg, $rows, $context);
    }
    return call_anthropic($cfg, $rows, $context);
}

function call_anthropic(array $cfg, array $rows, string $context): ?array {
    $apiKey = $cfg['api_key'];
    $model = $cfg['model'];
    $maxTokens = (int)($cfg['max_tokens'] ?? 4096);
    $baseUrl = rtrim($cfg['base_url'] ?? '', '/');
    $friend = $rows[0]['friend_name'] ?? 'unknown';
    $session = $rows[0]['session_id'] ?? 'unknown';

    $system = "You are an expert Arma Reforger server log analyst." .
        " Write a well-structured Markdown report with a title, summary, and a findings section." .
        " Do not return JSON. Do not wrap the response in markdown code fences." .
        " Return only the raw Markdown text.";

    $prompt = "Analyze the following Arma Reforger log(s) from friend '$friend' (session '$session').\n\n" .
        "Return a Markdown report exactly in this format:\n\n" .
        "# Title\\n\\n" .
        "## Summary\\n\\n" .
        "2-4 sentence overview. Use **bold** for important terms. Use line breaks for readability.\\n\\n" .
        "## Findings\\n\\n" .
        "### :red_circle: Critical: Short title\\n" .
        "Concise explanation. **Bold** key evidence.\\n\\n" .
        "### :warning: Warning: Short title\\n" .
        "Concise explanation.\\n\\n" .
        "### :blue_circle: Info: Short title\\n" .
        "Concise explanation.\\n\\n" .
        "Rules:\n" .
        "- Use '## Summary' and '## Findings' headers exactly.\n" .
        "- Each finding starts with '### :red_circle: Critical:', '### :warning: Warning:', or '### :blue_circle: Info:' followed by a short title.\n" .
        "- Only use 'Critical' for crashes, fatal exceptions, or game-breaking bugs.\n" .
        "- Use 'Warning' for notable errors, missing assets, stutters, or performance issues.\n" .
        "- Use 'Info' for minor noise, normal startup/shutdown, or cosmetic issues.\n" .
        "- Keep titles under 80 characters.\n" .
        "- Keep each finding details to 1-3 sentences.\n" .
        "- If nothing important happened, write a short summary and omit the Findings section.\n\n" .
        "Focus on: game crashes, exceptions, low FPS/stutter events, network timeouts, RCON/admin actions.\n\n" .
        "LOG CONTENT:\n" . $context . "\n\nReturn only raw Markdown.";

    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $url = $baseUrl === '' ? 'https://api.anthropic.com/v1/messages' : $baseUrl . '/v1/messages';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 300,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $err !== '') {
        error_log('Claude API error: ' . $err);
        return null;
    }

    $data = json_decode($resp, true);
    if (empty($data['content'][0]['text'])) {
        error_log('Claude API unexpected response: ' . $resp);
        return null;
    }

    $markdown = trim($data['content'][0]['text']);
    if (preg_match('/^```markdown\s*(.*?)\s*```$/s', $markdown, $m)) {
        $markdown = trim($m[1]);
    } elseif (preg_match('/^```\s*(.*?)\s*```$/s', $markdown, $m)) {
        $markdown = trim($m[1]);
    }

    $report = parse_markdown_report($markdown);
    return [
        'title'    => $report['title'] ?: 'AI report',
        'summary'  => $report['summary'] ?: $markdown,
        'findings' => $report['findings'],
        'markdown' => $markdown,
    ];
}



function call_openai(array $cfg, array $rows, string $context): ?array {
    $baseUrl = rtrim($cfg['base_url'] ?? '', '/');
    $apiKey = $cfg['api_key'] ?? '';
    $model = $cfg['model'];
    $maxTokens = (int)($cfg['max_tokens'] ?? 4096);
    $friend = $rows[0]['friend_name'] ?? 'unknown';
    $session = $rows[0]['session_id'] ?? 'unknown';

    if ($baseUrl === '') {
        error_log('OpenAI base_url is empty');
        return null;
    }

    $system = "You are an expert Arma Reforger server log analyst." .
        " Write a well-structured Markdown report with a title, summary, and a findings section." .
        " Do not return JSON. Do not wrap the response in markdown code fences." .
        " Return only the raw Markdown text.";

    $userPrompt = "Analyze the following Arma Reforger log(s) from friend '$friend' (session '$session').\n\n" .
        "Return a Markdown report exactly in this format:\n\n" .
        "# Title\\n\\n" .
        "## Summary\\n\\n" .
        "2-4 sentence overview. Use **bold** for important terms. Use line breaks for readability.\\n\\n" .
        "## Findings\\n\\n" .
        "### :red_circle: Critical: Short title\\n" .
        "Concise explanation. **Bold** key evidence.\\n\\n" .
        "### :warning: Warning: Short title\\n" .
        "Concise explanation.\\n\\n" .
        "### :blue_circle: Info: Short title\\n" .
        "Concise explanation.\\n\\n" .
        "Rules:\n" .
        "- Use '## Summary' and '## Findings' headers exactly.\n" .
        "- Each finding starts with '### :red_circle: Critical:', '### :warning: Warning:', or '### :blue_circle: Info:' followed by a short title.\n" .
        "- Only use 'Critical' for crashes, fatal exceptions, or game-breaking bugs.\n" .
        "- Use 'Warning' for notable errors, missing assets, stutters, or performance issues.\n" .
        "- Use 'Info' for minor noise, normal startup/shutdown, or cosmetic issues.\n" .
        "- Keep titles under 80 characters.\n" .
        "- Keep each finding details to 1-3 sentences.\n" .
        "- If nothing important happened, write a short summary and omit the Findings section.\n\n" .
        "Focus on: game crashes, exceptions, low FPS/stutter events, network timeouts, RCON/admin actions.\n\n" .
        "LOG CONTENT:\n" . $context . "\n\nReturn only raw Markdown.";

    $payload = [
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'temperature' => 0.2,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $url = $baseUrl . '/v1/chat/completions';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 300,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $err !== '') {
        error_log('OpenAI API error: ' . $err);
        return null;
    }

    $data = json_decode($resp, true);
    if (empty($data['choices'][0]['message']['content'])) {
        error_log('OpenAI API unexpected response: ' . $resp);
        return null;
    }

    $markdown = trim($data['choices'][0]['message']['content']);
    if (preg_match('/^```markdown\s*(.*?)\s*```$/s', $markdown, $m)) {
        $markdown = trim($m[1]);
    } elseif (preg_match('/^```\s*(.*?)\s*```$/s', $markdown, $m)) {
        $markdown = trim($m[1]);
    }

    $report = parse_markdown_report($markdown);
    return [
        'title'    => $report['title'] ?: 'AI report',
        'summary'  => $report['summary'] ?: $markdown,
        'findings' => $report['findings'],
        'markdown' => $markdown,
    ];
}

function call_ollama(array $cfg, array $rows, string $context): ?array {
    $baseUrl = rtrim($cfg['base_url'] ?? '', '/');
    $apiKey = $cfg['api_key'] ?? '';
    $model = $cfg['model'];
    $friend = $rows[0]['friend_name'] ?? 'unknown';
    $session = $rows[0]['session_id'] ?? 'unknown';

    if ($baseUrl === '') {
        error_log('Ollama base_url is empty');
        return null;
    }

    $system = <<<'SYS'
You are an expert Arma Reforger server and client log analyst. Your job is to read Enfusion console/crash/script logs and produce a concise, actionable Markdown report for a server admin who needs to decide what to fix.

Be ruthless about signal vs noise:
- A million logged exceptions is not a crash. Focus on per-frame null-pointer loops, spawn failures, and hard native crashes.
- One Virtual Machine Exception in OnUpdate/EOnFixedFrame is worse than 10,000 one-off content warnings because it fires 60 times per second.
- A .mdmp file with no VM exceptions in the same session is a native engine crash; note that and look at the previous session for the script-side trigger.

Common root-cause patterns you MUST recognize:
- `SCR_CharacterCameraHandlerComponent::JWK_ShouldForceFirstPerson` with `m_iThirdPersonCameraMode` NULL → JWK Framework first-person camera hook; causes severe stutter. Usually triggered by a broken inventory/arsenal catalog.
- `SCR_InventoryMenuUI::UpdateItemInfoPosition` per-frame null → inventory UI stutter. Root cause is usually a weapon/attachment mod with missing `UIInfo`.
- `SCR_InventoryMenuUI::HighlightAvailableStorages` / `JWK_InfiniteInventoryComponent::OnItemRemoved_S` / `JWK_ShopInterfaceUIComponent::SetupCard` null → broken catalog entries from outdated weapon or JWK-dependent mods.
- `Unknown class 'ADSS_*'` → missing or outdated `ADSSway - Core` dependency, or `Better Weapon Immersion 2.8` (`BetterRecoil`) mod injecting classes into vanilla weapon base prefabs.
- `component X cannot be combined with component Y` followed by `CreateEntityServer` NULL → vehicle/character prefab cannot spawn because two components conflict. Often caused by `Car Radio4All` injecting `RadioBroadcastSoundComponent`.
- `JWK_AmbientTrafficSystem` spawn failures or `AttachVehicle failed, missing VehicleRoadUserComponent on: NULL` → ambient traffic prefab missing components, cascades into `JWK_BodiesGarbageCollectorGrid` division-by-zero.
- `RVX_WeaponInfoManager::Init` with `mfdEntity` NULL, `SCR_MapWeatherUI::OnMapOpen/Close` → OH-58D Kiowa / MFD Framework version mismatch.
- `0xc0000374` heap corruption in a session with no VM exceptions → secondary crash; primary offender is usually in the previous session.
- `ENGINE (F): Crashed` with a clear mod/prefab line just before it → that mod is the immediate trigger.

Mod-count rules:
- Use the exact number from the log line `Loaded mission headers from N addon(s)!` when reporting how many addons were loaded.
- Do not round to vague terms like "300+" or "hundreds of mods". State the exact N.
- The count of unique addon GUIDs appearing anywhere in the log may be higher than the loaded count; report it separately as "N addons referenced/available, M loaded" only if you have both numbers.
- If multiple sessions are compared, give the loaded addon count for each session separately. Never attribute one session's mod count to another session.

Output rules:
- Use only Markdown. No JSON. No markdown code fences around the whole report.
- Start with one sharp TL;DR sentence. It must be specific and not catastrophize. Examples: "Session is healthy with minor mod warnings." / "Severe per-frame JWK camera/inventory stutter from a broken catalog." / "Native engine crash likely caused by earlier script-side memory corruption." / "Multiple vehicle prefabs fail to spawn due to a component conflict."
- Then a short ## Summary (3-5 sentences). Bold the key mod names and class/function names. State what actually happened in the log, not speculation beyond the evidence.
- Then ## Findings with ### Severity: Title sub-headings. Severity must be one of: Critical, Warning, Info.
- For each finding, include: (1) the likely offending mod or class, (2) the exact evidence pattern that points to it, (3) the concrete next step to fix it.
- Maximum 6 findings. Merge similar repeated exceptions into one finding.
- Do not paste long log excerpts. Cite a single representative line if needed.
- If the session is basically healthy, write only the TL;DR and Summary and omit Findings.
- Do not claim the mission ended, the game crashed to desktop, or players were kicked unless the log contains explicit evidence such as "ENGINE (F): Crashed", a .mdmp file, or a clear disconnect/disconnect line.
SYS;

    $userPrompt = "Analyze the following Arma Reforger log(s) from friend '$friend' (session '$session').\n\n" .
        "Return a Markdown report with:\n" .
        "1. A one-sentence TL;DR at the top.\n" .
        "2. A ## Summary section (3-5 sentences). Bold the key mod names and class/function names. State what actually happened in the log, not speculation beyond the evidence.\n" .
        "3. A ## Findings section with severity-ranked subsections only if there is something actionable. Use ### Critical: ..., ### Warning: ..., ### Info: ....\n\n" .
        "Prioritize in this order:\n" .
        "1. Hard native crashes (.mdmp files, ENGINE (F): Crashed, heap corruption).\n" .
        "2. Per-frame null-pointer exceptions in OnUpdate / EOnFixedFrame that cause stutter.\n" .
        "3. Spawn failures from component conflicts or missing classes.\n" .
        "4. Missing mod dependencies (Unknown class, Wrong GUID/name for resource).\n" .
        "5. FPS / memory degradation.\n" .
        "6. Cosmetic content warnings.\n\n" .
        "For each Critical or Warning finding, include:\n" .
        "- **Impact score (1-5):** 5 = game unplayable / crash, 4 = major stutter or spawn broken, 3 = noticeable bug, 2 = minor annoyance, 1 = cosmetic only.\n" .
        "- **Offending mod/class:** name the mod or class responsible.\n" .
        "- **Workshop / addon IDs:** if the log mentions addon GUIDs like {A1B2C3D4...} or Workshop IDs, quote them.\n" .
        "- **Evidence pattern:** one or two representative log lines or the exact error signature.\n" .
        "- **Concrete fix:** the next step the admin should take.\n\n" .
        "If the provided context includes multiple friends or multiple sessions for the same player, add a short ## Cross-Check section at the end noting:\n" .
        "- Treat each BEGIN/END LOG block as a separate session. Do not combine unrelated sessions into a single story or timeline.\n" .
        "- Do not assume all selected logs are from the same server, mission, or modset.\n" .
        "- If logs are from different friends, compare whether the same exceptions appear across clients (server/modset issue) vs one client only (local issue).\n" .
        "- If logs are from different sessions for the same player, note whether the issue is getting worse, getting better, or new.\n\n" .
        "Do not claim the mission ended, the game crashed to desktop, or players were kicked unless the log contains explicit evidence such as 'ENGINE (F): Crashed', a .mdmp file, or a clear disconnect line.\n\n" .
        "LOG CONTENT:\n" . $context . "\n\nReturn only raw Markdown.";

    $payload = [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'stream' => false,
        'options' => [
            'num_ctx' => (int)($cfg['num_ctx'] ?? 8192),
        ],
    ];

    $url = str_ends_with($baseUrl, '/api') ? $baseUrl . '/chat' : $baseUrl . '/api/chat';
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 300,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $err !== '') {
        error_log('Ollama API error: ' . $err);
        return null;
    }

    $data = json_decode($resp, true);
    if (empty($data['message']['content'])) {
        error_log('Ollama API unexpected response: ' . $resp);
        return null;
    }

    $markdown = trim($data['message']['content']);
    if (preg_match('/^```markdown\s*(.*?)\s*```$/s', $markdown, $m)) {
        $markdown = trim($m[1]);
    } elseif (preg_match('/^```\s*(.*?)\s*```$/s', $markdown, $m)) {
        $markdown = trim($m[1]);
    }

    $report = parse_markdown_report($markdown);
    return [
        'title'    => $report['title'] ?: 'AI report',
        'summary'  => $report['summary'] ?: $markdown,
        'findings' => $report['findings'],
        'markdown' => $markdown,
    ];
}

function parse_markdown_report(string $markdown): array {
    $title = '';
    $summary = '';
    $findings = [];

    if (preg_match('/^#\s+(.+)$/m', $markdown, $m)) {
        $title = trim($m[1]);
    }

    if (preg_match('/##\s+Summary\s*\n(.*?)(?=\n##\s+Findings|\n###\s+[:\w]|$)/s', $markdown, $m)) {
        $summary = trim($m[1]);
    }

    if (preg_match_all('/###\s+([^\n]+)\n(.*?)(?=\n###\s+|\n##\s+|\z)/s', $markdown, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $heading = trim($match[1]);
            $body = trim($match[2]);
            $severity = 'info';
            $category = 'other';
            $findingTitle = $heading;

            if (preg_match('/:red_circle:\s*Critical:\s*(.+)/i', $heading, $hm)) {
                $severity = 'critical';
                $findingTitle = trim($hm[1]);
            } elseif (preg_match('/:warning:\s*Warning:\s*(.+)/i', $heading, $hm)) {
                $severity = 'warning';
                $findingTitle = trim($hm[1]);
            } elseif (preg_match('/:blue_circle:\s*Info:\s*(.+)/i', $heading, $hm)) {
                $severity = 'info';
                $findingTitle = trim($hm[1]);
            } elseif (preg_match('/Critical:\s*(.+)/i', $heading, $hm)) {
                $severity = 'critical';
                $findingTitle = trim($hm[1]);
            } elseif (preg_match('/Warning:\s*(.+)/i', $heading, $hm)) {
                $severity = 'warning';
                $findingTitle = trim($hm[1]);
            } elseif (preg_match('/Info:\s*(.+)/i', $heading, $hm)) {
                $severity = 'info';
                $findingTitle = trim($hm[1]);
            }

            $findings[] = [
                'severity' => $severity,
                'category' => $category,
                'title'    => $findingTitle,
                'details'  => $body,
            ];
        }
    }

    return [
        'title'    => $title,
        'summary'  => $summary,
        'findings' => $findings,
    ];
}

function handle_reports(string $method): void {
    $pdo = db();
    if ($method === 'GET') {
        $pdfId = isset($_GET['pdf']) ? (int)$_GET['pdf'] : 0;
        if ($pdfId > 0) {
            serve_report_pdf($pdfId);
            return;
        }
        $stmt = $pdo->query(
            'SELECT r.id, r.title, r.summary, r.findings, r.markdown, r.model, r.created_at, r.share_token, f.name AS friend_name, s.session_id, r.is_multi_friend, r.is_multi_session
             FROM reports r
             LEFT JOIN friends f ON f.id = r.friend_id
             LEFT JOIN sessions s ON s.id = r.session_id
             ORDER BY r.created_at DESC
             LIMIT 1000'
        );
        json_response(['ok' => true, 'reports' => $stmt->fetchAll()]);
        return;
    }
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_error('ID required');
        }
        $stmt = $pdo->prepare('SELECT share_token FROM reports WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_error('Report not found', 404);
        }
        if (empty($row['share_token'])) {
            $token = bin2hex(random_bytes(16));
            $upd = $pdo->prepare('UPDATE reports SET share_token = :tok WHERE id = :id');
            $upd->execute([':tok' => $token, ':id' => $id]);
        } else {
            $token = $row['share_token'];
        }
        json_response(['ok' => true, 'share_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'armalogs.reichel.network') . '/share.php?token=' . $token]);
        return;
    }
    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_error('ID required');
        }
        $stmt = $pdo->prepare('DELETE FROM reports WHERE id = :id');
        $stmt->execute([':id' => $id]);
        json_response(['ok' => true, 'deleted' => $stmt->rowCount()]);
        return;
    }
    json_error('Method not allowed', 405);
}


function serve_report_pdf(int $reportId): void {
    require_once INCLUDES_DIR . '/pdf_report.php';

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT r.id, r.title, r.markdown, r.model, r.created_at, f.name AS friend_name, s.session_id, r.is_multi_friend, r.is_multi_session
         FROM reports r
         LEFT JOIN friends f ON f.id = r.friend_id
         LEFT JOIN sessions s ON s.id = r.session_id
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $reportId]);
    $report = $stmt->fetch();
    if (!$report) {
        json_error('Report not found', 404);
    }

    $pdfData = markdown_to_pdf($report);
    $filename = 'report_' . $reportId . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $report['title'] ?? 'report') . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
}
