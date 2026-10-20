<?php
if(session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__.'/db.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_check(){ if($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Invalid request token. Refresh and try again.');} }
csrf_check();
function current_user(){return $_SESSION['user']??null;}
function require_login(){if(!current_user()){header('Location: '.url('/login.php'));exit;}}
function require_role($roles){require_login(); if(!in_array(current_user()['role'],(array)$roles,true)){http_response_code(403);exit('403 Forbidden: insufficient permission.');}}
function url($p=''){return rtrim(BASE_URL,'/').'/'.ltrim($p,'/');}
function flash($msg,$type='success'){$_SESSION['flash']=['msg'=>$msg,'type'=>$type];}
function show_flash(){if(!empty($_SESSION['flash'])){$f=$_SESSION['flash'];echo '<div class="alert '.e($f['type']).'">'.e($f['msg']).'</div>';unset($_SESSION['flash']);}}
function log_action($action,$entity=null,$id=null,$details=''){global $db;$uid=current_user()['id']??null;$stmt=$db->prepare('INSERT INTO activity_logs(user_id,action,entity,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)');$ip=$_SERVER['REMOTE_ADDR']??'';$stmt->bind_param('ississ',$uid,$action,$entity,$id,$details,$ip);$stmt->execute();}
function send_verify_email($email,$name,$token){$link=url('verify.php?token='.urlencode($token));$subject='Verify your Library System account';$body="Hello $name,

Verify your account using this one-time link (expires in 24 hours):
$link

If you did not create this account, ignore this email.";$headers='From: '.MAIL_FROM."
".'Content-Type: text/plain; charset=UTF-8';return @mail($email,$subject,$body,$headers);}
function role_home(){return url('dashboard.php');}
