<?php
$student = require_login(['user']);
$profile = db_one('SELECT full_name,email,student_id,is_verified,created_at FROM users WHERE id = ?', 'i', (int) $student['id']);
?>
<div class="hero">
    <div>
        <span class="eyebrow">Account</span>
        <h1>My profile</h1>
    </div>
</div>
<section class="panel">
    <div class="notice">Email: <?= e($profile['email']) ?> · Verification: <?= $profile['is_verified'] ? 'Verified' : 'Not verified' ?></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save-profile">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" maxlength="150" value="<?= e($profile['full_name']) ?>" required>
        <label for="student_id">Student ID</label>
        <input id="student_id" name="student_id" maxlength="60" value="<?= e($profile['student_id']) ?>">
        <h2>Change password</h2>
        <p class="muted">Leave the password fields blank to keep your current password.</p>
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password">
        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password">
        <label for="password_confirm">Confirm new password</label>
        <input id="password_confirm" name="password_confirm" type="password" minlength="10" autocomplete="new-password">
        <p><button>Save profile</button></p>
    </form>
</section>
