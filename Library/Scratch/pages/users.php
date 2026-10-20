<?php
require_login(['admin']);
$search = trim((string) ($_GET['q'] ?? ''));
$role = (string) ($_GET['role'] ?? 'all');
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($role, ['all', 'admin', 'staff', 'user'], true)) $role = 'all';
if (!in_array($status, ['all', 'active', 'disabled'], true)) $status = 'all';
$where = ' WHERE 1=1';
$types = '';
$params = [];
if ($search !== '') {
    $where .= ' AND (full_name LIKE ? OR email LIKE ? OR student_id LIKE ?)';
    $wild = '%' . $search . '%';
    $types .= 'sss';
    array_push($params, $wild, $wild, $wild);
}
if ($role !== 'all') {
    $where .= ' AND role = ?';
    $types .= 's';
    $params[] = $role;
}
if ($status !== 'all') {
    $where .= ' AND status = ?';
    $types .= 's';
    $params[] = $status;
}
$count = db_one('SELECT COUNT(*) AS total FROM users' . $where, $types, ...$params);
$total = (int) ($count['total'] ?? 0);
$perPage = 50;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$accounts = db_all('SELECT id,full_name,email,student_id,role,status,is_verified,created_at FROM users' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?', $types . 'ii', ...array_merge($params, [$perPage, ($page - 1) * $perPage]));
$filterQuery = ['view' => 'users', 'q' => $search, 'role' => $role, 'status' => $status];
?>
<div class="hero"><div><span class="eyebrow">Access control</span><h1>Accounts</h1></div></div>
<form method="get" class="searchbar no-print">
    <input type="hidden" name="view" value="users">
    <input name="q" value="<?= e($search) ?>" placeholder="Name, email, or student ID">
    <select name="role"><?php foreach (['all','admin','staff','user'] as $option): ?><option value="<?= e($option) ?>" <?= $role === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select>
    <select name="status"><?php foreach (['all','active','disabled'] as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select>
    <button>Filter</button>
</form>
<div class="panel table-wrap">
    <table><thead><tr><th>Name and email</th><th>Student ID</th><th>Verified</th><th>Created</th><th>Role and status</th></tr></thead><tbody>
        <?php foreach ($accounts as $account): ?><tr>
            <td><?= e($account['full_name']) ?><br><span class="muted"><?= e($account['email']) ?></span></td><td><?= e($account['student_id'] ?: '') ?></td><td><?= $account['is_verified'] ? 'Yes' : 'No' ?></td><td><?= e($account['created_at']) ?></td>
            <td><form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="action" value="save-user"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><select name="role"><?php foreach (['user','staff','admin'] as $option): ?><option value="<?= e($option) ?>" <?= $account['role'] === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select><select name="status"><option value="active" <?= $account['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $account['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option></select><button class="small">Save</button></form></td>
        </tr><?php endforeach; ?>
        <?php if (!$accounts): ?><tr><td colspan="5" class="empty">No accounts match these filters.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<nav class="actions no-print" aria-label="Account pages">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> accounts</span>
    <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
