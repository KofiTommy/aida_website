<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log',__DIR__.'/../storage/php-errors.log');
set_exception_handler(function(Throwable $error): void {
    $reference=bin2hex(random_bytes(5)); error_log('AIDA '.$reference.': '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine());
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<h1>This request could not be completed.</h1><p>Please try again or contact the website administrator. Reference: '.htmlspecialchars($reference).'</p>';
});
if (PHP_SAPI!=='cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
require_once __DIR__.'/migrations.php';
require_once __DIR__.'/security.php';

$configFile = __DIR__ . '/config.local.php';
if (!is_file($configFile)) {
    throw new RuntimeException('AIDA CMS has not been configured. Copy app/config.sample.php to app/config.local.php.');
}
$loadedConfig = require $configFile;
if (!is_array($loadedConfig) || !isset($loadedConfig['db']) || !is_array($loadedConfig['db'])) {
    throw new RuntimeException('The AIDA database configuration is invalid. Check app/config.local.php.');
}
foreach (['host', 'port', 'name', 'user', 'pass'] as $key) {
    if (!array_key_exists($key, $loadedConfig['db']) || !is_string($loadedConfig['db'][$key])) {
        throw new RuntimeException('The AIDA database configuration is missing a valid connection field.');
    }
}
// Includes can run inside cms_setting(); explicitly preserve configuration globally.
$GLOBALS['config'] = $loadedConfig;

session_name('aida_session');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function db(): PDO {
    global $config;
    static $pdo;
    if (!$pdo) {
        $db = $config['db'];
        $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        try { backend_upgrade($pdo); } catch (Throwable $error) { $pdo=null; throw $error; }
    }
    return $pdo;
}
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    $token=$_POST['csrf']??null;
    if (!is_string($token) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) { http_response_code(419); exit('Your session has expired. Please return and try again.'); }
}
function current_user(): ?array {
    static $checked=false, $user=null;
    if ($checked) return $user;
    $checked=true; $session=$_SESSION['user']??null;
    if (!$session) return null;
    $now=time();
    if ($now-(int)($_SESSION['last_activity']??0)>1800 || $now-(int)($_SESSION['signed_in_at']??0)>43200) { unset($_SESSION['user']); return null; }
    $q=db()->prepare('SELECT u.id,u.name,u.email,u.role,s.session_version FROM users u LEFT JOIN user_security s ON s.user_id=u.id WHERE u.id=? AND u.is_active=1');
    $q->execute([(int)$session['id']]); $row=$q->fetch();
    if (!$row || (int)($row['session_version']??1)!==(int)($_SESSION['session_version']??0)) { unset($_SESSION['user']); return null; }
    $_SESSION['last_activity']=$now; unset($row['session_version']);
    return $user=$_SESSION['user']=$row;
}
function require_login(): void { if (!current_user()) { header('Location: login.php?expired=1'); exit; } if(!headers_sent()) header('Cache-Control: no-store'); }
function require_role(array $roles): void { require_login(); if (!in_array(current_user()['role'], $roles, true)) { http_response_code(403); exit('You do not have permission to perform this action.'); } }
function login(array $user): void { session_regenerate_id(true); $_SESSION=[]; $_SESSION['user'] = ['id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'role'=>$user['role']]; $_SESSION['signed_in_at']=$_SESSION['last_activity']=time(); $_SESSION['session_version']=(int)security_record((int)$user['id'])['session_version']; }
function logout(): void { $_SESSION = []; session_destroy(); }
function flash(string $key, ?string $value = null): ?string { if ($value !== null) { $_SESSION['flash'][$key] = $value; return null; } $v = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $v; }
function audit(string $action, string $entity, ?int $entityId = null): void { $u=current_user(); if (!$u) return; $stmt=db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)'); $stmt->execute([$u['id'],$action,$entity,$entityId,$_SERVER['REMOTE_ADDR'] ?? null]); }
function slugify(string $text): string { $text = strtolower(trim(preg_replace('~[^\pL\d]+~u', '-', $text))); return trim($text, '-') ?: 'item'; }
