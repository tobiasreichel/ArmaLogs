<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function bootstrap_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = config();
    session_name($cfg['session']['name']);
    session_set_cookie_params([
        'lifetime' => $cfg['session']['lifetime_s'],
        'path'     => '/',
        'secure'   => $cfg['session']['cookie_secure'],
        'httponly' => $cfg['session']['cookie_httponly'],
        'samesite' => $cfg['session']['samesite'],
    ]);
    session_start();
}

function hash_password(string $password): string {
    $cfg = config();
    return password_hash(
        $password,
        PASSWORD_ARGON2ID,
        $cfg['security']['argon2id_options']
    );
}

function is_admin(): bool {
    bootstrap_session();
    return !empty($_SESSION['admin_id']);
}

function require_admin(): void {
    bootstrap_session();
    if (!is_admin()) {
        header('Location: /login.php');
        exit;
    }
}

function current_admin(): ?array {
    bootstrap_session();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, created_at FROM admin_users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}

function login_admin(string $username, string $password): bool {
    bootstrap_session();
    $stmt = db()->prepare('SELECT id, password_hash FROM admin_users WHERE username = :u');
    $stmt->execute([':u' => $username]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin_id']       = (int)$u['id'];
    $_SESSION['admin_login_at'] = time();
    return true;
}

function logout_admin(): void {
    bootstrap_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

function authenticate_friend_by_token(string $token): ?array {
    $hash = hash('sha256', $token);
    $stmt = db()->prepare(
        'SELECT id, name, is_active FROM friends WHERE token_hash = :h LIMIT 1'
    );
    $stmt->execute([':h' => $hash]);
    $friend = $stmt->fetch();
    if (!$friend) {
        return null;
    }
    if (!$friend['is_active']) {
        return null;
    }
    return $friend;
}
