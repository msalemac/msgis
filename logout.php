<?php
// logout.php - معالج الخروج الآمن وتطهير وتدمير كوكيز الجلسة (النسخة النهائية لبيئات PHP 8.4)

// 1. استدعاء النواة البرمجية وقاعدة البيانات لتسجيل الخروج وتوثيق النشاط
require_once 'db.php';

// 2. توثيق وتسجيل حدث الخروج الآمن للموظف الحالي بجدول الرقابة قبل تدمير بياناته بالجلسة
if (isset($_SESSION['user_id'])) {
    logActivity($pdo, "خروج من النظام", "قام المستخدم بتسجيل خروجه الآمن من المنصة وتصفير كوكيز التصفح.");
}

// 3. تصفير وإفراغ مصفوفة متغيرات الجلسة بالكامل بالخادم
$_SESSION = array();

// 4. تدمير ومحو ملف تعريف ارتباط الجلسة (Cookie) من المتصفح باستخدام معايير التدمير المحدثة لـ PHP 8.4
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    
    // استخدام مصفوفة الخصائص الحديثة لـ setcookie لمطابقة بارامترات الأمان والحذف الصارم كلياً
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params["path"],
        'domain'   => $params["domain"],
        'secure'   => $params["secure"],
        'httponly' => $params["httponly"],
        'samesite' => $params["samesite"] ?? 'Lax'
    ]);
}

// 5. تدمير الجلسة بالخادم نهائياً
session_destroy();

// 6. التوجيه القسري الفوري لبوابة الدخول المنسقة
header("Location: login.php");
exit;