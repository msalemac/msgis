<?php
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$role = $_SESSION['role'];
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';
$user_allowed_pages = !empty($_SESSION['allowed_pages']) ? explode(',', $_SESSION['allowed_pages']) : [];

// [صمام الأمان]: التحقق أمنياً من رتبة المستخدم وصلاحياته قبل تحميل الصفحة
if ($role !== 'admin' && !in_array('transfers-view', $user_allowed_pages)) {
    die("عذراً، ليس لديك صلاحية دخول موديول الصادر والوارد.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية لثبات واستقرار التنبيهات
if (isset($_SESSION['transfers_success_msg'])) { $message = $_SESSION['transfers_success_msg']; unset($_SESSION['transfers_success_msg']); }
if (isset($_SESSION['transfers_error_msg'])) { $error = $_SESSION['transfers_error_msg']; unset($_SESSION['transfers_error_msg']); }

// ----------------- [1. معالجة عمليات الـ POST الخاصة بالتحويلات] -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // أ. معالجة طلب تصدير صادر جماعي جديد (قادم من records-manage)
    if (isset($_POST['action']) && $_POST['action'] === 'create_transfer') {
        $selected_ids_str = trim($_POST['selected_ids']);
        $receiver_dept = trim($_POST['receiver_dept']);
        $notes = trim($_POST['notes']);

        if (!empty($selected_ids_str) && !empty($receiver_dept)) {
            try {
                // إيجاد الإدارة الحالية (المرسلة) تلقائياً من أول سجل محدد لضمان مطابقة المنشأ
                $id_arr = explode(',', $selected_ids_str);
                $first_id = intval($id_arr[0]);
                
                $sender_dept = $pdo->query("
                    SELECT rt.label 
                    FROM records r 
                    JOIN record_types rt ON r.record_type_id = rt.id 
                    WHERE r.id = " . $first_id
                )->fetchColumn() ?: "الإدارة العامة";

                // إدراج شحنة الصادر بجدول التنقلات
                $stmt = $pdo->prepare("INSERT INTO record_transfers (record_ids, sender_dept, receiver_dept, operator_id, status, notes) VALUES (?, ?, ?, ?, 'pending', ?)");
                $stmt->execute([$selected_ids_str, $sender_dept, $receiver_dept, $_SESSION['user_id'], $notes]);
                $transfer_id = $pdo->lastInsertId();

                // تحديث حقل "حالة السجل" JSON تلقائياً لجميع السجلات المحددة دفعة واحدة بالنظام
                $clean_ids = implode(',', array_map('intval', $id_arr));
                $pdo->exec("UPDATE records SET dynamic_values = JSON_SET(dynamic_values, '$.record_status', 'صادر معلق إلى " . $receiver_dept . "') WHERE id IN ($clean_ids)");

                logActivity($pdo, "تصدير صادر جماعي", "قام المشرف بتصدير شحنة سجلات ميدانية معلقة برقم المعاملة إلى إدارة: " . $receiver_dept);
                $_SESSION['transfers_success_msg'] = "تم إنشاء وتصدير المعاملة كصادر معلق للإدارة بنجاح وجاري تتبع حركتها.";
            } catch (PDOException $e) {
                $_SESSION['transfers_error_msg'] = "فشل تصدير السجلات المحددة: " . $e->getMessage();
            }
        } else {
            $_SESSION['transfers_error_msg'] = "البيانات المرسلة مفقودة.";
        }
        header("Location: index.php?page=transfers-view");
        exit;
    }

    // ب. معالجة اعتماد واستلام وارد معلق ونقل حيازته للإدارة الجديدة تلقائياً
    if (isset($_POST['action']) && $_POST['action'] === 'approve_transfer') {
        $transfer_id = intval($_POST['transfer_id']);
        
        try {
            $transfer = $pdo->query("SELECT * FROM record_transfers WHERE id = " . $transfer_id)->fetch();
            
            if ($transfer) {
                // تحديث حالة المعاملة بجدول الصادر والوارد إلى (مستلم ومكتمل)
                $pdo->exec("UPDATE record_transfers SET status = 'approved' WHERE id = " . $transfer_id);

                // تحديث حقل "حالة السجل" JSON تلقائياً في السجلات ليتحول إلى (مستلم وفي الحيازة الحالية)
                $id_arr = explode(',', $transfer['record_ids']);
                $clean_ids = implode(',', array_map('intval', $id_arr));
                $pdo->exec("UPDATE records SET dynamic_values = JSON_SET(dynamic_values, '$.record_status', 'مستلم وفي حوزة " . $transfer['receiver_dept'] . "') WHERE id IN ($clean_ids)");

                logActivity($pdo, "تأكيد استلام وارد", "قام الموظف بتأكيد واستلام شحنة المعاملات الواردة رقم #{$transfer_id} ونقل حيازتها للإدارة: " . $transfer['receiver_dept']);
                $_SESSION['transfers_success_msg'] = "تم تأكيد واستلام المعاملات الواردة بنجاح، وتحديث حالتها القانونية آلياً بالنظام.";
            }
        } catch (PDOException $e) {
            $_SESSION['transfers_error_msg'] = "فشل تأكيد واستلام الشحنة الواردة.";
        }
        header("Location: index.php?page=transfers-view");
        exit;
    }
}

// ----------------- [2. جلب وتصنيف البيانات والوارد والصادر المعلق والمستلم المصفى] -----------------

// جلب الصادر المعلق (Pending Outbound)
$stmtOut = $pdo->prepare("
    SELECT rt.*, u.username 
    FROM record_transfers rt
    JOIN users u ON rt.operator_id = u.id
    WHERE rt.status = 'pending'
    ORDER BY rt.id DESC
");
$stmtOut->execute();
$pending_transfers = $stmtOut->fetchAll();

// جلب وتصفية الأقسام الفنية لرسم خيارات البحث
$types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();

// مخرجات تصفية وبحث الأرشيف المستلم (الصادر والوارد المكتمل للجهات والتاريخ من وإلى)
$where_clauses = ["rt.status = 'approved'"];
$paramsExp = [];

$filter_receiver_dept = isset($_GET['filter_receiver_dept']) ? trim($_GET['filter_receiver_dept']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

if (!empty($filter_receiver_dept)) {
    $where_clauses[] = "rt.receiver_dept = ?";
    $paramsExp[] = $filter_receiver_dept;
}
if (!empty($date_from)) {
    $where_clauses[] = "DATE(rt.created_at) >= ?";
    $paramsExp[] = $date_from;
}
if (!empty($date_to)) {
    $where_clauses[] = "DATE(rt.created_at) <= ?";
    $paramsExp[] = $date_to;
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// جلب حركة المعاملات المستلمة والمكتملة بالكامل مع الفلاتر الزمنية والفنية
$stmtApp = $pdo->prepare("
    SELECT rt.*, u.username 
    FROM record_transfers rt
    JOIN users u ON rt.operator_id = u.id
    $where_sql
    ORDER BY rt.id DESC LIMIT 150
");
$stmtApp->execute($paramsExp);
$approved_transfers = $stmtApp->fetchAll();

// حساب أرقام العدادات للكروت العلوية التفاعلية
$count_pending = count($pending_transfers);
$count_approved = count($approved_transfers);
?>

<!-- التنبيهات -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية', text: '<?php echo $message; ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ في المعاملة', text: '<?php echo $error; ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>

<div class="space-y-6 max-w-5xl mx-auto animate-fade">
    
    <!-- الكروت الإحصائية العلوية العصرية -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-150 flex items-center justify-between hover:shadow-md transition duration-200">
            <div class="space-y-1">
                <span class="text-[10px] text-gray-400 font-bold block">إجمالي حركات التنقل (المصفاة)</span>
                <span class="text-2xl font-black text-slate-800 font-sans"><?php echo ($count_pending + $count_approved); ?> حركة</span>
            </div>
            <div class="p-3 bg-blue-50 text-blue-600 rounded-xl"><i class="fa-solid fa-right-left text-lg"></i></div>
        </div>

        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-150 flex items-center justify-between hover:shadow-md transition duration-200">
            <div class="space-y-1">
                <span class="text-[10px] text-gray-400 font-bold block">المعاملات المعلقة (قيد التسليم)</span>
                <span class="text-2xl font-black text-amber-600 font-sans"><?php echo $count_pending; ?> معاملة</span>
            </div>
            <div class="p-3 bg-amber-50 text-amber-600 rounded-xl"><i class="fa-solid fa-clock text-lg"></i></div>
        </div>

        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between hover:shadow-md transition duration-200">
            <div class="space-y-1">
                <span class="text-[10px] text-gray-400 font-bold block">المعاملات المستلمة والمؤرشفة</span>
                <span class="text-2xl font-black text-emerald-600 font-sans"><?php echo $count_approved; ?> مستند</span>
            </div>
            <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl"><i class="fa-solid fa-circle-check text-lg"></i></div>
        </div>
    </div>

    <!-- لوحة عرض الصادر والوارد المعلق بانتظار التأكيد والاستلام -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
        <div class="flex items-center space-x-3 space-x-reverse border-b pb-3 border-gray-100">
            <div class="p-2 bg-amber-100 text-amber-600 rounded-lg"><i class="fa-solid fa-clock-rotate-left text-xl"></i></div>
            <div>
                <h3 class="text-sm font-bold text-gray-800">الوارد والصادر المعلق قيد المراجعة والاستلام</h3>
                <p class="text-[10px] text-gray-400">تظهر هنا الشحنات والمعاملات التي تم تصديرها وتنتظر تأكيد استلام الإدارة المستقبلة لتحديث حيازتها بالنظام.</p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-100">
            <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-gray-50 text-gray-700 font-bold">
                    <tr>
                        <th class="px-6 py-3">رقم المعاملة</th>
                        <th class="px-6 py-3">المعاينات المشمولة</th>
                        <th class="px-6 py-3">من إدارة (الصادر)</th>
                        <th class="px-6 py-3">إلى إدارة (الوارد)</th>
                        <th class="px-6 py-3">المسؤول عن الإرسال</th>
                        <th class="px-6 py-3">ملاحظات ومندوب التسليم</th>
                        <th class="px-6 py-3">تاريخ الإرسال</th>
                        <th class="px-6 py-3 text-center">تأكيد الاستلام والوارد</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100 text-gray-600 font-semibold">
                    <?php if ($count_pending === 0): ?>
                        <tr><td colspan="8" class="px-6 py-8 text-center text-gray-400">لا توجد معاملات صادر أو وارد معلقة حالياً بالإدارات.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pending_transfers as $t): 
                            $rec_ids = explode(',', $t['record_ids']);
                        ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="px-6 py-4 font-mono font-bold text-gray-400">#<?php echo $t['id']; ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <?php foreach ($rec_ids as $rid): ?>
                                            <a href="index.php?page=view-record&id=<?php echo $rid; ?>" target="_blank" class="bg-slate-100 hover:bg-slate-200 text-slate-800 text-[10px] font-bold px-1.5 py-0.5 rounded font-mono">#<?php echo $rid; ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-700"><?php echo htmlspecialchars($t['sender_dept']); ?></td>
                                <td class="px-6 py-4 font-bold text-blue-600"><?php echo htmlspecialchars($t['receiver_dept']); ?></td>
                                <td class="px-6 py-4 font-medium"><?php echo htmlspecialchars($t['username']); ?></td>
                                <td class="px-6 py-4 text-gray-400"><?php echo htmlspecialchars($t['notes'] ?: '-'); ?></td>
                                <td class="px-6 py-4 font-mono text-gray-400"><?php echo date('Y-m-d H:i', strtotime($t['created_at'])); ?></td>
                                <td class="px-6 py-4 text-center">
                                    <form action="index.php?page=transfers-view" method="POST" onsubmit="return confirmApproveTransfer(event, this)">
                                        <input type="hidden" name="action" value="approve_transfer">
                                        <input type="hidden" name="transfer_id" value="<?php echo $t['id']; ?>">
                                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-1 px-3 rounded text-[10px] transition shadow-sm flex items-center justify-center space-x-1 space-x-reverse mx-auto">
                                            <i class="fa-solid fa-check"></i>
                                            <span>اعتماد واستلام المعاملة</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- سجل الأرشيف والمستندات المكتملة والمستلمة بالكامل (مع محرك الفرز الفني والتاريخي المتطور) -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b pb-3 border-gray-100">
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-circle-check text-xl"></i></div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">أرشيف وجرد المعاملات المستلمة والمكتملة</h3>
                    <p class="text-[10px] text-gray-400">استخدم الفلاتر بالأسفل لجرد السجلات التي نُقلت لإدارة معينة خلال فترة زمنية محددة بدقة.</p>
                </div>
            </div>
        </div>

        <!-- [محرك الفرز والجرد المطور]: تصفية السجلات المنقولة إلى إدارة معينة من تاريخ وإلى تاريخ -->
        <form method="GET" action="index.php" class="grid grid-cols-1 md:grid-cols-4 gap-4 bg-gray-50/50 p-4 rounded-xl border items-end text-xs">
            <input type="hidden" name="page" value="transfers-view">
            
            <div>
                <label class="block text-gray-500 font-bold mb-1">الجهة/الإدارة المستلمة:</label>
                <select name="filter_receiver_dept" class="w-full px-2 py-1.5 border rounded-lg bg-white font-sans focus:outline-none">
                    <option value="">كل الإدارات المستلمة</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo $t['label']; ?>" <?php echo $filter_receiver_dept === $t['label'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-500 font-bold mb-1">من تاريخ التحويل:</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-2 py-1 border rounded-lg focus:outline-none">
            </div>

            <div>
                <label class="block text-gray-500 font-bold mb-1">إلى تاريخ التحويل:</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-2 py-1 border rounded-lg focus:outline-none">
            </div>

            <div class="flex space-x-2 space-x-reverse justify-end h-[34px]">
                <a href="index.php?page=transfers-view" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-1.5 px-4 rounded-lg text-xs transition">إعادة تعيين</a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-6 rounded-lg text-xs transition shadow-sm">تطبيق جرد الحركة</button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-gray-100">
            <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-gray-50 text-gray-700 font-bold">
                    <tr>
                        <th class="px-6 py-3">رقم المعاملة</th>
                        <th class="px-6 py-3">المعاينات المشمولة</th>
                        <th class="px-6 py-3">من إدارة (الصادر)</th>
                        <th class="px-6 py-3">إلى إدارة (الوارد)</th>
                        <th class="px-6 py-3">تاريخ ووقت الاستلام</th>
                        <th class="px-6 py-3">ملاحظات ومستلم العهدة</th>
                        <th class="px-6 py-3 text-center">حالة الحيازة</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100 text-gray-600 font-semibold">
                    <?php if ($count_approved === 0): ?>
                        <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">لا توجد معاملات مستلمة مطابقة للفلاتر المحددة حالياً.</td></tr>
                    <?php else: ?>
                        <?php foreach ($approved_transfers as $t): 
                            $rec_ids = explode(',', $t['record_ids']);
                        ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="px-6 py-4 font-mono font-bold text-gray-400">#<?php echo $t['id']; ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <?php foreach ($rec_ids as $rid): ?>
                                            <a href="index.php?page=view-record&id=<?php echo $rid; ?>" target="_blank" class="bg-slate-100 hover:bg-slate-200 text-slate-800 text-[10px] font-bold px-1.5 py-0.5 rounded font-mono">#<?php echo $rid; ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-gray-500"><?php echo htmlspecialchars($t['sender_dept']); ?></td>
                                <td class="px-6 py-4 font-bold text-slate-800"><?php echo htmlspecialchars($t['receiver_dept']); ?></td>
                                <td class="px-6 py-4 font-mono text-gray-400"><?php echo date('Y-m-d H:i', strtotime($t['created_at'])); ?></td>
                                <td class="px-6 py-4 text-gray-400"><?php echo htmlspecialchars($t['notes'] ?: '-'); ?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-100">
                                        <i class="fa-solid fa-circle-check ml-1"></i>
                                        <span>مستلمة بالقسم</span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
    function confirmApproveTransfer(event, form) {
        event.preventDefault();
        
        Swal.fire({
            title: 'هل تود تأكيد استلام هذه المعاملات؟',
            text: "تأكيد هذا الإجراء يعني تسلم قسمك للعهدة الورقية والملفات برسم رسمي وتحديث حيازة السجلات الجغرافية تلقائياً في قاعدة البيانات!",
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#10b981', // Emerald
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'تأكيد الاستلام ونقل الحيازة',
            cancelButtonText: 'إلغاء الإجراء'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }
</script>