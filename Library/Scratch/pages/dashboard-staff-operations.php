<?php
$dueItems = db_all("SELECT t.id,t.due_date,u.full_name,b.title
    FROM borrowing_transactions t JOIN users u ON u.id=t.user_id JOIN books b ON b.id=t.book_id
    WHERE t.status IN ('borrowed','overdue') AND t.due_date<=CURDATE()
    ORDER BY t.due_date,u.full_name LIMIT 10");
$pickupItems = db_all("SELECT r.id,r.expires_at,u.full_name,b.title
    FROM reservations r JOIN users u ON u.id=r.user_id JOIN books b ON b.id=r.book_id
    WHERE r.status='ready_for_pickup' AND r.expires_at>NOW()
    ORDER BY r.expires_at LIMIT 8");
$recentReturns = db_all("SELECT t.returned_at,u.full_name,b.title
    FROM borrowing_transactions t JOIN users u ON u.id=t.user_id JOIN books b ON b.id=t.book_id
    WHERE t.status='returned' ORDER BY t.returned_at DESC LIMIT 8");
?>
<div class="grid">
    <section class="panel">
        <h2>Due today and overdue</h2>
        <?php if ($dueItems): ?><table><thead><tr><th>Student</th><th>Book</th><th>Due</th></tr></thead><tbody>
            <?php foreach ($dueItems as $item): ?><tr><td><?= e($item['full_name']) ?></td><td><?= e($item['title']) ?></td><td><?= e($item['due_date']) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><p class="muted">No loans are due today or overdue.</p><?php endif; ?>
    </section>
    <section class="panel">
        <h2>Ready for pickup</h2>
        <?php if ($pickupItems): ?><table><thead><tr><th>Student</th><th>Book</th><th>Expires</th></tr></thead><tbody>
            <?php foreach ($pickupItems as $item): ?><tr><td><?= e($item['full_name']) ?></td><td><?= e($item['title']) ?></td><td><?= e($item['expires_at']) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><p class="muted">No active pickup offers.</p><?php endif; ?>
    </section>
    <section class="panel">
        <h2>Recently returned</h2>
        <?php if ($recentReturns): ?><table><thead><tr><th>Student</th><th>Book</th><th>Returned</th></tr></thead><tbody>
            <?php foreach ($recentReturns as $item): ?><tr><td><?= e($item['full_name']) ?></td><td><?= e($item['title']) ?></td><td><?= e($item['returned_at']) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><p class="muted">No returns recorded yet.</p><?php endif; ?>
    </section>
</div>
