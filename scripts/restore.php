<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/backups.php';
$args=getopt('',['archive:','database:','files:','confirm']);
if(!isset($args['archive'],$args['database'],$args['files'],$args['confirm'])){fwrite(STDERR,"Usage: php scripts/restore.php --archive=/path/backup.zip --database=EMPTY_DB --files=/path/EMPTY_FOLDER --confirm\n");exit(1);}
if(!preg_match('/^[a-zA-Z0-9_]+$/D',$args['database']) || $args['database']===$config['db']['name']){fwrite(STDERR,"Choose a separate empty recovery database, not the live database.\n");exit(1);}
try{$settings=$config['db'];$target=new PDO("mysql:host={$settings['host']};port={$settings['port']};dbname={$args['database']};charset=utf8mb4",$settings['user'],$settings['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);restore_backup($args['archive'],$target,$args['files']);echo "Recovery completed into the separate database and files directory.\n";}catch(Throwable $error){fwrite(STDERR,'Restore failed: '.$error->getMessage().PHP_EOL);exit(1);}
