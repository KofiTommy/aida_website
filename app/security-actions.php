<?php
declare(strict_types=1);
function security_actions(string $action): void {
    if(!in_array($action,['begin_2fa','confirm_2fa','disable_2fa','change_password','revoke_others'],true))return;
    $user=current_user();$id=(int)$user['id'];
    try{
        require_password((string)($_POST['current_password']??''));$record=security_record($id);
        if($action==='begin_2fa'){
            if($record['totp_secret'])throw new RuntimeException('Two-factor authentication is already enabled.');
            security_key();$_SESSION['totp_enrolment']=['secret'=>base32_encode(random_bytes(20)),'started'=>time()];
            admin_redirect('security','Add the key to your authenticator app, then confirm a code.');
        }
        if($action==='confirm_2fa'){
            $pending=$_SESSION['totp_enrolment']??null;
            if(!$pending || time()-$pending['started']>600)throw new RuntimeException('Setup expired. Start again.');
            if(login_is_limited($user['email']))throw new RuntimeException('Please wait 15 minutes before trying again.');
            $counter=totp_counter($pending['secret'],trim((string)($_POST['code']??'')));
            if($counter===null){failed_login($user['email']);throw new RuntimeException('The authenticator code is incorrect.');}
            $codes=[];for($i=0;$i<8;$i++)$codes[]=bin2hex(random_bytes(8));
            $hashes=array_map(fn($code)=>hash('sha256',$code),$codes);
            db()->prepare('UPDATE user_security SET totp_secret=?,recovery_codes=?,last_counter=?,session_version=session_version+1 WHERE user_id=?')->execute([encrypt_secret($pending['secret']),json_encode($hashes),$counter,$id]);
            $_SESSION['session_version']=(int)$record['session_version']+1;$_SESSION['recovery_codes_display']=$codes;unset($_SESSION['totp_enrolment']);audit('enable_2fa','user',$id);
            admin_redirect('security','Two-factor authentication enabled. Save your recovery codes now.');
        }
        if(in_array($action,['disable_2fa','change_password','revoke_others'],true) && $record['totp_secret'] && !verify_second_factor($id,trim((string)($_POST['code']??'')))){
            failed_login($user['email']);throw new RuntimeException('Enter a fresh authenticator code or an unused recovery code.');
        }
        if($action==='disable_2fa'){
            db()->prepare('UPDATE user_security SET totp_secret=NULL,recovery_codes=NULL,last_counter=-1 WHERE user_id=?')->execute([$id]);unset($_SESSION['totp_enrolment']);
        }
        if($action==='change_password'){
            $password=(string)($_POST['new_password']??'');
            if(strlen($password)<12 || strlen($password)>72 || $password!==($_POST['confirm_password']??''))throw new RuntimeException('Use matching passwords between 12 and 72 bytes.');
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        }
        db()->prepare('UPDATE user_security SET session_version=session_version+1 WHERE user_id=?')->execute([$id]);
        $_SESSION['session_version']=(int)security_record($id)['session_version'];session_regenerate_id(true);audit($action,'user',$id);admin_redirect('security','Security settings updated. Other sessions have been signed out.');
    }catch(Throwable $error){error_log('AIDA security settings: '.$error->getMessage());admin_redirect('security',$error instanceof PDOException?'Could not update security settings.':$error->getMessage(),true);}
}
