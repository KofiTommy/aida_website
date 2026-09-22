<?php
declare(strict_types=1);
session_start();
if (is_file(__DIR__ . '/storage/setup.lock')) { http_response_code(403); exit('Setup is locked. Remove storage/setup.lock only if you intentionally need to reinstall.'); }
$_SESSION['setup_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['setup_csrf'], $_POST['csrf'] ?? '')) { $error = 'Security check failed. Refresh and try again.'; }
    else {
        $host=trim($_POST['host'] ?? '127.0.0.1'); $port=trim($_POST['port'] ?? '3306'); $name=preg_replace('/[^a-zA-Z0-9_]/','',$_POST['database'] ?? 'aida_cms');
        $user=trim($_POST['db_user'] ?? ''); $pass=(string)($_POST['db_pass'] ?? ''); $owner=trim($_POST['owner'] ?? ''); $email=filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL); $ownerPass=(string)($_POST['password'] ?? '');
        if (!$name || !$user || !$owner || !$email || strlen($ownerPass) < 12) { $error='Complete every field. The owner password must be at least 12 characters.'; }
        else try {
            // Local XAMPP users can create a database here. Managed hosts normally create it in their panel first.
            try { $server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $ignored) {}
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            $schema = preg_replace('/CREATE DATABASE[^;]+;\s*USE[^;]+;\s*/i', '', $schema);
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) { $pdo->exec($statement); }
            $stmt=$pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,"administrator")');
            $stmt->execute([$owner,$email,password_hash($ownerPass,PASSWORD_DEFAULT)]);
            $key=bin2hex(random_bytes(32));
            $config="<?php\nreturn ".var_export(['db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],'app_key'=>$key,'upload_max_mb'=>25],true).";\n";
            if (file_put_contents(__DIR__.'/app/config.local.php',$config,LOCK_EX) === false) throw new RuntimeException('Could not write the local configuration file.');
            file_put_contents(__DIR__.'/storage/setup.lock', date('c'));
            header('Location: admin/login.php?installed=1'); exit;
        } catch (Throwable $e) { $error='Setup could not complete. Check your database details and permissions. Technical detail: '.$e->getMessage(); }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up AIDA CMS</title><link rel="stylesheet" href="assets/css/admin.css"></head><body class="admin-login"><main class="setup-card"><img src="Logo.png" alt="AIDA"><p class="eyebrow"><span></span> FIRST-TIME SETUP</p><h1>Set up the AIDA content portal.</h1><p>Create the secure owner account that will manage AIDA’s website.</p><?php if($error): ?><div class="admin-alert error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?><form method="post" class="admin-form"><input type="hidden" name="csrf" value="<?= $_SESSION['setup_csrf'] ?>"><h2>Database</h2><div class="form-grid"><label>Host<input name="host" value="127.0.0.1" required></label><label>Port<input name="port" value="3306" required></label><label>Database name<input name="database" value="aida_cms" required></label><label>Database user<input name="db_user" required></label><label class="full">Database password<input type="password" name="db_pass"></label></div><h2>Website owner</h2><div class="form-grid"><label>Full name<input name="owner" required></label><label>Email address<input type="email" name="email" required></label><label class="full">Password <small>At least 12 characters</small><input type="password" name="password" minlength="12" required></label></div><button class="admin-button" type="submit">Create AIDA portal →</button></form></main></body></html>
