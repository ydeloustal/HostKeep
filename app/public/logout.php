<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

security_headers();
start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && check_csrf()) {
    $_SESSION = [];
    session_destroy();
}
header('Location: /login.php');
