<?php
// PHP mail() transport is used by the current app. Configure a working SMTP/sendmail transport in XAMPP.
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'library@localhost');
