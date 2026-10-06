<?php
declare(strict_types=1);
if(!is_file(__DIR__.'/../app/config.local.php')){header('Location: ../setup.php');exit;}
require_once __DIR__.'/../app/bootstrap.php';
if(current_user()){header('Location: index.php');exit;}
header('Cache-Control: no-store');
$error='';$pending=$_SESSION['pending_login']??null;
if($pending && time()-(int)$pending['started']>300){unset($_SESSION['pending_login']);$pending=null;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 if(isset($_POST['cancel'])){unset($_SESSION['pending_login']);header('Location: login.php');exit;}
 if($pending){
  $q=db()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([$pending['id']]);$user=$q->fetch();
  if(!$user || (int)security_record((int)$user['id'])['session_version']!==$pending['version']){unset($_SESSION['pending_login']);header('Location: login.php');exit;}
  if(login_is_limited($user['email']))$error='Too many failed attempts. Please wait 15 minutes.';
  elseif(verify_second_factor((int)$user['id'],trim((string)($_POST['code']??'')))){login($user);db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);db()->prepare("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,ip_address) VALUES (?,'login_2fa','user',?,?)")->execute([$user['id'],$user['id'],$_SERVER['REMOTE_ADDR']??null]);header('Location: index.php');exit;}
  else{failed_login($user['email']);$error='Invalid or already-used code. Try your next authenticator code or a recovery code.';}
 }else{
  $email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');
  if(login_is_limited($email))$error='Too many failed attempts. Please wait 15 minutes.';
  else{
   $q=db()->prepare('SELECT * FROM users WHERE email=? AND is_active=1');$q->execute([$email]);$user=$q->fetch();
   $valid=password_verify($password,$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
   if($user && $valid){
    $security=security_record((int)$user['id']);
    if($security['totp_secret']){session_regenerate_id(true);$_SESSION['pending_login']=['id'=>(int)$user['id'],'version'=>(int)$security['session_version'],'started'=>time()];header('Location: login.php');exit;}
    login($user);db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
    db()->prepare("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,ip_address) VALUES (?,'login','user',?,?)")->execute([$user['id'],$user['id'],$_SERVER['REMOTE_ADDR']??null]);header('Location: index.php');exit;
   }
   failed_login($email);$error='Incorrect email address or password.';
  }
 }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AIDA Admin Login</title><link rel="stylesheet" href="../assets/css/admin.css"></head><body class="admin-login"><main class="login-card"><a href="../index.php"><img src="../Logo.png" alt="AIDA"></a><p class="eyebrow">AIDA CONTENT PORTAL</p><h1><?=$pending?'Verify your login':'Welcome back.'?></h1><p><?=$pending?'Enter your authenticator code or a single-use recovery code.':'Sign in to manage the AIDA website.'?></p>
<?php if(isset($_GET['expired'])):?><p class="hint">Please sign in again. Sessions expire after 30 minutes of inactivity.</p><?php endif;?>
<?php if($error):?><div class="admin-alert error" role="alert"><?=e($error)?></div><?php endif;?>
<form method="post" class="admin-form"><input type="hidden" name="csrf" value="<?=csrf()?>">
<?php if($pending):?><label>Verification code<input name="code" autocomplete="one-time-code" maxlength="32" required autofocus></label><button class="admin-button">Verify &amp; sign in</button><button name="cancel" value="1" formnovalidate class="admin-link">Use a different account</button>
<?php else:?><label>Email address<input type="email" name="email" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="admin-button">Sign in</button><?php endif;?></form><a class="back-link" href="../index.php">Return to website</a></main></body></html>
