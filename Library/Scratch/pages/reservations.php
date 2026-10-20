<?php
require_login(['admin', 'staff']);
$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'active');
$allowedStatuses = ['active', 'waiting', 'ready_for_pickup', 'fulfilled', 'expired', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'active';
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
if ($status === 'active') {
    $where .= " AND r.status IN ('waiting','ready_for_pickup')";
} elseif ($status !== 'active') {
    $where .= ' AND r.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($search !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR b.title LIKE ? OR CAST(r.id AS CHAR) LIKE ?)';
    $wild = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $wild, $wild, $wild, $wild);
}
if ($from !== '') {
    $where .= ' AND r.reserved_at >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND r.reserved_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $types .= 's';
    $params[] = $to;
}
$joins = ' FROM reservations r JOIN users u ON u.id=r.user_id JOIN books b ON b.id=r.book_id';
$count = db_one('SELECT COUNT(*) AS total' . $joins . $where, $types, ...$params);
$total = (int) ($count['total'] ?? 0);
$perPage = 50;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = db_all("SELECT r.id,r.book_id,r.status,r.queue_position,r.reserved_at,r.expires_at,u.full_name,b.title" . $joins . $where . " ORDER BY b.title,FIELD(r.status,'waiting','ready_for_pickup','fulfilled','expired','cancelled'),r.queue_position,r.id LIMIT ? OFFSET ?", $types . 'ii', ...array_merge($params, [$perPage, ($page - 1) * $perPage]));
$filterQuery = ['view' => 'reservations', 'q' => $search, 'status' => $status, 'from' => $from, 'to' => $to];
?>
<div class="hero"><div><span class="eyebrow">FIFO reservation queue</span><h1>Reservations</h1></div><button class="no-print" onclick="window.print()">Print</button></div>
<form method="get" class="searchbar no-print">
    <input type="hidden" name="view" value="reservations">
    <input name="q" value="<?= e($search) ?>" placeholder="Student, book, or reservation ID">
    <select name="status"><?php foreach ($allowedStatuses as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $option))) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="Reserved from">
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="Reserved through">
    <button>Filter</button>
</form>
<div class="panel table-wrap">
    <table><thead><tr><th>Queue order</th><th>Student</th><th>Book</th><th>Reserved</th><th>Status</th><th>Pickup expires</th><th class="no-print">Action</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr>
            <td><?= $row['status'] === 'waiting' ? (int) $row['queue_position'] : '—' ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['title']) ?></td><td><?= e($row['reserved_at']) ?></td>
            <td><span class="status <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><?= e($row['expires_at'] ?? '') ?></td>
            <td class="no-print actions">
                <?php if ($row['status'] === 'waiting'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="process-queue"><input type="hidden" name="book_id" value="<?= (int) $row['book_id'] ?>"><button class="small">Offer next eligible</button></form>
                <?php elseif ($row['status'] === 'ready_for_pickup' && $row['expires_at'] > date('Y-m-d H:i:s')): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fulfill-reservation"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="small">Record pickup</button></form>
                <?php endif; ?>
            </td>
        </tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">No reservations match these filters.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<nav class="actions no-print" aria-label="Reservation pages">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> reservations</span>
    <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
