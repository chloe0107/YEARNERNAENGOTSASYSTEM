<?php
require_once __DIR__ . '/functions.php';
$user = current_user();
if (!$user) return;
?>
<nav class="panel" aria-label="Main navigation">
<a href="<?= e(url('dashboard.php')) ?>">Dashboard</a> ·
<a href="<?= e(url('books.php')) ?>">Books</a> ·
<a href="<?= e(url('transactions.php')) ?>">Borrowing & Reservations</a> ·
<?php if (in_array($user['role'], ['admin','staff'], true)): ?>
<a href="<?= e(url('categories.php')) ?>">Categories</a> · <a href="<?= e(url('reports.php')) ?>">Reports</a>
<?php endif; ?>
<?php if ($user['role'] === 'admin'): ?> · <a href="<?= e(url('users.php')) ?>">Users</a> · <a href="<?= e(url('activity_logs.php')) ?>">Activity logs</a><?php endif; ?>
</nav>
