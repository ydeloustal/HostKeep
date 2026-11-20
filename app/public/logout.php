<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

security_headers();
start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && check_csrf()) {
    if ($u = current_user()) {
        audit('déconnexion', null, 0, $u['username']);
    }
    logout();
    header('Location: /login.php?m=bye');
    exit;
}
header('Location: /login.php');
