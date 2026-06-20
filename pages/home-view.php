<?php
// pages/home-view.php - البوابة الرئيسية التفاعلية للخدمات والموديولات (النسخة النهائية المحدثة لتشمل 15 موديول بالكامل)
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$user_allowed_pages = !empty($_SESSION['allowed_pages']) ? explode(',', (string)$_SESSION['allowed_pages']) : [];

// مصفوفة كروت الأقسام الشاملة المحدثة بجميع موديولات وخدمات المنصة الكلية (15 كارت تفاعلي منظم)
$portal_cards = [
    // أ. الموديولات التشغيلية والميدانية المتاحة للموظفين المصرح لهم
    'add-record' => [
        'title' => 'إضافة سجل جديد',
        'desc' => 'توثيق ورفع معاينة ميدانية جديدة بالـ GPS ومرفقات الصور والـ PDF الفورية.',
        'icon' => 'fa-plus-circle',
        'bg_color' => 'bg-blue-50 text-blue-600 border-blue-100 hover:border-blue-300'
    ],
    'map-view' => [
        'title' => 'الخريطة التفاعلية والحدود',
        'desc' => 'استعراض معالم السجلات جغرافياً على الخريطة وفلترتها بالتواريخ ورسم حدود KML.',
        'icon' => 'fa-map-location-dot',
        'bg_color' => 'bg-emerald-50 text-emerald-600 border-emerald-100 hover:border-emerald-300'
    ],
    'records-manage' => [
        'title' => 'إدارة السجلات الميدانية',
        'desc' => 'متابعة وتحديث السجلات وحذفها وتعديلها وإجراء المعاينات السريعة الجغرافية.',
        'icon' => 'fa-file-invoice',
        'bg_color' => 'bg-orange-50 text-orange-600 border-orange-100 hover:border-orange-300'
    ],
    'dashboard-view' => [
        'title' => 'لوحة المؤشرات والرسوم (Dashboard)',
        'desc' => 'تحليل فوري ورسوم بيانية ذكية (خطي ودائري) لمراقبة معدل النشاط بالأقسام والحقول.',
        'icon' => 'fa-chart-pie',
        'bg_color' => 'bg-indigo-50 text-indigo-600 border-indigo-100 hover:border-indigo-300'
    ],
    'reports-view' => [
        'title' => 'منشئ ومستخرج التقارير',
        'desc' => 'بناء تقارير تفصيلية ذكية، تصفية الأعمدة، البحث بالمعادلات، وتصدير PDF و XLS.',
        'icon' => 'fa-table-list',
        'bg_color' => 'bg-purple-50 text-purple-600 border-purple-100 hover:border-purple-300'
    ],
    'transfers-view' => [
        'title' => 'الصادر والوارد والحركة المستندية',
        'desc' => 'تصدير واستلام المعاينات الميدانية بين الإدارات المختلفة وتتبع حركتها وحيازتها آلياً.',
        'icon' => 'fa-right-left',
        'bg_color' => 'bg-sky-50 text-sky-600 border-sky-100 hover:border-sky-300'
    ],
    'import-view' => [
        'title' => 'معالج الاستيراد وتوطين القوالب',
        'desc' => 'قراءة ملفات البيانات (Excel/CSV) وتدقيقها يدوياً بشيت مدمج أو تلقائياً مع منع التكرار الثنائي.',
        'icon' => 'fa-file-import',
        'bg_color' => 'bg-teal-50 text-teal-600 border-teal-100 hover:border-teal-300'
    ],
    'export-view' => [
        'title' => 'مركز تصدير التقارير وخرائط KML',
        'desc' => 'تصدير السجلات جغرافياً للفترة والأقسام المحددة كخرائط مضلعة لجوجل إيرث أو جداول إكسل.',
        'icon' => 'fa-file-export',
        'bg_color' => 'bg-amber-50 text-amber-600 border-amber-100 hover:border-amber-300'
    ],

    // ب. موديولات إدارة وأمان النظام الخاصة بـ Admin فقط
    'users-view' => [
        'title' => 'إدارة الموظفين والصلاحيات',
        'desc' => 'تأسيس حسابات المشرفين، ضبط تصاريح الأقسام والموديولات وحل طلبات استعادة الكلمات.',
        'icon' => 'fa-users-gear',
        'bg_color' => 'bg-rose-50 text-rose-600 border-rose-100 hover:border-rose-300'
    ],
    'audit-logs' => [
        'title' => 'سجل أنشطة النظام الرقابي',
        'desc' => 'مراقبة العمليات والإجراءات الإدارية والميدانية المجرية على المنصة لضمان النزاهة الأمنية.',
        'icon' => 'fa-clock-rotate-left',
        'bg_color' => 'bg-slate-100 text-slate-600 border-slate-200 hover:border-slate-300'
    ],
    'backup-view' => [
        'title' => 'النسخ والاحتياط والصيانة',
        'desc' => 'توليد وضغط نسخ قواعد البيانات والملفات سحابياً، أداة الصيانة التطهيرية واسترجاع النظام.',
        'icon' => 'fa-database',
        'bg_color' => 'bg-cyan-50 text-cyan-600 border-cyan-100 hover:border-cyan-300'
    ],
    'data-repair' => [ // موديول مطهر ومصلح البيانات المطور (جديد ومدرج بالكامل)
        'title' => 'محرك إصلاح وتطهير البيانات الفوري',
        'desc' => 'محرك ذكي لاستكشاف وتصحيح وتوحيد الكلمات والحروف المكررة والخاطئة بضغطة زر.',
        'icon' => 'fa-wand-magic-sparkles',
        'bg_color' => 'bg-emerald-50 text-emerald-600 border-emerald-100 hover:border-emerald-300'
    ],
    'print-settings' => [
        'title' => 'تخصيص قوالب وإعدادات الطباعة',
        'desc' => 'بناء قوالب التقارير الورقية المعتمدة، ترويسات الصناديق، وتحديد التوقيعات الخمسة الرسمية.',
        'icon' => 'fa-print',
        'bg_color' => 'bg-violet-50 text-violet-600 border-violet-100 hover:border-violet-300'
    ],
    'settings-view' => [
        'title' => 'إعدادات النظام الفنية وباني الحقول',
        'desc' => 'التحكم في إعدادات المنصة الأساسية، إنشاء حقول الإدخال الديناميكية وتصنيف الأقسام الميدانية.',
        'icon' => 'fa-sliders',
        'bg_color' => 'bg-fuchsia-50 text-fuchsia-600 border-fuchsia-100 hover:border-fuchsia-300'
    ],

    // جـ. موديول الملف الشخصي المتاح لجميع الرتب
    'profile-view' => [
        'title' => 'إدارة ملفك الشخصي',
        'desc' => 'تعديل وتحديث بيانات حسابك الشخصي والبريد الإلكتروني وتغيير كلمة المرور الخاصة بك.',
        'icon' => 'fa-user-gear',
        'bg_color' => 'bg-gray-50 text-gray-600 border-gray-200 hover:border-gray-300'
    ]
];
?>

<div class="space-y-8 max-w-6xl mx-auto animate-fade text-right" dir="rtl">
    
    <!-- بوكس الترحيب الشامل والراقي بالمدير أو الموظف -->
    <div class="bg-gradient-to-r from-slate-800 to-slate-900 text-white p-8 rounded-3xl shadow-lg border border-slate-700 flex flex-col md:flex-row justify-between items-center gap-6">
        <div class="space-y-2 text-right">
            <h2 class="text-xl md:text-2xl font-extrabold">أهلاً بك في منصة GIS MANAGER الرقمية 👋</h2>
            <p class="text-xs text-slate-300 leading-normal max-w-2xl font-sans">هذه البوابة الرئيسية مصممة خصيصاً لتسهيل وصولك لأقسام وموديولات النظام المسموح لك بالعمل عليها. يرجى اختيار الإجراء المطلوب من الكروت أدناه لبدء العمل.</p>
        </div>
        <div class="text-left bg-slate-700/50 p-4 rounded-2xl border border-slate-600 min-w-[200px]">
            <span class="text-xs text-slate-300 block">المستخدم الحالي:</span>
            <span class="text-md font-bold text-white block"><i class="fa-solid fa-circle-user text-emerald-400 ml-1"></i> <?php echo htmlspecialchars($username); ?></span>
            <span class="text-[10px] text-gray-400 font-mono">الدور: <?php echo $role === 'admin' ? 'مدير نظام مطلق' : 'مسؤول ميداني مصرح'; ?></span>
        </div>
    </div>

    <!-- شبكة كروت البوابة الذكية (Dynamic Portal Cards Grid) -->
    <div class="space-y-4">
        <span class="text-xs text-gray-550 font-bold border-r-4 border-slate-800 pr-2 block">الخدمات والموديولات المصرح لك بدخولها:</span>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php 
            $rendered_cards = 0;
            // حظر الحقول وتأمينها رقابياً للأدمن فقط بداخل مصفوفة الحظر الشاملة
            $admin_only_modules = ['settings-view', 'users-view', 'print-settings', 'backup-view', 'audit-logs', 'data-repair'];
            $free_modules = ['profile-view'];

            foreach ($portal_cards as $slug => $card): 
                $show_card = false;

                // [صمام الأمان والتحقق من الصلاحيات والـ RBAC]: فرز ظهور الكروت الإدارية وتأمينها تلقائياً
                if ($role === 'admin') {
                    $show_card = true; // للمدير تظهر كافة الكروت والتحكمات
                } else {
                    if (in_array($slug, $free_modules)) {
                        $show_card = true; // متاح للجميع
                    } elseif (in_array($slug, $user_allowed_pages) && !in_array($slug, $admin_only_modules)) {
                        $show_card = true; // متاح للموظف العادي إذا تم منحه الصلاحية بالـ Sidebar وليست صفحة إدارية
                    }
                }

                if ($show_card): 
                    $rendered_cards++;
            ?>
                <!-- كرت تفاعلي فخم مع تأثيرات حركية هادئة وجذابة -->
                <a href="index.php?page=<?php echo $slug; ?>" class="bg-white p-6 rounded-2xl shadow-sm border <?php echo $card['bg_color']; ?> hover:shadow-xl hover:-translate-y-1 transform transition duration-300 flex flex-col justify-between h-56 group">
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <div class="p-3 bg-white rounded-xl shadow-inner group-hover:scale-110 transition duration-300">
                                <i class="fa-solid <?php echo $card['icon']; ?> text-2xl"></i>
                            </div>
                            <span class="text-[9px] bg-white border font-extrabold px-2 py-0.5 rounded-full shadow-inner">مصرح ومؤمن</span>
                        </div>
                        <h4 class="text-sm font-extrabold text-slate-800 tracking-wide font-sans"><?php echo $card['title']; ?></h4>
                        <p class="text-[10px] text-gray-400 leading-relaxed"><?php echo $card['desc']; ?></p>
                    </div>
                    <div class="text-left text-xs font-bold pt-2 flex items-center justify-end space-x-1 space-x-reverse group-hover:text-slate-800">
                        <span>دخول القسم</span>
                        <i class="fa-solid fa-arrow-left text-[10px] group-hover:-translate-x-1 transform transition"></i>
                    </div>
                </a>
            <?php 
                endif; 
            endforeach; 
            ?>

            <?php if ($rendered_cards === 0): ?>
                <div class="col-span-3 text-center py-12 bg-white rounded-2xl text-gray-400 font-bold">عذراً، لم يتم منحك صلاحية الوصول لأي موديول جانبي بالسيستم بعد. يرجى مراجعة إدارة الدعم الفني للمنصة.</div>
            <?php endif; ?>
        </div>
    </div>

</div>