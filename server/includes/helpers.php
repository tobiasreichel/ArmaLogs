<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function json_error(string $message, int $code = 400): void {
    json_response(['ok' => false, 'error' => $message], $code);
}

function html_safe(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ensure_storage_dir(string $base, string $friendName, string $sessionId): string {
    $safeFriend = preg_replace('/[^a-zA-Z0-9_-]/', '_', $friendName) ?: 'friend';
    $safeSession = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionId) ?: 'unknown';
    $dir = $base . '/' . date('Y-m') . '/' . $safeFriend . '/' . $safeSession;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create storage directory');
    }
    return $dir;
}

function safe_storage_filename(string $original): string {
    $info = pathinfo($original);
    $base = $info['filename'] ?? 'file';
    $ext = $info['extension'] ?? 'log';
    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base) ?: 'file';
    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?: 'log';
    return $safeBase . '.' . $safeExt;
}

function parse_session_id_from_filename(string $filename): ?string {
    if (preg_match('/logs_([0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2})/', $filename, $m)) {
        return 'logs_' . $m[1];
    }
    return null;
}

function parse_session_id_from_path(string $path): ?string {
    $parts = explode('/', str_replace('\\', '/', $path));
    foreach ($parts as $part) {
        if (preg_match('/^logs_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}$/', $part)) {
            return $part;
        }
    }
    return null;
}

function generate_friend_token(): string {
    return bin2hex(random_bytes(32));
}

function env_bool(string $key, bool $default = false): bool {
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    if ($val === null) {
        return $default;
    }
    return in_array(strtolower(trim((string)$val)), ['1', 'true', 'yes', 'on'], true);
}
