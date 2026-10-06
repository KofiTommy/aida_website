<?php
require_once __DIR__.'/../app/bootstrap.php';require_login();require_role(['administrator']);require_once __DIR__.'/../app/backups.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();try{require_sensitive_auth();create_backup();audit('backup','system');flash('success','Backup created.');}catch(Throwable $error){error_log('AIDA backup: '.$error->getMessage());flash('error',$error instanceof PDOException?'Backup failed. Check the private error log.':$error->getMessage());}
 header('Location: index.php?section=backups');exit;
}
$name=(string)($_GET['name']??'');if(!preg_match('/^aida-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.zip$/D',$name)){http_response_code(404);exit('Backup not found.');}
$path=__DIR__.'/../storage/backups/'.$name;if(!is_file($path)){http_response_code(404);exit('Backup not found.');}
audit('download_backup','system');header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.filesize($path));header('Cache-Control: no-store');session_write_close();readfile($path);
