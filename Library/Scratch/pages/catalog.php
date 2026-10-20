<?php
$search = trim((string) ($_GET['q'] ?? ''));
$category = (int) ($_GET['category'] ?? 0);
$availability = (string) ($_GET['availability'] ?? 'all');
if (!in_array($availability, ['all', 'available', 'unavailable'], true)) {
    $availability = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 24;
$categories = db_all('SELECT id, category_name FROM categories WHERE archived_at IS NULL ORDER BY category_name');
$sql = "SELECT b.id, b.title, b.isbn, b.total_copies, b.shelf_location, b.cover_image, c.category_name,
        GROUP_CONCAT(DISTINCT a.author_name ORDER BY a.author_name SEPARATOR ', ') AS authors,
        GREATEST(CAST(b.total_copies AS SIGNED)
            - (SELECT COUNT(*) FROM borrowing_transactions t WHERE t.book_id=b.id AND t.status IN ('borrowed','overdue','lost'))
            - (SELECT COUNT(*) FROM borrowing_requests r WHERE r.book_id=b.id AND r.status IN ('pending','approved'))
            - (SELECT COUNT(*) FROM reservations q WHERE q.book_id=b.id AND q.status='ready_for_pickup' AND q.expires_at>NOW()), 0) AS available
        FROM books b JOIN categories c ON c.id=b.category_id
        LEFT JOIN book_authors ba ON ba.book_id=b.id LEFT JOIN authors a ON a.id=ba.author_id
        WHERE b.archived_at IS NULL";
$types = '';
$params = [];
if ($search !== '') {
    $sql .= ' AND (b.title LIKE ? OR b.isbn LIKE ? OR b.description LIKE ? OR a.author_name LIKE ?)';
    $wild = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $wild, $wild, $wild, $wild);
}
if ($category > 0) {
    $sql .= ' AND b.category_id = ?';
    $types .= 'i';
    $params[] = $category;
}
$sql .= ' GROUP BY b.id';
if ($availability === 'available') {
    $sql .= ' HAVING available > 0';
} elseif ($availability === 'unavailable') {
    $sql .= ' HAVING available = 0';
}
$sql .= ' ORDER BY b.title LIMIT ? OFFSET ?';
$types .= 'ii';
$params[] = $perPage + 1;
$params[] = ($page - 1) * $perPage;
$books = db_all($sql, $types, ...$params);
$hasNext = count($books) > $perPage;
if ($hasNext) {
    array_pop($books);
}
$baseQuery = ['view' => 'catalog', 'q' => $search, 'category' => $category, 'availability' => $availability];
?>
<div class="hero">
    <div>
        <span class="eyebrow">Explore the collection</span>
        <h1>Find your next read</h1>
        <div class="muted">Search by title, author, ISBN, or description. Filter by category and availability.</div>
    </div>
</div>
<form class="searchbar" method="get">
    <input type="hidden" name="view" value="catalog">
    <input name="q" value="<?= e($search) ?>" placeholder="Title, author, ISBN, keyword">
    <select name="category">
        <option value="0">All categories</option>
        <?php foreach ($categories as $item): ?>
            <option value="<?= (int) $item['id'] ?>" <?= $category === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['category_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="availability">
        <option value="all" <?= $availability === 'all' ? 'selected' : '' ?>>Any availability</option>
        <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Available now</option>
        <option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
    </select>
    <button>Search</button>
</form>
<section class="books">
    <?php foreach ($books as $book): ?>
        <article class="book">
            <?php if ($book['cover_image']): ?><img class="book-cover" src="<?= e($book['cover_image']) ?>" alt="Cover of <?= e($book['title']) ?>" loading="lazy"><?php endif; ?>
            <div>
                <span class="tag"><?= e($book['category_name']) ?></span>
                <h3><a href="index.php?view=book-details&amp;id=<?= (int) $book['id'] ?>"><?= e($book['title']) ?></a></h3>
                <div class="muted"><?= e($book['authors'] ?: 'Author not listed') ?></div>
                <div class="muted">ISBN <?= e($book['isbn'] ?: 'Not listed') ?> · Shelf <?= e($book['shelf_location'] ?: 'Unassigned') ?></div>
            </div>
            <div class="actions">
                <span class="status <?= (int) $book['available'] > 0 ? '' : 'waiting' ?>"><?= (int) $book['available'] ?> available</span>
                <a class="btn small secondary" href="index.php?view=book-details&amp;id=<?= (int) $book['id'] ?>">View details</a>
                <?php if ($user && $user['role'] === 'user' && $user['is_verified']): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= (int) $book['available'] > 0 ? 'borrow' : 'reserve' ?>">
                        <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                        <button class="small"><?= (int) $book['available'] > 0 ? 'Request to borrow' : 'Join queue' ?></button>
                    </form>
                <?php elseif (!$user): ?>
                    <a class="btn small secondary" href="index.php?view=login">Sign in to borrow</a>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$books): ?><div class="panel empty">No books match those filters.</div><?php endif; ?>
</section>
<nav class="actions no-print" aria-label="Catalog pages">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($baseQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?></span>
    <?php if ($hasNext): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($baseQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
