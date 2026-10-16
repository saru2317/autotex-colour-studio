<?php
declare(strict_types=1);
require __DIR__.'/paths.php';
at_require_https(); at_session();
if (at_config()) at_form_page('Setup complete', '<p>An administrator has already been created. Initial setup is now disabled.</p><a class="primary" href="admin.php">Go to administrator sign-in <span>↗</span></a>');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        at_check_csrf(); at_rate_attempt('setup');
        $key = $_POST['setup_key'] ?? ''; $username = $_POST['username'] ?? ''; $password = $_POST['password'] ?? ''; $confirm = $_POST['confirm_password'] ?? '';
        $keyFile = AT_PRIVATE.'/setup-key.txt';
        if (!is_file($keyFile)) at_fail('Setup is locked. Restore a private setup-key.txt through cPanel to initialise this installation.', 403);
        $expected = trim((string)file_get_contents($keyFile));
        if (strlen($expected) < 40 || !is_string($key) || !hash_equals($expected, trim($key))) at_fail('The private setup key is incorrect.', 403);
        if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.@-]{3,80}$/D', $username)) at_fail('Use a username of 3–80 letters, numbers, dots, dashes, underscores or @.');
        if (!is_string($password) || strlen($password) < 14 || strlen($password) > 72 || !is_string($confirm) || !hash_equals($password, $confirm)) at_fail('Use a matching password of 14–72 bytes. A long, unique passphrase is recommended.');
        $lock = fopen(AT_PRIVATE.'/setup.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock account setup.');
        try {
            if (at_config()) at_fail('An administrator has already been created.', 409);
            $config = ['username'=>$username, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'auth_version'=>bin2hex(random_bytes(32)), 'created_at'=>gmdate('c')];
            at_atomic_write(AT_PRIVATE.'/admin.json', at_json_encode($config));
            if (is_file($keyFile)) unlink($keyFile);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        at_rate_clear('setup');
        session_regenerate_id(true);
        $_SESSION = ['authenticated'=>$config['auth_version'], 'username'=>$username, 'signed_in'=>time(), 'last_active'=>time(), 'csrf'=>bin2hex(random_bytes(32))];
        header('Location: admin.php', true, 303); exit;
    } catch (AtHttpError $e) { $error = $e->getMessage(); http_response_code($e->status); }
}
$content = '<p>Create the administrator for this installation. Find the setup key in <strong>autotex-private/setup-key.txt</strong> through cPanel File Manager. This key works only until setup is complete.</p>';
if ($error !== '') $content .= '<div class="error-box" role="alert">'.at_escape($error).'</div>';
$content .= '<form method="post" action="setup.php"><input type="hidden" name="csrf" value="'.at_escape(at_csrf()).'"><label class="field">Private setup key<input type="password" name="setup_key" required autocomplete="off"></label><label class="field">Admin username<input name="username" required autocomplete="username" minlength="3" maxlength="80"></label><label class="field">New password<input type="password" name="password" required autocomplete="new-password" minlength="14" maxlength="72"></label><label class="field">Confirm password<input type="password" name="confirm_password" required autocomplete="new-password" minlength="14" maxlength="72"></label><button class="primary" type="submit">Create administrator <span>↗</span></button></form><small>Choose a unique password. No default password is supplied.</small>';
at_form_page('Create administrator', $content);
