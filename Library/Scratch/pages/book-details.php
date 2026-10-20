<?php
$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$book = $bookId ? db_one(
    "SELECT b.id,b.isbn,b.title,b.publisher,b.publication_year,b.description,b.shelf_location,b.total_copies,b.cover_image,
            c.category_name,GROUP_CONCAT(DISTINCT a.author_name ORDER BY a.author_name SEPARATOR ', ') AS authors
     FROM books b JOIN categories c ON c.id=b.category_id
     LEFT JOIN book_authors ba ON ba.book_id=b.id LEFT JOIN authors a ON a.id=ba.author_id
     WHERE b.id=? AND b.archived_at IS NULL GROUP BY b.id",
    'i', $bookId
) : null;
if (!$book): ?>
    <section class="panel"><h1>Book not found</h1><p class="muted">This book may have been archived or the link is invalid.</p><a class="btn secondary" href="index.php?view=catalog">Back to catalog</a></section>
<?php else:
    $available = available_copies((int) $book['id'], (int) $book['total_copies']);
?>
    <div class="hero">
        <div>
            <span class="eyebrow"><?= e($book['category_name']) ?></span>
            <h1><?= e($book['title']) ?></h1>
            <div class="muted"><?= e($book['authors'] ?: 'Author not listed') ?></div>
        </div>
        <span class="status <?= $available > 0 ? '' : 'waiting' ?>"><?= $available ?> available</span>
    </div>
    <section class="panel">
        <?php if ($book['cover_image']): ?><img class="book-cover detail-cover" src="<?= e($book['cover_image']) ?>" alt="Cover of <?= e($book['title']) ?>"><?php endif; ?>
        <dl class="form-grid">
            <div><dt class="muted">ISBN</dt><dd><?= e($book['isbn'] ?: 'Not listed') ?></dd></div>
            <div><dt class="muted">Category</dt><dd><?= e($book['category_name']) ?></dd></div>
            <div><dt class="muted">Publisher</dt><dd><?= e($book['publisher'] ?: 'Not listed') ?></dd></div>
            <div><dt class="muted">Publication year</dt><dd><?= e($book['publication_year'] ?: 'Not listed') ?></dd></div>
            <div><dt class="muted">Shelf location</dt><dd><?= e($book['shelf_location'] ?: 'Unassigned') ?></dd></div>
            <div><dt class="muted">Total copies</dt><dd><?= (int) $book['total_copies'] ?></dd></div>
        </dl>
        <h2>Description</h2>
        <p><?= nl2br(e($book['description'] ?: 'No description is available.')) ?></p>
        <div class="actions">
            <?php if ($user && $user['role'] === 'user' && $user['is_verified']): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $available > 0 ? 'borrow' : 'reserve' ?>">
                    <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                    <button><?= $available > 0 ? 'Request to borrow' : 'Join reservation queue' ?></button>
                </form>
            <?php elseif (!$user): ?>
                <a class="btn" href="index.php?view=login">Sign in to borrow</a>
            <?php elseif ($user['role'] === 'user' && !$user['is_verified']): ?>
                <a class="btn secondary" href="index.php?view=verify-notice">Verify your email to borrow</a>
            <?php endif; ?>
            <a class="btn secondary" href="index.php?view=catalog">Back to catalog</a>
        </div>
    </section>
<?php endif; ?>
