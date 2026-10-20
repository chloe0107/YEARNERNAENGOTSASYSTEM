<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$view = (string) ($_GET['view'] ?? (current_user() ? 'dashboard' : 'catalog'));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = current_user();

if ($method === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'register':
                if (!allow_attempts('register', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 60)) throw new DomainException('Too many registration attempts. Try again later.');
                $name = trim((string) ($_POST['full_name'] ?? ''));
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $studentId = trim((string) ($_POST['student_id'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || $password !== (string) ($_POST['password_confirm'] ?? '')) {
                    throw new DomainException('Enter a valid name and email, use a password of at least 10 characters, and make both passwords match.');
                }
                if (db_one('SELECT id FROM users WHERE email = ? OR (? <> \'\' AND student_id = ?)', 'sss', $email, $studentId, $studentId)) {
                    throw new DomainException('That email address or student ID is already registered.');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                db_exec('INSERT INTO users (full_name, email, student_id, password_hash) VALUES (?, ?, ?, ?)', 'ssss', $name, $email, $studentId !== '' ? $studentId : null, $hash);
                $newUserId = (int) $db->insert_id;
                $token = bin2hex(random_bytes(32));
                db_exec('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))', 'is', $newUserId, hash('sha256', $token));
                log_activity($newUserId, 'account_created', 'user', $newUserId, 'Account registered.');
                try {
                    send_verification_email($email, $name, $token);
                    flash('success', 'Account created. Check your email for the verification link.');
                } catch (Throwable $mailError) {
                    flash('error', 'Account created, but verification email delivery failed. Configure SMTP and use Resend verification.');
                }
                redirect('index.php?view=login');

            case 'login':
                $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                if (!allow_attempts('login', $ipAddress, 8, 15)) throw new DomainException('Too many sign-in attempts. Try again in 15 minutes.');
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $account = db_one('SELECT id, full_name, email, password_hash, role, is_verified, status FROM users WHERE email = ?', 's', $email);
                if (!$account || !password_verify((string) ($_POST['password'] ?? ''), $account['password_hash']) || $account['status'] !== 'active') {
                    throw new DomainException('Email or password was not recognized.');
                }
                session_regenerate_id(true);
                unset($account['password_hash']);
                $_SESSION['user'] = $account;
                db_exec('DELETE FROM auth_rate_limits WHERE bucket_key = ?', 's', hash('sha256', 'login|' . strtolower($ipAddress)));
                log_activity((int) $account['id'], 'login', 'user', (int) $account['id'], 'Successful sign in.');
                if (!$account['is_verified'] && $account['role'] === 'user') {
                    flash('error', 'Verify your email before borrowing or reserving books.');
                    redirect('index.php?view=verify-notice');
                }
                redirect('index.php?view=dashboard');

            case 'logout':
                if ($user) {
                    log_activity((int) $user['id'], 'logout', 'user', (int) $user['id'], 'Signed out.');
                }
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
                }
                session_destroy();
                redirect('index.php');

            case 'resend':
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $maySend = allow_attempts('verification', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 3, 60);
                $account = db_one('SELECT id, full_name, email FROM users WHERE email = ? AND is_verified = 0 AND role = \'user\'', 's', $email);
                $recentTokens = $account ? db_one('SELECT COUNT(*) AS count FROM email_verification_tokens WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)', 'i', (int) $account['id']) : null;
                if ($account && $maySend && (int) $recentTokens['count'] < 3) {
                    $token = bin2hex(random_bytes(32));
                    db_exec('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))', 'is', (int) $account['id'], hash('sha256', $token));
                    send_verification_email($account['email'], $account['full_name'], $token);
                }
                flash('success', 'If this address belongs to an unverified account, verification instructions will be sent when SMTP is configured.');
                redirect('index.php?view=verify-notice');

            case 'forgot-password':
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $maySend = allow_attempts('password-reset', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 3, 60);
                $account = db_one("SELECT id,full_name,email FROM users WHERE email=? AND is_verified=1 AND status='active'", 's', $email);
                if ($maySend && $account) {
                    db_exec('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', 'i', (int) $account['id']);
                    $token = bin2hex(random_bytes(32));
                    db_exec('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'is', (int) $account['id'], hash('sha256', $token));
                    try {
                        send_password_reset_email($account['email'], $account['full_name'], $token);
                    } catch (Throwable $mailError) {
                        error_log('Password reset email delivery failed: ' . $mailError->getMessage());
                    }
                }
                flash('success', 'If a verified account matches that address, reset instructions will be sent when SMTP is configured.');
                redirect('index.php?view=forgot-password');

            case 'reset-password':
                $rawToken = (string) ($_POST['token'] ?? '');
                $password = (string) ($_POST['password'] ?? '');
                if (strlen($password) < 10 || $password !== (string) ($_POST['password_confirm'] ?? '')) throw new DomainException('Password must be at least 10 characters and match the confirmation.');
                $db->begin_transaction();
                try {
                    $reset = db_one('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE', 's', hash('sha256', $rawToken));
                    if (!$reset) throw new DomainException('This reset link is invalid, expired, or already used.');
                    db_exec('UPDATE users SET password_hash=? WHERE id=?', 'si', password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']);
                    db_exec('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL', 'i', (int) $reset['user_id']);
                    log_activity((int) $reset['user_id'], 'password_reset', 'user', (int) $reset['user_id'], 'Password reset completed.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                session_regenerate_id(true);
                flash('success', 'Password updated. Sign in with your new password.');
                redirect('index.php?view=login');

            case 'borrow':
                $student = require_login(['user']);
                $bookId = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
                if (!$bookId) throw new DomainException('Choose a valid book.');
                $db->begin_transaction();
                try {
                    db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', 'i', (int) $student['id']);
                    $book = db_one('SELECT id, total_copies FROM books WHERE id = ? AND archived_at IS NULL FOR UPDATE', 'i', $bookId);
                    if (!$book || available_copies($bookId, (int) $book['total_copies']) < 1) throw new DomainException('No unreserved copy is currently available. Join the reservation queue instead.');
                    $limit = (int) setting('borrowing_limit', '3');
                    $counts = db_one("SELECT (SELECT COUNT(*) FROM borrowing_transactions WHERE user_id = ? AND status IN ('borrowed','overdue')) AS loans, (SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status IN ('pending','approved')) AS requests, (SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'ready_for_pickup' AND expires_at > NOW()) AS ready", 'iii', (int) $student['id'], (int) $student['id'], (int) $student['id']);
                    if ((int) $counts['loans'] + (int) $counts['requests'] + (int) $counts['ready'] >= $limit) throw new DomainException('You have reached the current borrowing limit.');
                    if (db_one("SELECT id FROM borrowing_requests WHERE user_id = ? AND book_id = ? AND status IN ('pending','approved')", 'ii', (int) $student['id'], $bookId)) throw new DomainException('You already have an active request for this book.');
                    if (db_one("SELECT id FROM reservations WHERE user_id = ? AND book_id = ? AND status IN ('waiting','ready_for_pickup')", 'ii', (int) $student['id'], $bookId)) throw new DomainException('You already have an active reservation for this book.');
                    db_exec("INSERT INTO borrowing_requests (user_id, book_id, status) VALUES (?, ?, 'pending')", 'ii', (int) $student['id'], $bookId);
                    $requestId = (int) $db->insert_id;
                    log_activity((int) $student['id'], 'borrow_request', 'borrowing_request', $requestId, 'Submitted a borrowing request.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                flash('success', 'Borrowing request submitted for staff review.');
                redirect('index.php?view=my-activity');

            case 'reserve':
                $student = require_login(['user']);
                $bookId = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
                if (!$bookId) throw new DomainException('Choose a valid book.');
                $db->begin_transaction();
                try {
                    db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', 'i', (int) $student['id']);
                    $book = db_one('SELECT id, total_copies FROM books WHERE id = ? AND archived_at IS NULL FOR UPDATE', 'i', $bookId);
                    if (!$book || available_copies($bookId, (int) $book['total_copies']) > 0) throw new DomainException('A copy is available now. Submit a borrowing request instead.');
                    $limit = (int) setting('borrowing_limit', '3');
                    $counts = db_one("SELECT (SELECT COUNT(*) FROM borrowing_transactions WHERE user_id = ? AND status IN ('borrowed','overdue')) AS loans, (SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status IN ('pending','approved')) AS requests, (SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'ready_for_pickup' AND expires_at > NOW()) AS ready", 'iii', (int) $student['id'], (int) $student['id'], (int) $student['id']);
                    if ((int) $counts['loans'] + (int) $counts['requests'] + (int) $counts['ready'] >= $limit) throw new DomainException('You have reached the current borrowing limit.');
                    if (db_one("SELECT id FROM reservations WHERE user_id = ? AND book_id = ? AND status IN ('waiting','ready_for_pickup')", 'ii', (int) $student['id'], $bookId)) throw new DomainException('You already have an active reservation for this book.');
                    if (db_one("SELECT id FROM borrowing_requests WHERE user_id = ? AND book_id = ? AND status IN ('pending','approved')", 'ii', (int) $student['id'], $bookId)) throw new DomainException('You already have an active borrowing request for this book.');
                    db_exec("INSERT INTO reservations (user_id, book_id, queue_position, status) VALUES (?, ?, 0, 'waiting')", 'ii', (int) $student['id'], $bookId);
                    $reservationId = (int) $db->insert_id;
                    db_exec('UPDATE reservations SET queue_position = ? WHERE id = ?', 'ii', $reservationId, $reservationId);
                    log_activity((int) $student['id'], 'reservation_created', 'reservation', $reservationId, 'Joined the book reservation queue.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                flash('success', 'Reservation added to the FIFO queue.');
                redirect('index.php?view=my-activity');

            case 'cancel-request':
                $student = require_login(['user']);
                $id = (int) ($_POST['id'] ?? 0);
                $changed = db_exec("UPDATE borrowing_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'", 'ii', $id, (int) $student['id']);
                if (!$changed) throw new DomainException('That request can no longer be cancelled.');
                log_activity((int) $student['id'], 'request_cancelled', 'borrowing_request', $id, 'Cancelled a pending borrowing request.');
                flash('success', 'Request cancelled.');
                redirect('index.php?view=my-activity');

            case 'cancel-reservation':
                $student = require_login(['user']);
                $id = (int) ($_POST['id'] ?? 0);
                $changed = db_exec("UPDATE reservations SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'waiting'", 'ii', $id, (int) $student['id']);
                if (!$changed) throw new DomainException('That reservation can no longer be cancelled.');
                log_activity((int) $student['id'], 'reservation_cancelled', 'reservation', $id, 'Cancelled a waiting reservation.');
                flash('success', 'Reservation cancelled.');
                redirect('index.php?view=my-activity');

            case 'save-profile':
                $student = require_login(['user']);
                $name = trim((string) ($_POST['full_name'] ?? ''));
                $studentId = trim((string) ($_POST['student_id'] ?? ''));
                $newPassword = (string) ($_POST['new_password'] ?? '');
                if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || strlen($studentId) > 60) throw new DomainException('Enter a valid name and student ID.');
                if ($studentId !== '' && db_one('SELECT id FROM users WHERE student_id = ? AND id <> ?', 'si', $studentId, (int) $student['id'])) throw new DomainException('That student ID is already in use.');
                if ($newPassword !== '') {
                    $stored = db_one('SELECT password_hash FROM users WHERE id = ?', 'i', (int) $student['id']);
                    if (!password_verify((string) ($_POST['current_password'] ?? ''), $stored['password_hash'])) throw new DomainException('Current password is incorrect.');
                    if (strlen($newPassword) < 10 || $newPassword !== (string) ($_POST['password_confirm'] ?? '')) throw new DomainException('New password must be at least 10 characters and match the confirmation.');
                    db_exec('UPDATE users SET full_name = ?, student_id = ?, password_hash = ? WHERE id = ?', 'sssi', $name, $studentId !== '' ? $studentId : null, password_hash($newPassword, PASSWORD_DEFAULT), (int) $student['id']);
                } else {
                    db_exec('UPDATE users SET full_name = ?, student_id = ? WHERE id = ?', 'ssi', $name, $studentId !== '' ? $studentId : null, (int) $student['id']);
                }
                $_SESSION['user']['full_name'] = $name;
                log_activity((int) $student['id'], 'profile_updated', 'user', (int) $student['id'], 'Updated permitted profile information.');
                flash('success', 'Profile updated.');
                redirect('index.php?view=profile');

            case 'review-request':
                $staff = require_login(['admin', 'staff']);
                $id = (int) ($_POST['id'] ?? 0);
                $decision = (string) ($_POST['decision'] ?? '');
                if (!in_array($decision, ['approved', 'rejected'], true)) throw new DomainException('Invalid review decision.');
                $db->begin_transaction();
                try {
                    $request = db_one("SELECT id, user_id, status FROM borrowing_requests WHERE id = ? FOR UPDATE", 'i', $id);
                    if (!$request || $request['status'] !== 'pending') throw new DomainException('This request has already been reviewed.');
                    $reason = trim((string) ($_POST['reason'] ?? ''));
                    $changed = db_exec('UPDATE borrowing_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ? AND status = \'pending\'', 'sisi', $decision, (int) $staff['id'], $reason !== '' ? $reason : null, $id);
                    if (!$changed) throw new DomainException('This request has already been reviewed.');
                    log_activity((int) $staff['id'], 'request_' . $decision, 'borrowing_request', $id, 'Reviewed borrowing request as ' . $decision . '.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                $noticeSent = notify_user((int) $request['user_id'], 'Borrowing request ' . $decision, 'Your borrowing request has been ' . $decision . '.');
                flash($noticeSent ? 'success' : 'error', $noticeSent ? 'Request ' . $decision . ' and the student was notified.' : 'Request ' . $decision . ', but notification email delivery failed.');
                redirect('index.php?view=requests');

            case 'release':
                $staff = require_login(['admin', 'staff']);
                $id = (int) ($_POST['id'] ?? 0);
                $db->begin_transaction();
                try {
                    $request = db_one('SELECT id, user_id, book_id, status FROM borrowing_requests WHERE id = ? FOR UPDATE', 'i', $id);
                    if (!$request || $request['status'] !== 'approved') throw new DomainException('Only approved requests can be released.');
                    $book = db_one('SELECT total_copies FROM books WHERE id = ? FOR UPDATE', 'i', (int) $request['book_id']);
                    $active = db_one("SELECT COUNT(*) AS count FROM borrowing_transactions WHERE book_id = ? AND status IN ('borrowed','overdue')", 'i', (int) $request['book_id']);
                    if (!$book || (int) $active['count'] >= (int) $book['total_copies']) throw new DomainException('No copy is available for release.');
                    $days = max(1, min(60, (int) setting('loan_period_days', '7')));
                    $due = (new DateTimeImmutable('today'))->modify('+' . $days . ' days')->format('Y-m-d');
                    db_exec("INSERT INTO borrowing_transactions (request_id, user_id, book_id, released_by, borrowed_at, due_date, status) VALUES (?, ?, ?, ?, NOW(), ?, 'borrowed')", 'iiiis', $id, (int) $request['user_id'], (int) $request['book_id'], (int) $staff['id'], $due);
                    db_exec("UPDATE borrowing_requests SET status = 'released' WHERE id = ? AND status = 'approved'", 'i', $id);
                    log_activity((int) $staff['id'], 'book_released', 'borrowing_request', $id, 'Released book; due ' . $due . '.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                $noticeSent = notify_user((int) $request['user_id'], 'Book released', 'Your book is checked out and is due on ' . $due . '.');
                flash($noticeSent ? 'success' : 'error', $noticeSent ? 'Book released; due date is ' . $due . '.' : 'Book released, but notification email delivery failed.');
                redirect('index.php?view=requests');

            case 'return':
                $staff = require_login(['admin', 'staff']);
                $id = (int) ($_POST['id'] ?? 0);
                $changed = db_exec("UPDATE borrowing_transactions SET status = 'returned', returned_at = NOW(), received_by = ? WHERE id = ? AND status IN ('borrowed','overdue')", 'ii', (int) $staff['id'], $id);
                if (!$changed) throw new DomainException('This transaction is already returned or cannot be processed.');
                $loanOwner = db_one('SELECT user_id FROM borrowing_transactions WHERE id = ?', 'i', $id);
                log_activity((int) $staff['id'], 'book_returned', 'borrowing_transaction', $id, 'Recorded book return.');
                $noticeSent = notify_user((int) $loanOwner['user_id'], 'Book returned', 'The library has recorded your book return.');
                flash($noticeSent ? 'success' : 'error', $noticeSent ? 'Book return recorded.' : 'Book return recorded, but notification email delivery failed.');
                redirect('index.php?view=transactions');

            case 'mark-lost':
                $staff = require_login(['admin', 'staff']);
                $id = (int) ($_POST['id'] ?? 0);
                $note = trim((string) ($_POST['notes'] ?? ''));
                if (mb_strlen($note) > 1000) throw new DomainException('The note is too long.');
                $changed = db_exec("UPDATE borrowing_transactions SET status = 'lost', notes = ? WHERE id = ? AND status IN ('borrowed','overdue')", 'si', $note !== '' ? $note : null, $id);
                if (!$changed) throw new DomainException('This transaction is already closed or cannot be marked lost.');
                $loanOwner = db_one('SELECT user_id FROM borrowing_transactions WHERE id = ?', 'i', $id);
                log_activity((int) $staff['id'], 'book_marked_lost', 'borrowing_transaction', $id, 'Marked borrowed copy as lost.');
                $noticeSent = notify_user((int) $loanOwner['user_id'], 'Library loan marked lost', 'The library recorded this loan as lost. Contact staff if you believe this is incorrect.');
                flash($noticeSent ? 'success' : 'error', $noticeSent ? 'Loan marked lost.' : 'Loan marked lost, but notification email delivery failed.');
                redirect('index.php?view=transactions');

            case 'process-queue':
                $staff = require_login(['admin', 'staff']);
                $bookId = (int) ($_POST['book_id'] ?? 0);
                $db->begin_transaction();
                try {
                    $book = db_one('SELECT id, total_copies FROM books WHERE id = ? AND archived_at IS NULL FOR UPDATE', 'i', $bookId);
                    db_exec("UPDATE reservations SET status = 'expired' WHERE book_id = ? AND status = 'ready_for_pickup' AND expires_at <= NOW()", 'i', $bookId);
                    if (!$book || available_copies($bookId, (int) $book['total_copies']) < 1) throw new DomainException('There is no unallocated copy to offer.');
                    $next = reservation_queue_peek($bookId);
                    if (!$next) throw new DomainException('There is no eligible waiting reservation.');
                    $expiryDays = max(1, min(14, (int) setting('reservation_expiry_days', '2')));
                    if (!reservation_queue_dequeue((int) $next['id'], $expiryDays)) throw new DomainException('The queue changed before the offer could be recorded.');
                    log_activity((int) $staff['id'], 'reservation_ready', 'reservation', (int) $next['id'], 'Offered the next eligible FIFO reservation for pickup.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                $reservationOwner = db_one('SELECT user_id, expires_at FROM reservations WHERE id = ?', 'i', (int) $next['id']);
                $noticeSent = notify_user((int) $reservationOwner['user_id'], 'Reserved book ready for pickup', 'Your reserved book is ready. Please collect it before ' . $reservationOwner['expires_at'] . '.');
                flash($noticeSent ? 'success' : 'error', $noticeSent ? 'Next eligible student has been offered pickup and notified.' : 'Pickup offer was recorded, but notification email delivery failed.');
                redirect('index.php?view=reservations');

            case 'fulfill-reservation':
                $staff = require_login(['admin', 'staff']);
                $id = (int) ($_POST['id'] ?? 0);
                $db->begin_transaction();
                try {
                    $reservation = db_one("SELECT id, user_id, book_id FROM reservations WHERE id = ?", 'i', $id);
                    if (!$reservation) throw new DomainException('Reservation does not exist.');
                    db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', 'i', (int) $reservation['user_id']);
                    $book = db_one('SELECT total_copies FROM books WHERE id = ? FOR UPDATE', 'i', (int) $reservation['book_id']);
                    $reservation = db_one("SELECT id, user_id, book_id, status, expires_at, (expires_at > NOW()) AS pickup_active FROM reservations WHERE id = ? FOR UPDATE", 'i', $id);
                    if (!$reservation || $reservation['status'] !== 'ready_for_pickup') throw new DomainException('Reservation is not ready for pickup.');
                    if (!$reservation['expires_at'] || !(bool) $reservation['pickup_active']) {
                        db_exec("UPDATE reservations SET status = 'expired' WHERE id = ? AND status = 'ready_for_pickup'", 'i', $id);
                        $db->commit();
                        flash('error', 'Pickup window expired; reservation marked expired.');
                        redirect('index.php?view=reservations');
                    }
                    $limit = (int) setting('borrowing_limit', '3');
                    $eligibility = db_one("SELECT (SELECT COUNT(*) FROM borrowing_transactions WHERE user_id = ? AND status IN ('borrowed','overdue')) + (SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status IN ('pending','approved')) + (SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'ready_for_pickup' AND expires_at > NOW()) AS occupied", 'iii', (int) $reservation['user_id'], (int) $reservation['user_id'], (int) $reservation['user_id']);
                    if ((int) $eligibility['occupied'] > $limit) throw new DomainException('The student is over the current borrowing limit; resolve other active items first.');
                    $active = db_one("SELECT COUNT(*) AS count FROM borrowing_transactions WHERE book_id = ? AND status IN ('borrowed','overdue')", 'i', (int) $reservation['book_id']);
                    if (!$book || (int) $active['count'] >= (int) $book['total_copies']) throw new DomainException('No copy is available for pickup.');
                    $days = max(1, min(60, (int) setting('loan_period_days', '7')));
                    $due = (new DateTimeImmutable('today'))->modify('+' . $days . ' days')->format('Y-m-d');
                    db_exec("INSERT INTO borrowing_requests (user_id, book_id, status, reviewed_by, reviewed_at) VALUES (?, ?, 'released', ?, NOW())", 'iii', (int) $reservation['user_id'], (int) $reservation['book_id'], (int) $staff['id']);
                    $requestId = (int) $db->insert_id;
                    db_exec("INSERT INTO borrowing_transactions (request_id, user_id, book_id, released_by, borrowed_at, due_date, status) VALUES (?, ?, ?, ?, NOW(), ?, 'borrowed')", 'iiiis', $requestId, (int) $reservation['user_id'], (int) $reservation['book_id'], (int) $staff['id'], $due);
                    if (!db_exec("UPDATE reservations SET status = 'fulfilled', fulfilled_at = NOW() WHERE id = ? AND status = 'ready_for_pickup'", 'i', $id)) throw new DomainException('Reservation was already fulfilled.');
                    log_activity((int) $staff['id'], 'reservation_fulfilled', 'reservation', $id, 'Reservation fulfilled and book released.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                $noticeSent = notify_user((int) $reservation['user_id'], 'Reservation fulfilled', 'Your reserved book has been released and is due on ' . $due . '.');
                flash($noticeSent ? 'success' : 'error', $noticeSent ? 'Reservation fulfilled; book due ' . $due . '.' : 'Reservation fulfilled, but notification email delivery failed.');
                redirect('index.php?view=reservations');

            case 'save-book':
                $staff = require_login(['admin']);
                $id = (int) ($_POST['id'] ?? 0);
                $isNew = $id === 0;
                $title = trim((string) ($_POST['title'] ?? ''));
                $isbn = trim((string) ($_POST['isbn'] ?? ''));
                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $copies = filter_var($_POST['total_copies'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
                $author = trim((string) ($_POST['author'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $shelf = trim((string) ($_POST['shelf_location'] ?? ''));
                $publisher = trim((string) ($_POST['publisher'] ?? ''));
                if ($title === '' || !$categoryId || !$copies || !db_one('SELECT id FROM categories WHERE id = ? AND archived_at IS NULL', 'i', $categoryId)) throw new DomainException('Enter a title, valid category, and copy count from 1 to 1000.');
                if (strlen($isbn) > 20 || mb_strlen($title) > 220 || mb_strlen($author) > 160 || mb_strlen($publisher) > 160 || mb_strlen($shelf) > 80 || mb_strlen($description) > 10000) throw new DomainException('One or more book fields exceed their allowed length.');
                $year = (int) ($_POST['publication_year'] ?? 0) ?: null;
                if ($year !== null && ($year < 1000 || $year > 2100)) throw new DomainException('Publication year must be between 1000 and 2100.');
                $coverPath = store_book_cover(isset($_FILES['cover']) ? $_FILES['cover'] : null);
                $oldCover = null;
                if ($id) {
                    $existingBook = db_one('SELECT cover_image FROM books WHERE id = ? AND archived_at IS NULL', 'i', $id);
                    if (!$existingBook) throw new DomainException('Book not found.');
                    $oldCover = $existingBook['cover_image'];
                    $held = db_one("SELECT (SELECT COUNT(*) FROM borrowing_transactions WHERE book_id = ? AND status IN ('borrowed','overdue','lost')) + (SELECT COUNT(*) FROM borrowing_requests WHERE book_id = ? AND status IN ('pending','approved')) + (SELECT COUNT(*) FROM reservations WHERE book_id = ? AND status = 'ready_for_pickup' AND expires_at > NOW()) AS copies", 'iii', $id, $id, $id);
                    if ((int) $copies < (int) $held['copies']) throw new DomainException('Total copies cannot be lower than copies already allocated.');
                }
                $db->begin_transaction();
                try {
                    if ($id) {
                        db_exec('UPDATE books SET title=?,isbn=?,category_id=?,publisher=?,publication_year=?,description=?,shelf_location=?,total_copies=?,cover_image=COALESCE(?,cover_image) WHERE id=? AND archived_at IS NULL', 'ssisissisi', $title, $isbn !== '' ? $isbn : null, $categoryId, $publisher !== '' ? $publisher : null, $year, $description !== '' ? $description : null, $shelf !== '' ? $shelf : null, $copies, $coverPath, $id);
                        db_exec('DELETE FROM book_authors WHERE book_id = ?', 'i', $id);
                    } else {
                        db_exec('INSERT INTO books (title,isbn,category_id,publisher,publication_year,description,shelf_location,total_copies,cover_image) VALUES (?,?,?,?,?,?,?,?,?)', 'ssisissis', $title, $isbn !== '' ? $isbn : null, $categoryId, $publisher !== '' ? $publisher : null, $year, $description !== '' ? $description : null, $shelf !== '' ? $shelf : null, $copies, $coverPath);
                        $id = (int) $db->insert_id;
                    }
                    if ($author !== '') {
                        db_exec('INSERT INTO authors (author_name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', 's', $author);
                        $authorId = (int) $db->insert_id;
                        db_exec('INSERT IGNORE INTO book_authors (book_id, author_id) VALUES (?, ?)', 'ii', $id, $authorId);
                    }
                    log_activity((int) $staff['id'], $isNew ? 'book_created' : 'book_updated', 'book', $id, 'Book record saved.');
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    if ($coverPath) @unlink(dirname(__DIR__) . '/' . $coverPath);
                    throw $exception;
                }
                if ($coverPath && $oldCover && str_starts_with($oldCover, 'uploads/covers/') && basename($oldCover) === substr($oldCover, strlen('uploads/covers/'))) {
                    @unlink(dirname(__DIR__) . '/' . $oldCover);
                }
                flash('success', 'Book saved.');
                redirect('index.php?view=books');

            case 'archive-book':
                $admin = require_login(['admin']);
                $id = (int) ($_POST['id'] ?? 0);
                if (db_one("SELECT id FROM borrowing_transactions WHERE book_id = ? AND status IN ('borrowed','overdue')", 'i', $id)) throw new DomainException('Return active copies before archiving this book.');
                db_exec('UPDATE books SET archived_at = NOW() WHERE id = ? AND archived_at IS NULL', 'i', $id);
                log_activity((int) $admin['id'], 'book_archived', 'book', $id, 'Archived book record.');
                flash('success', 'Book archived.');
                redirect('index.php?view=books');

            case 'save-category':
                $admin = require_login(['admin']);
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['category_name'] ?? ''));
                if ($name === '') throw new DomainException('Category name is required.');
                if ($id) db_exec('UPDATE categories SET category_name = ?, description = ? WHERE id = ?', 'ssi', $name, trim((string) ($_POST['description'] ?? '')), $id);
                else db_exec('INSERT INTO categories (category_name, description) VALUES (?, ?)', 'ss', $name, trim((string) ($_POST['description'] ?? '')));
                log_activity((int) $admin['id'], 'category_saved', 'category', $id ?: (int) $db->insert_id, 'Saved category.');
                flash('success', 'Category saved.');
                redirect('index.php?view=categories');

            case 'save-user':
                $admin = require_login(['admin']);
                $id = (int) ($_POST['id'] ?? 0);
                $role = (string) ($_POST['role'] ?? '');
                $status = (string) ($_POST['status'] ?? '');
                if (!in_array($role, ['admin', 'staff', 'user'], true) || !in_array($status, ['active', 'disabled'], true)) throw new DomainException('Choose a valid role and account status.');
                $target = db_one('SELECT id, role, status FROM users WHERE id = ?', 'i', $id);
                if (!$target) throw new DomainException('Account not found.');
                if ($id === (int) $admin['id'] && ($role !== 'admin' || $status !== 'active')) throw new DomainException('You cannot remove your own administrator access.');
                if ($target['role'] === 'admin' && $target['status'] === 'active' && ($role !== 'admin' || $status !== 'active')) {
                    $admins = db_one("SELECT COUNT(*) AS count FROM users WHERE role = 'admin' AND status = 'active'");
                    if ((int) $admins['count'] <= 1) throw new DomainException('At least one active administrator must remain.');
                }
                db_exec('UPDATE users SET role = ?, status = ? WHERE id = ?', 'ssi', $role, $status, $id);
                log_activity((int) $admin['id'], 'account_updated', 'user', $id, 'Updated account role/status.');
                flash('success', 'Account updated.');
                redirect('index.php?view=users');

            case 'save-settings':
                $admin = require_login(['admin']);
                $settings = [
                    'loan_period_days' => [1, 60],
                    'borrowing_limit' => [1, 20],
                    'reservation_expiry_days' => [1, 14],
                ];
                foreach ($settings as $key => [$minimum, $maximum]) {
                    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
                    if ($value === false || $value < $minimum || $value > $maximum) throw new DomainException('Settings are outside the permitted range.');
                    db_exec('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', 'ss', $key, (string) $value);
                }
                log_activity((int) $admin['id'], 'settings_updated', 'settings', null, 'Updated borrowing settings.');
                flash('success', 'Settings updated.');
                redirect('index.php?view=settings');

            default:
                throw new DomainException('Unknown action.');
        }
    } catch (DomainException $exception) {
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Library action error: ' . $exception->getMessage());
        flash('error', 'The action could not be completed. Check the submitted information and try again.');
    }
    redirect('index.php?view=' . rawurlencode($view));
}

if ($view === 'verify' && isset($_GET['token'])) {
    $tokenHash = hash('sha256', (string) $_GET['token']);
    $db->begin_transaction();
    try {
        $token = db_one('SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE', 's', $tokenHash);
        if ($token) {
            $consumed = db_exec('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL', 'i', (int) $token['id']);
            if (!$consumed) throw new DomainException('This verification link has already been used.');
            db_exec('UPDATE users SET is_verified = 1 WHERE id = ?', 'i', (int) $token['user_id']);
            log_activity((int) $token['user_id'], 'email_verified', 'user', (int) $token['user_id'], 'Email address verified.');
            $db->commit();
            flash('success', 'Email verified. You can sign in now.');
        } else {
            $db->commit();
            flash('error', 'This verification link is invalid, expired, or already used.');
        }
    } catch (Throwable $exception) {
        $db->rollback();
        flash('error', 'This verification link is invalid, expired, or already used.');
    }
    redirect('index.php?view=login');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$allowedPublic = ['catalog', 'book-details', 'login', 'register', 'verify-notice', 'forgot-password', 'reset-password'];
if (!in_array($view, $allowedPublic, true) && $view !== 'verify') {
    if (!$user) redirect('index.php?view=login');
    if (!$user['is_verified'] && $user['role'] === 'user') redirect('index.php?view=verify-notice');
}
$resetToken = (string) ($_GET['token'] ?? '');
$resetTokenValid = false;
if ($view === 'reset-password' && $resetToken !== '') {
    $resetTokenValid = (bool) db_one('SELECT id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW()', 's', hash('sha256', $resetToken));
}

function page_header(string $title, ?array $user, ?array $flash): void
{
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> | School Library</title><link rel="stylesheet" href="assets/css/app.css"></head><body><header class="topbar"><a class="brand" href="index.php">School Library</a><nav><a href="index.php?view=catalog">Catalog</a><?php if ($user): ?><a href="index.php?view=dashboard">Dashboard</a><?php if ($user['role'] === 'user'): ?><a href="index.php?view=my-activity">My activity</a><a href="index.php?view=profile">Profile</a><?php else: ?><a href="index.php?view=requests">Requests</a><a href="index.php?view=reservations">Queue</a><a href="index.php?view=transactions">Loans</a><a href="index.php?view=reports">Reports</a><?php if ($user['role'] === 'admin'): ?><a href="index.php?view=books">Manage books</a><a href="index.php?view=categories">Categories</a><a href="index.php?view=users">Accounts</a><a href="index.php?view=logs">Logs</a><a href="index.php?view=settings">Settings</a><?php endif; ?><?php endif; ?><form class="inline" method="post" action="logout.php"><?= csrf_field() ?><button class="small secondary">Sign out</button></form><?php else: ?><a href="index.php?view=login">Sign in</a><a href="index.php?view=register">Register</a><?php endif; ?></nav></header><main class="wrap"><?php if ($flash): ?><div class="alert <?= $flash['type'] === 'error' ? 'error' : '' ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?><?php
}
function page_footer(): void { ?></main><footer class="footer">School Library Management System · PHP + MySQLi</footer></body></html><?php }

$title = ucfirst(str_replace('-', ' ', $view));
page_header($title, $user, $flash);

if ($view === 'login' || $view === 'register' || $view === 'verify-notice' || $view === 'forgot-password' || $view === 'reset-password') {
    require __DIR__ . '/pages/auth.php';
} elseif ($view === 'catalog') {
    require __DIR__ . '/pages/catalog.php';
} elseif ($view === 'book-details') {
    require __DIR__ . '/pages/book-details.php';
} elseif ($view === 'dashboard') {
    $account = require_login();
    if ($account['role'] === 'user') {
        $counts = db_one("SELECT (SELECT COUNT(*) FROM borrowing_transactions WHERE user_id = ? AND status IN ('borrowed','overdue')) AS loans, (SELECT COUNT(*) FROM borrowing_requests WHERE user_id = ? AND status='pending') AS pending, (SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status IN ('waiting','ready_for_pickup')) AS reservations, (SELECT COUNT(*) FROM borrowing_transactions WHERE user_id = ? AND status IN ('borrowed','overdue') AND due_date < CURDATE()) AS overdue", 'iiii', (int) $account['id'], (int) $account['id'], (int) $account['id'], (int) $account['id']);
        ?><div class="hero"><div><span class="eyebrow">Student dashboard</span><h1>Welcome, <?= e($account['full_name']) ?></h1><div class="muted">Your library activity at a glance.</div></div></div><div class="grid"><?php foreach (['Active loans'=>'loans','Pending requests'=>'pending','Active reservations'=>'reservations','Overdue'=>'overdue'] as $label=>$key): ?><div class="stat"><strong><?= (int) $counts[$key] ?></strong><span><?= e($label) ?></span></div><?php endforeach; ?></div><div class="panel"><h2>Upcoming returns</h2><?php $upcoming = db_all("SELECT t.id,t.due_date,b.title FROM borrowing_transactions t JOIN books b ON b.id=t.book_id WHERE t.user_id=? AND t.status IN ('borrowed','overdue') ORDER BY t.due_date LIMIT 8", 'i', (int) $account['id']); ?><?php if (!$upcoming): ?><p class="muted">No active loans.</p><?php else: ?><table><thead><tr><th>Book</th><th>Due date</th><th>Status</th></tr></thead><tbody><?php foreach ($upcoming as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= e($row['due_date']) ?></td><td><span class="status <?= $row['due_date'] < date('Y-m-d') ? 'overdue' : '' ?>"><?= $row['due_date'] < date('Y-m-d') ? 'Overdue' : 'Borrowed' ?></span></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div><?php
        $recentBooks = db_all("SELECT b.id,b.title,c.category_name FROM books b JOIN categories c ON c.id=b.category_id WHERE b.archived_at IS NULL ORDER BY b.created_at DESC LIMIT 6");
        ?><section class="panel"><h2>Recently added</h2><div class="books"><?php foreach ($recentBooks as $recentBook): ?><article class="book"><div><span class="tag"><?= e($recentBook['category_name']) ?></span><h3><?= e($recentBook['title']) ?></h3></div><a href="index.php?view=book-details&amp;id=<?= (int) $recentBook['id'] ?>">View details</a></article><?php endforeach; ?></div><?php if (!$recentBooks): ?><p class="muted">No new titles yet.</p><?php endif; ?></section><?php
    } else {
        $stats = db_one("SELECT
            (SELECT COUNT(*) FROM users WHERE role='user') AS users,
            (SELECT COUNT(*) FROM users WHERE role='user' AND is_verified=1) AS verified,
            (SELECT COUNT(*) FROM books WHERE archived_at IS NULL) AS books,
            (SELECT COALESCE(SUM(total_copies),0) FROM books WHERE archived_at IS NULL) AS copies,
            (SELECT COALESCE(SUM(GREATEST(CAST(b.total_copies AS SIGNED) - (SELECT COUNT(*) FROM borrowing_transactions t WHERE t.book_id=b.id AND t.status IN ('borrowed','overdue','lost')) - (SELECT COUNT(*) FROM borrowing_requests r WHERE r.book_id=b.id AND r.status IN ('pending','approved')) - (SELECT COUNT(*) FROM reservations q WHERE q.book_id=b.id AND q.status='ready_for_pickup' AND q.expires_at>NOW()),0)),0) FROM books b WHERE b.archived_at IS NULL) AS available,
            (SELECT COUNT(*) FROM borrowing_transactions WHERE status IN ('borrowed','overdue')) AS borrowed,
            (SELECT COUNT(*) FROM borrowing_requests WHERE status='pending') AS pending,
            (SELECT COUNT(*) FROM borrowing_requests WHERE status='approved') AS approved,
            (SELECT COUNT(*) FROM borrowing_transactions WHERE status IN ('borrowed','overdue') AND due_date=CURDATE()) AS due_today,
            (SELECT COUNT(*) FROM borrowing_transactions WHERE status IN ('borrowed','overdue') AND due_date<CURDATE()) AS overdue,
            (SELECT COUNT(*) FROM reservations WHERE status='waiting') AS waiting,
            (SELECT COUNT(*) FROM reservations WHERE status='ready_for_pickup' AND expires_at>NOW()) AS ready,
            (SELECT COUNT(*) FROM borrowing_transactions WHERE status='returned' AND returned_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) AS returned");
        $cards = $account['role'] === 'admin'
            ? ['Registered users'=>'users','Verified users'=>'verified','Books'=>'books','Total copies'=>'copies','Available copies'=>'available','Borrowed copies'=>'borrowed','Pending requests'=>'pending','Overdue loans'=>'overdue']
            : ['Pending requests'=>'pending','Approved awaiting release'=>'approved','Currently borrowed'=>'borrowed','Due today'=>'due_today','Overdue'=>'overdue','Waiting reservations'=>'waiting','Ready for pickup'=>'ready','Recently returned'=>'returned'];
        ?><div class="hero"><div><span class="eyebrow"><?= e($account['role']) ?> workspace</span><h1>Library operations</h1><div class="muted">Current service and collection summary.</div></div></div><div class="grid"><?php foreach ($cards as $label=>$key): ?><div class="stat"><strong><?= (int) $stats[$key] ?></strong><span><?= e($label) ?></span></div><?php endforeach; ?></div><div class="panel"><h2>Latest activity</h2><?php $recent = db_all('SELECT l.created_at,l.action,l.description,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 8'); ?><div class="table-wrap"><table><thead><tr><th>When</th><th>Actor</th><th>Activity</th><th>Details</th></tr></thead><tbody><?php foreach ($recent as $row): ?><tr><td><?= e($row['created_at']) ?></td><td><?= e($row['full_name'] ?: 'System') ?></td><td><?= e($row['action']) ?></td><td><?= e($row['description']) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php
        if ($account['role'] === 'admin') {
            require __DIR__ . '/pages/dashboard-admin-metrics.php';
        } else {
            require __DIR__ . '/pages/dashboard-staff-operations.php';
        }
    }
} elseif ($view === 'profile') {
    require __DIR__ . '/pages/profile.php';
} elseif ($view === 'my-activity') {
    $student = require_login(['user']);
    $requests = db_all('SELECT r.id,r.status,r.requested_at,r.rejection_reason,b.title,t.due_date FROM borrowing_requests r JOIN books b ON b.id=r.book_id LEFT JOIN borrowing_transactions t ON t.request_id=r.id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 100', 'i', (int) $student['id']);
    $reservations = db_all('SELECT r.id,r.status,r.queue_position,r.reserved_at,r.expires_at,b.title FROM reservations r JOIN books b ON b.id=r.book_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 100', 'i', (int) $student['id']);
    ?><div class="hero"><div><span class="eyebrow">Personal records</span><h1>My library activity</h1></div></div><div class="panel"><h2>Borrowing requests and loans</h2><div class="table-wrap"><table><thead><tr><th>Book</th><th>Requested</th><th>Status</th><th>Due</th><th></th></tr></thead><tbody><?php foreach ($requests as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= e($row['requested_at']) ?></td><td><span class="status <?= e($row['status']) ?>"><?= e(str_replace('_',' ',$row['status'])) ?></span><?= $row['rejection_reason'] ? '<div class="muted">' . e($row['rejection_reason']) . '</div>' : '' ?></td><td><?= e($row['due_date'] ?? '') ?></td><td><?php if ($row['status'] === 'pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel-request"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="small secondary">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div><div class="panel"><h2>Reservations</h2><div class="table-wrap"><table><thead><tr><th>Book</th><th>Queue position</th><th>Status</th><th>Pickup expires</th><th></th></tr></thead><tbody><?php foreach ($reservations as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= $row['status'] === 'waiting' ? (int) $row['queue_position'] : '—' ?></td><td><span class="status <?= e($row['status']) ?>"><?= e(str_replace('_',' ',$row['status'])) ?></span></td><td><?= e($row['expires_at'] ?? '') ?></td><td><?php if ($row['status'] === 'waiting'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel-reservation"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="small secondary">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php
} elseif ($view === 'requests') {
    require __DIR__ . '/pages/requests.php';
} elseif ($view === 'reservations') {
    require __DIR__ . '/pages/reservations.php';
} elseif ($view === 'transactions') {
    require __DIR__ . '/pages/transactions.php';
} elseif ($view === 'book-edit') {
    require __DIR__ . '/pages/book-edit.php';
} elseif ($view === 'books') {
    require __DIR__ . '/pages/books.php';
} elseif ($view === 'categories') {
    require_login(['admin']);
    $rows = db_all('SELECT id,category_name,description FROM categories WHERE archived_at IS NULL ORDER BY category_name');
    ?><div class="hero"><div><span class="eyebrow">Collection structure</span><h1>Categories</h1></div></div><div class="panel"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save-category"><div><label>Category name</label><input name="category_name" required></div><div><label>Description</label><input name="description"></div><div class="wide"><button>Add category</button></div></form></div><div class="panel table-wrap"><table><thead><tr><th>Category</th><th>Description</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td colspan="2"><form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="action" value="save-category"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input name="category_name" value="<?= e($row['category_name']) ?>" required><input name="description" value="<?= e($row['description']) ?>"><button class="small">Save</button></form></td><td><form method="post" onsubmit="return confirm('Archive this category?')"><?= csrf_field() ?><input type="hidden" name="action" value="archive-category"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="small danger">Archive</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php
} elseif ($view === 'users') {
    require __DIR__ . '/pages/users.php';
} elseif ($view === 'reports') {
    require_login(['admin','staff']);
    $report = (string) ($_GET['report'] ?? 'inventory');
    $availableReports = ['inventory','active','overdue','history','reservations','popular'];
    if (!in_array($report, $availableReports, true)) $report = 'inventory';
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
    foreach (['from' => &$from, 'to' => &$to] as &$dateValue) {
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        if ($dateValue !== '' && (!preg_match($datePattern, $dateValue) || !$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue)) $dateValue = '';
    }
    unset($dateValue);
    $dateColumn = in_array($report, ['reservations'], true) ? 'r.reserved_at' : 't.borrowed_at';
    $whereDate = '';
    $dateTypes = '';
    $dateValues = [];
    if ($from !== '') { $whereDate .= " AND $dateColumn >= ?"; $dateTypes .= 's'; $dateValues[] = $from . ' 00:00:00'; }
    if ($to !== '') { $whereDate .= " AND $dateColumn < DATE_ADD(?, INTERVAL 1 DAY)"; $dateTypes .= 's'; $dateValues[] = $to; }
    if ($report === 'inventory') {
        $reportRows = db_all('SELECT b.id,b.title,b.total_copies,c.category_name FROM books b JOIN categories c ON c.id=b.category_id WHERE b.archived_at IS NULL ORDER BY b.title LIMIT 500');
        $reportTitle = 'Book inventory';
    } elseif ($report === 'active' || $report === 'overdue' || $report === 'history') {
        $statusCondition = $report === 'active' ? " AND t.status IN ('borrowed','overdue')" : ($report === 'overdue' ? " AND t.status IN ('borrowed','overdue') AND t.due_date < CURDATE()" : '');
        $reportRows = db_all("SELECT t.id,t.borrowed_at,t.due_date,t.returned_at,t.status,u.full_name,b.title FROM borrowing_transactions t JOIN users u ON u.id=t.user_id JOIN books b ON b.id=t.book_id WHERE 1=1$statusCondition$whereDate ORDER BY t.id DESC LIMIT 500", $dateTypes, ...$dateValues);
        $reportTitle = ucfirst($report) . ' borrowing';
    } elseif ($report === 'reservations') {
        $reportRows = db_all("SELECT r.id,r.reserved_at,r.queue_position,r.status,u.full_name,b.title FROM reservations r JOIN users u ON u.id=r.user_id JOIN books b ON b.id=r.book_id WHERE 1=1$whereDate ORDER BY r.id DESC LIMIT 500", $dateTypes, ...$dateValues);
        $reportTitle = 'Reservation history';
    } else {
        $reportRows = db_all("SELECT b.title,COUNT(t.id) AS borrow_count FROM borrowing_transactions t JOIN books b ON b.id=t.book_id WHERE 1=1$whereDate GROUP BY b.id ORDER BY borrow_count DESC LIMIT 100", $dateTypes, ...$dateValues);
        $reportTitle = 'Most borrowed books';
    }
    ?><div class="hero"><div><span class="eyebrow">Library reports</span><h1><?= e($reportTitle) ?></h1></div><button class="no-print" onclick="window.print()">Print report</button></div><div class="panel no-print"><form method="get" class="searchbar"><input type="hidden" name="view" value="reports"><select name="report"><?php foreach ($availableReports as $option): ?><option value="<?= e($option) ?>" <?= $report===$option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button>Apply filters</button></form></div><div class="panel table-wrap"><table><thead><tr><?php if ($report==='inventory'): ?><th>Title</th><th>Category</th><th>Total</th><th>Available</th><?php elseif ($report==='popular'): ?><th>Book</th><th>Borrow count</th><?php elseif ($report==='reservations'): ?><th>Student</th><th>Book</th><th>Reserved</th><th>Queue position</th><th>Status</th><?php else: ?><th>Student</th><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th><?php endif; ?></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><?php if ($report==='inventory'): ?><td><?= e($row['title']) ?></td><td><?= e($row['category_name']) ?></td><td><?= (int) $row['total_copies'] ?></td><td><?= available_copies((int) $row['id'],(int) $row['total_copies']) ?></td><?php elseif ($report==='popular'): ?><td><?= e($row['title']) ?></td><td><?= (int) $row['borrow_count'] ?></td><?php elseif ($report==='reservations'): ?><td><?= e($row['full_name']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['reserved_at']) ?></td><td><?= $row['status']==='waiting' ? (int) $row['queue_position'] : '—' ?></td><td><?= e(str_replace('_',' ',$row['status'])) ?></td><?php else: ?><td><?= e($row['full_name']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['borrowed_at']) ?></td><td><?= e($row['due_date']) ?></td><td><?= e($row['returned_at'] ?? '') ?></td><td><?= e($row['status']) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table><?php if (!$reportRows): ?><div class="empty">No records match this report.</div><?php endif; ?></div><?php
} elseif ($view === 'logs') {
    require __DIR__ . '/pages/activity-logs.php';
} elseif ($view === 'settings') {
    require_login(['admin']);
    ?><div class="hero"><div><span class="eyebrow">System configuration</span><h1>Library settings</h1></div></div><div class="panel"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save-settings"><label>Loan period (days)</label><input type="number" min="1" max="60" name="loan_period_days" value="<?= e(setting('loan_period_days','7')) ?>"><label>Borrowing limit per student</label><input type="number" min="1" max="20" name="borrowing_limit" value="<?= e(setting('borrowing_limit','3')) ?>"><label>Reservation pickup expiry (days)</label><input type="number" min="1" max="14" name="reservation_expiry_days" value="<?= e(setting('reservation_expiry_days','2')) ?>"><p><button>Save settings</button></p></form></div><?php
} else {
    ?><section class="panel"><h1>Page not found</h1><a href="index.php">Return to catalog</a></section><?php
}
page_footer();
