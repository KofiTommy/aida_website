<?php
require_once __DIR__.'/../app/bootstrap.php';require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Use the Sign out button.');}
verify_csrf();audit('logout','user',(int)current_user()['id']);logout();header('Location: login.php');
