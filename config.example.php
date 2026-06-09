<?php
// ملف إرشادي لتهيئة وضبط الإعدادات على السيرفر الجديد
// قم بنسخ هذا الملف وتسميته باسم (config.php) ثم املأ البيانات بالأسفل

define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_DATABASE_USER');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

define('RECAPTCHA_SITE_KEY', 'YOUR_GOOGLE_RECAPTCHA_SITE_KEY');
define('RECAPTCHA_SECRET_KEY', 'YOUR_GOOGLE_RECAPTCHA_SECRET_KEY');

define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN');
define('TELEGRAM_CHAT_ID', 'YOUR_TELEGRAM_CHAT_ID');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = md5(uniqid(rand(), true));
    }
}