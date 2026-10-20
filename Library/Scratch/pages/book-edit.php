<?php
require_login(['admin']);
$id = (int) ($_GET['id'] ?? 0);
$book = db_one("SELECT b.*,GROUP_CONCAT(a.author_name ORDER BY a.author_name SEPARATOR ', ') AS author
    FROM books b LEFT JOIN book_authors ba ON ba.book_id=b.id LEFT JOIN authors a ON a.id=ba.author_id
    WHERE b.id=? AND b.archived_at IS NULL GROUP BY b.id", 'i', $id);
if (!$book): ?>
    <div class="panel"><h1>Book not found</h1><a class="btn secondary" href="index.php?view=books">Back to books</a></div>
<?php else:
    $categories = db_all('SELECT id,category_name FROM categories WHERE archived_at IS NULL ORDER BY category_name');
?>
    <div class="hero"><div><span class="eyebrow">Collection management</span><h1>Edit book</h1></div></div>
    <section class="panel">
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="save-book"><input type="hidden" name="id" value="<?= (int) $book['id'] ?>">
            <div><label for="title">Title</label><input id="title" name="title" maxlength="220" value="<?= e($book['title']) ?>" required></div>
            <div><label for="author">Author</label><input id="author" name="author" maxlength="160" value="<?= e($book['author']) ?>"></div>
            <div><label for="isbn">ISBN</label><input id="isbn" name="isbn" maxlength="20" value="<?= e($book['isbn']) ?>"></div>
            <div><label for="category_id">Category</label><select id="category_id" name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $book['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="publisher">Publisher</label><input id="publisher" name="publisher" maxlength="160" value="<?= e($book['publisher']) ?>"></div>
            <div><label for="publication_year">Publication year</label><input id="publication_year" type="number" name="publication_year" min="1000" max="2100" value="<?= e($book['publication_year']) ?>"></div>
            <div><label for="total_copies">Total copies</label><input id="total_copies" type="number" name="total_copies" min="1" max="1000" value="<?= (int) $book['total_copies'] ?>" required></div>
            <div><label for="shelf_location">Shelf location</label><input id="shelf_location" name="shelf_location" maxlength="80" value="<?= e($book['shelf_location']) ?>"></div>
            <div class="wide"><label for="description">Description</label><textarea id="description" name="description" maxlength="10000"><?= e($book['description']) ?></textarea></div>
            <div class="wide"><label for="cover">Cover image (JPEG, PNG, or WebP; max 2 MB)</label><input id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp"><?php if ($book['cover_image']): ?><p><img src="<?= e($book['cover_image']) ?>" alt="Current cover for <?= e($book['title']) ?>" style="max-width:160px;max-height:220px;object-fit:contain"></p><?php endif; ?></div>
            <div class="wide"><button>Save book</button> <a class="btn secondary" href="index.php?view=books">Cancel</a></div>
        </form>
    </section>
<?php endif; ?>
