<?php
require_login(['admin', 'staff']);
$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'released', 'cancelled'];
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
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR b.title LIKE ? OR CAST(r.id AS CHAR) LIKE ?)';
    $wild = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $wild, $wild, $wild, $wild);
}
if ($status !== 'all') {
    $where .= ' AND r.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($from !== '') {
    $where .= ' AND r.requested_at >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND r.requested_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $types .= 's';
    $params[] = $to;
}
$joins = ' FROM borrowing_requests r JOIN users u ON u.id=r.user_id JOIN books b ON b.id=r.book_id';
$count = db_one('SELECT COUNT(*) AS total' . $joins . $where, $types, ...$params);
$total = (int) ($count['total'] ?? 0);
$perPage = 50;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = db_all('SELECT r.id,r.status,r.requested_at,r.rejection_reason,u.full_name,u.email,b.title' . $joins . $where . " ORDER BY FIELD(r.status,'pending','approved','released','rejected','cancelled'),r.id DESC LIMIT ? OFFSET ?", $types . 'ii', ...array_merge($params, [$perPage, ($page - 1) * $perPage]));
$filterQuery = ['view' => 'requests', 'q' => $search, 'status' => $status, 'from' => $from, 'to' => $to];
?>
<div class="hero">
    <div><span class="eyebrow">Staff operations</span><h1>Borrowing requests</h1></div>
    <button class="no-print" onclick="window.print()">Print</button>
</div>
<form method="get" class="searchbar no-print">
    <input type="hidden" name="view" value="requests">
    <input name="q" value="<?= e($search) ?>" placeholder="Borrower, book, or request ID">
    <select name="status"><?php foreach ($allowedStatuses as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="Requested from">
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="Requested through">
    <button>Filter</button>
</form>
<div class="panel table-wrap">
    <table><thead><tr><th>Student</th><th>Book</th><th>Requested</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= e($row['full_name']) ?><br><span class="muted"><?= e($row['email']) ?></span></td>
                <td><?= e($row['title']) ?></td><td><?= e($row['requested_at']) ?></td>
                <td><span class="status <?= e($row['status']) ?>"><?= e($row['status']) ?></span><?php if ($row['rejection_reason']): ?><div class="muted"><?= e($row['rejection_reason']) ?></div><?php endif; ?></td>
                <td class="actions">
                    <?php if ($row['status'] === 'pending'): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="review-request"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="decision" value="approved"><button class="small">Approve</button></form>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="review-request"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="decision" value="rejected"><input name="reason" maxlength="500" placeholder="Rejection reason"><button class="small danger">Reject</button></form>
                    <?php elseif ($row['status'] === 'approved'): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="release"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="small">Release book</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="empty">No requests match these filters.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<nav class="actions no-print" aria-label="Request pages">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> requests</span>
    <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
