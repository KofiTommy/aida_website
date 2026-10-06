<?php
declare(strict_types=1);
function upload_formats(string $kind='any'): array {
 $formats=[
  'image'=>['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'],
  'document'=>['application/pdf'=>'pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx'],
  'video'=>['video/mp4'=>'mp4','video/webm'=>'webm'],
 ];
 return $formats[$kind]??array_merge(...array_values($formats));
}
function validate_upload(?array $file,string $kind='any'): ?array {
 if(!$file || ($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
 if(($file['error']??-1)!==UPLOAD_ERR_OK)throw new RuntimeException('Upload failed. Check the file size and select the file again.');
 if(!is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name']))throw new RuntimeException('Invalid upload. Please choose the file again.');
 $tmp=$file['tmp_name'];$size=filesize($tmp);
 if(!$size || $size>content_upload_limit())throw new RuntimeException('Choose a file within the displayed upload limit.');
 $name=mb_substr(basename(str_replace('\\','/',(string)($file['name']??'attachment'))),0,180);
 $name=preg_replace('/[\x00-\x1f\x7f]/','',$name);
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
 if(in_array($mime,['application/zip','application/octet-stream','application/x-zip-compressed'],true) || str_starts_with($mime,'application/vnd.openxmlformats-officedocument.')){
  $zip=new ZipArchive();
  if($zip->open($tmp)===true){
   $entries=['word/document.xml'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xl/workbook.xml'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt/presentation.xml'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
   if($zip->numFiles>1024){$zip->close();throw new RuntimeException('The Office document has too many internal files.');}
   $safe=true;$expanded=0;$mime='application/zip';
   for($i=0;$i<$zip->numFiles;$i++){
    $stat=$zip->statIndex($i);$expanded+=$stat['size'];
    if(preg_match('~(^/|(^|/)\.\.(/|$)|vbaProject|\.exe$|\.js$)~i',$stat['name']) || $expanded>200*1048576)$safe=false;
   }
   if($safe && $zip->locateName('[Content_Types].xml')!==false)foreach($entries as $entry=>$detected)if($zip->locateName($entry)!==false){$mime=$detected;break;}
   $zip->close();
  }
 }
 $allowed=upload_formats($kind);
 if(!isset($allowed[$mime]))throw new RuntimeException('That file format is not supported. Choose an image, PDF, supported Office document, MP4 or WebM.');
 $extension=$allowed[$mime];$clientExt=strtolower(pathinfo($name,PATHINFO_EXTENSION));
 if($clientExt!==$extension && !($extension==='jpg' && $clientExt==='jpeg'))throw new RuntimeException('The filename extension does not match the actual file format.');
 if(str_starts_with($mime,'image/')){
  $dimensions=@getimagesize($tmp);
  if(!$dimensions || $dimensions[0]*$dimensions[1]>40000000)throw new RuntimeException('The image is invalid or exceeds 40 megapixels.');
 }
 scan_upload($tmp);
 return ['tmp'=>$tmp,'mime'=>$mime,'extension'=>$extension,'size'=>$size,'name'=>$name,'sha256'=>hash_file('sha256',$tmp)];
}
function scan_upload(string $path): void {
 global $config;$scanner=$config['malware_scanner']??null;
 if(!$scanner)return;
 if(!is_string($scanner) || !is_file($scanner) || !function_exists('proc_open'))throw new RuntimeException('The configured malware scanner is unavailable. Uploads are paused.');
 $pipes=[];$process=proc_open([$scanner,'--no-summary',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('The malware scan could not start.');
 fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);
 if($exit!==0)throw new RuntimeException($exit===1?'This file was rejected by the malware scanner.':'The malware scan failed. Please contact the administrator.');
}
function content_uploads(array $files,int $limit): array {
 $result=[];
 foreach(['image_file'=>'image','document_file'=>'document','video_file'=>'video'] as $field=>$kind){
  $upload=validate_upload($files[$field]??null,$kind);if($upload)$result[$field]=$upload;
 }return $result;
}
function multiple_uploads(array $files,string $field,string $kind='document'): array {
 $result=[];$group=$files[$field]??null;if(!$group)return [];
 $names=$group['name']??[];if(!is_array($names))throw new RuntimeException('Invalid attachment selection.');
 if(count($names)>10)throw new RuntimeException('Upload at most 10 files at a time.');
 foreach($names as $i=>$name){
  $file=[];foreach(['name','tmp_name','error','size'] as $key)$file[$key]=$group[$key][$i]??null;
  $upload=validate_upload($file,$kind);if($upload)$result[]=$upload;
 }return $result;
}
function content_upload_limit(): int {
 global $config;$limit=(int)($config['upload_max_mb']??25)*1048576;
 foreach(['upload_max_filesize','post_max_size'] as $option){
  $value=trim((string)ini_get($option));$bytes=(float)$value;$unit=strtolower(substr($value,-1));
  $bytes *= ['k'=>1024,'m'=>1048576,'g'=>1073741824][$unit]??1;
  if($bytes>0)$limit=min($limit,(int)$bytes);
 }return max(1,$limit);
}
function ini_bytes(string $value): int {
 $value=trim($value);$unit=strtolower(substr($value,-1));return (int)((float)$value*(['k'=>1024,'m'=>1048576,'g'=>1073741824][$unit]??1));
}
function store_upload(array $upload,array &$created,?int $replaceId=null,string $alt=''): array {
 $dir=__DIR__.'/../storage/media/'.date('Y/m');
 if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir))throw new RuntimeException('The private upload folder is not writable.');
 $path='storage/media/'.date('Y/m').'/'.bin2hex(random_bytes(16)).'.'.$upload['extension'];
 $absolute=__DIR__.'/../'.$path;
 if(!move_uploaded_file($upload['tmp'],$absolute))throw new RuntimeException('The server could not store the file.');
 $created[]=$absolute;$user=current_user();
 if($replaceId){
  $old=editable_media($replaceId);
  if(media_kind($old['mime_type'])!==media_kind($upload['mime']))throw new RuntimeException('Replace the file with the same category: image, document or video.');
  $q=db()->prepare('SELECT COUNT(*) FROM media_versions WHERE media_id=?');$q->execute([$replaceId]);
  if(!(int)$q->fetchColumn())db()->prepare('INSERT INTO media_versions (media_id,original_name,file_path,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?)')->execute([$replaceId,$old['original_name'],$old['stored_name'],$old['mime_type'],$old['file_size'],$old['uploaded_by']]);
  db()->prepare('UPDATE media SET original_name=?,mime_type=?,file_size=? WHERE id=?')->execute([$upload['name'],$upload['mime'],$upload['size'],$replaceId]);
  $id=$replaceId;$logical=$old['stored_name'];
 }else{
  db()->prepare('INSERT INTO media (original_name,stored_name,mime_type,file_size,alt_text,uploaded_by) VALUES (?,?,?,?,?,?)')->execute([$upload['name'],$path,$upload['mime'],$upload['size'],mb_substr($alt,0,255),$user['id']]);
  $id=(int)db()->lastInsertId();$logical=$path;
 }
 db()->prepare('INSERT INTO media_versions (media_id,original_name,file_path,mime_type,file_size,sha256,uploaded_by) VALUES (?,?,?,?,?,?,?)')->execute([$id,$upload['name'],$path,$upload['mime'],$upload['size'],$upload['sha256'],$user['id']]);
 audit($replaceId?'replace':'upload','media',$id);
 return ['id'=>$id,'path'=>$logical];
}
function cleanup_uploads(array $created): void {foreach($created as $file)if(is_file($file))unlink($file);}
