<?php
require __DIR__ . '/bootstrap.php';
if(!authorized()) {http_response_code(401);exit('Authentication required.');}
$assets=['app.js'=>'application/javascript','persistence.js'=>'application/javascript','xlsx.full.min.js'=>'application/javascript','style.css'=>'text/css'];
$name=$_GET['name'] ?? '';
if(!is_string($name) || !isset($assets[$name])) {http_response_code(404);exit;}
// A fixed allowlist prevents path traversal and arbitrary file reads.
$path=dirname($configPath).'/assets/'.$name;
if(!is_file($path)) {http_response_code(503);exit('Asset missing.');}
header('Content-Type: '.$assets[$name].'; charset=utf-8');
session_write_close();
readfile($path);
