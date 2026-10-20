<?php
// Local XAMPP defaults. Override these values for your environment.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'library_borrowing');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('APP_NAME', 'Library Borrowing and Reservation System');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost/library_system');
