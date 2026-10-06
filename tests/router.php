<?php
// Local integration runner only. No bypass login or production test endpoint.
if(PHP_SAPI!=='cli-server' || !preg_match('/^aida_test_[a-f0-9]{12}$/D',(string)getenv('AIDA_TEST_DATABASE'))){http_response_code(403);exit;}
$request=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('~^/(app|storage|uploads|database|tests|scripts)(/|$)~',$request)){http_response_code(403);exit;}
require_once __DIR__.'/../app/bootstrap.php';
$GLOBALS['config']['db']['name']=getenv('AIDA_TEST_DATABASE');
$GLOBALS['config']['app_key']='integration-only-secret-key-not-for-production-2026';
return false;
