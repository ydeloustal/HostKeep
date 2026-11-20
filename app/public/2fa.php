<?php
declare(strict_types=1);

/**
 * Two-factor authentication of the signed-in account: set up (QR code + first code), new recovery
 * codes, turn off. Reached from Mon compte, or forced right after login when the role requires 2FA
 * (Administration › Paramètres › Sécurité) and the account has none yet.
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

security_headers();
enforce_ip_allowlist();
start_session();
header('Cache-Control: no-store');
$me = current_user();
if (!$me) {
    header('Location: /login.php');
    exit;
}
if ($me['must_change_password']) {
    header('Location: /password.php');
    exit;
}

$state = two_fa_state($me['id']);
$required = two_fa_required($me['role']);
$error = '';
$info = '';
$codes = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $code = (string) ($_POST['code'] ?? '');
    if (!check_csrf()) {
        $error = 'Session expirée, réessaie.';
    } elseif ($do === 'enable' && !$state['enabled']) {
        $secret = (string) ($_SESSION['totp_setup'] ?? '');
        $step = totp_verify($secret, $code);
        if ($step === null) {
            $error = 'Code incorrect. Vérifie que l\'heure du téléphone est automatique, puis saisis le code affiché en ce moment.';
        } else {
            $codes = two_fa_enable($me['id'], $secret, $step);
            unset($_SESSION['totp_setup']);
            audit('double authentification activée');
        }
    } elseif ($do === 'codes' && $state['enabled']) {
        if (two_fa_check($me['id'], $code) === null) {
            $error = 'Code incorrect.';
        } else {
            $codes = two_fa_new_recovery_codes($me['id']);
            audit('nouveaux codes de secours 2FA');
        }
    } elseif ($do === 'disable' && $state['enabled']) {
        $s = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $s->execute([$me['id']]);
        if ($required) {
            $error = 'Ton rôle exige la double authentification : elle ne peut pas être désactivée.';
        } elseif (!password_verify((string) ($_POST['pass'] ?? ''), (string) $s->fetchColumn()) || two_fa_check($me['id'], $code) === null) {
            record_attempt(client_ip(), false, $me['username']);
            $error = 'Mot de passe ou code incorrect.';
        } else {
            two_fa_disable($me['id']);
            audit('double authentification désactivée');
            $info = 'Double authentification désactivée.';
        }
    }
    $state = two_fa_state($me['id']);
}
if (!$state['enabled'] && $codes === null) {
    $_SESSION['totp_setup'] ??= totp_new_secret();   // same secret across reloads: an already scanned QR code stays valid
}
$secret = (string) ($_SESSION['totp_setup'] ?? '');
$name = panel_name();
$token = csrf_token();
$host = gethostname() ?: 'serveur';
$account = $me['username'] . '@' . $host;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Double authentification · <?= h($name) ?></title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
<main class="login-card wide">
  <img src="/assets/favicon.svg" alt="" class="login-logo">
  <h1>Double authentification</h1>
  <p class="muted">Compte <strong><?= h($me['username']) ?></strong> · serveur <span class="mono"><?= h($host) ?></span></p>
  <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php if ($info !== ''): ?><p class="form-info" role="status"><?= h($info) ?></p><?php endif; ?>

  <?php if ($codes !== null): ?>
    <p class="form-info" role="status">C'est fait. Garde ces <strong><?= count($codes) ?> codes de secours</strong> en lieu sûr (gestionnaire de mots de passe, papier) : chacun permet <strong>une</strong> connexion si tu n'as plus ton téléphone. Ils ne seront plus jamais affichés.</p>
    <pre class="recovery-codes"><?= h(implode("\n", $codes)) ?></pre>
    <a class="btn primary" href="/#account">J'ai noté mes codes, continuer</a>

  <?php elseif (!$state['enabled']): ?>
    <?php if ($me['must_setup_2fa']): ?>
      <p class="form-error" role="alert">Ton rôle (<?= h(role_label($me['role'])) ?>) exige la double authentification. Active-la pour accéder au panel.</p>
    <?php else: ?>
      <p>À chaque connexion, en plus du mot de passe, tu saisiras un code à 6 chiffres donné par ton téléphone. Un mot de passe volé ne suffira plus.</p>
    <?php endif; ?>
    <ol class="steps">
      <li>Installe une application d'authentification : <strong>Aegis</strong> (Android), <strong>Google Authenticator</strong>, FreeOTP, ou ton gestionnaire de mots de passe (Bitwarden, 1Password…).</li>
      <li>Dans l'application, ajoute un compte en scannant ce QR code :
        <div class="qr"><?= qr_svg(otpauth_uri($account, $name, $secret)) ?></div>
        <details><summary>Impossible de scanner ? Saisis la clé à la main</summary>
          <p class="mono secret-key"><?= h(trim(chunk_split($secret, 4, ' '))) ?></p>
          <p class="muted small">Type : basé sur le temps (TOTP), 6 chiffres, 30 secondes.</p>
        </details>
      </li>
      <li>Saisis le code à 6 chiffres que l'application affiche :
        <form method="post" action="/2fa.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="do" value="enable">
          <label class="sr-only" for="code">Code à 6 chiffres</label>
          <input id="code" name="code" class="otp-input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
          <button type="submit" class="btn primary" data-busy="Vérification…">Activer</button>
        </form>
      </li>
    </ol>
    <?php if ($me['must_setup_2fa']): ?>
      <form method="post" action="/logout.php" class="login-links inline-form"><input type="hidden" name="csrf" value="<?= h($token) ?>"><button type="submit" class="linkish">Se déconnecter</button></form>
    <?php else: ?>
      <p class="login-links"><a href="/#account">Plus tard</a></p>
    <?php endif; ?>

  <?php else: ?>
    <p class="form-info" role="status">Activée depuis le <?= h(date('d/m/Y', (int) $state['since'])) ?>. Codes de secours restants : <strong><?= (int) $state['codes_left'] ?></strong> / <?= RECOVERY_CODES ?>.</p>
    <?php if ($state['codes_left'] <= 3): ?><p class="form-error" role="alert">Il te reste peu de codes de secours : génères-en de nouveaux.</p><?php endif; ?>
    <h2>Nouveaux codes de secours</h2>
    <p class="muted">Remplace tous les anciens codes. Confirme avec un code de l'application.</p>
    <form method="post" action="/2fa.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="do" value="codes">
      <label>Code à 6 chiffres<input name="code" class="otp-input" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required></label>
      <button type="submit" class="btn">Générer de nouveaux codes</button>
    </form>
    <h2>Désactiver</h2>
    <?php if ($required): ?>
      <p class="muted">Ton rôle exige la double authentification (Administration › Paramètres › Sécurité) : elle ne peut pas être désactivée. Pour changer de téléphone, demande à un administrateur de la réinitialiser, puis active-la de nouveau.</p>
    <?php else: ?>
      <form method="post" action="/2fa.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="do" value="disable">
        <label>Mot de passe<input name="pass" type="password" autocomplete="current-password" required></label>
        <label>Code à 6 chiffres ou code de secours<input name="code" class="otp-input" autocomplete="one-time-code" maxlength="12" required></label>
        <button type="submit" class="btn danger">Désactiver la double authentification</button>
      </form>
    <?php endif; ?>
    <p class="login-links"><a href="/#account">Retour au panel</a></p>
  <?php endif; ?>
</main>
<script src="/assets/login.js"></script>
</body>
</html>
