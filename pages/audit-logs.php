<?php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية
if (isset($_SESSION['log_success_msg'])) { $message = $_SESSION['log_success_msg']; unset($_SESSION['log_success_msg']); }
if (isset($_SESSION['log_error_msg'])) { $error = $_SESSION['log_error_msg']; unset($_SESSION['log_error_msg']); }

// ----------------- [1. معالجة طلب تفريغ وتطهير السجل بالكامل (Truncate)] -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    try {
        // تفريغ وتصفير الجدول بالكامل وإعادة تصفير الـ Auto-increment
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("TRUNCATE TABLE `audit_logs`");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        
        // [اللمسة الأمنية الذكية]: كتابة أول سطر جديد فوراً في السجل الفارغ يوثق عملية مسحه ومن قام بها!
        logActivity($pdo, "تطهير سجل العمليات", "قام مدير النظام بمسح وتصفير سجل العمليات والرقابة بالكامل لتهيئة وتوفير مساحة السيرفر.");
        
        $_SESSION['log_success_msg'] = "تم تفريغ وتهيئة سجل الأنشطة والعمليات بنجاح.";
    } catch (PDOException $e) {
        $_SESSION['log_error_msg'] = "عذراً، فشل تفريغ السجل.";
    }
    header("Location: index.php?page=audit-logs");
    exit;
}

// ----------------- [2. بناء فلاتر التصفية الزمنية والمسؤول] -----------------
$where_clauses = [];
$params = [];

$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

if ($filter_user > 0) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $filter_user;
}
if (!empty($date_from)) {
    $where_clauses[] = "DATE(al.created_at) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where_clauses[] = "DATE(al.created_at) <= ?";
    $params[] = $date_to;
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// جلب وحصر السجلات بالترتيب التنازلي بناءً على الفلترة
try {
    $stmtLogs = $pdo->prepare("
        SELECT al.*, u.username, u.role
        FROM audit_logs al
        JOIN users u ON al.user_id = u.id
        $where_sql
        ORDER BY al.id DESC LIMIT 300
    ");
    $stmtLogs->execute($params);
    $logs = $stmtLogs->fetchAll();
} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

$users = $pdo->query("SELECT id, username FROM users ORDER BY id DESC")->fetchAll();
?>

<!-- التنبيهات -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تم التطهير', text: '<?php echo $message; ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'تنبيه خطأ', text: '<?php echo $error; ?>' }); });</script>
<?php endif; ?>

<div class="space-y-6 max-w-5xl mx-auto">

    <!-- أولاً: صندوق محرك البحث والفرز والمدد الزمنية وزر التطهير والمحو -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100">
        <form method="GET" action="index.php" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <input type="hidden" name="page" value="audit-logs">
            
            <!-- تصفية بالموظف المسؤول -->
            <div>
                <label class="block text-gray-500 text-xs font-semibold mb-1">الموظف القائم بالإجراء</label>
                <select name="filter_user" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none text-xs font-bold text-gray-700 bg-white">
                    <option value="">كل موظفي النظام</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $filter_user === intval($u['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- مدى زمني: من -->
            <div>
                <label class="block text-gray-500 text-xs font-semibold mb-1">من تاريخ الحدث</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-3 py-1.5 border border-gray-200 rounded-lg focus:outline-none text-xs text-gray-700">
            </div>

            <!-- مدى زمني: إلى -->
            <div>
                <label class="block text-gray-500 text-xs font-semibold mb-1">إلى تاريخ الحدث</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-3 py-1.5 border border-gray-200 rounded-lg focus:outline-none text-xs text-gray-700">
            </div>

            <!-- أزرار الإجراءات التفاعلية والتطهير -->
            <div class="flex space-x-2 space-x-reverse justify-end">
                <a href="index.php?page=audit-logs" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2 px-3 rounded-lg text-xs transition">إعادة تعيين</a>
                
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>تطبيق الفلترة</span>
                </button>

                <!-- زر تصدير التقرير الرقابي لـ PDF -->
                <button type="button" onclick="printAuditLogsPDF()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm">
                    <i class="fa-solid fa-file-pdf"></i>
                    <span>تصدير تقرير PDF</span>
                </button>

                <!-- زر التطهير والمحو الكلي المحمي للـ Admin -->
                <button type="button" onclick="confirmPurgeLogs()" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm" title="تصفير وحذف السجل بالكامل لتهيئة السيرفر">
                    <i class="fa-solid fa-eraser"></i>
                    <span>تفريغ السجل</span>
                </button>
            </div>
        </form>
    </div>

    <!-- ثانياً: كارت عرض جدول سجل أنشطة النظام المصفى -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b pb-3">
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg">
                    <i class="fa-solid fa-user-shield text-xl"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">تفاصيل رصد العمليات والأنشطة الإدارية</h3>
                    <p class="text-[10px] text-gray-400">سجل أمني يوثق العمليات الإدارية والميدانية المجرية على المنصة.</p>
                </div>
            </div>
            
            <div class="flex items-center space-x-3 space-x-reverse">
                <!-- شريط البحث الفوري التفاعلي (Live Search) -->
                <div class="relative w-48 md:w-60">
                    <input type="text" id="live-search-logs" onkeyup="liveSearchLogs()" placeholder="البحث السريع في الأحداث..." class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 bg-gray-50/50">
                    <span class="absolute left-2.5 top-2 text-gray-400 text-[10px]"><i class="fa-solid fa-magnifying-glass"></i></span>
                </div>
                <span class="text-xs bg-slate-100 text-slate-700 font-bold px-3 py-1.5 rounded-full whitespace-nowrap">المطابقة: <span id="logs-visible-count"><?php echo count($logs); ?></span> حدث</span>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-100" id="audit-table-container">
            <table class="min-w-full divide-y divide-gray-200 text-right text-xs" id="audit-table">
                <thead class="bg-gray-50 text-gray-700 font-bold uppercase">
                    <tr>
                        <th class="px-6 py-3">رقم العملية</th>
                        <th class="px-6 py-3">الموظف المسؤول</th>
                        <th class="px-6 py-3">الصفة/الدور</th>
                        <th class="px-6 py-3">نوع العملية والتأثير</th>
                        <th class="px-6 py-3">تفاصيل الإجراء والمفتاح</th>
                        <th class="px-6 py-3">تاريخ ووقت الحدوث</th>
                    </tr>
                </thead>
                <tbody id="logs-table-body" class="bg-white divide-y divide-gray-100 text-gray-600 font-semibold">
                    <?php if (count($logs) === 0): ?>
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">لا توجد عمليات مطابقة لخيارات الفلترة المحددة.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): 
                            $action_text = $log['action'];
                            $badge_class = "bg-gray-100 text-gray-800"; // افتراضي
                            $icon = '<i class="fa-solid fa-circle-info ml-1 text-gray-400"></i>';

                            // تصنيف العمليات لفرزها بصرياً وتسهيل الرقابة للمدير
                            if (strpos($action_text, 'حذف') !== false || strpos($action_text, 'تطهير') !== false || strpos($action_text, 'إفراغ') !== false || strpos($action_text, 'تصفير') !== false) {
                                $badge_class = "bg-red-50 text-red-700 border border-red-100";
                                $icon = '<span class="text-red-500 ml-1">🚨</span>';
                            } elseif (strpos($action_text, 'إنشاء') !== false || strpos($action_text, 'إضافة') !== false || strpos($action_text, 'رفع') !== false) {
                                $badge_class = "bg-emerald-50 text-emerald-700 border border-emerald-100";
                                $icon = '<i class="fa-solid fa-circle-plus ml-1 text-emerald-500"></i>';
                            } elseif (strpos($action_text, 'تعديل') !== false || strpos($action_text, 'تحديث') !== false) {
                                $badge_class = "bg-amber-50 text-amber-700 border border-amber-100";
                                $icon = '<i class="fa-solid fa-pen ml-1 text-amber-500"></i>';
                            } elseif (strpos($action_text, 'دخول') !== false || strpos($action_text, 'تسجيل') !== false) {
                                $badge_class = "bg-blue-50 text-blue-700 border border-blue-100";
                                $icon = '<i class="fa-solid fa-right-to-bracket ml-1 text-blue-500"></i>';
                            }
                        ?>
                            <tr class="log-row hover:bg-gray-50/50 transition">
                                <td class="px-6 py-3 font-mono font-bold text-gray-400">#<?php echo $log['id']; ?></td>
                                <td class="px-6 py-3 font-bold text-slate-800"><i class="fa-regular fa-circle-user text-gray-300 ml-1.5 text-sm"></i><?php echo htmlspecialchars($log['username']); ?></td>
                                <td class="px-6 py-3">
                                    <?php if ($log['role'] === 'admin'): ?>
                                        <span class="bg-red-50 text-red-700 text-[10px] font-bold px-2 py-0.5 rounded">مدير نظام</span>
                                    <?php else: ?>
                                        <span class="bg-emerald-50 text-emerald-700 text-[10px] font-bold px-2 py-0.5 rounded">مسؤول ميداني</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-3">
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-[10px] font-bold <?php echo $badge_class; ?>">
                                        <?php echo $icon; ?>
                                        <span><?php echo htmlspecialchars($log['action']); ?></span>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-gray-500 font-mono text-[10px]"><?php echo htmlspecialchars($log['details']); ?></td>
                                <td class="px-6 py-3 font-semibold text-gray-400"><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- نموذج تفريغ السجل المخفي POST -->
<form id="purge-logs-form" method="POST" action="index.php?page=audit-logs" class="hidden">
    <input type="hidden" name="action" value="clear_logs">
</form>

<script>
    // نافذة تأكيد مزدوجة ومحمية لحماية السجل من المسح الخاطئ
    function confirmPurgeLogs() {
        Swal.fire({
            title: 'هل تريد مسح وتصفير السجل؟',
            text: "هذه العملية ستقوم بحذف كافة سجلات الأنشطة وتوقيتات الدخول والخروج والعمليات بالكامل لتهيئة السيرفر!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3b82f6',
            confirmButtonText: 'نعم، مسح وتصفير السجل بالكامل',
            cancelButtonText: 'إلغاء وتراجع'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('purge-logs-form').submit();
            }
        });
    }

    // دالة البحث الفوري التفاعلي (Live Search) عبر جافا سكريبت في السجل حالياً
    function liveSearchLogs() {
        const query = document.getElementById('live-search-logs').value.trim().toLowerCase();
        const rows = document.querySelectorAll('.log-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            if (text.includes(query)) {
                row.classList.remove('hidden');
                visibleCount++;
            } else {
                row.classList.add('hidden');
            }
        });

        document.getElementById('logs-visible-count').innerText = visibleCount;
    }

    // دالة تصدير تقرير الرقابة والأنشطة الإدارية كـ PDF منسق للغاية للجهات الإدارية
    function printAuditLogsPDF() {
        const printWindow = window.open('', '_blank');
        const tableHTML = document.getElementById('audit-table-container').innerHTML;
        
        let htmlContent = '<html lang="ar" dir="rtl"><head><title>سجل رقابة العمليات والأنشطة الإدارية</title>';
        htmlContent += '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">';
        htmlContent += '<script src="https://cdn.tailwindcss.com"><' + '/script>';
        htmlContent += '<style>body { font-family: "Cairo", sans-serif; padding: 40px; background: white; color: black; }';
        htmlContent += '.no-print, #live-search-logs, span.whitespace-nowrap { display: none !important; }';
        htmlContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }';
        htmlContent += 'th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: right; }';
        htmlContent += 'th { background-color: #f8fafc; }</style></head><body>';
        htmlContent += '<div class="text-center mb-6"><h1 class="text-lg font-bold text-gray-800">تقرير سجل رقابة العمليات والأنشطة الإدارية بالمنصة</h1>';
        htmlContent += '<span class="text-[10px] text-gray-400 block mt-1">تاريخ استخراج التقرير: ' + new Date().toISOString().substring(0, 10) + '</span></div>';
        
        // إزالة الحقول التي قد تحتوي على نصوص تصفية تفادياً لطباعتها بالخطأ
        let cleanTableHTML = tableHTML.replace(/<input[^>]*>/g, '');
        
        htmlContent += cleanTableHTML;
        htmlContent += '<' + 'script' + '>';
        htmlContent += 'window.addEventListener("DOMContentLoaded", () => { setTimeout(() => { window.print(); window.close(); }, 500); });';
        htmlContent += '<' + '/script' + '></body></html>';
        
        printWindow.document.write(htmlContent);
        printWindow.document.close();
    }
</script>