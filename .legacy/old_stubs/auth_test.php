<?php
declare(strict_types=1);

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
