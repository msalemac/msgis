<?php
// pages/records-manage.php - لوحة إدارة وجرد السجلات الميدانية (النسخة النهائية الفائقة لبيئات PHP 8.4)
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$role = $_SESSION['role'];
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';

$message = '';
$error = '';

// قراءة رسائل الجلسة المؤقتة ومسحها تلقائياً بعد العرض لثبات التنبيهات
if (isset($_SESSION['manage_success_msg'])) { $message = $_SESSION['manage_success_msg']; unset($_SESSION['manage_success_msg']); }
if (isset($_SESSION['manage_error_msg'])) { $error = $_SESSION['manage_error_msg']; unset($_SESSION['manage_error_msg']); }

/**
 * دالة إعادة التوجيه الفائقة والمقاومة لقيود البفر والـ Headers
 */
function safeRedirect($url) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header("Location: " . $url);
    } else {
        echo "<script>window.location.href='" . $url . "';</script>";
    }
    exit;
}

// معالجة الحذف الفردي الآمن تحت حماية CSRF المزدوجة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الموحد لتوكن حماية CSRF لمنع طلبات التزوير
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_record') {
        $del_id = intval($_POST['record_id'] ?? 0);
        if ($role === 'admin' || $role === 'editor') {
            try {
                // التحقق أمنياً من أن الموظف العادي لا يحذف سجلاً ينتمي لقسم محظور عنه
                $stmtCheck = $pdo->prepare("SELECT record_type_id, photo_path, pdf_path FROM records WHERE id = ?");
                $stmtCheck->execute([$del_id]);
                $record_data = $stmtCheck->fetch();

                if ($record_data) {
                    if ($role !== 'admin' && !in_array($record_data['record_type_id'], explode(',', $allowed_types))) {
                        die("محاولة تلاعب أمنية محظورة.");
                    }

                    // حذف الملفات المادية من السيرفر
                    if ($record_data['photo_path'] && file_exists($record_data['photo_path'])) { unlink($record_data['photo_path']); }
                    if ($record_data['pdf_path'] && file_exists($record_data['pdf_path'])) { unlink($record_data['pdf_path']); }

                    $stmtDel = $pdo->prepare("DELETE FROM records WHERE id = ?");
                    $stmtDel->execute([$del_id]);

                    // تسجيل الحذف في سجل الرقابة
                    logActivity($pdo, "حذف سجل ميداني", "قام المستخدم بحذف السجل الميداني رقم #" . $del_id);
                    $_SESSION['manage_success_msg'] = "تم حذف السجل ومرفقاته بنجاح من النظام الميداني.";
                }
            } catch (PDOException $e) { 
                $_SESSION['manage_error_msg'] = "فشل حذف السجل ارتباطه ببيانات أخرى."; 
            }
        } else { 
            $_SESSION['manage_error_msg'] = "ليس لديك صلاحية حذف السجلات الميدانية."; 
        }

        // تحويل المتصفح فوراً لإنهاء طلب POST ومنع تشوه الواجهة
        safeRedirect("index.php?page=records-manage");
    }
}

// بناء استعلام الفرز وحماية البيانات للأقسام المصرح بها
$where_clauses = [];
$params = [];

if ($role !== 'admin') {
    $where_clauses[] = "FIND_IN_SET(r.record_type_id, ?)";
    $params[] = $allowed_types;
}

$filter_type = isset($_GET['filter_type']) ? intval($_GET['filter_type']) : 0;
$search_query = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

if ($filter_type > 0) {
    if ($role !== 'admin' && !in_array($filter_type, explode(',', $allowed_types))) {
        die("محاولة تلاعب أمنية محظورة.");
    }
    $where_clauses[] = "r.record_type_id = ?";
    $params[] = $filter_type;
}
if (!empty($search_query)) {
    $where_clauses[] = "(r.dynamic_values LIKE ? OR u.username LIKE ? OR r.id = ?)";
    $params[] = '%' . $search_query . '%'; 
    $params[] = '%' . $search_query . '%'; 
    $params[] = intval($search_query);
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// حساب وإعداد التقسيم لصفحات لتسريع تحميل قاعدة البيانات
$count_query = "
    SELECT COUNT(*) 
    FROM records r
    JOIN record_types rt ON r.record_type_id = rt.id
    JOIN users u ON r.user_id = u.id
    $where_sql
";
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn() ?: 0;

$limit = 20; // عرض 20 سجلاً فقط بالصفحة
$total_pages = ceil($total_records / $limit);
$current_page = isset($_GET['p_num']) ? max(1, intval($_GET['p_num'])) : 1;
if ($current_page > $total_pages && $total_pages > 0) { $current_page = $total_pages; }
$offset = ($current_page - 1) * $limit;

// جلب وتصفية السجلات للصفحة الحالية فقط
$records_query = "
    SELECT r.*, rt.label AS type_label, rt.color, u.username 
    FROM records r
    JOIN record_types rt ON r.record_type_id = rt.id
    JOIN users u ON r.user_id = u.id
    $where_sql
    ORDER BY r.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($records_query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// جلب الأقسام الفنية المتاحة
if ($role === 'admin') {
    $types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
    $all_fields_for_columns = $pdo->query("SELECT field_name, label FROM fields WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
} else {
    $stmtT = $pdo->prepare("SELECT * FROM record_types WHERE FIND_IN_SET(id, ?) ORDER BY id DESC");
    $stmtT->execute([$allowed_types]);
    $types = $stmtT->fetchAll();

    $stmtF = $pdo->prepare("SELECT field_name, label FROM fields WHERE FIND_IN_SET(record_type_id, ?) AND is_active = 1 ORDER BY id ASC");
    $stmtF->execute([$allowed_types]);
    $all_fields_for_columns = $stmtF->fetchAll();
}

$print_templates = $pdo->query("SELECT id, template_name FROM print_templates ORDER BY id DESC")->fetchAll();
?>

<!-- التنبيهات الجمالية -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<div class="space-y-6 animate-fade text-right" dir="rtl">
    
    <!-- شريط العمليات الجماعية للتصدير الفوري (عائم وأنيق يظهر فقط عند تحديد سجلات من الجدول) -->
    <div id="bulk-action-panel" class="hidden bg-slate-900 text-white p-4 rounded-2xl shadow-md border border-slate-800 flex items-center justify-between transition-all duration-300">
        <div class="flex items-center space-x-3 space-x-reverse">
            <div class="p-2 bg-slate-800 text-blue-400 rounded-lg"><i class="fa-solid fa-right-left text-md"></i></div>
            <div>
                <span class="text-xs font-bold block">شريط العمليات الجماعية والتصدير الإداري</span>
                <span class="text-[10px] text-slate-400">تم تحديد عدد (<span id="checked-records-count" class="font-bold text-white font-mono text-sm">0</span>) سجلات ميدانية بنجاح.</span>
            </div>
        </div>
        <button type="button" onclick="runBulkTransfer()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-5 rounded-xl text-xs transition shadow-sm flex items-center space-x-1.5 space-x-reverse">
            <i class="fa-solid fa-right-left text-white"></i>
            <span class="text-white">تصدير كصادر جديد للإدارة</span>
        </button>
    </div>

    <!-- صندوق الفلترة السريعة وتخصيص الأعمدة المنسدلة الشامل -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 flex flex-wrap items-center justify-between gap-4">
        <form method="GET" action="index.php" class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <input type="hidden" name="page" value="records-manage">
            <div>
                <label class="block text-gray-500 text-xs font-semibold mb-1">القسم الميداني للفرز</label>
                <select name="filter_type" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none text-xs font-bold text-gray-700 bg-white">
                    <option value="">كل الأقسام المصرحة</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php echo $filter_type === intval($type['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-gray-500 text-xs font-semibold mb-1">البحث السريع</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="رقم السجل، الموظف، قيم الحقول..." class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none text-xs bg-white font-semibold">
            </div>
            <div class="flex space-x-2 space-x-reverse">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg text-xs transition flex items-center justify-center space-x-2 space-x-reverse shadow-sm">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>تطبيق البحث</span>
                </button>
                <a href="index.php?page=records-manage" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2 px-4 rounded-lg text-xs transition">إعادة تعيين</a>
            </div>
        </form>

        <div class="relative inline-block text-right">
            <button onclick="toggleManageColDropdown()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 px-4 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm">
                <i class="fa-solid fa-table-columns"></i>
                <span>تخصيص الأعمدة المعروضة</span>
            </button>
            <div id="manage-col-dropdown" class="absolute left-0 mt-2 w-64 rounded-xl shadow-2xl bg-white border border-gray-100 z-20 p-4 hidden text-gray-800 text-right">
                <span class="block text-xs font-bold text-gray-700 border-b pb-1 mb-2">إخفاء وإظهار أعمدة الإدارة</span>
                <div class="space-y-2 max-h-60 overflow-y-auto text-xs">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-id" onchange="toggleManageCol('col-id', this.checked)" checked class="w-4 h-4 rounded"><span>رقم المعاينة</span></label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-type" onchange="toggleManageCol('col-type', this.checked)" checked class="w-4 h-4 rounded"><span>القسم الميداني</span></label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-user" onchange="toggleManageCol('col-user', this.checked)" checked class="w-4 h-4 rounded"><span>الموظف المسؤول</span></label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-coords" onchange="toggleManageCol('col-coords', this.checked)" checked class="w-4 h-4 rounded"><span>الموقع الجغرافي</span></label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-attachments" onchange="toggleManageCol('col-attachments', this.checked)" checked class="w-4 h-4 rounded"><span>المرفقات</span></label>
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-date" onchange="toggleManageCol('col-date', this.checked)" checked class="w-4 h-4 rounded"><span>تاريخ التوثيق</span></label>
                    <hr class="my-2">
                    <?php foreach ($all_fields_for_columns as $f): ?>
                        <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                            <input type="checkbox" id="cb-col-<?php echo $f['field_name']; ?>" onchange="toggleManageCol('col-<?php echo $f['field_name']; ?>', this.checked)" class="w-4 h-4 rounded text-purple-600">
                            <span><?php echo htmlspecialchars($f['label']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول السجلات الرئيسي -->
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-sm font-extrabold text-slate-800">سجل التوثيقات والمعاينات الميدانية النشطة</h3>
            <span class="text-xs bg-slate-100 text-slate-700 font-bold px-3 py-1 rounded-full">المعروض حالياً: <?php echo count($records); ?> من <?php echo $total_records; ?> سجل</span>
        </div>
        <div class="overflow-x-auto">
            <table id="manage-records-table" class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-gray-50 text-gray-500 font-bold uppercase">
                    <tr>
                        <th class="px-4 py-3 text-center no-print w-10">
                            <input type="checkbox" id="select-all-records" onchange="toggleSelectAllRecords(this)" class="w-4 h-4 text-blue-600 rounded cursor-pointer">
                        </th>
                        <th data-column="col-id" class="px-6 py-4">رقم المعاينة</th>
                        <th data-column="col-type" class="px-6 py-4">القسم الميداني</th>
                        <th data-column="col-user" class="px-6 py-4">الموظف المسؤول</th>
                        <th data-column="col-coords" class="px-6 py-4">الموقع الجغرافي</th>
                        <th data-column="col-attachments" class="px-6 py-4">المرفقات والملفات</th>
                        <th data-column="col-date" class="px-6 py-4">تاريخ التوثيق</th>
                        
                        <?php foreach ($all_fields_for_columns as $f): ?>
                            <th data-column="col-<?php echo $f['field_name']; ?>" class="px-6 py-4 hidden text-purple-600 font-bold"><?php echo htmlspecialchars($f['label']); ?></th>
                        <?php endforeach; ?>

                        <th class="px-6 py-4 text-center font-bold">العمليات والإجراءات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100 text-gray-600 font-semibold">
                    <?php if (count($records) === 0): ?>
                        <tr><td colspan="20" class="px-6 py-12 text-center text-gray-400 font-bold text-sm">لا توجد سجلات معاينة حالياً بالقسم.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $rec): ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="px-4 py-3 text-center no-print border-b border-gray-100">
                                    <input type="checkbox" value="<?php echo $rec['id']; ?>" onchange="updateBulkActionPanel()" class="record-select-cb w-4 h-4 text-blue-600 rounded cursor-pointer">
                                </td>
                                <td data-column="col-id" class="px-6 py-4 font-mono font-bold text-gray-400">#<?php echo $rec['id']; ?></td>
                                <td data-column="col-type" class="px-6 py-4 font-bold text-slate-700">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full ml-1.5" style="background-color: <?php echo $rec['color'] ?: '#3085d6'; ?>;"></span>
                                    <?php echo htmlspecialchars($rec['type_label']); ?>
                                </td>
                                <td data-column="col-user" class="px-6 py-4 font-bold"><i class="fa-regular fa-user text-gray-300 ml-1"></i> <?php echo htmlspecialchars($rec['username']); ?></td>
                                <td data-column="col-coords" class="px-6 py-4 font-mono text-[10px]">
                                    <?php if ($rec['latitude'] && $rec['longitude']): ?>
                                        <span class="text-emerald-600 font-bold bg-emerald-50 px-2 py-0.5 rounded-full">Lat: <?php echo round($rec['latitude'], 5); ?>, Lng: <?php echo round($rec['longitude'], 5); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">غير محدد</span>
                                    <?php endif; ?>
                                </td>
                                <td data-column="col-attachments" class="px-6 py-4 space-x-1 space-x-reverse">
                                    <?php if ($rec['photo_path']): ?>
                                        <span class="bg-amber-100 text-amber-800 text-[10px] font-bold px-2 py-1 rounded-full">صورة</span>
                                    <?php endif; ?>
                                    <?php if ($rec['pdf_path']): ?>
                                        <span class="bg-red-100 text-red-800 text-[10px] font-bold px-2 py-1 rounded-full">PDF</span>
                                    <?php endif; ?>
                                </td>
                                <td data-column="col-date" class="px-6 py-4 text-gray-400 font-semibold"><?php echo date('Y-m-d H:i', strtotime($rec['created_at'])); ?></td>
                                
                                <?php 
                                $dyn_vals = json_decode($rec['dynamic_values'], true) ?: [];
                                foreach ($all_fields_for_columns as $f): 
                                    $val = isset($dyn_vals[$f['field_name']]) ? (string)$dyn_vals[$f['field_name']] : '-';
                                ?>
                                    <td data-column="col-<?php echo $f['field_name']; ?>" class="px-6 py-4 hidden font-bold text-gray-700"><?php echo htmlspecialchars($val); ?></td>
                                <?php endforeach; ?>

                                <td class="px-6 py-4 text-center space-x-1.5 space-x-reverse font-sans">
                                    <a href="index.php?page=view-record&id=<?php echo $rec['id']; ?>" class="inline-flex items-center p-2 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg transition" title="عرض السجل"><i class="fa-solid fa-eye text-sm text-blue-600"></i></a>
                                    <?php if ($rec['latitude'] && $rec['longitude']): ?>
                                        <a href="index.php?page=map-view&highlight=<?php echo $rec['id']; ?>" class="inline-flex items-center p-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-600 rounded-lg transition" title="تحديد بالخريطة"><i class="fa-solid fa-map-location-dot text-sm text-emerald-600"></i></a>
                                    <?php endif; ?>
                                    
                                    <button type="button" onclick="openPrintWizard(<?php echo $rec['id']; ?>)" class="inline-flex items-center p-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 rounded-lg transition" title="طباعة">
                                        <i class="fa-solid fa-print text-sm text-indigo-600"></i>
                                    </button>

                                    <?php if ($role === 'admin' || $role === 'editor'): ?>
                                        <a href="index.php?page=edit-record&id=<?php echo $rec['id']; ?>" class="inline-flex items-center p-2 bg-orange-50 hover:bg-orange-100 text-orange-600 rounded-lg transition" title="تعديل"><i class="fa-solid fa-pen-to-square text-sm text-orange-600"></i></a>
                                        <button onclick="confirmDelete(<?php echo $rec['id']; ?>)" class="inline-flex items-center p-2 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg transition" title="حذف"><i class="fa-solid fa-trash-can text-sm text-red-600"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- شريط التنقل الرقمي والتقسيم لصفحات (Pagination Bar) -->
        <?php if ($total_pages > 1): ?>
            <div class="bg-gray-50 px-6 py-4 flex items-center justify-between border-t border-gray-150 select-none text-xs">
                <div class="text-gray-550 font-semibold">
                    عرض المعاينات من <span class="font-bold text-slate-800 font-sans"><?php echo $offset + 1; ?></span> إلى <span class="font-bold text-slate-800 font-sans"><?php echo min($offset + $limit, $total_records); ?></span> من إجمالي <span class="font-bold text-slate-800 font-sans"><?php echo $total_records; ?></span> سجل موثق.
                </div>
                <div class="flex items-center space-x-1.5 space-x-reverse font-bold font-sans">
                    <?php if ($current_page > 1): ?>
                        <a href="index.php?page=records-manage&p_num=<?php echo $current_page - 1; ?>&filter_type=<?php echo $filter_type; ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 rounded-lg transition">&laquo; السابق</a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 border border-gray-150 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed">&laquo; السابق</span>
                    <?php endif; ?>

                    <?php 
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <a href="index.php?page=records-manage&p_num=<?php echo $i; ?>&filter_type=<?php echo $filter_type; ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 border rounded-lg transition <?php echo $i === $current_page ? 'bg-blue-600 border-blue-600 text-white shadow-sm' : 'border-gray-200 bg-white hover:bg-gray-50 text-gray-700'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="index.php?page=records-manage&p_num=<?php echo $current_page + 1; ?>&filter_type=<?php echo $filter_type; ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 rounded-lg transition">التالي &raquo;</a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 border border-gray-150 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed">التالي &raquo;</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- نموذج إرسال التصدير والتحويل الإداري الجماعي المخفي لـ transfers-view (محدث لحقن الـ CSRF Token) -->
<form id="bulk-transfer-form" action="index.php?page=transfers-view" method="POST" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="action" value="create_transfer">
    <input type="hidden" name="selected_ids" id="transfer_selected_ids">
    <input type="hidden" name="receiver_dept" id="transfer_receiver_dept">
    <input type="hidden" name="notes" id="transfer_notes">
</form>

<!-- نموذج الحذف المخفي (محدث لحقن الـ CSRF Token) -->
<form id="delete-form" method="POST" action="index.php?page=records-manage" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="action" value="delete_record">
    <input type="hidden" name="record_id" id="delete-record-id">
</form>

<script>
    const dynamicColumns = <?php echo json_encode($all_fields_for_columns, JSON_UNESCAPED_UNICODE); ?>;
    const printTemplates = <?php echo json_encode($print_templates, JSON_UNESCAPED_UNICODE); ?>;

    document.addEventListener("DOMContentLoaded", function() {
        loadManageColumns();
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.relative')) {
                document.getElementById('manage-col-dropdown').classList.add('hidden');
            }
        });
    });

    function openPrintWizard(recordId) {
        if (printTemplates.length === 0) {
            window.open('print.php?id=' + recordId, '_blank');
            return;
        }

        let optionsHTML = '';
        printTemplates.forEach(t => {
            optionsHTML += `<option value="${t.id}">${t.template_name}</option>`;
        });

        Swal.fire({
            title: 'اختر نموذج وقالب الطباعة',
            html: `<p class="text-xs text-gray-400 mb-2">حدد النموذج الإداري المعتمد لطباعة محضر هذا السجل:</p>
                   <select id="swal-template-id" class="w-full px-3 py-2 border rounded-lg text-xs font-bold text-gray-700 bg-white focus:outline-none font-sans">${optionsHTML}</select>`,
            showCancelButton: true,
            confirmButtonText: 'بدء الطباعة الرسمية',
            cancelButtonText: 'إلغاء وتراجع',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) {
                const templateId = document.getElementById('swal-template-id').value;
                window.open(`print.php?id=${recordId}&template_id=${templateId}`, '_blank');
            }
        });
    }

    function toggleManageColDropdown() {
        document.getElementById('manage-col-dropdown').classList.toggle('hidden');
    }

    function toggleManageCol(colName, isVisible) {
        document.querySelectorAll(`[data-column="${colName}"]`).forEach(el => {
            if (isVisible) { el.classList.remove('hidden'); }
            else { el.classList.add('hidden'); }
        });
        localStorage.setItem('manage-hide-' + colName, isVisible ? 'false' : 'true');
    }

    function loadManageColumns() {
        const columns = ['col-id', 'col-type', 'col-user', 'col-coords', 'col-attachments', 'col-date'];
        dynamicColumns.forEach(f => { columns.push('col-' + f.field_name); });

        columns.forEach(col => {
            const cb = document.getElementById('cb-' + col);
            let isVisible = true;
            
            if (col.startsWith('col-') && !['col-id', 'col-type', 'col-user', 'col-coords', 'col-attachments', 'col-date'].includes(col)) {
                isVisible = localStorage.getItem('manage-hide-' + col) === 'false';
            } else {
                isVisible = localStorage.getItem('manage-hide-' + col) !== 'true';
            }

            document.querySelectorAll(`[data-column="${col}"]`).forEach(el => {
                if (isVisible) { el.classList.remove('hidden'); }
                else { el.classList.add('hidden'); }
            });

            if (cb) { cb.checked = isVisible; }
        });
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'هل تريد الحذف نهائياً؟',
            text: "سيتم حذف السجل والمرفقات نهائياً من السيرفر!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3b82f6',
            confirmButtonText: 'احذفه!',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete-record-id').value = id;
                document.getElementById('delete-form').submit();
            }
        });
    }

    // تحديد وإلغاء تحديد كافة المعاينات بضغطة واحدة من الترويسة
    function toggleSelectAllRecords(source) {
        const checkboxes = document.querySelectorAll('.record-select-cb');
        checkboxes.forEach(cb => cb.checked = source.checked);
        updateBulkActionPanel();
    }

    // تحديث شريط الحالة العائم المخصص للعمليات الجماعية بناء على التحديد النشط
    function updateBulkActionPanel() {
        const checkedBoxes = document.querySelectorAll('.record-select-cb:checked');
        const panel = document.getElementById('bulk-action-panel');
        const countSpan = document.getElementById('checked-records-count');
        
        if (checkedBoxes.length > 0) {
            countSpan.innerText = checkedBoxes.length;
            panel.classList.remove('hidden');
        } else {
            panel.classList.add('hidden');
            document.getElementById('select-all-records').checked = false;
        }
    }

    // دالة معالجة التصدير التفاعلي الجماعي وبناء نافذة التحويل الإداري
    function runBulkTransfer() {
        const checkedBoxes = document.querySelectorAll('.record-select-cb:checked');
        const selectedIds = Array.from(checkedBoxes).map(cb => cb.value);

        if (selectedIds.length === 0) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى تحديد سجل واحد على الأقل للبدء بالتصدير.' });
            return;
        }

        let deptsHTML = '';
        const depts = <?php echo json_encode($types, JSON_UNESCAPED_UNICODE); ?>;
        depts.forEach(d => {
            deptsHTML += `<option value="${d.label}">${d.label}</option>`;
        });

        Swal.fire({
            title: 'إنشاء وتصدير صادر جماعي جديد',
            html: `
                <div class="text-right space-y-3 text-xs text-gray-700" dir="rtl">
                    <p class="text-blue-600 font-bold mb-2">جاري تحويل وتصدير عدد ( ${selectedIds.length} ) سجلات ميدانية مختارة للإدارة المستهدفة.</p>
                    <div>
                        <label class="block font-bold text-gray-650 mb-1">الإدارة أو الجهة المستلمة المعنية:</label>
                        <select id="swal_transfer_dept" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans">${deptsHTML}</select>
                    </div>
                    <div>
                        <label class="block font-bold text-gray-650 mb-1">ملاحظات تسليم المعاملة (اسم المندوب، رقم الخطاب):</label>
                        <textarea id="swal_transfer_notes" rows="2" placeholder="اكتب رقم وتاريخ المكاتبة أو اسم المندوب المستلم للملفات..." class="w-full px-3 py-2 border rounded-lg text-xs focus:outline-none"></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#4f46e5', // Indigo
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'تأكيد تصدير المعاملة فوراً',
            cancelButtonText: 'تراجع وإلغاء',
            preConfirm: () => {
                const dept = document.getElementById('swal_transfer_dept').value;
                const notes = document.getElementById('swal_transfer_notes').value.trim();
                if (!dept) {
                    Swal.showValidationMessage('يرجى تحديد الجهة أو الإدارة المستلمة للمعاملة!');
                }
                return { dept, notes };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const { dept, notes } = result.value;
                
                // شحن وتغذية مدخلات الاستمارة المخفية للـ POST
                document.getElementById('transfer_selected_ids').value = selectedIds.join(',');
                document.getElementById('transfer_receiver_dept').value = dept;
                document.getElementById('transfer_notes').value = notes;
                
                // إرسال استمارة التحويل لصفحة الصادر والوارد
                document.getElementById('bulk-transfer-form').submit();
            }
        });
    }
</script>