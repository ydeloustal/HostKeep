<?php
declare(strict_types=1);

/**
 * Choose a password:
 *  - with ?token=... : link from a reset or invitation e-mail (no session needed);
 *  - signed in with "must change password" (temporary password given by an admin or the installer).
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/pwa.php';

security_headers();
enforce_ip_allowlist();
start_session();

$tokenParam = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$invite = isset($_GET['invite']) || isset($_POST['invite']);
$me = current_user();
$target = null;
if ($tokenParam !== '') {
    $target = user_from_reset_token($tokenParam);
} elseif ($me) {
    if (!$me['must_change_password']) {
        header('Location: /#account');
        exit;
    }
    $target = $me;
} else {
    header('Location: /login.php');
    exit;
}

$error = '';
if ($target && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $p1 = (string) ($_POST['new1'] ?? '');
    if (!check_csrf()) {
        $error = 'Session expirée, réessaie.';
    } elseif ($p1 !== (string) ($_POST['new2'] ?? '')) {
        $error = 'Les deux saisies diffèrent.';
    } elseif ($p = password_problem($p1, $target)) {
        $error = $p;
    } elseif (password_verify($p1, (string) db()->query('SELECT password_hash FROM users WHERE id = ' . (int) $target['id'])->fetchColumn())) {
        $error = 'Choisis un mot de passe différent de l\'actuel.';
    } else {
        if ($tokenParam !== '') {
            set_password((int) $target['id'], $p1);   // ends every session of the account
            audit($invite ? 'mot de passe choisi (invitation)' : 'mot de passe réinitialisé par lien', null, 0, $target['username']);
            if ($me) {
                logout();
            }
            header('Location: /login.php?m=reset');
        } else {
            set_password((int) $target['id'], $p1, false, current_session_id());
            audit('mot de passe temporaire remplacé', null, 0, $target['username']);
            header('Location: /');
        }
        exit;
    }
}
$csrf = csrf_token();
$name = panel_name();
$min = password_min_length();
$title = $invite ? 'Bienvenue' : ($tokenParam !== '' ? 'Nouveau mot de passe' : 'Change ton mot de passe');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= h($title) ?> · <?= h($name) ?></title>
<?= pwa_head($name) ?>
<link rel="stylesheet" href="<?= h(asset('/assets/app.css')) ?>">
</head>
<body class="login-page">
<main class="login-card">
  <img src="/assets/favicon.svg" alt="" class="login-logo">
  <h1><?= h($title) ?></h1>
  <?php if (!$target): ?>
    <p class="form-error" role="alert">Ce lien n'est plus valable (déjà utilisé ou expiré). Demande un nouveau lien.</p>
    <p class="login-links"><a href="/login.php">Retour à la connexion</a></p>
  <?php else: ?>
    <p class="muted"><?= $tokenParam === '' ? 'Le mot de passe actuel est temporaire. ' : '' ?>Compte <strong><?= h($target['username']) ?></strong></p>
    <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="/password.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <?php if ($tokenParam !== ''): ?><input type="hidden" name="token" value="<?= h($tokenParam) ?>"><?php endif; ?>
      <?php if ($invite): ?><input type="hidden" name="invite" value="1"><?php endif; ?>
      <input type="text" name="username" value="<?= h($target['username']) ?>" autocomplete="username" hidden>
      <label>Nouveau mot de passe
        <span class="pw-field">
          <input name="new1" id="new1" type="password" autocomplete="new-password" minlength="<?= $min ?>" required autofocus aria-describedby="pw-rule">
          <button type="button" aria-controls="new1" aria-pressed="false" hidden>Afficher</button>
        </span>
      </label>
      <p class="muted small" id="pw-rule"><?= $min ?> caractères minimum. Une phrase de passe (plusieurs mots) est plus simple à retenir.</p>
      <label>Confirmation
        <span class="pw-field">
          <input name="new2" id="new2" type="password" autocomplete="new-password" minlength="<?= $min ?>" required>
          <button type="button" aria-controls="new2" aria-pressed="false" hidden>Afficher</button>
        </span>
      </label>
      <p class="caps" id="match" role="status" hidden>Les deux saisies diffèrent.</p>
      <p class="caps" id="caps" role="status" hidden>Verrouillage des majuscules activé.</p>
      <button type="submit" class="btn primary" data-busy="Enregistrement…">Enregistrer le mot de passe</button>
    </form>
    <?php if ($tokenParam === ''): ?>
      <form method="post" action="/logout.php" class="login-links"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="linkish" type="submit">Se déconnecter</button></form>
    <?php endif; ?>
  <?php endif; ?>
</main>
<script src="<?= h(asset('/assets/login.js')) ?>"></script>
</body>
</html>
