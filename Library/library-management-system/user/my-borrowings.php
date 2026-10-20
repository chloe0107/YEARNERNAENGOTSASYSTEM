<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role(['user']);
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = url('transactions.php');
if ($query !== '') $target .= '?' . $query;
header('Location: ' . $target);
exit;
