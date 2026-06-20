<?php
// cron.php - المجدول التلقائي الذكي لإرسال التقارير الأسبوعية للتلجرام (النسخة النهائية لبيئات PHP 8.4)

// 1. استدعاء ملف الاتصال والنواة البرمجية باستخدام المسارات المطلقة لضمان العمل التلقائي عبر مجدول السيرفر (Cron Job)
require_once dirname(__FILE__) . '/db.php';

try {
    // 2. جلب السجلات التي تم إدخالها وتوثيقها في قاعدة البيانات بآخر 7 أيام
    $stmt = $pdo->query("
        SELECT r.id, rt.label AS type_label 
        FROM records r 
        JOIN record_types rt ON r.record_type_id = rt.id 
        WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $records = $stmt->fetchAll();
    $total_weekly_records = count($records);

    // 3. تجميع وعد السجلات المضافة مفرزة حسب كل قسم ميداني
    $summary = [];
    foreach ($records as $r) {
        $lbl = $r['type_label'];
        $summary[$lbl] = isset($summary[$lbl]) ? $summary[$lbl] + 1 : 1;
    }

    // 4. تنسيق نص التقرير الإحصائي الأسبوعي بالخطوط العريضة المناسبة لـ Telegram HTML
    $msg = "📅 <b>التقرير الإحصائي الأسبوعي التلقائي للمنصة</b> 📅\n";
    $msg .= "<i>الفترة: من " . date('Y-m-d', strtotime('-7 days')) . " إلى " . date('Y-m-d') . "</i>\n\n";
    $msg .= "<b>إجمالي المعاينات والتوثيقات الجديدة:</b> " . $total_weekly_records . " معاينة موثقة\n";
    $msg .= "-------------------------------------------\n";
    
    if ($total_weekly_records > 0) {
        foreach ($summary as $lbl => $cnt) {
            $msg .= "• <b>" . $lbl . ":</b> " . $cnt . " سجل جديد\n";
        }
    } else {
        $msg .= "لم يتم توثيق أي سجلات أو معاينات جديدة هذا الأسبوع.\n";
    }
    $msg .= "-------------------------------------------\n";
    $msg .= "<i>تم الاستخراج والإرسال التلقائي بواسطة مجدول النظام GIS MANAGER CRON BOT</i>";

    // 5. إرسال التنبيه التلقائي للمجموعة في تليجرام باستخدام دالة الـ cURL المؤمنة والحديثة
    sendTelegramNotification($msg);

    // 6. توثيق هذه العملية المؤتمتة آلياً في سجل الرقابة والأنشطة الإدارية لضمان النزاهة
    $admin_id = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
    if ($admin_id) {
        $stmtLog = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, details) 
            VALUES (?, 'تقرير أسبوعي تلقائي', 'تم توليد وإرسال التقرير الإحصائي الأسبوعي المؤتمت لجروب تليجرام بنجاح.')
        ");
        $stmtLog->execute([$admin_id]);
    }

    echo "Success: Weekly automated report generated and sent successfully!";
} catch (Exception $e) {
    echo "Error generating weekly report: " . $e->getMessage();
}