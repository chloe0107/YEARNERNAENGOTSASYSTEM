<?php
?>
<section class="panel auth">
    <span class="eyebrow">School library</span>
    <h1><?= $view === 'login' ? 'Sign in' : ($view === 'register' ? 'Create your account' : ($view === 'verify-notice' ? 'Verify your email' : ($view === 'forgot-password' ? 'Reset your password' : 'Choose a new password'))) ?></h1>
    <?php if ($view === 'login'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="login">
            <label>Email</label><input name="email" type="email" autocomplete="email" required>
            <label>Password</label><input name="password" type="password" autocomplete="current-password" required>
            <p><button>Sign in</button></p>
        </form>
        <p class="muted"><a href="index.php?view=forgot-password">Forgot your password?</a></p>
        <p class="muted">New to the library? <a href="index.php?view=register">Create an account</a>.</p>
    <?php elseif ($view === 'register'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="register">
            <label>Full name</label><input name="full_name" maxlength="150" required>
            <label>School email</label><input name="email" type="email" maxlength="190" required>
            <label>Student ID <span class="muted">(optional)</span></label><input name="student_id" maxlength="60">
            <label>Password</label><input name="password" type="password" minlength="10" autocomplete="new-password" required>
            <label>Confirm password</label><input name="password_confirm" type="password" minlength="10" autocomplete="new-password" required>
            <p><button>Create account</button></p>
        </form>
    <?php elseif ($view === 'verify-notice'): ?>
        <p class="muted">Borrowing and reservations require a verified email address.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend">
            <label>School email</label><input name="email" type="email" autocomplete="email" required>
            <p><button>Resend verification</button></p>
        </form>
    <?php elseif ($view === 'forgot-password'): ?>
        <p class="muted">If a verified account matches the address, reset instructions will be sent when SMTP is configured.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="forgot-password">
            <label>Email</label><input name="email" type="email" autocomplete="email" required>
            <p><button>Send reset link</button></p>
        </form>
    <?php elseif ($resetTokenValid): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset-password"><input type="hidden" name="token" value="<?= e($resetToken) ?>">
            <label>New password</label><input name="password" type="password" minlength="10" autocomplete="new-password" required>
            <label>Confirm new password</label><input name="password_confirm" type="password" minlength="10" autocomplete="new-password" required>
            <p><button>Update password</button></p>
        </form>
    <?php else: ?>
        <p class="muted">This reset link is invalid, expired, or already used. <a href="index.php?view=forgot-password">Request another link</a>.</p>
    <?php endif; ?>
</section>
