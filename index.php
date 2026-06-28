<?php
// index.php - الموجه المركزي الجغرافي المطور (النسخة النهائية الفائقة الأمان والاتزان لبيئات PHP 8.4)

// تفعيل التخزين المؤقت للمخرجات لضمان تشغيل إعادة التوجيه الفورية الآمنة (safeRedirect) ومنع تداخل الكاش والتنسيقات
ob_start();

// 1. استدعاء ملف النواة والإعدادات وقاعدة البيانات في مطلع الملف لضمان تشغيل الجلسة بأعلى معايير الأمان المكتوبة بـ config.php
require_once 'db.php';

// 2. ترويسة منع الكاش القياسية والأكثر توافقاً مع المتصفحات لضمان تحديث البيانات جغرافياً
header("Cache-Control: no-cache, no-store, must-revalidate"); // متوافق مع HTTP 1.1
header("Pragma: no-cache");                                   // متوافق مع HTTP 1.0
header("Expires: 0");                                         // لضمان انتهاء الصلاحية فوراً للبروكسي

// 3. حماية النظام والتحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 4. استخراج وتعريف متغيرات الجلسة والصلاحيات الأساسية مبكراً لتكون متاحة لجميع الصفحات المضمنة
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';

// ----------------- [حلول الاستيراد والتصدير والنسخ والرقابة - الاعتراض قبل الـ HTML] -----------------

// أ. اعتراض طلب تصدير تقارير Excel المفلترة وحفظ الملف فورا من صفحة التصدير المستقلة
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    include 'pages/export-csv.php';
    exit;
}

// ب. اعتراض طلب تصدير خرائط الـ KML المفلترة وحفظ الملف فورا من صفحة التصدير المستقلة
if (isset($_GET['action']) && $_GET['action'] === 'export_kml') {
    include 'pages/export-view.php';
    exit;
}

// جـ. اعتراض طلب تحميل قالب الاستيراد المخصص (XLS) للقسم المحدد وتوليد ملف الإكسل آلياً وتنزيله فوراً باللغة العربية
if (isset($_GET['action']) && $_GET['action'] === 'download_import_template') {
    $type_id = intval($_GET['type_id'] ?? 0);
    if ($type_id > 0) {
        // جلب المسميات العربية للحقول النشطة المخصصة لهذا القسم من قاعدة البيانات
        $stmt = $pdo->prepare("SELECT label, type, options FROM fields WHERE FIND_IN_SET(?, record_type_id) AND is_active = 1 ORDER BY field_order ASC");
        $stmt->execute([$type_id]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // تأسيس أعمدة الرأس الافتراضية باللغة العربية الفصحى
        $headers = ['خط العرض (Latitude)', 'خط الطول (Longitude)'];
        $validations = [];

        // دالة مساعدة لتحويل ترتيب الأعمدة الرقمي إلى حروف أبجدية متوافقة مع نطاقات إكسل (A, B, C...)
        if (!function_exists('numToLetter')) {
            function numToLetter($num) {
                $numeric = ($num - 1) % 26;
                $letter = chr(65 + $numeric);
                $num2 = intval(($num - 1) / 26);
                if ($num2 > 0) {
                    return numToLetter($num2) . $letter;
                } else {
                    return $letter;
                }
            }
        }

        // إعداد نطاق التحقق لكل عمود ابتداءً من العمود الثالث C (حيث أن A لخط العرض وB لخط الطول)
        $col_num = 3;
        foreach ($fields as $f) {
            $headers[] = $f['label']; // إدراج المسمى العربي الصريح في رأس العمود
            $col_letter = numToLetter($col_num);

            if ($f['type'] === 'select' && !empty($f['options'])) {
                // مواءمة الفواصل لتتناسب مع بيئة إكسل في الشرق الأوسط (استخدام الفاصلة المنقوطة)
                $formatted_options = str_replace(',', ';', $f['options']);
                $validations[] = [
                    'range' => "{$col_letter}2:{$col_letter}1000",
                    'list'  => $formatted_options
                ];
            } elseif ($f['type'] === 'checkbox') {
                $validations[] = [
                    'range' => "{$col_letter}2:{$col_letter}1000",
                    'list'  => 'نعم;لا'
                ];
            }
            $col_num++;
        }

        // إرسال ترويسات قوية تجبر المتصفح على تحميل الملف فوراً كملف خارجي وتجنب فتحه داخل المتصفح كـ HTML
        header("Content-Type: application/octet-stream"); 
        header("Content-Disposition: attachment; filename=import_template_section_{$type_id}.xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        // بناء مستند XLS المتوافق برمجياً وتمرير مصفوفة المطابقة والـ Data Validation
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
        echo '<!--[if gte mso 9]><xml>';
        echo ' <x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        echo '  <x:Name>Template</x:Name>';
        echo '  <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        echo ' </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>';
        
        // حقن تاجات التحقق والقوائم المنسدلة التفاعلية لكل حقل مخصص
        if (!empty($validations)) {
            foreach ($validations as $val) {
                echo ' <x:DataValidation>';
                echo '  <x:Range>' . $val['range'] . '</x:Range>';
                echo '  <x:Type>List</x:Type>';
                echo '  <x:Value>&quot;' . htmlspecialchars($val['list'], ENT_QUOTES, 'UTF-8') . '&quot;</x:Value>';
                echo ' </x:DataValidation>';
            }
        }
        
        echo '</xml><![endif]-->';
        echo '<style>th { background-color: #1e293b; color: white; font-weight: bold; height: 35px; text-align: center; font-family: Cairo, Arial, sans-serif; } td { height: 25px; text-align: right; }</style>';
        echo '</head><body dir="rtl"><table border="1"><tr>';
        
        // طباعة العناوين العربية الصريحة
        foreach ($headers as $h) {
            echo "<th>" . htmlspecialchars($h) . "</th>";
        }
        echo '</tr><tr>';
        
        // طباعة صف فارغ أسفل العناوين لتسهيل الكتابة
        foreach ($headers as $h) {
            echo "<td></td>";
        }
        echo '</tr></table></body></html>';
    }
    exit;
}

// د. اعتراض زر إلغاء الاستيراد فورياً لتنظيف جلسة الرفع وحذف الملف المؤقت ثم إعادة التوجيه الآمن
if (isset($_GET['cancel_import']) && $_GET['cancel_import'] == 1) {
    if (isset($_SESSION['import_temp_json']) && file_exists($_SESSION['import_temp_json'])) {
        @unlink($_SESSION['import_temp_json']);
    }
    unset($_SESSION['import_temp_json']);
    header("Location: index.php?page=import-view");
    exit;
}

// هـ. اعتراض طلبات تحميل ملفات الـ SQL والـ ZIP فورا من السيرفر قبل الـ HTML
if (isset($_GET['action']) && in_array($_GET['action'], ['download_sql', 'download_zip', 'download_local'])) {
    include 'pages/backup-view.php';
    exit;
}

// 5. تحديد الصفحة الحالية والصفحات المسموح بالوصول إليها
$page = isset($_GET['page']) ? trim((string)$_GET['page']) : 'home-view';
$allowed_pages = [
    'home-view',      
    'add-record', 
    'map-view', 
    'records-manage', 
    'dashboard-view', 
    'reports-view',   
    'settings-view', 
    'view-record', 
    'edit-record', 
    'users-view', 
    'print-settings',
    'backup-view',
    'import-view', 
    'export-view', 
    'transfers-view', 
    'audit-logs',
    'profile-view',
    'data-repair' 
];

if (!in_array($page, $allowed_pages)) { 
    $page = 'home-view'; 
}

// 6. [حماية إضافية متطورة وديناميكية بالـ RBAC]: فحص صلاحية الدخول للموديول بالاعتماد على دالة التحقق تفصيلياً (hasPermission)
$protected_modules = ['add-record', 'map-view', 'records-manage', 'dashboard-view', 'reports-view', 'import-view', 'export-view', 'transfers-view'];

if (in_array($page, $protected_modules)) {
    // التحقق الفوري ما إذا كان الحساب يملك إذن القراءة والعرض (read) لهذا الموديول تحديداً
    if (!hasPermission($page, 'read')) {
        header("Location: index.php?page=home-view");
        exit;
    }
}

// 7. [آلية الاعتراض الهجين المطور لطلبات الـ POST قبل الـ HTML]
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($page, $allowed_pages)) {
        ob_start(); // فتح بفر مؤقت لالتقاط وحماية مخرجات الملف البرمجي
        include "pages/{$page}.php";
        
        // إذا نجحت المعالجة وتم التوجيه؛ فسيقوم الملف بعمل exit داخلي آمن.
        // وإذا فشل التحقق (وجود أخطاء بالاستمارة)، نقوم بتطهير البفر المؤقت لمنع تكرار الواجهة
        // ونسمح للموجه بمتابعة تشغيله المعتاد وتحميل القوالب والتنسيقات والـ CSS لتعرض الأخطاء منسقة
        ob_end_clean(); 
    }
}

// قصر صفحات التحكم الفنية والسرية والنسخ الاحتياطي وإصلاح البيانات على الأدمن فقط (إصلاح وتطهير موديولي الاستيراد والتصدير لربطهما بالنظام الديناميكي)
$admin_only_pages = ['settings-view', 'users-view', 'print-settings', 'backup-view', 'audit-logs', 'data-repair'];
if (in_array($page, $admin_only_pages) && $role !== 'admin') { 
    $page = 'home-view'; 
}

// ----------------- [جلب إحصائيات الإشعارات المركزية للشريط العلوي] -----------------
try {
    $count_total = $pdo->query("SELECT COUNT(*) FROM records")->fetchColumn() ?: 0;
    $count_today = $pdo->query("SELECT COUNT(*) FROM records WHERE DATE(created_at) = CURRENT_DATE")->fetchColumn() ?: 0;
    $count_month = $pdo->query("SELECT COUNT(*) FROM records WHERE MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)")->fetchColumn() ?: 0;
    $stmt_last = $pdo->query("
        SELECT r.id, u.username, r.created_at 
        FROM records r 
        JOIN users u ON r.user_id = u.id 
        ORDER BY r.id DESC 
        LIMIT 1
    ");
    $last_act = $stmt_last->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $count_total = 0; $count_today = 0; $count_month = 0; $last_act = null;
}

// 8. مصفوفة العناوين الكاملة والمصححة لجميع صفحات لوحة التحكم
$titles = [
    'home-view'      => 'بوابة منصة GIS MANAGER الرقمية',
    'add-record'     => 'إضافة سجل جديد',
    'map-view'       => 'الخريطة التفاعلية والحدود الجغرافية',
    'records-manage' => 'لوحة إدارة السجلات الميدانية',
    'dashboard-view' => 'لوحة المؤشرات والرسوم البيانية (Dashboard)',
    'reports-view'   => 'منشئ ومستخرج التقارير التفصيلية',
    'settings-view'  => 'إعدادات النظام الفنية وباني الحقول',
    'view-record'    => 'عرض تفاصيل المعاينة',
    'edit-record'    => 'تعديل السجل الميداني',
    'users-view'     => 'إدارة المستخدمين والصلاحيات',
    'print-settings' => 'تخصيص قوالب وإعدادات الطباعة',
    'backup-view'    => 'مركز النسخ الاحتياطي واسترجاع النظام الكلي',
    'import-view'    => 'معالج استيراد البيانات وتنزيل القوالب المعتمدة',
    'export-view'    => 'مركز تصدير البيانات الجغرافية والتقارير KML',
    'transfers-view' => 'مركز إدارة الصادر والوارد والحركة المستندية', 
    'audit-logs'     => 'سجل رقابة العمليات والأنشطة الإدارية',
    'profile-view'   => 'إدارة وتحديث بيانات ملفك الشخصي',
    'data-repair'    => 'محرك استعلام وإصلاح وتطهير البيانات الفوري' 
];
$pageTitle = isset($titles[$page]) ? $titles[$page] : 'لوحة التحكم';

// 9. استدعاء الأجزاء المشتركة للواجهة الرسومية (يبدأ طباعة كود HTML هنا)
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- خلفية ضبابية تظهر وتغطي الشاشة في الموبايل عند فتح السايدبار ويؤدي النقر عليها لإغلاقه تلقائياً -->
<div id="sidebar-overlay" onclick="toggleSidebar()" class="hidden fixed inset-0 bg-black/50 z-20 md:hidden transition-opacity"></div>

<!-- الهيكل البصري الرئيسي للموقع والـ Sidebar -->
<main class="flex-1 flex flex-col h-full overflow-y-auto">
    <!-- شريط علوي تفاعلي وثابت -->
    <header class="bg-white shadow px-6 py-4 flex justify-between items-center z-10">
        <div class="flex items-center space-x-4 space-x-reverse">
            <!-- زر إخفاء وإظهار القائمة الجانبية -->
            <button onclick="toggleSidebar()" class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg focus:outline-none">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
            <h2 class="text-lg font-bold text-gray-800"><?php echo $pageTitle; ?></h2>
        </div>
        
        <!-- الجزء الأيسر من الشريط العلوي (الإشعارات والملف الشخصي المنسدل) -->
        <div class="flex items-center space-x-5 space-x-reverse">
            
            <!-- أ. جرس الإشعارات المطور -->
            <div class="relative">
                <button type="button" onclick="toggleNotificationDropdown(event)" class="relative text-gray-400 hover:text-gray-600 transition focus:outline-none" title="نشاط المنصة اليومي">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($count_today > 0): ?>
                        <span class="absolute -top-1 -left-1 bg-red-600 text-white text-[8px] font-bold w-4 h-4 rounded-full flex items-center justify-center animate-bounce"><?php echo $count_today; ?></span>
                    <?php endif; ?>
                </button>

                <!-- لوحة الإشعارات المنسدلة الفاخرة للجهة اليسرى -->
                <div id="notification-dropdown" class="hidden absolute left-0 mt-3 w-64 bg-slate-900 border border-slate-700 text-right p-4 rounded-xl shadow-2xl z-50 space-y-3 text-xs text-slate-300">
                    <span class="block text-[10px] font-bold text-slate-400 border-b border-slate-800 pb-1.5 mb-2">
                        <i class="fa-solid fa-chart-line text-blue-500 ml-1"></i> ملخص نشاط المنصة
                    </span>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center bg-slate-800/60 p-2 rounded-lg">
                            <span>إجمالي السجلات الكلي:</span>
                            <span class="font-bold text-white font-mono"><?php echo $count_total; ?></span>
                        </div>
                        <div class="flex justify-between items-center bg-slate-800/60 p-2 rounded-lg">
                            <span>سجلات مضافة اليوم:</span>
                            <span class="font-bold text-emerald-400 font-mono"><?php echo $count_today; ?></span>
                        </div>
                        <div class="flex justify-between items-center bg-slate-800/60 p-2 rounded-lg">
                            <span>سجلات هذا الشهر:</span>
                            <span class="font-bold text-blue-400 font-mono"><?php echo $count_month; ?></span>
                        </div>
                    </div>
                    <?php if ($last_act): ?>
                        <div class="border-t border-slate-800 pt-2 mt-2 text-[10px] text-slate-400 space-y-1">
                            <span class="block font-bold text-slate-500">آخر معاينة ميدانية:</span>
                            <div class="bg-slate-950 p-2 rounded border border-slate-800/80 text-[9px] leading-relaxed text-right">
                                الموظف: <strong class="text-white"><?php echo htmlspecialchars($last_act['username']); ?></strong><br>
                                بتاريخ: <span class="font-mono text-slate-500"><?php echo date('Y-m-d H:i', strtotime($last_act['created_at'])); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ب. قائمة حساب المستخدم المنسدلة التفاعلية -->
            <div class="relative">
                <button type="button" onclick="toggleUserDropdown(event)" class="flex items-center space-x-2 space-x-reverse bg-slate-50 hover:bg-slate-100 px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-800 border focus:outline-none transition">
                    <i class="fa-solid fa-user-shield text-slate-400"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <span class="text-[9px] bg-slate-200 text-slate-600 px-1.5 py-0.5 rounded font-bold font-sans">
                        <?php echo $role === 'admin' ? 'مدير' : 'مستخدم'; ?>
                    </span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                </button>

                <!-- القائمة المنسدلة للتوجيه السريع وتجنب تكرار الروابط -->
                <div id="user-dropdown" class="hidden absolute left-0 mt-3 w-44 bg-white border border-gray-100 rounded-xl shadow-xl z-50 overflow-hidden text-right">
                    <a href="index.php?page=profile-view" class="block px-4 py-2.5 text-xs text-gray-700 hover:bg-gray-50 border-b border-gray-100 transition font-semibold">
                        <i class="fa-solid fa-user-gear ml-1.5 text-gray-400"></i>
                        <span>الملف الشخصي</span>
                    </a>
                    <a href="logout.php" class="block px-4 py-2.5 text-xs text-red-600 hover:bg-red-50 transition font-bold">
                        <i class="fa-solid fa-right-from-bracket ml-1.5 text-red-400"></i>
                        <span>تسجيل الخروج</span>
                    </a>
                </div>
            </div>

        </div>
    </header>

    <!-- مكان استدعاء وحقن الصفحة النشطة ديناميكياً وعزلها كلياً -->
    <div class="p-6 flex-1 bg-gray-100">
        <?php include "pages/{$page}.php"; ?>
    </div>
</main>

<script>
    // دالة التحكم المرن والذكي في إخفاء وإظهار السايدبار
    function toggleSidebar() {
        const sidebar = document.getElementById('main-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        if (window.innerWidth < 768) {
            if (sidebar.classList.contains('translate-x-full')) {
                sidebar.classList.remove('translate-x-full');
                sidebar.classList.add('translate-x-0');
                overlay.classList.remove('hidden');
            } else {
                sidebar.classList.add('translate-x-full');
                sidebar.classList.remove('translate-x-0');
                overlay.classList.add('hidden');
            }
        } else {
            if (sidebar.classList.contains('md:w-64')) {
                sidebar.classList.remove('md:w-64');
                sidebar.classList.add('md:w-0', 'md:overflow-hidden', 'md:opacity-0');
            } else {
                sidebar.classList.remove('md:w-0', 'md:overflow-hidden', 'md:opacity-0');
                sidebar.classList.add('md:w-64');
            }
        }
    }

    function toggleNotificationDropdown(event) {
        event.stopPropagation();
        document.getElementById('user-dropdown').classList.add('hidden'); 
        document.getElementById('notification-dropdown').classList.toggle('hidden');
    }

    function toggleUserDropdown(event) {
        event.stopPropagation();
        document.getElementById('notification-dropdown').classList.add('hidden'); 
        document.getElementById('user-dropdown').classList.toggle('hidden');
    }

    document.addEventListener('click', function() {
        document.getElementById('notification-dropdown').classList.add('hidden');
        document.getElementById('user-dropdown').classList.add('hidden');
    });
</script>

<?php 
// استدعاء ملف ذيل الصفحة والنهايات والمكتبات المدمجة
include 'includes/footer.php'; 
?>