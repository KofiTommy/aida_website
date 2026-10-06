<?php
declare(strict_types=1);
function backend_upgrade(PDO $pdo): void {
    if ($pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='backend_schema_version'")->fetchColumn()==='2') return;
    $name='aida-upgrade-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,30);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,10)'); $lock->execute([$name]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Database upgrade is busy. Please try again.');
    try {
        foreach (['applications.sql','backend-upgrade.sql'] as $file) {
            $sql=file_get_contents(__DIR__.'/../database/'.$file);
            if ($sql===false) throw new RuntimeException('A database upgrade file is missing.');
            foreach (array_filter(array_map('trim',explode(';',$sql))) as $statement) $pdo->exec($statement);
        }
        $pdo->exec("INSERT INTO site_settings (setting_key,setting_value) VALUES ('backend_schema_version','2') ON DUPLICATE KEY UPDATE setting_value='2'");
    } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
}
