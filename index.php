<?php
// 1. بدء الجلسة في أول السطر قبل أي مخرجات
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. ترويسة منع الكاش القياسية والأكثر توافقاً مع السيرفرات والمتصفحات
header("Cache-Control: no-cache, no-store, must-revalidate"); // متوافق مع HTTP 1.1
header("Pragma: no-cache");                                   // متوافق مع HTTP 1.0
header("Expires: 0");                                         // لضمان انتهاء الصلاحية فوراً للبروكسي

// استدعاء ملف قاعدة البيانات
require_once 'db.php';

// 3. حماية النظام والتحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 4. استخراج وتعريف متغيرات الجلسة والصلاحيات الأساسية مبكراً لتكون متاحة لجميع الصفحات المضمنة
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';
$user_allowed_pages = !empty($_SESSION['allowed_pages']) ? explode(',', $_SESSION['allowed_pages']) : [];

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

// جـ. اعتراض طلب تحميل قالب الاستيراد المخصص (XLS) للقسم المحدد فوراً وقبل الـ HTML
if (isset($_GET['action']) && $_GET['action'] === 'download_import_template') {
    include 'pages/import-view.php';
    exit;
}

// د. اعتراض زر إلغاء الاستيراد فورياً قبل طباعة الهيدر والـ HTML لمنع خطأ الـ Headers Sent
if (isset($_GET['cancel_import']) && $_GET['cancel_import'] == 1) {
    include 'pages/import-view.php';
    exit;
}

// هـ. اعتراض طلبات تحميل ملفات الـ SQL والـ ZIP فورا من السيرفر قبل الـ HTML
if (isset($_GET['action']) && in_array($_GET['action'], ['download_sql', 'download_zip', 'download_local'])) {
    include 'pages/backup-view.php';
    exit;
}

// 5. تحديد الصفحة الحالية والصفحات المسموح بالوصول إليها
$page = isset($_GET['page']) ? $_GET['page'] : 'home-view';
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
    'transfers-view', // تسجيل موديول الصادر والوارد الجديد برمجياً
    'audit-logs',
    'profile-view'
];

if (!in_array($page, $allowed_pages)) { 
    $page = 'home-view'; 
}

// 6. حماية إضافية (RBAC) لصفحات الإدارة والأمان بناءً على رتبة المستخدم
$protected_modules = ['add-record', 'map-view', 'records-manage', 'dashboard-view', 'reports-view', 'import-view', 'export-view', 'transfers-view'];

if ($role !== 'admin') {
    if (in_array($page, $protected_modules) && !in_array($page, $user_allowed_pages)) {
        if (count($user_allowed_pages) > 0) {
            header("Location: index.php?page=home-view");
        } else {
            header("Location: logout.php");
        }
        exit;
    }
}

$admin_only_pages = ['settings-view', 'users-view', 'print-settings', 'backup-view', 'import-view', 'export-view', 'audit-logs'];
if (in_array($page, $admin_only_pages) && $role !== 'admin') { 
    $page = 'home-view'; 
}

// 7. [الاعتراض العام الذكي لجميع طلبات POST] - تم نقله هنا لضمان معرفة صلاحيات المستخدم والـ Role قبل المعالجة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($page, $allowed_pages)) {
        include "pages/{$page}.php";
        exit; 
    }
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
    'transfers-view' => 'مركز إدارة الصادر والوارد والحركة المستندية', // ترويسة عنوان موديول الصادر والوارد
    'audit-logs'     => 'سجل رقابة العمليات والأنشطة الإدارية',
    'profile-view'   => 'إدارة وتحديث بيانات ملفك الشخصي'
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