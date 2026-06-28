<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login_admin($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: /');
        exit;
    }
    $error = 'Invalid username or password.';
}
?x>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — ArmaLogs</title>
  <style>
    :root{--bg:#0f1115;--panel:#1a1d23;--text:#d8dce4;--muted:#8b92a8;--accent:#4f8cff;--danger:#ff4f4f;}
    *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);display:grid;place-items:center;min-height:100vh}
    .box{width:min(360px,92vw);padding:28px;background:var(--panel);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.3)}
    h1{margin:0 0 6px;font-size:1.5rem}p{margin:0 0 18px;color:var(--muted)}
    label{display:block;margin:14px 0 4px;font-size:.85rem;color:var(--muted)}
    input{width:100%;padding:10px 12px;background:#0f1115;border:1px solid #2c303a;border-radius:6px;color:var(--text);font-size:1rem}
    input:focus{outline:none;border-color:var(--accent)}
    button{margin-top:18px;width:100%;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:6px;font-size:1rem;cursor:pointer}
    button:hover{filter:brightness(1.1)}
    .error{color:var(--danger);font-size:.9rem;margin-top:12px}
  </style>
</head>
<body>
  <div class="box">
    <h1>ArmaLogs</h1>
    <p>Admin login</p>
    <form method="POST" autocomplete="off">
      <label for="username">Username</label>
      <input id="username" name="username" required autofocus>

      <label for="password">Password</label>
      <input id="password" name="password" type="password" required>

      <button type="submit">Login</button>
    </form>
    <?php if ($error): ?>
      <p class="error"><?= html_safe($error) ?></p>
    <?php endif; ?>
  </div>
</body>
</html>
