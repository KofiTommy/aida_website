<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
$root=realpath(__DIR__.'/..');$nonce=bin2hex(random_bytes(6));$testName='aida_test_'.$nonce;$restoreName=$testName.'_restore';
$work=$root.'/storage/test-'.$nonce;mkdir($work,0700,true);ini_set('session.save_path',$work);
require $root.'/app/bootstrap.php';require $root.'/app/backups.php';require $root.'/app/content-uploads.php';
$settings=$config['db'];$server=new PDO("mysql:host={$settings['host']};port={$settings['port']};charset=utf8mb4",$settings['user'],$settings['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$process=null;$backup=null;$passed=0;$createdDatabases=[];
function check(bool $condition,string $name): void {global $passed;if(!$condition)throw new RuntimeException('FAIL: '.$name);$passed++;echo 'PASS: '.$name.PHP_EOL;}
function http_test(string $path,string $account='visitor',?array $fields=null,array $headers=[]): array {
 global $work,$base;
 $ch=curl_init($base.$path);$cookie=$work.'/'.$account.'.cookies';
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);
 if($fields!==null){curl_setopt($ch,CURLOPT_POST,true);$hasFile=false;foreach($fields as $value)if($value instanceof CURLFile)$hasFile=true;curl_setopt($ch,CURLOPT_POSTFIELDS,$hasFile?$fields:http_build_query($fields));}
 if($headers)curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
 $response=curl_exec($ch);if($response===false)throw new RuntimeException('HTTP test connection failed: '.curl_error($ch));
 $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$size=curl_getinfo($ch,CURLINFO_HEADER_SIZE);$head=substr($response,0,$size);$body=substr($response,$size);$ch=null;return ['status'=>$code,'headers'=>$head,'body'=>$body];
}
function token(string $account,string $page='/admin/index.php'): string {
 $r=http_test($page,$account);preg_match('/name="csrf" value="([^"]+)"/',$r['body'],$m);if(empty($m[1]))throw new RuntimeException('Cannot get CSRF token: '.$page.' HTTP '.$r['status']);return html_entity_decode($m[1]);
}
function sign_in(string $account): array {return http_test('/admin/login.php',$account,['csrf'=>token($account,'/admin/login.php'),'email'=>$account.'@test.invalid','password'=>'test-password-2026-safe']);}
function action(array $fields,string $account='admin'): array {
 $fields['csrf']=$fields['csrf']??token($account);return http_test('/admin/index.php',$account,$fields,['X-Requested-With: XMLHttpRequest']);
}
function action_ok(array $fields,string $name,string $account='admin'): array {
 $r=action($fields,$account);$json=json_decode($r['body'],true);check($r['status']===200 && is_array($json) && empty($json['error']),$name);return $json;
}
function safe_remove_test_tree(string $path,string $work): void {
 $resolved=realpath($path);$base=realpath($work);if(!$resolved)return;
 if($resolved!==$base && !str_starts_with(str_replace('\\','/',$resolved),str_replace('\\','/',$base).'/'))throw new RuntimeException('Unsafe test cleanup path.');
 foreach(new FilesystemIterator($resolved) as $entry){if($entry->isDir()&&!$entry->isLink())safe_remove_test_tree($entry->getPathname(),$work);else unlink($entry->getPathname());}rmdir($resolved);
}
try{
 foreach([$testName,$restoreName] as $name){$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$createdDatabases[]=$name;}
 $config['db']['name']=$testName;$config['app_key']='integration-only-secret-key-not-for-production-2026';
 $seed=new PDO("mysql:host={$settings['host']};port={$settings['port']};dbname=$testName;charset=utf8mb4",$settings['user'],$settings['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $schema=preg_replace('/CREATE DATABASE[^;]+;\s*USE[^;]+;\s*/i','',file_get_contents($root.'/database/schema.sql'));
 foreach(array_filter(array_map('trim',explode(';',$schema))) as $sql)$seed->exec($sql);
 foreach(['admin'=>'administrator','editor'=>'editor','writer'=>'contributor'] as $name=>$role){$seed->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)')->execute([$name,$name.'@test.invalid',password_hash('test-password-2026-safe',PASSWORD_DEFAULT),$role]);}
 $pdo=db();backend_upgrade($pdo);check($pdo->query("SHOW TABLES LIKE 'applications'")->fetchColumn()!==false,'additive migration creates Applications and is repeatable');
 $secret=base32_encode('12345678901234567890');check(totp_value($secret,1,8)==='94287082','RFC 6238 SHA-1 test vector');$binary=random_bytes(20);check(base32_decode(base32_encode($binary))===$binary,'Base32 roundtrip');check(decrypt_secret(encrypt_secret($secret))===$secret,'authenticator secret encryption roundtrip');
 security_record(3);$recovery='0123456789abcdef';$pdo->prepare('UPDATE user_security SET totp_secret=?,recovery_codes=? WHERE user_id=3')->execute([encrypt_secret($secret),json_encode([hash('sha256',$recovery)])]);
 $otp=totp_value($secret,intdiv(time(),30));check(verify_second_factor(3,$otp),'valid authenticator code');check(!verify_second_factor(3,$otp),'authenticator replay denied');check(verify_second_factor(3,$recovery),'recovery code accepted');check(!verify_second_factor(3,$recovery),'recovery code cannot be reused');$pdo->exec('UPDATE user_security SET totp_secret=NULL,recovery_codes=NULL,last_counter=-1 WHERE user_id=3');
 $config['malware_scanner']=$work.'/missing-scanner';try{scan_upload($root.'/Logo.png');$blocked=false;}catch(RuntimeException $e){$blocked=true;}check($blocked,'configured unavailable malware scanner fails closed');unset($config['malware_scanner']);
 $port=random_int(20000,45000);$base='http://127.0.0.1:'.$port;
 $env=getenv();$env['AIDA_TEST_DATABASE']=$testName;
 $process=proc_open([PHP_BINARY,'-d','session.save_path='.$work,'-d','upload_tmp_dir='.$work,'-S','127.0.0.1:'.$port,'-t',$root,$root.'/tests/router.php'],[0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a']],$pipes,$root,$env);if(!is_resource($process))throw new RuntimeException('Test server failed.');fclose($pipes[0]);
 for($i=0;$i<30;$i++){usleep(100000);$probe=@fsockopen('127.0.0.1',$port,$errno,$error,0.1);if($probe){fclose($probe);break;}}
 check(http_test('/admin/index.php')['status']===302,'anonymous admin access requires login');check(sign_in('admin')['status']===302,'password login');
 foreach(['dashboard','editor','media','trash','security','activity','backups','users','applications','settings','messages'] as $page)check(http_test('/admin/index.php?section='.$page,'admin')['status']===200,'admin page '.$page.' renders');
 check(action(['action'=>'upload_media','csrf'=>'bad'])['status']===419,'CSRF failure blocks write');
 check(http_test('/admin/logout.php','admin')['status']===405,'logout requires POST');
 check(http_test('/admin/logout.php','admin',['csrf'=>'bad'])['status']===419,'logout requires CSRF');
 $idle=http_test('/admin/login.php','idle',['csrf'=>token('idle','/admin/login.php'),'email'=>'admin@test.invalid','password'=>'test-password-2026-safe']);check($idle['status']===302,'separate session for inactivity test');
 preg_match('/aida_session\s+([^\s]+)/',file_get_contents($work.'/idle.cookies'),$idleCookie);$sessionFile=$work.'/sess_'.$idleCookie[1];
 file_put_contents($sessionFile,preg_replace('/last_activity\|i:\d+;/','last_activity|i:0;',file_get_contents($sessionFile)));
 check(http_test('/admin/index.php','idle')['status']===302,'inactive session expires');
 $fake=action(['action'=>'upload_media','files[0]'=>new CURLFile($root.'/app/bootstrap.php','image/png','disguised.png')]);check(!empty(json_decode($fake['body'],true)['error']),'disguised script rejected');
 action_ok(['action'=>'upload_media','files[0]'=>new CURLFile($root.'/Logo.png','image/png','test-banner.png'),'files[1]'=>new CURLFile($root.'/AIDA Profile.pdf','application/pdf','test-report.pdf')],'batch image and PDF upload');
 $media=$pdo->query('SELECT * FROM media ORDER BY id')->fetchAll();$image=$media[0];$document=$media[1];
 check(http_test('/file.php?id='.$document['id'])['status']===404,'unattached document is private');check(http_test('/file.php?id='.$document['id'],'admin')['status']===200,'staff can preview private document');
 $mismatch=action(['action'=>'upload_media','files[0]'=>new CURLFile($root.'/AIDA Profile.pdf','application/pdf','report.php')]);check(!empty(json_decode($mismatch['body'],true)['error']),'MIME/extension mismatch rejected');
 $beforeMedia=(int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn();$mixed=action(['action'=>'upload_media','files[0]'=>new CURLFile($root.'/Logo.png','image/png','valid.png'),'files[1]'=>new CURLFile($root.'/app/bootstrap.php','image/png','bad.png')]);check(!empty(json_decode($mixed['body'],true)['error']) && (int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn()===$beforeMedia,'invalid batch leaves no partial uploads');
 $fields=['action'=>'save_content','title'=>'Integration publication','slug'=>'integration-publication','content_type'=>'publication','status'=>'draft','summary'=>'Research summary','body'=>'Full body','authors'=>'Test Authors','category'=>'Economics','document_date'=>'2026-10-06','image_existing'=>$image['id'],'document_existing'=>$document['id'],'document_ids'=>[$document['id']],'show_on_homepage'=>'1'];
 action_ok($fields,'content with existing media, metadata and supporting document');$id=(int)$pdo->query('SELECT id FROM content')->fetchColumn();$fields['id']=$id;
 check(!str_contains(http_test('/index.php')['body'],'Integration publication'),'draft hidden from homepage');check(http_test('/file.php?id='.$document['id'])['status']===404,'draft document denies visitor');
 $fields['status']='published';action_ok($fields,'publish featured content');
 check(!str_contains(http_test('/insights.php?type=event')['body'],'Integration publication'),'event category excludes publications');check(http_test('/file.php?id='.$document['id'],'visitor',null,['Range: bytes=999999999-'])['status']===416,'invalid range rejected');
 check(str_contains(http_test('/index.php')['body'],'Integration publication'),'published content visible on homepage');check(str_contains(http_test('/insights.php?type=publication')['body'],'Test Authors'),'publication metadata visible');check(http_test('/file.php?id='.$document['id'])['status']===200,'published document accessible');check(http_test('/file.php?id='.$document['id'],'visitor',null,['Range: bytes=0-9'])['status']===206,'download supports byte ranges');
 action_ok(['action'=>'replace_media','media_id'=>$document['id'],'file'=>new CURLFile($root.'/AIDA Profile.pdf','application/pdf','updated-report.pdf')],'document replacement');
 $versions=$pdo->query('SELECT id FROM media_versions WHERE media_id='.(int)$document['id'].' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);check(count($versions)===2,'replacement preserves both versions');check(http_test('/file.php?id='.$document['id'].'&version='.$versions[0])['status']===404,'old version remains private');check(http_test('/file.php?id='.$document['id'].'&version='.$versions[0],'admin')['status']===200,'staff can download old version');
 $r=action(['action'=>'trash_media','id'=>$document['id'],'confirm_delete'=>'1']);check(!empty(json_decode($r['body'],true)['error']),'in-use file cannot be trashed');
 action_ok(['action'=>'delete_content','id'=>$id,'confirm_delete'=>'1'],'content moves to Trash');check(!str_contains(http_test('/index.php')['body'],'Integration publication'),'Trash content hidden from homepage');check(http_test('/file.php?id='.$document['id'])['status']===404,'Trash attachment denies visitor');
 action_ok(['action'=>'restore_content','id'=>$id],'content restoration');check($pdo->query('SELECT status FROM content WHERE id='.$id)->fetchColumn()==='draft','restored content is draft');
 $r=action(['action'=>'delete_content','id'=>$id]);check(!empty(json_decode($r['body'],true)['error']),'Trash requires confirmation');
 check(sign_in('writer')['status']===302,'contributor login');check(action(['action'=>'delete_content','id'=>$id,'confirm_delete'=>'1'],'writer')['status']===403,'contributor cannot delete content');check(http_test('/file.php?id='.$document['id'],'writer')['status']===404,'contributor cannot access another private file');
 foreach(['users','applications','backups','activity'] as $denied)check(http_test('/admin/index.php?section='.$denied,'writer')['status']===403,'contributor denied '.$denied.' page');
 $r=action(['action'=>'save_content','id'=>$id,'title'=>'tamper','content_type'=>'insight','status'=>'draft'],'writer');check(!empty(json_decode($r['body'],true)['error']),'contributor cannot edit another author content');
 action_ok(['action'=>'save_content','title'=>'Writer draft','content_type'=>'insight','status'=>'published'],'contributor submission saved for review','writer');check($pdo->query("SELECT status FROM content WHERE title='Writer draft'")->fetchColumn()==='review','contributor cannot publish');
 check(sign_in('editor')['status']===302,'editor login');action_ok(['action'=>'toggle_user','id'=>2,'current_password'=>'test-password-2026-safe'],'disable team account');check(http_test('/admin/index.php','editor')['status']===302,'disabled account session revoked immediately');action_ok(['action'=>'toggle_user','id'=>2,'current_password'=>'test-password-2026-safe'],'re-enable team account');
 check(sign_in('editor')['status']===302,'re-enabled account can log in');action_ok(['action'=>'revoke_sessions','id'=>2,'current_password'=>'test-password-2026-safe'],'revoke account sessions');check(http_test('/admin/index.php','editor')['status']===302,'revoked session no longer works');
 $documentPath=$pdo->query('SELECT stored_name FROM media WHERE id='.(int)$document['id'])->fetchColumn();$filePath=media_disk_path($documentPath);check(is_file($filePath),'original file preserved');
 $fields['status']='draft';$fields['remove_document']='1';$fields['document_existing']='';$fields['document_ids']=[];action_ok($fields,'detach main and supporting documents');action_ok(['action'=>'trash_media','id'=>$document['id'],'confirm_delete'=>'1'],'unused file moves to Trash');action_ok(['action'=>'restore_media','id'=>$document['id']],'file restored from Trash');
 $office=$work.'/example.docx';$zip=new ZipArchive();$zip->open($office,ZipArchive::CREATE);$zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');$zip->addFromString('word/document.xml','<document/>');$zip->close();action_ok(['action'=>'upload_media','files[0]'=>new CURLFile($office,'application/zip','example.docx')],'valid Office ZIP recognised');
 action_ok(['action'=>'begin_2fa','current_password'=>'test-password-2026-safe'],'start authenticator enrolment');$securityPage=http_test('/admin/index.php?section=security','admin');preg_match('/<code>([A-Z2-7]+)<\/code>/',$securityPage['body'],$match);check(!empty($match[1]),'manual authenticator setup key shown');$enrolCode=totp_value($match[1],intdiv(time(),30));action_ok(['action'=>'confirm_2fa','current_password'=>'test-password-2026-safe','code'=>$enrolCode],'enable two-factor authentication');$securityPage=http_test('/admin/index.php?section=security','admin');preg_match('/<pre>(.*?)<\/pre>/s',$securityPage['body'],$match);$codes=explode("\n",html_entity_decode($match[1]??''));check(count($codes)===8,'eight recovery codes shown once');check(!str_contains(http_test('/admin/index.php?section=security','admin')['body'],'<pre>'),'recovery codes are not shown again');
 $login2=http_test('/admin/login.php','admin2',['csrf'=>token('admin2','/admin/login.php'),'email'=>'admin@test.invalid','password'=>'test-password-2026-safe']);check($login2['status']===302 && http_test('/admin/index.php','admin2')['status']===302,'MFA pending state has no admin access');$r=http_test('/admin/login.php','admin2',['csrf'=>token('admin2','/admin/login.php'),'code'=>$codes[0]]);check($r['status']===302 && http_test('/admin/index.php','admin2')['status']===200,'MFA recovery login succeeds');
 $backup=create_backup();[$checkedZip,$snapshot,$manifest]=verified_backup($root.'/storage/backups/'.$backup);$checkedZip->close();check(count($snapshot['tables'])===14 && count($manifest)>=4,'signed backup includes database and file versions');
 $tampered=$work.'/tampered.zip';copy($root.'/storage/backups/'.$backup,$tampered);$tamperedZip=new ZipArchive();$tamperedZip->open($tampered);$tamperedZip->addFromString('signature.txt',str_repeat('0',64));$tamperedZip->close();try{verified_backup($tampered);$blocked=false;}catch(RuntimeException $e){$blocked=true;}check($blocked,'tampered backup signature rejected');
 $restoreDir=$work.'/restored';mkdir($restoreDir,0700);$restoreDb=new PDO("mysql:host={$settings['host']};port={$settings['port']};dbname=$restoreName;charset=utf8mb4",$settings['user'],$settings['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);restore_backup($root.'/storage/backups/'.$backup,$restoreDb,$restoreDir);check((int)$restoreDb->query('SELECT COUNT(*) FROM content')->fetchColumn()===(int)$pdo->query('SELECT COUNT(*) FROM content')->fetchColumn(),'backup restoration recovers content');foreach($manifest as $entry)check(hash_equals($entry['sha256'],hash_file('sha256',$restoreDir.'/'.$entry['path'])),'restored file checksum matches');
 try{restore_backup($root.'/storage/backups/'.$backup,$restoreDb,$restoreDir);$blocked=false;}catch(RuntimeException $e){$blocked=true;}check($blocked,'restore refuses nonempty database/files');
 for($i=0;$i<5;$i++)http_test('/admin/login.php','bad',['csrf'=>token('bad','/admin/login.php'),'email'=>'bad@test.invalid','password'=>'wrong']);$limited=http_test('/admin/login.php','bad',['csrf'=>token('bad','/admin/login.php'),'email'=>'bad@test.invalid','password'=>'wrong']);check(str_contains($limited['body'],'Too many failed attempts'),'five failures trigger account throttle');
 echo "Completed $passed checks in isolated databases. No production records modified.\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL);$failed=true;}
finally{
 if(is_resource($process)){proc_terminate($process);proc_close($process);}session_write_close();
 if(isset($pdo)){foreach($pdo->query('SELECT stored_name FROM media UNION SELECT file_path FROM media_versions')->fetchAll(PDO::FETCH_COLUMN) as $path){try{$file=media_disk_path($path);if(str_starts_with($path,'storage/media/'))unlink($file);}catch(Throwable $e){}}}
 if($backup && is_file($root.'/storage/backups/'.$backup))unlink($root.'/storage/backups/'.$backup);
 foreach($createdDatabases as $name){if(!preg_match('/^aida_test_[a-f0-9]{12}(_restore)?$/D',$name)||$name===$settings['name'])throw new RuntimeException('Unsafe test database cleanup.');$server->exec('DROP DATABASE `'.$name.'`');}
 safe_remove_test_tree($work,$work);
}
exit(!empty($failed)?1:0);
