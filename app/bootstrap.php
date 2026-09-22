<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.local.php';
if (!is_file($configFile)) {
    throw new RuntimeException('AIDA CMS has not been configured. Copy app/config.sample.php to app/config.local.php.');
}
$config = require $configFile;

session_name('aida_session');
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
    }
    return $pdo;
}
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Your session has expired. Please return and try again.'); }
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!current_user()) { header('Location: login.php'); exit; } }
function require_role(array $roles): void { require_login(); if (!in_array(current_user()['role'], $roles, true)) { http_response_code(403); exit('You do not have permission to perform this action.'); } }
function login(array $user): void { session_regenerate_id(true); $_SESSION['user'] = ['id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'role'=>$user['role']]; }
function logout(): void { $_SESSION = []; session_destroy(); }
function flash(string $key, ?string $value = null): ?string { if ($value !== null) { $_SESSION['flash'][$key] = $value; return null; } $v = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $v; }
function audit(string $action, string $entity, ?int $entityId = null): void { $u=current_user(); if (!$u) return; $stmt=db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)'); $stmt->execute([$u['id'],$action,$entity,$entityId,$_SERVER['REMOTE_ADDR'] ?? null]); }
function slugify(string $text): string { $text = strtolower(trim(preg_replace('~[^\pL\d]+~u', '-', $text))); return trim($text, '-') ?: 'item'; }
