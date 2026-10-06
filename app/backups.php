<?php
declare(strict_types=1);
require_once __DIR__.'/media.php';
function backup_list(): array {
    $result=[];foreach(glob(__DIR__.'/../storage/backups/aida-*.zip')?:[] as $file)$result[]=['name'=>basename($file),'size'=>filesize($file)];
    usort($result,fn($a,$b)=>strcmp($b['name'],$a['name']));return $result;
}
function create_backup(): string {
    if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension to create backups.');
    $dir=__DIR__.'/../storage/backups';if(!is_dir($dir) && !mkdir($dir,0700,true))throw new RuntimeException('Backup folder is not writable.');
    $name='aida-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';$path=$dir.'/'.$name;
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Backup could not be started.');
    $pdo=db();$tables=['users','site_settings','content','media','audit_logs','contact_messages','applications','user_security','login_attempts','content_state','content_metadata','content_documents','media_state','media_versions'];
    $snapshot=['format'=>1,'created_at'=>gmdate(DATE_ATOM),'tables'=>[]];$manifest=[];
    try{
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
        foreach($tables as $table){$ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$rows=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll();$snapshot['tables'][$table]=['ddl'=>$ddl,'rows'=>$rows];}
        $paths=array_column($snapshot['tables']['media']['rows'],'stored_name');$paths=array_merge($paths,array_column($snapshot['tables']['media_versions']['rows'],'file_path'));$paths=array_unique($paths);$total=0;
        foreach($paths as $relative){$absolute=media_disk_path($relative);$total+=filesize($absolute);if($total>1024*1048576)throw new RuntimeException('Library exceeds the 1 GB application backup limit. Use your hosting backup system.');
            $entry='files/'.hash('sha256',$relative).'.bin';$manifest[]=['path'=>$relative,'entry'=>$entry,'sha256'=>hash_file('sha256',$absolute)];if(!$zip->addFile($absolute,$entry))throw new RuntimeException('A file could not be added to the backup.');
        }
        $pdo->commit();$json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);$manifestJson=json_encode($manifest,JSON_THROW_ON_ERROR);
        $signature=hash_hmac('sha256',$json."\n".$manifestJson,security_key());
        if(!$zip->addFromString('database.json',$json) || !$zip->addFromString('manifest.json',$manifestJson) || !$zip->addFromString('signature.txt',$signature) || !$zip->close())throw new RuntimeException('The backup archive could not be completed.');
        chmod($path,0600);return $name;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();@$zip->close();if(is_file($path))unlink($path);throw $error;}
}
function verified_backup(string $path): array {
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Cannot read backup.');
    $json=$zip->getFromName('database.json');$manifestJson=$zip->getFromName('manifest.json');$signature=$zip->getFromName('signature.txt');
    if(!is_string($json)||!is_string($manifestJson)||!is_string($signature)||!hash_equals(hash_hmac('sha256',$json."\n".$manifestJson,security_key()),$signature)){$zip->close();throw new RuntimeException('Backup signature is invalid. Use the original application key.');}
    $snapshot=json_decode($json,true,512,JSON_THROW_ON_ERROR);$manifest=json_decode($manifestJson,true,512,JSON_THROW_ON_ERROR);
    if(($snapshot['format']??0)!==1)throw new RuntimeException('Unsupported backup format.');
    foreach($manifest as $file){if(!preg_match('~^(uploads/|storage/media/)[a-zA-Z0-9/_ .-]+$~D',$file['path']) || str_contains($file['path'],'..'))throw new RuntimeException('Unsafe archive path.');
        $stream=$zip->getStream($file['entry']);if(!$stream)throw new RuntimeException('A backup file is missing.');$hash=hash_init('sha256');hash_update_stream($hash,$stream);fclose($stream);if(!hash_equals($file['sha256'],hash_final($hash)))throw new RuntimeException('A backup file failed its integrity check.');
    }return [$zip,$snapshot,$manifest];
}
function restore_backup(string $path,PDO $target,string $destination): void {
    if($target->query('SHOW TABLES')->fetchColumn())throw new RuntimeException('Restore requires an EMPTY target database; existing data will not be overwritten.');
    if(!is_dir($destination) || (new FilesystemIterator($destination))->valid())throw new RuntimeException('Restore requires an existing EMPTY destination directory for uploaded files.');
    [$zip,$snapshot,$manifest]=verified_backup($path);$root=realpath($destination);
    try{
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($snapshot['tables'] as $table=>$data){if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Invalid table name.');$target->exec($data['ddl']);}
        $target->beginTransaction();
        foreach($snapshot['tables'] as $table=>$data)foreach($data['rows'] as $row){$columns=array_keys($row);foreach($columns as $column)if(!preg_match('/^[a-z][a-z0-9_]*$/D',$column))throw new RuntimeException('Invalid column name.');
            $q=$target->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');$q->execute(array_values($row));
        }
        foreach($manifest as $file){$absolute=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$file['path']);$dir=dirname($absolute);if(!is_dir($dir))mkdir($dir,0700,true);$input=$zip->getStream($file['entry']);$output=fopen($absolute,'xb');if(!$output)throw new RuntimeException('Restore file could not be created.');stream_copy_to_stream($input,$output);fclose($input);fclose($output);chmod($absolute,0600);}
        $target->commit();
    }finally{if($target->inTransaction())$target->rollBack();$target->exec('SET FOREIGN_KEY_CHECKS=1');$zip->close();}
}
