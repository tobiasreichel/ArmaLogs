<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

require_once INCLUDES_DIR . '/auth.php';
logout_admin();
header('Location: /login.php');
exit;
