# Library Borrowing and Reservation System

PHP 8+, MySQL/MariaDB, MySQLi, HTML/CSS/JavaScript. Intended for XAMPP and a college final-project demonstration.

## Project structure
- `config/`: database and mail settings. Do not commit secrets.
- `includes/`: shared database, authentication, CSRF, layout and helper code.
- `admin/`, `staff/`, `user/`: role-protected route aliases for the corresponding system areas. The working handlers are kept as root compatibility pages (`books.php`, `categories.php`, `transactions.php`, `users.php`, `reports.php`) so existing form actions continue to work.
- `database/library_management_system.sql`: database schema and sample records. `database.sql` is retained as a compatibility copy.
- `assets/css`, `assets/js`, `assets/images`: static assets.
- `actions/`: notes for current form handlers; POST actions should not be redirected.

## Install with XAMPP
1. Extract the folder as `C:\xampp\htdocs\library_system`.
2. Start Apache and MySQL.
3. In phpMyAdmin, import `database/library_management_system.sql` into a fresh database. It creates `library_borrowing`.
4. Demo accounts are inserted automatically by the SQL import; no separate seed step is needed.
5. Demo credentials (all use `Demo123!`): `admin@library.local`, `staff@library.local`, `student@library.local`.
6. Default XAMPP database settings are localhost/root/blank password. Update `config/database.php` if your local settings differ.

## Email verification
The current verification handler uses PHP `mail()`. Configure a working SMTP/sendmail transport in XAMPP/hosting to receive real messages. `composer.json` declares PHPMailer for a future SMTP integration; run `composer install` to install dependencies. No real mail password or API key belongs in this ZIP.

## Features
- Three roles: admin, staff and user, with server-side role checks.
- Registration, login/logout, password hashing, sessions and expiring single-use email verification tokens.
- Book and category management; user search/catalogue.
- Borrow/reservation requests, approve/reject, release, return, cancellation and inventory updates.
- Dashboard, filtered reports with print/save-PDF view, activity logs and FIFO queue demonstration.
- MySQLi prepared statements, server-side validation, output escaping and CSRF tokens for POST forms.

## Important
This archive is structurally organized with role-specific route aliases, but core form handlers remain at the root for compatibility. Test the full workflow against your own MySQL server before submission. Actual email delivery depends on mail configuration. The SQL import creates the demo accounts directly. `seed_demo.php` is retained as an optional helper, but is not required for a fresh installation. Change demo passwords before any non-demo deployment.
