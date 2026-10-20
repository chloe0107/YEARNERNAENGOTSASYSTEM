<?php
require_login(['admin']);
$search = trim((string) ($_GET['q'] ?? ''));
$action = trim((string) ($_GET['action_filter'] ?? ''));
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
    $where .= ' AND (u.full_name LIKE ? OR l.entity_type LIKE ? OR l.description LIKE ? OR CAST(l.entity_id AS CHAR) LIKE ?)';
    $wild = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $wild, $wild, $wild, $wild);
}
if ($action !== '') {
    $where .= ' AND l.action = ?';
    $types .= 's';
    $params[] = $action;
}
if ($from !== '') {
    $where .= ' AND l.created_at >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $types .= 's';
    $params[] = $to;
}
$joins = ' FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id';
$count = db_one('SELECT COUNT(*) AS total' . $joins . $where, $types, ...$params);
$total = (int) ($count['total'] ?? 0);
$perPage = 50;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = db_all('SELECT l.id,l.created_at,l.action,l.entity_type,l.entity_id,l.description,l.ip_address,u.full_name' . $joins . $where . ' ORDER BY l.id DESC LIMIT ? OFFSET ?', $types . 'ii', ...array_merge($params, [$perPage, ($page - 1) * $perPage]));
$actions = db_all('SELECT DISTINCT action FROM activity_logs ORDER BY action');
$filterQuery = ['view' => 'logs', 'q' => $search, 'action_filter' => $action, 'from' => $from, 'to' => $to];
?>
<div class="hero"><div><span class="eyebrow">Accountability</span><h1>Activity logs</h1></div><button class="no-print" onclick="window.print()">Print</button></div>
<form method="get" class="searchbar no-print">
    <input type="hidden" name="view" value="logs">
    <input name="q" value="<?= e($search) ?>" placeholder="Actor, entity, record ID, or description">
    <select name="action_filter"><option value="">All actions</option><?php foreach ($actions as $item): ?><option value="<?= e($item['action']) ?>" <?= $action === $item['action'] ? 'selected' : '' ?>><?= e($item['action']) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="Through date">
    <button>Filter</button>
</form>
<div class="panel table-wrap">
    <table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Description</th><th>IP</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><td><?= e($row['created_at']) ?></td><td><?= e($row['full_name'] ?: 'System') ?></td><td><?= e($row['action']) ?></td><td><?= e($row['entity_type']) ?> #<?= e($row['entity_id']) ?></td><td><?= e($row['description']) ?></td><td><?= e($row['ip_address']) ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">No activity matches these filters.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<nav class="actions no-print" aria-label="Activity log pages">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> entries</span>
    <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
