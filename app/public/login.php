<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

security_headers();
start_session();
if (is_logged()) {
    header('Location: /');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!check_csrf()) {
        $error = 'Session expirée, réessaie.';
    } else {
        $res = attempt_login((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''));
        if ($res === true) {
            header('Location: /');
            exit;
        }
        $error = $res;
    }
}
$token = csrf_token();
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion · Admin VPS</title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
<main class="login-card">
  <img src="/assets/favicon.svg" alt="" class="login-logo">
  <h1>Admin VPS</h1>
  <p class="muted"><?= h(gethostname() ?: 'serveur') ?></p>
  <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Identifiant<input name="user" autocomplete="username" required autofocus></label>
    <label>Mot de passe<input name="pass" type="password" autocomplete="current-password" required></label>
    <button type="submit" class="btn primary">Se connecter</button>
  </form>
</main>
</body>
</html>
