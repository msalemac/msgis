<?php
require_once 'config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // تسجيل تفاصيل خطأ الاتصال الحساسة في ملف السيرفر داخلياً لحمايتها من تسريب البيانات
    error_log("Database connection failed: " . $e->getMessage());
    die("عذراً، فشل الاتصال بقاعدة البيانات. يرجى مراجعة المسؤول الفني للنظام.");
}

// ----------------- [دوال المساعدة العامة المضافة للرقابة والتنبيهات] -----------------

// 1. دالة تسجيل العمليات والرقابة التلقائية (logActivity)
function logActivity($pdo, $action, $details = null) {
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $action, $details]);
        } catch (PDOException $e) {
            // تجاهل أي خطأ لكي لا تتوقف العملية الأساسية
        }
    }
}

// 2. دالة إرسال التنبيهات الفورية والخرائط والتقارير المصورة لجروب التليجرام (sendTelegramNotification)
function sendTelegramNotification($message, $photo_path = null) {
    if (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE') {
        $token = TELEGRAM_BOT_TOKEN;
        $chat_id = TELEGRAM_CHAT_ID;
        
        // إذا كان السجل يحتوي على صورة معاينة، نرفعها للمجموعة كملف مرفق مع شرح نصي مدمج
        if ($photo_path && file_exists($photo_path)) {
            $url = "https://api.telegram.org/bot" . $token . "/sendPhoto";
            $post_fields = [
                'chat_id' => $chat_id,
                'photo' => new CURLFile(realpath($photo_path)),
                'caption' => $message,
                'parse_mode' => 'HTML'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type:multipart/form-data"));
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            curl_exec($ch);
            curl_close($ch);
        } else {
            // إرسال رسالة نصية عادية مع تفعيل التنسيق والروابط
            $url = "https://api.telegram.org/bot" . $token . "/sendMessage?chat_id=" . $chat_id . "&text=" . urlencode($message) . "&parse_mode=HTML";
            file_get_contents($url);
        }
    }
}

// 3. [تحديث أمني]: دالة التحقق المركزي من صحة الرموز الوقائية ضد هجمات الـ CSRF
function validateCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    // استخدام دالة hash_equals لمنع ثغرات التوقيت الزمني أثناء الفحص والمقارنة (Timing Attacks)
    return hash_equals($_SESSION['csrf_token'], $token);
}