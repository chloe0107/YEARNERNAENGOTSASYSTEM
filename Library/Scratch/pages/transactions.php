<?php
require_login(['admin', 'staff']);
$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'borrowed', 'overdue', 'returned', 'lost'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
foreach (['from' => &$from, 'to' => &$to] as &$dateValue) {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
    if ($dateValue !== '' && (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue)) {
        $dateValue = '';
    }
}
unset($dateValue);
$where = ' WHERE 1=1';
$types = '';
$params = [];
if ($search !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR b.title LIKE ? OR CAST(t.id AS CHAR) LIKE ?)';
    $wild = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $wild, $wild, $wild, $wild);
}
if ($status !== 'all') {
    $where .= ' AND t.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($from !== '') {
    $where .= ' AND t.borrowed_at >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND t.borrowed_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $types .= 's';
    $params[] = $to;
}
$joins = ' FROM borrowing_transactions t JOIN users u ON u.id=t.user_id JOIN books b ON b.id=t.book_id';
$count = db_one('SELECT COUNT(*) AS total' . $joins . $where, $types, ...$params);
$total = (int) ($count['total'] ?? 0);
$perPage = 50;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;
$rows = db_all('SELECT t.id,t.status,t.borrowed_at,t.due_date,t.returned_at,t.notes,u.full_name,b.title' . $joins . $where . ' ORDER BY t.id DESC LIMIT ? OFFSET ?', $types . 'ii', ...array_merge($params, [$perPage, $offset]));
$filterQuery = ['view' => 'transactions', 'q' => $search, 'status' => $status, 'from' => $from, 'to' => $to];
?>
<div class="hero">
    <div><span class="eyebrow">Circulation desk</span><h1>Borrowing transactions</h1></div>
    <button class="no-print" onclick="window.print()">Print report</button>
</div>
<form method="get" class="searchbar no-print">
    <input type="hidden" name="view" value="transactions">
    <input name="q" value="<?= e($search) ?>" placeholder="Borrower, book, or transaction ID">
    <select name="status">
        <?php foreach ($allowedStatuses as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="Borrowed from">
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="Borrowed through">
    <button>Filter</button>
</form>
<div class="panel table-wrap">
    <table>
        <thead><tr><th>Student</th><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status / notes</th><th class="no-print">Action</th></tr></thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['full_name']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['borrowed_at']) ?></td><td><?= e($row['due_date']) ?></td><td><?= e($row['returned_at'] ?? '') ?></td>
                    <td><span class="status <?= e($row['status']) ?>"><?= e($row['status']) ?></span><?php if ($row['notes']): ?><div class="muted"><?= e($row['notes']) ?></div><?php endif; ?></td>
                    <td class="no-print">
                        <?php if (in_array($row['status'], ['borrowed', 'overdue'], true)): ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="small">Record return</button></form>
                            <form method="post" onsubmit="return confirm('Mark this copy as lost?')"><?= csrf_field() ?><input type="hidden" name="action" value="mark-lost"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input name="notes" maxlength="1000" placeholder="Optional note"><button class="small danger">Mark lost</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="empty">No transactions match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<nav class="actions no-print" aria-label="Transaction pages">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> transactions</span>
    <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
