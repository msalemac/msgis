<?php
// db.php - ملف الاتصال بقاعدة البيانات ودوال المساعدة والرقابة والإشعار (النسخة النهائية لبيئات PHP 8.4)

require_once 'config.php';

try {
    // تأسيس الاتصال الآمن بقاعدة البيانات بترميز UTF8MB4 لدعم العربية والرموز بالكامل
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // تفعيل إطلاق الاستثناءات عند حدوث أي خطأ برميجي لمعالجته
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // جلب البيانات كـ مصفوفة ترابطية بشكل افتراضي
        PDO::ATTR_EMULATE_PREPARES   => false,                  // إيقاف المحاكاة المؤقتة لـ Prepared Statements لضمان السرعة والأمان المطلق
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // [حماية سريّة البيانات]: كتابة تفاصيل خطأ الاتصال بداخل ملف سجل الأخطاء المغلق بالسيرفر وتجنب طباعته للمستخدم
    error_log("Database connection failed: " . $e->getMessage());
    die("عذراً، فشل الاتصال بقاعدة البيانات السحابية. يرجى مراجعة المسؤول الفني للنظام.");
}

// ----------------- [دوال المساعدة العامة المضافة للرقابة والتنبيهات] -----------------

/**
 * 1. دالة تسجيل العمليات والرقابة التلقائية (logActivity)
 * توثق الإجراءات وتغيرات الحيازة المستندية وحذف وتعديل السجلات في ملف الأنشطة
 */
function logActivity($pdo, $action, $details = null) {
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $action, $details]);
        } catch (PDOException $e) {
            // تجاهل أي خطأ لكي لا تتوقف العملية الأساسية للنظام
        }
    }
}

/**
 * 2. دالة إرسال التنبيهات الفورية والخرائط والتقارير المصورة لجروب التليجرام (sendTelegramNotification)
 * تم ترقيتها بالكامل لتعمل عبر الـ cURL لتخطي قفل allow_url_fopen في السيرفرات المشتركة
 */
function sendTelegramNotification($message, $photo_path = null) {
    if (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE') {
        $token = TELEGRAM_BOT_TOKEN;
        $chat_id = TELEGRAM_CHAT_ID;
        
        // أ. إذا كان السجل يحتوي على صورة معاينة، نرفعها للمجموعة كملف مرفق مع شرح نصي مدمج
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
            // ب. إرسال رسالة نصية عادية منسقة (تم ترقيتها لـ cURL لضمان ثبات الإرسال في جميع الاستضافات)
            $url = "https://api.telegram.org/bot" . $token . "/sendMessage";
            $post_fields = [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

/**
 * 3. دالة التحقق المركزي من صحة الرموز الوقائية ضد هجمات الـ CSRF
 * تستخدم دالة hash_equals لمنع ثغرات التوقيت الزمني أثناء الفحص والمقارنة (Timing Attacks)
 */
function validateCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}