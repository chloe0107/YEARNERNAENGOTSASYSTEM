<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$demoAccounts = [
    ['Library Administrator', 'admin', 'admin'],
    ['Library Staff', 'staff', 'staff'],
    ['Demo Student', 'student', 'user'],
];
$credentials = [];
foreach ($demoAccounts as [$name, $prefix, $role]) {
    $email = $prefix . '@library.test';
    $password = bin2hex(random_bytes(9));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $existing = db_one('SELECT id FROM users WHERE email = ?', 's', $email);
    if ($existing) {
        db_exec('UPDATE users SET full_name = ?, password_hash = ?, role = ?, is_verified = 1, status = \'active\' WHERE id = ?', 'sssi', $name, $hash, $role, (int) $existing['id']);
    } else {
        db_exec('INSERT INTO users (full_name, email, password_hash, role, is_verified) VALUES (?, ?, ?, ?, 1)', 'ssss', $name, $email, $hash, $role);
    }
    $credentials[] = [$role, $email, $password];
}
echo "Demo accounts created. Save these generated passwords now:\n";
foreach ($credentials as [$role, $email, $password]) {
    echo strtoupper($role) . " | {$email} | {$password}\n";
}
