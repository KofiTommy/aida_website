<?php require_once __DIR__ . '/../app/bootstrap.php'; audit('logout','user',current_user()['id'] ?? null); logout(); header('Location: login.php');
