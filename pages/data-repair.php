<?php
// pages/data-repair.php - محرك استعلام وإصلاح وتطهير البيانات (النسخة النهائية المؤمنة لبيئات PHP 8.4)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// دالة إعادة التوجيه الفائقة والمقاومة لقيود البفر والـ Headers في بيئة PHP 8.4
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

// جلب جميع الحقول النشطة لخيارات الفرز والدمج المعتمدة
$fields_list = $pdo->query("SELECT field_name, label FROM fields WHERE is_active = 1 ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_field = isset($_GET['target_field']) ? trim((string)$_GET['target_field']) : '';
$analysis_data = [];

// 1. في حال اختيار حقل معين: جلب القيم الفريدة وتكرارها من قاعدة البيانات
if (!empty($selected_field)) {
    // التأكد أمنياً من أن اسم الحقل يطابق الحقول المسجلة بالنظام لمنع ثغرات الـ SQL Injection
    $valid_fields = array_column($fields_list, 'field_name');
    if (in_array($selected_field, $valid_fields)) {
        $dbCol = "JSON_UNQUOTE(JSON_EXTRACT(dynamic_values, '$.$selected_field'))";
        
        try {
            $stmtAnalysis = $pdo->prepare("
                SELECT $dbCol AS val, COUNT(id) AS cnt 
                FROM records 
                WHERE $dbCol IS NOT NULL AND $dbCol != '' AND $dbCol != 'null'
                GROUP BY val
                ORDER BY cnt DESC
            ");
            $stmtAnalysis->execute();
            $analysis_data = $stmtAnalysis->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = "حدث خطأ أثناء قراءة قيم الحقل: " . $e->getMessage();
        }
    } else {
        $error = "الحقل المحدد غير صالح.";
        $selected_field = '';
    }
}

// 2. معالجة طلب الاستبدال والدمج الفوري (POST) تحت حماية CSRF المزدوجة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الإجباري من توكن حماية CSRF لحماية تطهير السجلات
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'execute_repair') {
        $field_name = trim((string)($_POST['field_name'] ?? ''));
        $old_value = trim((string)($_POST['old_value'] ?? ''));
        $new_value = trim((string)($_POST['new_value'] ?? ''));

        $valid_fields = array_column($fields_list, 'field_name');
        if (in_array($field_name, $valid_fields) && !empty($old_value) && !empty($new_value)) {
            try {
                $dbCol = "JSON_UNQUOTE(JSON_EXTRACT(dynamic_values, '$.$field_name'))";
                
                // استعلام التحديث المطور لدمج واستبدال القيم داخل كائن الـ JSON بدقة
                $stmtUpdate = $pdo->prepare("
                    UPDATE records 
                    SET dynamic_values = JSON_REPLACE(dynamic_values, '$.$field_name', ?) 
                    WHERE $dbCol = ?
                ");
                $stmtUpdate->execute([$new_value, $old_value]);
                $affected_rows = $stmtUpdate->rowCount();

                if ($affected_rows > 0) {
                    // تسجيل الإجراء بجدول الرقابة والأنشطة
                    logActivity($pdo, "تطهير ودمج بيانات", "قام المسؤول بدمج وتوحيد القيمة ('$old_value') إلى ('$new_value') في الحقل المخصص ($field_name) لعدد $affected_rows سجل.");
                    $_SESSION['settings_success_msg'] = "تمت العملية بنجاح! تم استبدال وتوحيد القيمة في عدد ($affected_rows) سجلات ميدانية بنجاح.";
                } else {
                    $_SESSION['settings_error_msg'] = "لم يتم العثور على أي سجلات مطابقة للقيمة القديمة المدخلة للاستبدال.";
                }
            } catch (PDOException $e) {
                $_SESSION['settings_error_msg'] = "فشل تنفيذ عملية الدمج بقاعدة البيانات: " . $e->getMessage();
            }
        } else {
            $_SESSION['settings_error_msg'] = "يرجى ملء جميع الحقول المطلوبة بشكل صحيح للبدء بالمعالجة.";
        }
        
        // إعادة توجيه قسرية لثبات وحفظ حالة الصفحة ومنع تشوه الواجهات
        safeRedirect("index.php?page=data-repair&target_field=" . $field_name);
    }
}

// قراءة التنبيهات من الجلسة
if (isset($_SESSION['settings_success_msg'])) { $message = $_SESSION['settings_success_msg']; unset($_SESSION['settings_success_msg']); }
if (isset($_SESSION['settings_error_msg'])) { $error = $_SESSION['settings_error_msg']; unset($_SESSION['settings_error_msg']); }
?>

<!-- التنبيهات التفاعلية بـ SweetAlert -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تم الإصلاح والدمج', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ في المعالجة', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<div class="max-w-4xl mx-auto space-y-6 animate-fade text-right" dir="rtl">
    
    <!-- كارت الفحص واختيار الحقل الفني -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 hover:shadow-lg transition duration-300">
        <div class="flex items-center space-x-3 space-x-reverse mb-5 border-b pb-3 border-gray-100">
            <div class="p-2 bg-indigo-100 text-indigo-600 rounded-lg">
                <i class="fa-solid fa-wand-magic-sparkles text-xl"></i>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-900">محرك استعلام وتطهير البيانات الفوري</h3>
                <p class="text-[10px] text-gray-400 font-bold">قم باختيار الحقل الفني ليقوم النظام بتحليل قيم الحقل وعرض تكرارها وتصحيحها فورياً.</p>
            </div>
        </div>

        <form method="GET" action="index.php" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <input type="hidden" name="page" value="data-repair">
            <div class="md:col-span-2">
                <label class="block text-slate-500 text-xs font-bold mb-1.5">اختر الحقل المراد فحصه وتحليل قيمه:</label>
                <select name="target_field" id="target_field" required class="w-full px-3 py-2 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 bg-white focus:outline-none">
                    <option value="">-- حدد الحقل المستهدف --</option>
                    <?php foreach ($fields_list as $f): ?>
                        <option value="<?php echo $f['field_name']; ?>" <?php echo $selected_field === $f['field_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-black py-2.5 rounded-xl text-xs transition shadow-md">
                <i class="fa-solid fa-chart-pie ml-1 text-blue-400"></i> تحليل وفحص قيم الحقل
            </button>
        </form>
    </div>

    <?php if (!empty($selected_field)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- أ. نتائج الفحص وجدول التكرارات الحالي -->
            <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100">
                <span class="text-xs font-black text-slate-900 block mb-3 border-b pb-2"><i class="fa-solid fa-list-ol text-blue-500 ml-1"></i> التكرارات الحالية للقيم بداخل الجدول:</span>
                <div class="overflow-x-auto rounded-xl border border-gray-150 max-h-80">
                    <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                        <thead class="bg-slate-100 text-slate-900 font-black">
                            <tr>
                                <th class="px-4 py-2.5">القيمة المخزنة</th>
                                <th class="px-4 py-2.5">التكرار بالسجلات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100 text-slate-800 font-bold">
                            <?php if (count($analysis_data) === 0): ?>
                                <tr><td colspan="2" class="px-4 py-6 text-center text-gray-400">لا توجد أي سجلات مدونة بهذا الحقل حالياً.</td></tr>
                            <?php else: ?>
                                <?php foreach ($analysis_data as $row): ?>
                                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="fillRepairValues('<?php echo htmlspecialchars($row['val']); ?>')">
                                        <td class="px-4 py-2.5 text-slate-950 font-black"><?php echo htmlspecialchars($row['val']); ?></td>
                                        <td class="px-4 py-2.5 font-mono text-indigo-600 font-black"><?php echo $row['cnt']; ?> سجل</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-[9px] text-gray-400 mt-2 font-bold"><i class="fa-solid fa-info-circle"></i> تلميح: يمكنك النقر فوق أي صف بالجدول لنقل القيمة تلقائياً إلى خانة الإصلاح.</p>
            </div>

            <!-- ب. فورم الإصلاح والدمج التلقائي السريع -->
            <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 flex flex-col justify-between">
                <div>
                    <span class="text-xs font-black text-slate-900 block mb-3 border-b pb-2"><i class="fa-solid fa-wrench text-emerald-500 ml-1"></i> معالج الاستبدال وتوحيد البيانات:</span>
                    <form id="repair-execution-form" action="index.php?page=data-repair" method="POST" class="space-y-4">
                        
                        <!-- حقل الأمان CSRF -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        
                        <input type="hidden" name="action" value="execute_repair">
                        <input type="hidden" name="field_name" value="<?php echo htmlspecialchars($selected_field); ?>">

                        <div>
                            <label class="block text-slate-650 text-xs font-bold mb-1.5">1. اكتب القيمة القديمة الخاطئة (المطلوب محوها):</label>
                            <input type="text" name="old_value" id="old_value" required placeholder="مثال: خارج 1ك" class="w-full px-4 py-2 border rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-1 focus:ring-red-500 bg-white">
                        </div>

                        <div>
                            <label class="block text-slate-650 text-xs font-bold mb-1.5">2. اكتب القيمة الصحيحة (التي سيتم الدمج بداخلها):</label>
                            <input type="text" name="new_value" id="new_value" required placeholder="مثال: خارج 1 ك" class="w-full px-4 py-2 border rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-1 focus:ring-emerald-500 bg-white">
                        </div>
                        
                        <p class="text-[10px] text-red-500 bg-red-50 p-2.5 rounded-lg border border-red-150 font-bold leading-relaxed">
                            <i class="fa-solid fa-triangle-exclamation"></i> تحذير: هذه العملية ستقوم باستبدال كافة السجلات التي تحتوي على القيمة القديمة داخل قاعدة البيانات فورياً ولا يمكن التراجع عنها.
                        </p>
                    </form>
                </div>

                <div class="pt-4 border-t mt-4">
                    <button type="button" onclick="confirmRepairSubmit()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-2.5 px-4 rounded-xl text-xs transition shadow-md flex items-center justify-center space-x-1.5 space-x-reverse">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>تنفيذ الدمج والاصلاح الفوري</span>
                    </button>
                </div>
            </div>

        </div>
    <?php endif; ?>
</div>

<script>
    // دالة تعبئة القيم تلقائياً عند النقر على الجدول
    function fillRepairValues(val) {
        document.getElementById('old_value').value = val;
        document.getElementById('new_value').focus();
    }

    // تأكيد الحفظ بنوافذ SweetAlert
    function confirmRepairSubmit() {
        const oldVal = document.getElementById('old_value').value.trim();
        const newVal = document.getElementById('new_value').value.trim();

        if (oldVal === "" || newVal === "") {
            Swal.fire({ icon: 'warning', title: 'حقول فارغة', text: 'يرجى كتابة القيمة القديمة والقيمة الجديدة للبدء بالدمج.' });
            return;
        }

        Swal.fire({
            title: 'هل أنت متأكد من تنفيذ التطهير؟',
            text: `جاري تحويل كافة السجلات التي تحمل القيمة (${oldVal}) إلى القيمة الموحدة (${newVal}) نهائياً!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#10b981', // Emerald
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'تأكيد التنفيذ الفوري',
            cancelButtonText: 'تراجع وإلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('repair-execution-form').submit();
            }
        });
    }
</script>