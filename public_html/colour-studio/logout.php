<?php
declare(strict_types=1);
require __DIR__.'/paths.php';
at_require_https();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') at_fail('Use the Sign out button to end your session.', 405);
at_check_csrf();
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>$params['path'], 'secure'=>$params['secure'], 'httponly'=>true, 'samesite'=>'Strict']);
session_destroy();
header('Location: admin.php', true, 303); exit;
