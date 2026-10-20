<?php
require_login(['admin']);
$search = trim((string) ($_GET['q'] ?? ''));
$categoryId = (int) ($_GET['category'] ?? 0);
$categories = db_all('SELECT id,category_name FROM categories WHERE archived_at IS NULL ORDER BY category_name');
$where = ' WHERE b.archived_at IS NULL';
$types = '';
$params = [];
if ($search !== '') {
    $where .= ' AND (b.title LIKE ? OR b.isbn LIKE ? OR a.author_name LIKE ?)';
    $wild = '%' . $search . '%';
    $types .= 'sss';
    array_push($params, $wild, $wild, $wild);
}
if ($categoryId > 0) {
    $where .= ' AND b.category_id = ?';
    $types .= 'i';
    $params[] = $categoryId;
}
$joins = ' FROM books b JOIN categories c ON c.id=b.category_id LEFT JOIN book_authors ba ON ba.book_id=b.id LEFT JOIN authors a ON a.id=ba.author_id';
$count = db_one('SELECT COUNT(DISTINCT b.id) AS total' . $joins . $where, $types, ...$params);
$total = (int) ($count['total'] ?? 0);
$perPage = 50;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = db_all("SELECT b.id,b.title,b.isbn,b.total_copies,c.category_name,GROUP_CONCAT(DISTINCT a.author_name ORDER BY a.author_name SEPARATOR ', ') AS authors" . $joins . $where . ' GROUP BY b.id ORDER BY b.title LIMIT ? OFFSET ?', $types . 'ii', ...array_merge($params, [$perPage, ($page - 1) * $perPage]));
$filterQuery = ['view' => 'books', 'q' => $search, 'category' => $categoryId];
?>
<div class="hero"><div><span class="eyebrow">Collection management</span><h1>Books</h1></div></div>
<section class="panel">
    <h2>Add a book</h2>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="save-book">
        <div><label>Title</label><input name="title" maxlength="220" required></div><div><label>Author</label><input name="author" maxlength="160"></div>
        <div><label>ISBN</label><input name="isbn" maxlength="20"></div><div><label>Category</label><select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['category_name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Publisher</label><input name="publisher" maxlength="160"></div><div><label>Publication year</label><input type="number" name="publication_year" min="1000" max="2100"></div>
        <div><label>Shelf location</label><input name="shelf_location" maxlength="80"></div><div><label>Total copies</label><input type="number" name="total_copies" min="1" max="1000" value="1" required></div>
        <div class="wide"><label>Description</label><textarea name="description" maxlength="10000"></textarea></div>
        <div class="wide"><label>Cover image (JPEG, PNG, or WebP; max 2 MB)</label><input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></div>
        <div class="wide"><button>Add book</button></div>
    </form>
</section>
<form method="get" class="searchbar no-print">
    <input type="hidden" name="view" value="books"><input name="q" value="<?= e($search) ?>" placeholder="Title, author, or ISBN">
    <select name="category"><option value="0">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option><?php endforeach; ?></select>
    <button>Filter</button>
</form>
<div class="panel table-wrap">
    <table><thead><tr><th>Title</th><th>Author</th><th>ISBN</th><th>Category</th><th>Copies</th><th>Available</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= e($row['authors'] ?: 'Not listed') ?></td><td><?= e($row['isbn'] ?: '') ?></td><td><?= e($row['category_name']) ?></td><td><?= (int) $row['total_copies'] ?></td><td><?= available_copies((int) $row['id'], (int) $row['total_copies']) ?></td><td class="actions"><a class="btn small secondary" href="index.php?view=book-edit&amp;id=<?= (int) $row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this book?')"><?= csrf_field() ?><input type="hidden" name="action" value="archive-book"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="small danger">Archive</button></form></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">No books match these filters.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<nav class="actions no-print" aria-label="Book pages">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> books</span>
    <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= e('index.php?' . http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
