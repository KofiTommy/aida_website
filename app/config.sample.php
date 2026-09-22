<?php
// Copy this file to config.local.php and set a unique, long application key.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'aida_cms',
        'user' => 'root',
        'pass' => '',
    ],
    'app_key' => 'replace-with-a-long-random-secret-at-least-32-characters',
    'upload_max_mb' => 25,
];
