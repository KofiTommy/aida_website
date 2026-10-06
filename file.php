<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/media.php';
$id=(int)($_GET['id']??0);$version=(int)($_GET['version']??0);
$q=db()->prepare('SELECT m.*,s.deleted_at FROM media m LEFT JOIN media_state s ON s.media_id=m.id WHERE m.id=?');$q->execute([$id]);$media=$q->fetch();
$user=current_user();$privileged=$user && ($user['role']!=='contributor' || ($media && (int)$media['uploaded_by']===(int)$user['id']));
if(!$media || ($version && !$privileged) || (!$privileged && ($media['deleted_at'] || !media_is_public($media)))){http_response_code(404);exit('File unavailable.');}
$q=db()->prepare('SELECT * FROM media_versions WHERE media_id=?'.($version?' AND id=?':'').' ORDER BY id DESC LIMIT 1');$q->execute($version?[$id,$version]:[$id]);$record=$q->fetch();
if($version && !$record){http_response_code(404);exit('File unavailable.');}
$record=$record?:['file_path'=>$media['stored_name'],'mime_type'=>$media['mime_type'],'original_name'=>$media['original_name']];
try{$path=media_disk_path($record['file_path']);}catch(Throwable $error){http_response_code(404);exit('File unavailable.');}
$size=filesize($path);$start=0;$end=$size-1;
if(isset($_SERVER['HTTP_RANGE'])){
 if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$parts) || ($parts[1]==='' && $parts[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
 if($parts[1]==='')$start=max(0,$size-(int)$parts[2]);else{$start=(int)$parts[1];if($parts[2]!=='')$end=min($end,(int)$parts[2]);}
 if($start>$end || $start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
 http_response_code(206);header("Content-Range: bytes $start-$end/$size");
}
$mime=$record['mime_type'];$inline=str_starts_with($mime,'image/') || str_starts_with($mime,'video/') || $mime==='application/pdf';
$name=preg_replace('/[\x00-\x1f\x7f"\\\\]/','_',basename($record['original_name']));
header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.preg_replace('/[^a-zA-Z0-9 ._-]/','_',$name).'"; filename*=UTF-8\'\''.rawurlencode($name));
header('Cache-Control: private, no-store');header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));
session_write_close();if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
$stream=fopen($path,'rb');fseek($stream,$start);$remaining=$end-$start+1;
while($remaining>0 && !feof($stream)){ $chunk=fread($stream,min(65536,$remaining));if($chunk===false)break;echo $chunk;$remaining-=strlen($chunk); }fclose($stream);
