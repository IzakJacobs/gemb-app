<?php
require __DIR__.'/bootstrap.php';
$name=$_GET['name']??'';
try {$admin=authorized();$staff=$admin?null:assistant_account();}catch(Throwable $e){error_log('Quorum asset authorization: '.$e->getMessage());http_response_code(503);exit('Access unavailable.');}
if(!$admin&&!$staff){http_response_code(401);exit('Authentication required.');}
$assets=['app.js'=>'application/javascript','persistence.js'=>'application/javascript','xlsx.full.min.js'=>'application/javascript','style.css'=>'text/css','assistant.js'=>'application/javascript'];
if(!is_string($name)||!isset($assets[$name])){http_response_code(404);exit;}
if(!$admin&&$name!=='assistant.js'){http_response_code(403);exit('Assistant asset access only.');}
$path=dirname($configPath).'/assets/'.$name;
if(!is_file($path)){http_response_code(503);exit('Asset missing.');}
header('Content-Type: '.$assets[$name].'; charset=utf-8');session_write_close();readfile($path);
