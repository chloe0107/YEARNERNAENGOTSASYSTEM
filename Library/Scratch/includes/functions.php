<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db_one(string $sql, string $types = '', mixed ...$values): ?array
{
    global $db;
    $statement = $db->prepare($sql);
    if ($types !== '') {
        $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    $result = $statement->get_result();
    $row = $result->fetch_assoc() ?: null;
    $statement->close();
    return $row;
}

function db_all(string $sql, string $types = '', mixed ...$values): array
{
    global $db;
    $statement = $db->prepare($sql);
    if ($types !== '') {
        $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function db_exec(string $sql, string $types = '', mixed ...$values): int
{
    global $db;
    $statement = $db->prepare($sql);
    if ($types !== '') {
        $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    $affected = $statement->affected_rows;
    $statement->close();
    return $affected;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(array $roles = []): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect('index.php?view=login');
    }
    $freshUser = db_one('SELECT id, full_name, email, role, is_verified, status FROM users WHERE id = ?', 'i', (int) $user['id']);
    if (!$freshUser || $freshUser['status'] !== 'active') {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        flash('error', 'This account is not active.');
        redirect('index.php?view=login');
    }
    $_SESSION['user'] = $freshUser;
    $user = $freshUser;
    if (!$user['is_verified'] && $user['role'] === 'user') {
        flash('error', 'Verify your email before using library services.');
        redirect('index.php?view=verify-notice');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    if (in_array($user['role'], ['admin', 'staff'], true)) {
        refresh_overdue_transactions();
    }
    return $user;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('This form expired. Return to the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function log_activity(?int $userId, string $action, string $entity, ?int $entityId, string $description): void
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    db_exec(
        'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
        'ississ', $userId, $action, $entity, $entityId, $description, $ip
    );
}

function setting(string $key, string $fallback): string
{
    $row = db_one('SELECT setting_value FROM system_settings WHERE setting_key = ?', 's', $key);
    return $row['setting_value'] ?? $fallback;
}

function configured_mailer(): object
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('Email library is missing. Run composer install.');
    }
    foreach (['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM_ADDRESS'] as $key) {
        if (!getenv($key)) {
            throw new RuntimeException('SMTP is not configured. Update the local .env file.');
        }
    }
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = getenv('SMTP_HOST');
    $mailer->SMTPAuth = true;
    $mailer->Username = getenv('SMTP_USERNAME');
    $mailer->Password = getenv('SMTP_PASSWORD');
    $mailer->Port = (int) (getenv('SMTP_PORT') ?: 587);
    $encryption = strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls');
    if ($encryption === 'tls') {
        $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    }
    $mailer->setFrom(getenv('MAIL_FROM_ADDRESS'), getenv('MAIL_FROM_NAME') ?: 'School Library');
    return $mailer;
}

function send_verification_email(string $address, string $name, string $token): void
{
    $mailer = configured_mailer();
    $mailer->addAddress($address, $name);
    $base = rtrim(getenv('APP_URL') ?: 'http://localhost/library-management-system', '/');
    $mailer->isHTML(true);
    $mailer->Subject = 'Verify your School Library account';
    $mailer->Body = '<p>Hello ' . e($name) . ',</p><p>Verify your email address to activate your library account:</p><p><a href="' . e($base . '/index.php?view=verify&token=' . rawurlencode($token)) . '">Verify email address</a></p><p>This link expires in 24 hours.</p>';
    $mailer->AltBody = 'Verify your account: ' . $base . '/index.php?view=verify&token=' . rawurlencode($token);
    $mailer->send();
}

function send_password_reset_email(string $address, string $name, string $token): void
{
    $mailer = configured_mailer();
    $mailer->addAddress($address, $name);
    $base = rtrim(getenv('APP_URL') ?: 'http://localhost/library-management-system', '/');
    $link = $base . '/index.php?view=reset-password&token=' . rawurlencode($token);
    $mailer->isHTML(true);
    $mailer->Subject = 'Reset your School Library password';
    $mailer->Body = '<p>Hello ' . e($name) . ',</p><p>Use this link to set a new password. It expires in one hour and can only be used once:</p><p><a href="' . e($link) . '">Reset password</a></p>';
    $mailer->AltBody = 'Reset your password: ' . $link;
    $mailer->send();
}

function notify_user(int $userId, string $subject, string $message): bool
{
    $account = db_one('SELECT full_name, email FROM users WHERE id = ?', 'i', $userId);
    if (!$account) return false;
    try {
        $mailer = configured_mailer();
        $mailer->addAddress($account['email'], $account['full_name']);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = '<p>Hello ' . e($account['full_name']) . ',</p><p>' . e($message) . '</p><p>School Library</p>';
        $mailer->AltBody = $message;
        $mailer->send();
        return true;
    } catch (Throwable $exception) {
        error_log('Library notification delivery failed: ' . $exception->getMessage());
        return false;
    }
}

function store_book_cover(?array $upload): ?string
{
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new DomainException('Cover must upload successfully and be no larger than 2 MB.');
    }
    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new DomainException('The uploaded cover could not be verified.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $image = @getimagesize($temporaryPath);
    if (!isset($extensions[$mime]) || !$image || $image[0] < 1 || $image[1] < 1 || $image[0] > 8000 || $image[1] > 8000 || $image[0] * $image[1] > 25000000) {
        throw new DomainException('Cover must be a valid JPEG, PNG, or WebP image with reasonable dimensions.');
    }
    $directory = dirname(__DIR__) . '/uploads/covers';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Cover storage could not be prepared.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporaryPath, $directory . '/' . $filename)) {
        throw new RuntimeException('Cover could not be stored.');
    }
    return 'uploads/covers/' . $filename;
}

function reservation_queue(int $bookId): array
{
    $rows = db_all(
        "SELECT r.id, r.user_id, r.queue_position, r.reserved_at, u.full_name, u.is_verified
         FROM reservations r JOIN users u ON u.id = r.user_id
         WHERE r.book_id = ? AND r.status = 'waiting' AND u.status = 'active'
         ORDER BY r.queue_position ASC, r.id ASC",
        'i', $bookId
    );
    // Enqueue persists the reservation; ordered traversal is O(n), and the eligibility scan for peek is O(n).
    return $rows;
}

function reservation_queue_peek(int $bookId): ?array
{
    foreach (reservation_queue($bookId) as $reservation) {
        if (!(bool) $reservation['is_verified']) {
            continue;
        }
        $limit = (int) setting('borrowing_limit', '3');
        $counts = db_one(
            "SELECT
                (SELECT COUNT(*) FROM borrowing_transactions WHERE user_id = ? AND status IN ('borrowed','overdue')) AS active_loans,
                (SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status IN ('pending','approved')) AS active_requests,
                (SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'ready_for_pickup' AND expires_at > NOW()) AS ready_reservations",
            'iii', (int) $reservation['user_id'], (int) $reservation['user_id'], (int) $reservation['user_id']
        );
        if ((int) $counts['active_loans'] + (int) $counts['active_requests'] + (int) $counts['ready_reservations'] < $limit) {
            return $reservation;
        }
    }
    return null;
}

function reservation_queue_dequeue(int $reservationId, int $expiryDays): bool
{
    return db_exec(
        "UPDATE reservations SET status = 'ready_for_pickup', ready_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ? AND status = 'waiting'",
        'ii', $expiryDays, $reservationId
    ) === 1;
}

function available_copies(int $bookId, int $total): int
{
    $counts = db_one(
        "SELECT
            (SELECT COUNT(*) FROM borrowing_transactions WHERE book_id = ? AND status IN ('borrowed','overdue','lost')) AS loans,
            (SELECT COUNT(*) FROM borrowing_requests WHERE book_id = ? AND status IN ('pending','approved')) AS requests,
            (SELECT COUNT(*) FROM reservations WHERE book_id = ? AND status = 'ready_for_pickup' AND expires_at > NOW()) AS ready",
        'iii', $bookId, $bookId, $bookId
    );
    return max(0, $total - (int) $counts['loans'] - (int) $counts['requests'] - (int) $counts['ready']);
}

function refresh_overdue_transactions(): int
{
    $updated = db_exec("UPDATE borrowing_transactions SET status = 'overdue' WHERE status = 'borrowed' AND due_date < CURDATE()");
    if ($updated > 0) {
        log_activity(null, 'overdue_detected', 'borrowing_transaction', null, 'Marked ' . $updated . ' overdue transaction(s).');
    }
    return $updated;
}
