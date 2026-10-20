# School Library Borrowing and Reservation System

A PHP 8.0+, MySQL/MariaDB, and MySQLi application designed to run locally with XAMPP. It includes email verification and transaction notifications through PHPMailer SMTP, session authentication, server-side role checks, book/category management, borrowing and return workflows, FIFO reservations, activity logs, dashboards, and printable reports.

## XAMPP Setup

1. Copy this project folder into `C:\xampp\htdocs\library-management-system`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Open phpMyAdmin and import `database/library_management_system.sql`.
4. Copy `.env.example` to `.env`. Set `APP_URL` to the local project URL and configure database settings as needed.
5. Install Composer for Windows if needed, then run `composer install` from a terminal in the project folder. This installs PHPMailer; SMTP email is not available until this step and SMTP configuration are complete.
6. Configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, and `MAIL_FROM_ADDRESS` in the local `.env`. Use a mail provider/app password; never commit `.env`.
7. Visit `http://localhost/library-management-system/`. The sign-in page is `http://localhost/library-management-system/index.php?view=login`; after login, use **Sign out** in the top navigation. Sign out is handled by the CSRF-protected POST endpoint `logout.php`.

Registration succeeds even when email delivery is misconfigured only as an account creation step: the page reports that delivery failed, and the verification link is not shown or treated as sent. Configure SMTP and use the resend-verification form to send a fresh link.

## Safe Local Accounts

After importing the schema and configuring the database, run:

```powershell
C:\xampp\php\php.exe .\scripts\seed-demo.php
```

The script generates and prints a fresh random password for each administrator, staff, and student demo account, stores only `password_hash()` values, and marks these local demo accounts verified. Keep the output private. Rerunning the command rotates these passwords. Public registration always creates the `user` role; it cannot create staff or administrators.

## Workflows

- Students register, verify by one-time expiring email token, sign in, search/filter the catalog, request a copy, or join the reservation queue when no copy is available.
- Staff/admin approve or reject pending requests, release approved books, record returns, and process the next eligible FIFO reservation.
- Administrators can add/edit/archive books and categories and configure loan period, borrowing limit, and reservation pickup expiry.
- Dashboards and print reports query live database records. Admin/staff can review activity logs; students only see their own requests, loans, and reservations.

Reservation queue operations use ordered MySQL records in `reservations`: enqueue inserts a persisted row, `reservation_queue_peek()` scans FIFO order for the first eligible student, traversal displays queue order, and `reservation_queue_dequeue()` moves the selected row to pickup-ready without deleting history. The peek scan is O(n); dequeue is an indexed row update.

## Data Relationships

Users own borrowing requests, transactions, and reservations. A request refers to one user and one book and can produce at most one borrowing transaction. Books belong to a category and have a many-to-many relationship with authors through `book_authors`. Reservations retain their queue position and lifecycle. Activity logs reference the actor and affected entity. Available inventory is calculated from total copies less active loans, pending/approved requests, and copies reserved for pickup; it is not duplicated as a stored counter.

## Security Notes

- State-changing forms use POST and CSRF validation; email verification uses its one-time token link.
- SQL involving request data uses MySQLi prepared statements.
- Session IDs rotate after login; cookies are HttpOnly and SameSite=Lax.
- Server-side authorization is enforced for roles and student-owned records.
- Passwords and verification tokens are stored as hashes; verification links expire after 24 hours and are single-use.
- Escape dynamic HTML output. Do not expose `.env` or `vendor` in a submitted ZIP.

## Project Scope / Limitations

This is a working core project, not yet a complete pass of every acceptance item in the assignment. Implemented modules now include public catalog/details, account authentication/recovery/profile, borrowing requests, searchable and paginated circulation/request/reservation/account/log pages, FIFO pickup processing, and role-specific dashboards. Remaining scope includes book-cover uploads, author management screens, damaged-item processing, complete pagination/filtering on every report/list, additional report variants, and a scheduled overdue job. Overdue records are refreshed when an administrator or staff member accesses a protected page; there is no cron job. Review these gaps against the instructor rubric and test with XAMPP plus a test SMTP account before submission.
