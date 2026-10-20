<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Sign out must be submitted as a form.');
}
verify_csrf();
$user = current_user();
if ($user) {
    log_activity((int) $user['id'], 'logout', 'user', (int) $user['id'], 'Signed out.');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: index.php');
exit;
