
<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    header('Location: ' . role_home());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $s = $db->prepare(
        'SELECT id, name, email, password_hash, role, is_verified, status
         FROM users
         WHERE email = ?
         LIMIT 1'
    );

    $s->bind_param('s', $email);
    $s->execute();

    $u = $s->get_result()->fetch_assoc();

    if (
        $u &&
        password_verify($password, $u['password_hash']) &&
        $u['status'] === 'active'
    ) {
        if (!$u['is_verified']) {
            $error = 'Please verify your email before logging in.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id'    => $u['id'],
                'name'  => $u['name'],
                'email' => $u['email'],
                'role'  => $u['role']
            ];

            log_action(
                'login',
                'users',
                $u['id'],
                'Successful login'
            );

            header('Location: ' . role_home());
            exit;
        }
    } else {
        $error = 'Invalid email or password, or account disabled.';
    }
}

$title = 'Login';
require __DIR__ . '/includes/header.php';
?>

<style>
.login-page {
    min-height: 72vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 30px 16px;
}

.login-card {
    width: 100%;
    max-width: 440px;
    background: #ffffff;
    padding: 36px;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 15px 45px rgba(23, 37, 84, 0.10);
}

.login-icon {
    width: 64px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    background: #dbeafe;
    color: #1d4ed8;
    border-radius: 18px;
    font-size: 30px;
}

.login-heading {
    text-align: center;
    margin-bottom: 8px;
    color: #172554;
    font-size: 28px;
    font-weight: 800;
}

.login-description {
    text-align: center;
    color: #64748b;
    margin: 0 0 28px;
    font-size: 14px;
}

.login-form label {
    display: block;
    margin-bottom: 6px;
    color: #334155;
    font-size: 14px;
    font-weight: 700;
}

.login-form input {
    display: block;
    width: 100%;
    min-height: 46px;
    padding: 11px 13px;
    margin: 0 0 19px;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    background: #f8fafc;
    color: #1e293b;
    font: inherit;
    transition: border-color .2s, box-shadow .2s;
}

.login-form input:focus {
    outline: none;
    border-color: #2563eb;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .13);
}

.login-button {
    display: block;
    width: 100%;
    min-height: 47px;
    margin-top: 5px;
    border: none;
    border-radius: 10px;
    background: #2563eb;
    color: #fff;
    font-size: 15px;
    font-weight: 750;
    cursor: pointer;
    transition: background .2s, transform .2s;
}

.login-button:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
}

.login-links {
    text-align: center;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 14px;
}

.login-links a {
    color: #2563eb;
    font-weight: 650;
    text-decoration: none;
}

.login-links a:hover {
    text-decoration: underline;
}

.login-links p {
    margin: 7px 0;
}

.login-security {
    text-align: center;
    margin-top: 20px;
    color: #64748b;
    font-size: 12px;
}

@media (max-width: 480px) {
    .login-card {
        padding: 26px 21px;
        border-radius: 15px;
    }

    .login-heading {
        font-size: 25px;
    }
}
</style>

<div class="login-page">
    <section class="login-card">

        <div class="login-icon" aria-hidden="true">
            &#128218;
        </div>

        <h1 class="login-heading">Welcome Back!</h1>

        <p class="login-description">
            Sign in to your Library Borrowing and Reservation System.
        </p>

        <?php if ($error): ?>
            <div class="alert error" role="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="login-form">
            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf_token()) ?>"
            >

            <label for="email">Email Address</label>
            <input
                id="email"
                type="email"
                name="email"
                placeholder="Enter your email address"
                required
                autocomplete="username"
                value="<?= e($_POST['email'] ?? '') ?>"
            >

            <label for="password">Password</label>
            <input
                id="password"
                type="password"
                name="password"
                placeholder="Enter your password"
                required
                autocomplete="current-password"
            >

            <button type="submit" class="login-button">
                Sign In to Your Account
            </button>
        </form>

        <div class="login-links">
            <p>
                Don't have an account?
                <a href="<?= e(url('register.php')) ?>">
                    Create an account
                </a>
            </p>

            <p>
                Didn't receive the email?
                <a href="<?= e(url('resend.php')) ?>">
                    Resend verification
                </a>
            </p>
        </div>

        <div class="login-security">
            &#128274; Secure access to your library account
        </div>

    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
