<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);
$title='Settings'; require __DIR__ . '/../includes/header.php';
?>
<h1>System settings</h1><div class="panel"><p>Database and email configuration are loaded from <code>config/database.php</code> and <code>config/mail.php</code>. For deployment, set environment variables instead of committing secrets. Email verification requires a configured mail transport.</p></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
