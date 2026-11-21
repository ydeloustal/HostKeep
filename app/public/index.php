<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/pwa.php';
require __DIR__ . '/../src/mailer.php';
require __DIR__ . '/../src/users.php';

security_headers();
enforce_ip_allowlist();
start_session();
$me = current_user();
if (!$me) {
    header('Location: /login.php' . (isset($_COOKIE['hksid']) ? '?m=expired' : ''));
    exit;
}
if ($me['must_change_password'] || $me['must_setup_2fa']) {
    header('Location: ' . ($me['must_change_password'] ? '/password.php' : '/2fa.php'));
    exit;
}
header('Cache-Control: no-store');
$token = csrf_token();
$name = panel_name();
// Main sections, grouped in the sidebar. The phone tab bar shows the same 5 (Administration is in the account menu there).
$groups = ['Surveillance' => ['home' => 'Accueil', 'sites' => 'Sites', 'system' => 'Système'], 'Protection' => ['security' => 'Sécurité', 'data' => 'Données']];
$nav = array_merge(...array_values($groups));
$admin = can($me, 'users.view') || can($me, 'settings.view');
$initials = mb_strtoupper(mb_substr($me['display_name'] ?: $me['username'], 0, 1));
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= h($token) ?>">
<title><?= h($name) ?></title>
<?= pwa_head($name) ?>
<link rel="stylesheet" href="<?= h(asset('/assets/app.css')) ?>">
<script type="application/json" id="me-data"><?= json_encode(me_payload($me), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
<a class="skip" id="skip" href="#main">Aller au contenu</a>
<div class="netbar" id="netbar" role="status" hidden>Connexion au serveur perdue. Nouvelle tentative en cours…</div>

<!-- Desktop (≥ 1024 px): sidebar -->
<aside class="sidebar" aria-label="Barre latérale">
  <a class="brand" href="#home"><img src="/assets/favicon.svg" alt=""><span><strong><?= h($name) ?></strong><small>Administration serveur</small></span></a>
  <button class="search-btn search-trigger" type="button" aria-keyshortcuts="Control+K"><span class="ico" data-icon="search"></span><span class="lbl">Rechercher…</span><kbd aria-hidden="true">Ctrl K</kbd></button>
  <nav class="sidenav" aria-label="Navigation principale">
    <?php foreach ($groups as $g => $items): ?>
      <p class="nav-group"><?= h($g) ?></p>
      <?php foreach ($items as $id => $label): ?>
        <a href="#<?= $id ?>" data-nav="<?= $id ?>"><span class="ico" data-icon="<?= $id ?>"></span><span class="lbl"><?= h($label) ?></span><span class="count" data-count="<?= $id ?>" hidden></span></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if ($admin): ?>
      <p class="nav-group">Gestion</p>
      <a href="#admin" data-nav="admin"><span class="ico" data-icon="admin"></span><span class="lbl">Administration</span></a>
    <?php endif; ?>
  </nav>
  <div class="side-foot">
    <a class="server-card" href="#home" data-state="ok" title="État du serveur">
      <span class="dot" aria-hidden="true"></span>
      <span class="server-text"><span class="mono" id="hostname"><?= h(gethostname() ?: '') ?></span><small class="server-state">Vérification…</small></span>
    </a>
    <button class="account-btn account-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="account-menu">
      <span class="avatar" aria-hidden="true"><?= h($initials) ?></span>
      <span class="account-text"><span class="who"><?= h($me['display_name'] ?: $me['username']) ?></span><small><?= h(role_label($me['role'])) ?></small></span>
      <span class="ico chev" data-icon="updown"></span>
    </button>
  </div>
</aside>

<!-- Phones and tablets: compact top bar + bottom tab bar -->
<header class="topbar">
  <a class="brand" href="#home"><img src="/assets/favicon.svg" alt=""><strong><?= h($name) ?></strong></a>
  <a class="health" id="health" href="#home" hidden></a>
  <button class="icon-btn search-trigger" type="button" aria-label="Rechercher une page ou une action"><span class="ico" data-icon="search"></span></button>
  <button class="avatar-btn account-trigger" type="button" aria-label="Compte et réglages" aria-haspopup="true" aria-expanded="false" aria-controls="account-menu"><span class="avatar" aria-hidden="true"><?= h($initials) ?></span></button>
</header>
<nav class="tabbar" aria-label="Navigation principale">
  <?php foreach ($nav as $id => $label): ?>
    <a href="#<?= $id ?>" data-nav="<?= $id ?>"><span class="ico" data-icon="<?= $id ?>"></span><span class="lbl"><?= h($label) ?></span><span class="count" data-count="<?= $id ?>" hidden></span></a>
  <?php endforeach; ?>
</nav>

<!-- Account menu (opened from the sidebar or the avatar) -->
<div class="menu" id="account-menu" hidden>
  <div class="menu-head">
    <span class="avatar" aria-hidden="true"><?= h($initials) ?></span>
    <span><strong><?= h($me['display_name'] ?: $me['username']) ?></strong><small><?= h(role_label($me['role'])) ?><?= $me['email'] ? ' · ' . h($me['email']) : '' ?></small></span>
  </div>
  <a class="menu-item" href="#account" data-nav="account"><span class="ico" data-icon="account"></span>Mon compte</a>
  <?php if ($admin): ?><a class="menu-item only-mobile" href="#admin" data-nav="admin"><span class="ico" data-icon="admin"></span>Administration</a><?php endif; ?>
  <hr>
  <button class="menu-item" id="theme-btn" type="button"><span class="ico" data-icon="theme"></span><span id="theme-lbl">Thème sombre</span></button>
  <button class="menu-item" id="keys-btn" type="button"><span class="ico" data-icon="keyboard"></span>Raccourcis clavier</button>
  <button class="menu-item" id="install-btn" type="button" hidden><span class="ico" data-icon="install"></span>Installer l'appli</button>
  <hr>
  <form method="post" action="/logout.php" id="logout-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <button class="menu-item danger" type="submit"><span class="ico" data-icon="logout"></span>Se déconnecter</button>
  </form>
</div>

<main class="content" id="main" tabindex="-1">
  <div class="page-head">
    <div class="page-title">
      <h1 id="page-title">Accueil</h1>
      <p class="muted" id="page-desc"></p>
    </div>
    <div class="page-tools">
      <span class="live" id="updated" hidden></span>
      <button class="icon-btn" id="refresh-btn" type="button" title="Rafraîchir (R)" aria-label="Rafraîchir les données"><span class="ico" data-icon="refresh"></span></button>
    </div>
  </div>
  <nav class="tabs" id="tabs" aria-label="Sections de la page" hidden></nav>
  <div id="content" class="blocks" aria-live="polite"></div>
</main>
<div class="modal" id="modal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <header><h2 id="modal-title"></h2><button class="icon-btn" id="modal-close" aria-label="Fermer"><span class="ico" data-icon="x"></span></button></header>
    <div id="modal-body"></div>
  </div>
</div>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script src="<?= h(asset('/assets/app.js')) ?>"></script>
</body>
</html>
