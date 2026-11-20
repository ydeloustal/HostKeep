<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/mailer.php';

security_headers();
enforce_ip_allowlist();
start_session();
if ($u = current_user()) {
    header('Location: ' . ($u['must_change_password'] ? '/password.php' : '/'));
    exit;
}

$forgot = isset($_GET['forgot']) && setting_bool('security.password_reset') && mail_configured();
$error = '';
$info = match ($_GET['m'] ?? '') {
    'reset' => 'Mot de passe enregistré. Tu peux te connecter.',
    'bye' => 'Tu es déconnecté.',
    'expired' => 'Session expirée : reconnecte-toi.',
    default => '',
};
$noUser = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!check_csrf()) {
        $error = 'Session expirée, réessaie.';
    } elseif (($_POST['do'] ?? '') === 'forgot' && $forgot) {
        if (too_many_attempts(client_ip())) {
            $error = 'Trop de tentatives depuis ton adresse. Réessaie plus tard.';
        } else {
            request_password_reset((string) ($_POST['login'] ?? ''));
            $info = 'Si un compte actif correspond, un e-mail avec un lien de réinitialisation vient d\'être envoyé (valable 1 heure).';
            $forgot = false;
        }
    } else {
        $res = attempt_login((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''));
        if ($res === true) {
            header('Location: ' . (current_user()['must_change_password'] ?? false ? '/password.php' : '/'));
            exit;
        }
        $error = $res;
    }
}
$token = csrf_token();
$name = panel_name();
$canForgot = setting_bool('security.password_reset') && mail_configured();
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $forgot ? 'Mot de passe oublié' : 'Connexion' ?> · <?= h($name) ?></title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="manifest" href="/assets/manifest.json">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#131720" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-title" content="<?= h($name) ?>">
<meta name="mobile-web-app-capable" content="yes">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
<main class="login-card">
  <img src="/assets/favicon.svg" alt="" class="login-logo">
  <h1><?= $forgot ? 'Mot de passe oublié' : 'Connexion à ' . h($name) ?></h1>
  <p class="muted">Serveur <span class="mono"><?= h(gethostname() ?: 'serveur') ?></span></p>
  <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php if ($info !== ''): ?><p class="form-info" role="status"><?= h($info) ?></p><?php endif; ?>
  <?php if ($noUser): ?>
    <p class="form-error" role="alert">Aucun compte n'existe encore. Sur le serveur, crée le premier Super Admin :<br><code>sudo hostkeep admin</code></p>
  <?php elseif ($forgot): ?>
    <form method="post" action="/login.php?forgot=1">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="do" value="forgot">
      <label>Identifiant ou e-mail<input name="login" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
      <button type="submit" class="btn primary">Recevoir un lien</button>
    </form>
    <p class="login-links"><a href="/login.php">Retour à la connexion</a></p>
  <?php else: ?>
    <form method="post" action="/login.php" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <label>Identifiant ou e-mail<input name="user" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
      <label>Mot de passe
        <span class="pw-field">
          <input name="pass" id="pass" type="password" autocomplete="current-password" required>
          <button type="button" id="pw-toggle" aria-controls="pass" aria-pressed="false" hidden>Afficher</button>
        </span>
      </label>
      <p class="caps" id="caps" role="status" hidden>Verrouillage des majuscules activé.</p>
      <button type="submit" class="btn primary" id="login-submit" data-busy="Connexion…">Se connecter</button>
    </form>
    <?php if ($canForgot): ?><p class="login-links"><a href="/login.php?forgot=1">Mot de passe oublié ?</a></p><?php endif; ?>
    <p class="login-note">Les échecs répétés bloquent temporairement le compte et l'adresse IP.</p>
  <?php endif; ?>
</main>
<script src="/assets/login.js"></script>
</body>
</html>
