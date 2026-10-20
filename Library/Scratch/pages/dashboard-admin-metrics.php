<?php
$popularBooks = db_all("SELECT b.title,COUNT(t.id) AS borrow_count
    FROM borrowing_transactions t JOIN books b ON b.id=t.book_id
    WHERE t.status IN ('borrowed','returned','overdue','lost')
    GROUP BY b.id ORDER BY borrow_count DESC,b.title LIMIT 5");
$categoryCounts = db_all("SELECT c.category_name,COUNT(b.id) AS titles,COALESCE(SUM(b.total_copies),0) AS copies
    FROM categories c LEFT JOIN books b ON b.category_id=c.id AND b.archived_at IS NULL
    WHERE c.archived_at IS NULL GROUP BY c.id ORDER BY titles DESC,c.category_name LIMIT 8");
$borrowingTrend = db_all("SELECT DATE_FORMAT(borrowed_at,'%Y-%m') AS month,COUNT(*) AS loans
    FROM borrowing_transactions WHERE borrowed_at>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(borrowed_at,'%Y-%m') ORDER BY month");
?>
<div class="grid">
    <section class="panel">
        <h2>Popular books</h2>
        <?php if ($popularBooks): ?><table><thead><tr><th>Title</th><th>Loans</th></tr></thead><tbody>
            <?php foreach ($popularBooks as $item): ?><tr><td><?= e($item['title']) ?></td><td><?= (int) $item['borrow_count'] ?></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><p class="muted">No borrowing history yet.</p><?php endif; ?>
    </section>
    <section class="panel">
        <h2>Category distribution</h2>
        <?php if ($categoryCounts): ?><table><thead><tr><th>Category</th><th>Titles</th><th>Copies</th></tr></thead><tbody>
            <?php foreach ($categoryCounts as $item): ?><tr><td><?= e($item['category_name']) ?></td><td><?= (int) $item['titles'] ?></td><td><?= (int) $item['copies'] ?></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><p class="muted">No active categories.</p><?php endif; ?>
    </section>
    <section class="panel">
        <h2>Borrowing trend · last six months</h2>
        <?php if ($borrowingTrend): ?><table><thead><tr><th>Month</th><th>Loans</th></tr></thead><tbody>
            <?php foreach ($borrowingTrend as $item): ?><tr><td><?= e($item['month']) ?></td><td><?= (int) $item['loans'] ?></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><p class="muted">No borrowing activity in the selected period.</p><?php endif; ?>
    </section>
</div>
