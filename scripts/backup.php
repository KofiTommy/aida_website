<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/backups.php';
try{echo create_backup().PHP_EOL;}catch(Throwable $error){fwrite(STDERR,'Backup failed: '.$error->getMessage().PHP_EOL);exit(1);}
