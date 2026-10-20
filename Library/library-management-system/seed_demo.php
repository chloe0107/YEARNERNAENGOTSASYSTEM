<?php
// Run once after importing database.sql, then DELETE this file. Password for all demo users: Demo123!
require_once __DIR__.'/includes/db.php';
$hash='$2y$12$fAB4I4OGWj5KY01/uCRUfuHT5PsEMbU8uosWqvwafUf07akVA3Tsq';
$rows=[['System Administrator','admin@library.local','admin'],['Library Staff','staff@library.local','staff'],['Sample Student','student@library.local','user']];
foreach($rows as $r){ $s=$db->prepare("INSERT INTO users(name,email,password_hash,role,is_verified) VALUES(?,?,?, ?,1) ON DUPLICATE KEY UPDATE is_verified=1");$s->bind_param('ssss',$r[0],$r[1],$hash,$r[2]);$s->execute(); }
echo 'Demo accounts created. Password: Demo123!. Delete seed_demo.php now.';
