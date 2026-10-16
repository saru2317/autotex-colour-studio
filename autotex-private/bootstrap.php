<?php
declare(strict_types=1);

// This entire directory must remain outside the web document root.
ini_set('display_errors', '0');
const AT_MAX_FILE = 20971520;
const AT_IDLE_SECONDS = 1800;
const AT_MAX_SESSION_SECONDS = 28800;

final class AtHttpError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}
function at_fail(string $message, int $status = 400): never { throw new AtHttpError($message, $status); }
function at_escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function at_base(): string {
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim($base, '/') . '/';
}
function at_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}
function at_require_https(): void {
    $local = getenv('AUTOTEX_ALLOW_HTTP') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!at_https() && !$local) at_fail('Administrator access requires HTTPS. Enable SSL for this domain in cPanel, then use its https:// address.', 403);
}
function at_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
    header('Cache-Control: no-store');
}
function at_mkdir(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Unable to create private storage.');
}
function at_json_encode(mixed $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function at_atomic_write(string $path, string $content): void {
    $tmp = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
    try {
        if (file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('Unable to write private storage.');
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) throw new RuntimeException('Unable to save private storage.');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function at_json_file(string $path): array {
    $text = file_get_contents($path);
    if ($text === false) throw new RuntimeException('Unable to read private storage.');
    $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Invalid private configuration.');
    return $value;
}
function at_config(): ?array { return is_file(AT_PRIVATE . '/admin.json') ? at_json_file(AT_PRIVATE . '/admin.json') : null; }
function at_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    at_require_https();
    at_mkdir(AT_PRIVATE . '/sessions');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) AT_MAX_SESSION_SECONDS);
    session_save_path(AT_PRIVATE . '/sessions');
    session_name('AUTOTEX_' . substr(hash('sha256', AT_PRIVATE), 0, 12));
    session_set_cookie_params(['lifetime'=>0, 'path'=>at_base(), 'secure'=>at_https(), 'httponly'=>true, 'samesite'=>'Strict']);
    if (!session_start()) throw new RuntimeException('Unable to start administrator session.');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $now = time();
    if (isset($_SESSION['authenticated']) && ($now - (int)($_SESSION['last_active'] ?? 0) > AT_IDLE_SECONDS || $now - (int)($_SESSION['signed_in'] ?? 0) > AT_MAX_SESSION_SECONDS)) {
        unset($_SESSION['authenticated'], $_SESSION['username']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
function at_is_admin(): bool {
    at_session();
    $config = at_config();
    if (!$config || !is_string($_SESSION['authenticated'] ?? null) || !hash_equals($config['auth_version'], $_SESSION['authenticated'])) return false;
    $_SESSION['last_active'] = time();
    return true;
}
function at_csrf(): string { at_session(); return $_SESSION['csrf']; }
function at_check_csrf(): void {
    at_session();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) at_fail('Your security token expired. Reload this page and try again.', 403);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null) {
        $expected = (at_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
        if (!hash_equals($expected, rtrim($origin, '/'))) at_fail('Invalid request origin.', 403);
    }
}
function at_require_admin(bool $write = false): void {
    if (!at_is_admin()) at_fail('Administrator sign-in is required.', 401);
    if ($write) at_check_csrf();
}
function at_rate_attempt(string $scope): void {
    $file = AT_PRIVATE . '/data/attempts.json';
    $lock = fopen(AT_PRIVATE . '/data/attempts.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to check sign-in attempts.');
    try {
        $all = is_file($file) ? at_json_file($file) : [];
        $now = time();
        $all = array_filter($all, fn($v) => is_array($v) && (int)($v['since'] ?? 0) > $now - 900);
        $key = hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $global = 'global-' . $scope;
        if (($all[$key]['count'] ?? 0) >= 5 || ($all[$global]['count'] ?? 0) >= 60) at_fail('Too many attempts. Please try again in 15 minutes.', 429);
        foreach ([$key, $global] as $k) {
            $all[$k] ??= ['since'=>$now, 'count'=>0];
            $all[$k]['count']++;
        }
        if (count($all) > 2000) at_fail('Please try signing in later.', 429);
        at_atomic_write($file, at_json_encode($all));
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function at_rate_clear(string $scope): void {
    $file = AT_PRIVATE . '/data/attempts.json';
    $lock = fopen(AT_PRIVATE . '/data/attempts.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) return;
    try {
        $all = is_file($file) ? at_json_file($file) : [];
        unset($all[hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))]);
        at_atomic_write($file, at_json_encode($all));
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function at_text(mixed $value, int $max): bool {
    return is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1 && preg_match_all('/./us', $value) <= $max && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
}
function at_uuid(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function at_valid_id(mixed $id): bool { return is_string($id) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) === 1; }
function at_product_dir(string $id): string { if (!at_valid_id($id)) at_fail('Invalid product.'); return AT_PRIVATE . '/data/products/' . $id; }
function at_catalog_unlocked(): array {
    $file = is_file(AT_PRIVATE . '/data/catalog.json') ? AT_PRIVATE . '/data/catalog.json' : AT_PRIVATE . '/default-catalog.json';
    $raw = file_get_contents($file); if ($raw === false) throw new RuntimeException('Unable to read the colour library.');
    return ['catalog'=>json_decode($raw, true, 512, JSON_THROW_ON_ERROR), 'revision'=>hash('sha256', $raw)];
}
function at_catalog(): array {
    $lock = fopen(AT_PRIVATE . '/data/catalog.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_SH)) throw new RuntimeException('Unable to read settings.');
    try { return at_catalog_unlocked(); } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function at_validate_catalog(array $data): array {
    if (!is_string($data['revision'] ?? null) || !is_array($data['colours'] ?? null) || !array_is_list($data['colours']) || count($data['colours']) < 1 || count($data['colours']) > 80) at_fail('Choose between 1 and 80 colours.');
    $ids = []; $codes = []; $colours = []; $visible = 0;
    foreach ($data['colours'] as $c) {
        if (!is_array($c) || !is_string($c['id'] ?? null) || !preg_match('/^[a-zA-Z0-9-]{1,80}$/D', $c['id']) || isset($ids[$c['id']]) || !at_text($c['name'] ?? null, 60) || !at_text($c['code'] ?? null, 30) || isset($codes[strtolower(trim($c['code']))]) || !is_string($c['hex'] ?? null) || !preg_match('/^#[a-fA-F0-9]{6}$/D', $c['hex']) || !is_bool($c['visible'] ?? null)) at_fail('Each colour needs a name, unique code and valid six-digit HEX value.');
        $ids[$c['id']] = true; $codes[strtolower(trim($c['code']))] = true;
        $colours[] = ['id'=>$c['id'],'name'=>trim($c['name']),'code'=>trim($c['code']),'hex'=>strtolower($c['hex']),'visible'=>$c['visible']];
        if ($c['visible']) $visible++;
    }
    if (!$visible) at_fail('Keep at least one colour visible.');
    $product = null;
    if (($data['product'] ?? null) !== null) {
        $p = $data['product'];
        if (!is_array($p) || !at_valid_id($p['id'] ?? null) || !at_text($p['name'] ?? null, 100)) at_fail('Upload a valid product before saving.');
        $dir = at_product_dir($p['id']);
        if (!is_file($dir.'/metadata.json') || !is_file($dir.'/source.step') || !is_file($dir.'/preview.glb')) at_fail('The uploaded product was not found. Upload it again.');
        $product = at_json_file($dir.'/metadata.json');
        $product['name'] = trim($p['name']);
    }
    return ['version'=>1,'paletteRevision'=>'autotex-standard-screen-2026-10-15','product'=>$product,'colours'=>$colours,'updatedAt'=>gmdate('c')];
}
function at_save_catalog(array $data): array {
    $catalog = at_validate_catalog($data);
    $lock = fopen(AT_PRIVATE . '/data/catalog.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to save settings.');
    try {
        $current = at_catalog_unlocked();
        if (!hash_equals($current['revision'], $data['revision'])) at_fail('Settings changed in another session. Reload before saving again.', 409);
        $raw = at_json_encode($catalog);
        at_atomic_write(AT_PRIVATE . '/data/catalog.json', $raw);
        return ['catalog'=>$catalog,'revision'=>hash('sha256', $raw)];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function at_upload(): array {
    foreach (['step','model'] as $field) {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || !isset($f['tmp_name']) || is_array($f['tmp_name']) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) at_fail('Both the STEP file and converted preview are required. Check the PHP upload limits if a file is missing.', 400);
        $size = filesize($f['tmp_name']);
        if ($size === false || $size < 1 || $size > AT_MAX_FILE) at_fail('The STEP file and converted preview must each be 20 MB or smaller.', 413);
    }
    $step = $_FILES['step']; $model = $_FILES['model'];
    $name = $_POST['name'] ?? null;
    if (!at_text($name, 100) || !at_text($step['name'] ?? null, 180) || !preg_match('/\.(stp|step)$/iD', $step['name'])) at_fail('Enter a product name and choose a .stp or .step file.');
    $prefix = file_get_contents($step['tmp_name'], false, null, 0, 4096);
    if ($prefix === false || !preg_match('/ISO-10303-21\s*;/i', $prefix)) at_fail('This is not a valid STEP exchange file.');
    $header = file_get_contents($model['tmp_name'], false, null, 0, 12);
    if ($header === false || strlen($header) !== 12) at_fail('Invalid converted preview.');
    $glb = unpack('Vmagic/Vversion/Vlength', $header);
    if ($glb['magic'] !== 0x46546c67 || $glb['version'] !== 2 || $glb['length'] !== filesize($model['tmp_name'])) at_fail('Invalid converted preview.');
    $id = at_uuid(); $dir = at_product_dir($id); at_mkdir($dir);
    $product = ['id'=>$id,'name'=>trim($name),'filename'=>basename($step['name']),'size'=>filesize($step['tmp_name']),'uploadedAt'=>gmdate('c'),'stepKey'=>'products/'.$id.'/source.step','modelKey'=>'products/'.$id.'/preview.glb'];
    try {
        if (!move_uploaded_file($step['tmp_name'], $dir.'/source.step') || !move_uploaded_file($model['tmp_name'], $dir.'/preview.glb')) throw new RuntimeException('Upload could not be stored.');
        chmod($dir.'/source.step', 0600); chmod($dir.'/preview.glb', 0600);
        at_atomic_write($dir.'/metadata.json', at_json_encode($product));
    } catch (Throwable $e) {
        foreach (['source.step','preview.glb','metadata.json'] as $f) if (is_file($dir.'/'.$f)) unlink($dir.'/'.$f);
        rmdir($dir); throw $e;
    }
    return $product;
}
function at_send_file(string $path, string $type, ?string $filename = null): never {
    if (!is_file($path)) at_fail('Product file not found.', 404);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: '.$type); header('Content-Length: '.filesize($path));
    if ($filename !== null) header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($filename));
    readfile($path); exit;
}
function at_json(mixed $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo at_json_encode($data); exit; }
function at_css(): string {
    $manifest = at_json_file(AT_PUBLIC.'/asset-manifest.json');
    return $manifest['index.html']['css'][0] ?? '';
}
function at_shell(string $page): never {
    $template = file_get_contents(AT_PUBLIC.'/shell.html');
    if ($template === false) throw new RuntimeException('Frontend assets are missing.');
    $admin = $page === 'admin';
    header('Content-Type: text/html; charset=utf-8');
    echo strtr($template, ['__AUTOTEX_BASE__'=>at_escape(at_base()), '__AUTOTEX_CSRF__'=>$admin ? at_escape(at_csrf()) : '', '__AUTOTEX_PAGE__'=>$page, '__AUTOTEX_ADMIN__'=>$admin ? at_escape((string)$_SESSION['username']) : '']);
    exit;
}
function at_form_page(string $title, string $content): never {
    header('Content-Type: text/html; charset=utf-8');
    $base = at_escape(at_base()); $css = at_escape(at_css());
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><base href="'.$base.'"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.at_escape($title).' | Autotex</title><link rel="icon" href="favicon.svg"><link rel="stylesheet" href="'.$css.'"></head><body><header><a href="./"><img class="logo" src="autotex-logo.png" alt="Autotex"></a><a href="./">← Colour studio</a></header><main class="login-page"><p class="eyebrow">AUTOTEX ADMINISTRATION</p><h1>'.at_escape($title).'</h1><div class="login-box">'.$content.'</div></main></body></html>';
    exit;
}

if (PHP_VERSION_ID < 80200) { http_response_code(503); exit('PHP 8.2 or later is required. Select a supported PHP version in cPanel.'); }
at_mkdir(AT_PRIVATE.'/data');
at_mkdir(AT_PRIVATE.'/data/products');
at_headers();
set_exception_handler(function(Throwable $e): void {
    $status = $e instanceof AtHttpError ? $e->status : 500;
    $message = $e instanceof AtHttpError ? $e->getMessage() : 'The server could not complete this request. Check the hosting error log and private-folder permissions.';
    if (!($e instanceof AtHttpError)) error_log('Autotex: '.$e->getMessage());
    http_response_code($status);
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>$message]); }
    else { header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Autotex</title><body><h1>Autotex</h1><p>'.at_escape($message).'</p></body></html>'; }
});
