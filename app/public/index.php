<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

security_headers();
start_session();
if (!is_logged()) {
    header('Location: /login.php');
    exit;
}
$token = csrf_token();
$nav = ['home' => 'Accueil', 'sites' => 'Sites', 'system' => 'Système', 'security' => 'Sécurité', 'data' => 'Données'];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= h($token) ?>">
<title>Admin VPS</title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="#home"><img src="/assets/favicon.svg" alt=""><span><strong>Admin VPS</strong><small id="hostname"><?= h(gethostname() ?: '') ?></small></span></a>
  <nav class="mainnav" aria-label="Sections">
    <?php foreach ($nav as $id => $label): ?>
      <a href="#<?= $id ?>" data-nav="<?= $id ?>"><span class="ico" data-icon="<?= $id ?>"></span><span><?= h($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="topbar-right">
    <a class="health" id="health" href="#home" hidden></a>
    <button class="icon-btn" id="refresh-btn" title="Rafraîchir" aria-label="Rafraîchir"><span class="ico" data-icon="refresh"></span></button>
    <button class="icon-btn" id="theme-btn" title="Thème clair / sombre" aria-label="Changer de thème"><span class="ico" data-icon="theme"></span></button>
    <form method="post" action="/logout.php">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <button class="icon-btn" type="submit" title="Se déconnecter" aria-label="Se déconnecter"><span class="ico" data-icon="logout"></span></button>
    </form>
  </div>
</header>
<main class="content">
  <div class="page-head">
    <div>
      <h1 id="page-title">Accueil</h1>
      <p class="muted" id="page-desc"></p>
    </div>
    <span class="muted small" id="updated"></span>
  </div>
  <nav class="chips" id="chips" aria-label="Aller à"></nav>
  <div id="content" class="blocks" aria-live="polite"></div>
</main>
<div class="modal" id="modal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <header><h2 id="modal-title"></h2><button class="icon-btn" id="modal-close" aria-label="Fermer"><span class="ico" data-icon="x"></span></button></header>
    <div id="modal-body"></div>
  </div>
</div>
<script src="/assets/app.js"></script>
</body>
</html>
