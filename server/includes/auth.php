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
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_admin(): bool {
    bootstrap_session();
    return !empty($_SESSION['admin_id']);
}

function require_ad login_admin(string $username, string $password): bool {
    bootstrap_session();
    $stmt = db()->prepare('SELECT id, password_hash FROM admin_users WHERE username = :u');
    $stmt->execute([':u' => $username]);
    $u = $stmt->fetch();
    if (!$u) {
        return false;
    }
    if (!password_verify($password, $u['password_hash'])) {
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
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function current_ad;
    }
    $stmt = db()->prepare('SELECT id, username, created_at FROM admin_users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}
