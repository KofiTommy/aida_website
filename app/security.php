<?php
declare(strict_types=1);
function security_record(int $id): array {
    db()->prepare('INSERT IGNORE INTO user_security (user_id) VALUES (?)')->execute([$id]);
    $q=db()->prepare('SELECT * FROM user_security WHERE user_id=?');$q->execute([$id]);return $q->fetch();
}
function login_key(string $email): string {return hash('sha256',strtolower(trim($email)));}
function login_is_limited(string $email): bool {
    $q=db()->prepare('SELECT SUM(account_key=?) account_count,SUM(ip_address=?) ip_count FROM login_attempts WHERE attempted_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE) AND (account_key=? OR ip_address=?)');
    $ip=$_SERVER['REMOTE_ADDR']??'unknown';$key=login_key($email);$q->execute([$key,$ip,$key,$ip]);$r=$q->fetch();
    return (int)$r['account_count']>=5 || (int)$r['ip_count']>=25;
}
function failed_login(string $email): void {
    db()->prepare('INSERT INTO login_attempts (account_key,ip_address) VALUES (?,?)')->execute([login_key($email),$_SERVER['REMOTE_ADDR']??'unknown']);
    db()->exec('DELETE FROM login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
}
function base32_encode(string $raw): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';$out='';
    foreach(str_split($raw) as $char)$bits.=str_pad(decbin(ord($char)),8,'0',STR_PAD_LEFT);
    foreach(str_split($bits,5) as $chunk)$out.=$alphabet[bindec(str_pad($chunk,5,'0'))];return $out;
}
function base32_decode(string $secret): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';$out='';
    foreach(str_split(strtoupper($secret)) as $char){$n=strpos($alphabet,$char);if($n===false)throw new RuntimeException('Invalid authenticator key.');$bits.=str_pad(decbin($n),5,'0',STR_PAD_LEFT);}
    foreach(str_split($bits,8) as $chunk)if(strlen($chunk)===8)$out.=chr(bindec($chunk));return $out;
}
function totp_value(string $secret,int $counter,int $digits=6): string {
    $hash=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),base32_decode($secret),true);
    $offset=ord($hash[19])&15;$value=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($value%(10**$digits)),$digits,'0',STR_PAD_LEFT);
}
function totp_counter(string $secret,string $code): ?int {
    if(!preg_match('/^\d{6}$/D',$code))return null;$now=intdiv(time(),30);
    foreach([$now,$now-1,$now+1] as $counter)if(hash_equals(totp_value($secret,$counter),$code))return $counter;return null;
}
function security_key(): string {
    global $config;$key=(string)($config['app_key']??'');
    if(strlen($key)<32 || str_contains($key,'replace-with'))throw new RuntimeException('Set a unique application key before enabling two-factor authentication.');return hash('sha256',$key,true);
}
function encrypt_secret(string $secret): string {
    $iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt($secret,'aes-256-gcm',security_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($encrypted===false)throw new RuntimeException('Authenticator encryption failed.');return base64_encode($iv.$tag.$encrypted);
}
function decrypt_secret(string $encrypted): string {
    $raw=base64_decode($encrypted,true);if($raw===false || strlen($raw)<29)throw new RuntimeException('Authenticator key is invalid.');
    $secret=openssl_decrypt(substr($raw,28),'aes-256-gcm',security_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    if($secret===false)throw new RuntimeException('Authenticator key could not be decrypted.');return $secret;
}
function verify_second_factor(int $id,string $code): bool {
    db()->beginTransaction();
    try {
        $q=db()->prepare('SELECT * FROM user_security WHERE user_id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();
        if(!$r || !$r['totp_secret']){db()->rollBack();return false;}
        $counter=totp_counter(decrypt_secret($r['totp_secret']),$code);
        if($counter!==null && $counter>(int)$r['last_counter']){db()->prepare('UPDATE user_security SET last_counter=? WHERE user_id=?')->execute([$counter,$id]);db()->commit();return true;}
        $codes=json_decode($r['recovery_codes']??'[]',true)?:[];$hash=hash('sha256',strtolower(str_replace('-','',trim($code))));
        foreach($codes as $i=>$saved)if(hash_equals($saved,$hash)){
            unset($codes[$i]);db()->prepare('UPDATE user_security SET recovery_codes=? WHERE user_id=?')->execute([json_encode(array_values($codes)),$id]);db()->commit();return true;
        }
        db()->rollBack();return false;
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
function require_password(string $password): void {
    $user=current_user();if(login_is_limited($user['email']))throw new RuntimeException('Too many failed attempts. Please wait 15 minutes.');
    $q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$user['id']]);
    if(!password_verify($password,(string)$q->fetchColumn())){failed_login($user['email']);throw new RuntimeException('Your current password is incorrect.');}
}
function require_sensitive_auth(): void {
    require_password((string)($_POST['current_password']??''));
    $user=current_user();$record=security_record((int)$user['id']);
    if($record['totp_secret'] && !verify_second_factor((int)$user['id'],trim((string)($_POST['code']??'')))){failed_login($user['email']);throw new RuntimeException('Enter a fresh authenticator code or an unused recovery code.');}
}
