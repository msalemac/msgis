<?php
// includes/sidebar.php - القائمة الجانبية المنزلقة المتجاوبة (النسخة النهائية المحدثة لتشمل 15 موديول بالكامل)

$currentPage = isset($_GET['page']) ? trim((string)$_GET['page']) : 'home-view';
$role = $_SESSION['role'] ?? 'user';

// جلب قائمة الصفحات المصرح للموظف بفتحها مع تحويلها لنص صريح لبيئات PHP 8.4
$user_allowed_pages = !empty($_SESSION['allowed_pages']) ? explode(',', (string)$_SESSION['allowed_pages']) : [];
?>
<!-- [تطوير استراتيجي]: تم تمكين السايدبار من الالتفاف كـ درج منزلق fixed بالهاتف وتجنب ضغط الخريطة أو حجب الـ GPS -->
<aside id="main-sidebar" class="sidebar-transition fixed md:relative right-0 top-0 h-screen z-30 w-64 bg-slate-800 text-white flex flex-col justify-between shadow-lg transform translate-x-full md:translate-x-0 overflow-y-auto duration-300">
    <div>
        <div class="p-5 text-center border-b border-slate-700">
            <h1 class="text-xl font-bold tracking-wider">GIS MANAGER</h1>
            <p class="text-xs text-slate-400 mt-1">بوابة التحكم الإدارية</p>
        </div>
        
        <nav class="p-4 space-y-2 text-right">
            <!-- 1. زر بوابة النظام الرئيسية (متاح لجميع المستخدمين) -->
            <a href="index.php?page=home-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'home-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-house w-5 text-center text-slate-300"></i>
                <span>بوابة النظام الرئيسية</span>
            </a>

            <hr class="border-slate-700 my-2">

            <!-- 2. إضافة سجل جديد -->
            <?php if ($role === 'admin' || in_array('add-record', $user_allowed_pages)): ?>
            <a href="index.php?page=add-record" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'add-record' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-plus-circle w-5 text-center text-slate-300"></i>
                <span>إضافة سجل جديد</span>
            </a>
            <?php endif; ?>
            
            <!-- 3. الخريطة التفاعلية -->
            <?php if ($role === 'admin' || in_array('map-view', $user_allowed_pages)): ?>
            <a href="index.php?page=map-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'map-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-map-location-dot w-5 text-center text-slate-300"></i>
                <span>الخريطة التفاعلية</span>
            </a>
            <?php endif; ?>
            
            <!-- 4. إدارة السجلات الميدانية -->
            <?php if ($role === 'admin' || in_array('records-manage', $user_allowed_pages)): ?>
            <a href="index.php?page=records-manage" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'records-manage' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-file-invoice w-5 text-center text-slate-300"></i>
                <span>إدارة السجلات الميدانية</span>
            </a>
            <?php endif; ?>

            <!-- 5. لوحة الإحصائيات والمؤشرات (Dashboard) -->
            <?php if ($role === 'admin' || in_array('dashboard-view', $user_allowed_pages)): ?>
            <a href="index.php?page=dashboard-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'dashboard-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-chart-pie w-5 text-center text-slate-300"></i>
                <span>لوحة المؤشرات البيانية</span>
            </a>
            <?php endif; ?>

            <!-- 6. منشئ ومستخرج التقارير -->
            <?php if ($role === 'admin' || in_array('reports-view', $user_allowed_pages)): ?>
            <a href="index.php?page=reports-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'reports-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-table-list w-5 text-center text-slate-300"></i>
                <span>منشئ التقارير التفصيلية</span>
            </a>
            <?php endif; ?>

            <!-- 7. موديول الصادر والوارد وتتبع الحركة المستندية -->
            <?php if ($role === 'admin' || in_array('transfers-view', $user_allowed_pages)): ?>
            <a href="index.php?page=transfers-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'transfers-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-right-left w-5 text-center text-blue-400"></i>
                <span>الصادر والوارد</span>
            </a>
            <?php endif; ?>

            <!-- 8. موديول الاستيراد الميداني وتنزيل قوالب إكسل المخصصة -->
            <?php if ($role === 'admin' || in_array('import-view', $user_allowed_pages)): ?>
            <a href="index.php?page=import-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'import-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-file-import w-5 text-center text-emerald-400"></i>
                <span>استيراد وتنزيل القوالب</span>
            </a>
            <?php endif; ?>

            <!-- 9. موديول التصدير الجغرافي والـ KML لجوجل إيرث -->
            <?php if ($role === 'admin' || in_array('export-view', $user_allowed_pages)): ?>
            <a href="index.php?page=export-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'export-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-file-export w-5 text-center text-orange-400"></i>
                <span>مركز تصدير التقارير KML</span>
            </a>
            <?php endif; ?>
            
            <?php if ($role === 'admin'): ?>
            <hr class="border-slate-700 my-2">
            
            <!-- 10. موديول إدارة المستخدمين والصلاحيات (أدمن فقط) -->
            <a href="index.php?page=users-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'users-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-users-gear w-5 text-center text-slate-300"></i>
                <span>إدارة المستخدمين</span>
            </a>
            
            <!-- 11. سجل الأنشطة والرقابة والعمليات الإدارية (أدمن فقط) -->
            <a href="index.php?page=audit-logs" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'audit-logs' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-clock-rotate-left w-5 text-center text-slate-300"></i>
                <span>سجل أنشطة النظام</span>
            </a>
            
            <!-- 12. محرك دمج وإصلاح وتطهير السجلات الفوري الجديد (أدمن فقط) -->
            <a href="index.php?page=data-repair" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'data-repair' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-wand-magic-sparkles w-5 text-center text-emerald-450 animate-pulse"></i>
                <span>مطهّر ومصلح البيانات</span>
            </a>
            
            <!-- 13. النسخ الاحتياطي واسترجاع النظام الكلي والصيانة (أدمن فقط) -->
            <a href="index.php?page=backup-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'backup-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-database w-5 text-center text-slate-300"></i>
                <span>النسخ والاسترجاع</span>
            </a>
            
            <!-- 14. إعدادات وقوالب وتوقيعات الطباعة الرسمية (أدمن فقط) -->
            <a href="index.php?page=print-settings" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'print-settings' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-print w-5 text-center text-slate-300"></i>
                <span>إعدادات الطباعة</span>
            </a>
            
            <!-- 15. إعدادات النظام الفنية وباني الحقول الديناميكية (أدمن فقط) -->
            <a href="index.php?page=settings-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'settings-view' ? 'bg-blue-600' : ''; ?>">
                <i class="fa-solid fa-sliders w-5 text-center text-slate-300"></i>
                <span>إعدادات النظام</span>
            </a>
            <?php endif; ?>
        </nav>
    </div>
    
    <!-- زر الملف الشخصي (متاح دائماً لجميع الرتب والمستخدمين) -->
    <a href="index.php?page=profile-view" class="w-full flex items-center space-x-3 space-x-reverse px-4 py-2.5 rounded-lg hover:bg-slate-700 transition duration-200 <?php echo $currentPage === 'profile-view' ? 'bg-blue-600' : ''; ?>">
        <i class="fa-solid fa-user-gear w-5 text-center text-slate-300"></i>
        <span>الملف الشخصي</span>
    </a>
    
    <div class="p-4 border-t border-slate-700">
        <a href="logout.php" class="w-full flex items-center justify-center space-x-2 space-x-reverse bg-red-600 hover:bg-red-700 text-white py-2 px-4 rounded-lg transition duration-200 text-sm">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>تسجيل الخروج</span>
        </a>
    </div>
</aside>