<?php
declare(strict_types=1);
require __DIR__.'/paths.php';
at_require_https();
if (at_is_admin()) at_shell('admin');
$config = at_config();
if (!$config) at_form_page('Administrator setup', '<p>This installation has no administrator yet. Use the private setup key supplied with your deployment package to create your account.</p><a class="primary" href="setup.php">Create administrator <span>↗</span></a>');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        at_check_csrf(); at_rate_attempt('login');
        $username = $_POST['username'] ?? ''; $password = $_POST['password'] ?? '';
        $validUser = is_string($username) && hash_equals($config['username'], $username);
        $validPassword = is_string($password) && strlen($password) <= 72 && password_verify($password, $config['password_hash']);
        if (!$validUser || !$validPassword) at_fail('The username or password is incorrect.', 401);
        at_rate_clear('login');
        session_regenerate_id(true);
        $_SESSION = ['authenticated'=>$config['auth_version'], 'username'=>$config['username'], 'signed_in'=>time(), 'last_active'=>time(), 'csrf'=>bin2hex(random_bytes(32))];
        header('Location: admin.php', true, 303); exit;
    } catch (AtHttpError $e) { $error = $e->getMessage(); http_response_code($e->status); }
}
$content = '<p>Sign in to upload your product and manage the colour library.</p>';
if (isset($_GET['expired'])) $content .= '<div class="status-box">Your session expired. Please sign in again.</div>';
if ($error !== '') $content .= '<div class="error-box" role="alert">'.at_escape($error).'</div>';
$content .= '<form method="post" action="admin.php"><input type="hidden" name="csrf" value="'.at_escape(at_csrf()).'"><label class="field">Username<input name="username" required autocomplete="username" maxlength="80"></label><label class="field">Password<input type="password" name="password" required autocomplete="current-password" maxlength="72"></label><button class="primary" type="submit">Sign in <span>↗</span></button></form><small>Administrator sessions expire after 30 minutes of inactivity.</small>';
at_form_page('Administrator sign-in', $content);
