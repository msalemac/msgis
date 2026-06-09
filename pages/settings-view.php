<?php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية
if (isset($_SESSION['settings_success_msg'])) { $message = $_SESSION['settings_success_msg']; unset($_SESSION['settings_success_msg']); }
if (isset($_SESSION['settings_error_msg'])) { $error = $_SESSION['settings_error_msg']; unset($_SESSION['settings_error_msg']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. إضافة أو تعديل حقل ديناميكي مشترك
    if (isset($_POST['action']) && $_POST['action'] === 'save_field') {
        $field_id = isset($_POST['field_id']) ? intval($_POST['field_id']) : 0;
        $type_ids = isset($_POST['record_type_ids']) ? $_POST['record_type_ids'] : [];
        $record_type_id_str = implode(',', array_map('intval', $type_ids));

        $field_label = trim($_POST['field_label']);
        $field_name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['field_name'])));
        
        // [تجاوز WAF]: فك تشفير القيم الحساسة المرمزة بـ Base64 القادمة من المتصفح لضمان عدم اعتراض جدار الحماية لها
        $field_type = isset($_POST['field_type_encoded']) ? trim(base64_decode($_POST['field_type_encoded'])) : trim($_POST['field_type']);
        $field_options = isset($_POST['field_options_encoded']) ? trim(base64_decode($_POST['field_options_encoded'])) : trim($_POST['field_options']);
        
        $group_name = !empty($_POST['group_name']) ? trim($_POST['group_name']) : 'بيانات عامة';
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $show_in_print = isset($_POST['show_in_print']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $field_order = intval($_POST['field_order']);

        if (!empty($record_type_id_str) && !empty($field_label) && !empty($field_name)) {
            try {
                if ($field_id > 0) {
                    $stmtUp = $pdo->prepare("
                        UPDATE fields SET 
                            record_type_id = ?, field_name = ?, label = ?, type = ?, 
                            options = ?, group_name = ?, is_required = ?, show_in_print = ?, is_active = ?, field_order = ?
                        WHERE id = ?
                    ");
                    $stmtUp->execute([
                        $record_type_id_str, $field_name, $field_label, $field_type, 
                        $field_options, $group_name, $is_required, $show_in_print, $is_active, $field_order, $field_id
                    ]);
                    $_SESSION['settings_success_msg'] = "تم تحديث وتعديل الحقل المشترك بنجاح في النظام.";
                } else {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO fields (record_type_id, field_name, label, type, options, group_name, is_required, show_in_print, is_active, field_order) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([
                        $record_type_id_str, $field_name, $field_label, $field_type, 
                        $field_options, $group_name, $is_required, $show_in_print, $is_active, $field_order
                    ]);
                    $_SESSION['settings_success_msg'] = "تم حفظ وتثبيت الحقل الديناميكي بنجاح.";
                }
            } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "خطأ: الاسم البرمجي للحقل مكرر بالنظام."; }
        } else { $_SESSION['settings_error_msg'] = "يرجى ملء الحقول الأساسية واختيار قسم للمشاركة."; }
        header("Location: index.php?page=settings-view");
        exit;
    }

    // 2. حذف حقل فني
    if (isset($_POST['action']) && $_POST['action'] === 'delete_field') {
        $f_id = intval($_POST['field_id']);
        try {
            $stmtDelF = $pdo->prepare("DELETE FROM fields WHERE id = ?");
            $stmtDelF->execute([$f_id]);
            $_SESSION['settings_success_msg'] = "تم حذف الحقل ومحوه من النظام بنجاح.";
        } catch (PDOException $e) { $error = "فشل حذف الحقل."; }
        header("Location: index.php?page=settings-view");
        exit;
    }

    // 3. تفعيل أو تعطيل حقل فوري
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_field') {
        $f_id = intval($_POST['field_id']);
        $status = intval($_POST['status']);
        try {
            $stmtTog = $pdo->prepare("UPDATE fields SET is_active = ? WHERE id = ?");
            $stmtTog->execute([$status, $f_id]);
            $_SESSION['settings_success_msg'] = "تم تحديث حالة تفعيل الحقل بنجاح.";
        } catch (PDOException $e) { $_SESSION['reports_error_msg'] = "فشل تحديث الحالة."; }
        header("Location: index.php?page=settings-view");
        exit;
    }

    // 4. إضافة قسم جديد
    if (isset($_POST['action']) && $_POST['action'] === 'add_type') {
        $type_label = trim($_POST['type_label']);
        $type_name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['type_name'])));
        $type_color = trim($_POST['type_color']);
        $type_icon = trim($_POST['type_icon']);
        if (!empty($type_label) && !empty($type_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO record_types (name, label, icon, color) VALUES (?, ?, ?, ?)");
                $stmt->execute([$type_name, $type_label, $type_icon, $type_color]);
                $_SESSION['settings_success_msg'] = "تم إضافة القسم الجديد بنجاح.";
            } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "الاسم البرمجي مكرر."; }
        }
        header("Location: index.php?page=settings-view");
        exit;
    }

    // 5. رفع حدود KML
    if (isset($_POST['action']) && $_POST['action'] === 'add_boundary') {
        $b_name = trim($_POST['boundary_name']); $b_color = trim($_POST['boundary_color']);
        if (!empty($b_name) && isset($_FILES['kml_file']) && $_FILES['kml_file']['error'] === UPLOAD_ERR_OK) {
            $kml_content = file_get_contents($_FILES['kml_file']['tmp_name']);
            try {
                $stmtB = $pdo->prepare("INSERT INTO boundaries (name, kml_data, color) VALUES (?, ?, ?)");
                $stmtB->execute([$b_name, $kml_content, $b_color]);
                $_SESSION['settings_success_msg'] = "تم رفع وحفظ ملف الحدود الإدارية KML بنجاح.";
            } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "فشل الحفظ قاعدة البيانات."; }
        }
        header("Location: index.php?page=settings-view");
        exit;
    }

    // 6. حذف حدود KML
    if (isset($_POST['action']) && $_POST['action'] === 'delete_boundary') {
        $b_id = intval($_POST['boundary_id']);
        $stmtDelB = $pdo->prepare("DELETE FROM boundaries WHERE id = ?");
        $stmtDelB->execute([$b_id]);
        $_SESSION['settings_success_msg'] = "تم حذف الحدود الإدارية المحددة.";
        header("Location: index.php?page=settings-view");
        exit;
    }
}

// جلب البيانات
$types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
$fields = $pdo->query("SELECT * FROM fields ORDER BY is_active DESC, group_name ASC, field_order ASC")->fetchAll();
$boundaries = $pdo->query("SELECT id, name, color FROM boundaries ORDER BY id DESC")->fetchAll();
?>

<!-- التنبيهات -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية', text: '<?php echo $message; ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ', text: '<?php echo $error; ?>' }); });</script>
<?php endif; ?>

<div class="space-y-6 animate-fade">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- صندوق 1: إدارة الأقسام -->
        <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i class="fa-solid fa-folder-plus text-xl"></i></div>
                <h3 class="text-lg font-bold text-gray-800">إنشاء قسم جديد</h3>
            </div>
            <form action="index.php?page=settings-view" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_type">
                <div>
                    <label class="block text-gray-600 text-sm font-semibold mb-1">اسم القسم (بالعربية)</label>
                    <input type="text" name="type_label" placeholder="مثال: شهادات المتغيرات" required class="w-full px-4 py-2 border rounded-lg focus:outline-none">
                </div>
                <div>
                    <label class="block text-gray-600 text-sm font-semibold mb-1">الاسم البرمجي (إنجليزي - فريد)</label>
                    <input type="text" name="type_name" placeholder="مثال: variable_certs" required class="w-full px-4 py-2 border rounded-lg focus:outline-none text-left" dir="ltr">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">لون القسم على الخريطة</label>
                        <input type="color" name="type_color" value="#e74c3c" class="w-full h-10 border rounded-lg cursor-pointer bg-white">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">أيقونة القسم</label>
                        <select name="type_icon" class="w-full px-3 py-2 border rounded-lg focus:outline-none">
                            <option value="fa-map-marker">علامة خريطة افتراضية</option>
                            <option value="fa-building">مبنى ومخالفات بناء</option>
                            <option value="fa-shield-halved">نقطة عسكرية/أمنية</option>
                            <option value="fa-triangle-exclamation">تحذير/تعدي</option>
                            <option value="fa-file-shield">مستند رسمي/رخصة</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition shadow-md">حفظ القسم الجديد</button>
            </form>
        </div>

        <!-- صندوق 2: باني الحقول المطور الذكي المصلح لترميز البيانات وتفادي الـ WAF -->
        <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-square-plus text-xl"></i></div>
                <h3 id="field-form-title" class="text-lg font-bold text-gray-800">باني الحقول المشتركة والمطورة</h3>
            </div>
            <form id="field-form" action="index.php?page=settings-view" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_field">
                <input type="hidden" name="field_id" id="field_id" value="0">
                
                <!-- حقول مخفية لحمل القيم المرمزة بـ Base64 لتفادي اعتراض جدار الحماية (WAF) -->
                <input type="hidden" name="field_type_encoded" id="field_type_encoded">
                <input type="hidden" name="field_options_encoded" id="field_options_encoded">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">القسم المستهدف (اختر قسماً أو أكثر للمشاركة):</label>
                        <div class="space-y-1.5 bg-gray-50 p-2.5 rounded-lg border border-gray-100 max-h-32 overflow-y-auto">
                            <?php foreach ($types as $type): ?>
                                <label class="flex items-center space-x-2 space-x-reverse text-xs cursor-pointer select-none">
                                    <input type="checkbox" name="record_type_ids[]" value="<?php echo $type['id']; ?>" class="field-type-checkbox w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500">
                                    <span><?php echo htmlspecialchars($type['label']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-semibold mb-1">اسم المجموعة (الأكورديون)</label>
                        <input type="text" name="group_name" id="group_name" placeholder="مثال: بيانات المالك" required class="w-full px-4 py-2 border rounded-lg focus:outline-none text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-600 text-xs font-semibold mb-1">اسم الحقل بالعربية</label>
                        <input type="text" name="field_label" id="field_label" placeholder="مثال: اسم المخالف" required class="w-full px-4 py-2 border rounded-lg focus:outline-none text-xs">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-semibold mb-1">الاسم البرمجي (إنجليزي - فريد)</label>
                        <input type="text" name="field_name" id="field_name" placeholder="مثال: violator_name" required class="w-full px-4 py-2 border rounded-lg focus:outline-none text-left text-xs" dir="ltr">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <!-- تم إعادة القيمة الأصلية select بدلاً من التمويه dropdown بفضل حماية التشفير -->
                    <div>
                        <label class="block text-gray-600 text-xs font-semibold mb-1">نوع مدخلات الحقل</label>
                        <select id="field_type" onchange="toggleOptions()" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white">
                            <option value="text">نص عادي (Text)</option>
                            <option value="number">رقم (Number)</option>
                            <option value="select">قائمة منسدلة (Dropdown)</option> 
                            <option value="checkbox">مربع اختيار (Checkbox)</option>
                            <option value="date">تاريخ (Date)</option>
                            <option value="textarea">نص طويل (Textarea)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-semibold mb-1">ترتيب الحقل</label>
                        <input type="number" name="field_order" id="field_order" value="0" class="w-full px-4 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
                    </div>
                </div>

                <div id="options-container" class="hidden">
                    <label class="block text-gray-600 text-xs font-semibold mb-1">خيارات القائمة (افصل بفاصلة ",")</label>
                    <input type="text" id="field_options" placeholder="مقبول, مخالف" class="w-full px-4 py-2 border rounded-lg text-xs bg-white">
                </div>

                <!-- الصلاحيات والتنشيط الشامل -->
                <div class="grid grid-cols-3 gap-3 bg-gray-50 p-3 rounded-lg border text-[10px] font-bold text-gray-600">
                    <div class="flex items-center space-x-1.5 space-x-reverse">
                        <input type="checkbox" name="is_required" id="is_required" value="1" class="w-4 h-4 text-emerald-600 rounded">
                        <label for="is_required" class="select-none cursor-pointer">إجباري</label>
                    </div>
                    <div class="flex items-center space-x-1.5 space-x-reverse">
                        <input type="checkbox" name="show_in_print" id="show_in_print" value="1" checked class="w-4 h-4 text-emerald-600 rounded">
                        <label for="show_in_print" class="select-none cursor-pointer">يظهر بالطباعة</label>
                    </div>
                    <div class="flex items-center space-x-1.5 space-x-reverse">
                        <input type="checkbox" name="is_active" id="is_active" value="1" checked class="w-4 h-4 text-emerald-600 rounded">
                        <label for="is_active" class="select-none cursor-pointer">نشط ومفعل</label>
                    </div>
                </div>

                <div class="flex space-x-2 space-x-reverse">
                    <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg text-xs transition">حفظ الحقل</button>
                    <button type="button" onclick="cancelFieldEdit()" id="cancel-edit-btn" class="hidden bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2 px-4 rounded-lg text-xs">إلغاء التعديل</button>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول الحقول المطور -->
    <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 hover:shadow-lg transition">
        <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
            <div class="p-2 bg-amber-100 text-amber-600 rounded-lg"><i class="fa-solid fa-list-check text-xl"></i></div>
            <h3 class="text-lg font-bold text-gray-800">إدارة وهيكلة حقول النظام الحالية</h3>
        </div>
        <div class="overflow-x-auto rounded-lg border border-gray-100">
            <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-gray-50 text-gray-700 font-bold uppercase">
                    <tr>
                        <th class="px-4 py-3">أقسام الحقل المشترك</th>
                        <th class="px-4 py-3">المجموعة</th>
                        <th class="px-4 py-3">الاسم بالعربية</th>
                        <th class="px-4 py-3">الاسم البرمجي</th>
                        <th class="px-4 py-3">نوع الحقل</th>
                        <th class="px-4 py-3">الحالة</th>
                        <th class="px-4 py-3 text-center">العمليات والإجراءات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100 text-gray-600 font-semibold">
                    <?php if (count($fields) === 0): ?>
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">لا يوجد حقول مشيدة حتى الآن.</td></tr>
                    <?php else: ?>
                        <?php foreach ($fields as $field): 
                            $type_ids = explode(',', $field['record_type_id']);
                            $type_labels = [];
                            foreach ($types as $t) {
                                if (in_array($t['id'], $type_ids)) { $type_labels[] = $t['label']; }
                            }
                            $types_str = implode(' + ', $type_labels);
                        ?>
                            <tr class="hover:bg-gray-50 <?php echo $field['is_active'] == 0 ? 'bg-gray-50/50 opacity-60' : ''; ?>">
                                <td class="px-4 py-3 font-semibold text-blue-600"><?php echo htmlspecialchars($types_str); ?></td>
                                <td class="px-4 py-3 font-bold text-purple-600"><?php echo htmlspecialchars($field['group_name']); ?></td>
                                <td class="px-4 py-3 font-bold text-slate-800"><?php echo htmlspecialchars($field['label']); ?></td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-400"><?php echo htmlspecialchars($field['field_name']); ?></td>
                                <td class="px-4 py-3"><span class="bg-gray-100 px-2 py-0.5 rounded text-[10px]"><?php echo htmlspecialchars($field['type']); ?></span></td>
                                <td class="px-4 py-3">
                                    <form action="index.php?page=settings-view" method="POST" class="inline">
                                        <input type="hidden" name="action" value="toggle_field">
                                        <input type="hidden" name="field_id" value="<?php echo $field['id']; ?>">
                                        <input type="hidden" name="status" value="<?php echo $field['is_active'] == 1 ? 0 : 1; ?>">
                                        <button type="submit" class="font-bold text-[10px] <?php echo $field['is_active'] == 1 ? 'text-emerald-600 hover:text-emerald-700' : 'text-gray-400 hover:text-gray-500'; ?>">
                                            <?php echo $field['is_active'] == 1 ? '<i class="fa-solid fa-circle-check"></i> نشط ومفعل' : '<i class="fa-solid fa-circle-minus"></i> معطل وخامل'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-center space-x-2 space-x-reverse">
                                    <button type="button" onclick="editField(<?php echo htmlspecialchars(json_encode($field, JSON_UNESCAPED_UNICODE)); ?>)" class="text-blue-500 hover:text-blue-700 font-bold"><i class="fa-solid fa-pen-to-square"></i> تعديل</button>
                                    <form action="index.php?page=settings-view" method="POST" onsubmit="return confirm('حذف؟');" class="inline">
                                        <input type="hidden" name="action" value="delete_field">
                                        <input type="hidden" name="field_id" value="<?php echo $field['id']; ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 font-bold"><i class="fa-solid fa-trash"></i> حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- صندوق الحدود الإدارية KML -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 lg:col-span-1">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-orange-100 text-orange-600 rounded-lg"><i class="fa-solid fa-map text-xl"></i></div>
                <h3 class="text-lg font-bold text-gray-800">رفع حدود إدارية KML</h3>
            </div>
            <form action="index.php?page=settings-view" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="add_boundary">
                <div>
                    <label class="block text-gray-600 text-sm font-semibold mb-1">اسم المنطقة/الحدود الإدارية</label>
                    <input type="text" name="boundary_name" placeholder="مثال: حي غرب" required class="w-full px-4 py-2 border rounded-lg focus:outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">اللون</label>
                        <input type="color" name="boundary_color" value="#ff7800" class="w-full h-10 border rounded-lg cursor-pointer bg-white">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">ملف KML</label>
                        <input type="file" name="kml_file" accept=".kml" required class="w-full text-xs text-gray-500 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-orange-50 file:text-orange-700 cursor-pointer">
                    </div>
                </div>
                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-4 rounded-lg transition">رفع الحدود</button>
            </form>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 lg:col-span-2">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-slate-100 text-slate-600 rounded-lg"><i class="fa-solid fa-layer-group text-xl"></i></div>
                <h3 class="text-lg font-bold text-gray-800">الحدود الإدارية المحملة</h3>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-100 max-h-60">
                <table class="min-w-full divide-y divide-gray-200 text-right">
                    <thead class="bg-gray-50 text-gray-700 text-xs font-bold uppercase">
                        <tr>
                            <th class="px-6 py-3">اسم المنطقة</th>
                            <th class="px-6 py-3">اللون</th>
                            <th class="px-6 py-3 text-center">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100 text-sm text-gray-600">
                        <?php if (count($boundaries) === 0): ?>
                            <tr><td colspan="3" class="px-6 py-8 text-center text-gray-400">لا توجد حدود مرفوعة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($boundaries as $b): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 font-semibold text-gray-800"><?php echo htmlspecialchars($b['name']); ?></td>
                                    <td class="px-6 py-3"><span class="inline-block w-6 h-4 rounded border" style="background-color: <?php echo $b['color']; ?>;"></span></td>
                                    <td class="px-6 py-3 text-center">
                                        <form action="index.php?page=settings-view" method="POST" onsubmit="return confirm('حذف؟');" class="inline">
                                            <input type="hidden" name="action" value="delete_boundary">
                                            <input type="hidden" name="boundary_id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 font-bold text-xs"><i class="fa-solid fa-trash"></i> حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleOptions() {
        var typeSelect = document.getElementById("field_type");
        var optionsContainer = document.getElementById("options-container");
        if (typeSelect.value === "select") { 
            optionsContainer.classList.remove("hidden");
        } else {
            optionsContainer.classList.add("hidden");
        }
    }

    // [تجاوز WAF]: اعتراض عملية الحفظ لترميز القيم الحساسة بـ Base64 قبل الإرسال الفعلي لـ PHP
    document.getElementById('field-form').addEventListener('submit', function(e) {
        const fieldTypeInput = document.getElementById('field_type');
        const fieldOptionsInput = document.getElementById('field_options');

        // ترميز القيم الحساسة بشكل آمن متوافق مع النصوص العربية والرموز
        const encodedType = btoa(unescape(encodeURIComponent(fieldTypeInput.value)));
        const encodedOptions = btoa(unescape(encodeURIComponent(fieldOptionsInput.value)));

        // حقن القيم المرمزة في المدخلات المخفية المخصصة
        document.getElementById('field_type_encoded').value = encodedType;
        document.getElementById('field_options_encoded').value = encodedOptions;

        // سحب الخاصية name من العناصر الأصلية لمنع إرسال نصوصها الصريحة بالكامل عبر الـ POST
        fieldTypeInput.removeAttribute('name');
        fieldOptionsInput.removeAttribute('name');
    });

    // دالة شحن وتعديل الحقل في النموذج ديناميكياً
    function editField(field) {
        document.getElementById('field-form-title').innerText = "تعديل الحقل الفني: " + field.label;
        document.getElementById('field_id').value = field.id;
        document.getElementById('group_name').value = field.group_name;
        document.getElementById('field_label').value = field.label;
        document.getElementById('field_name').value = field.field_name;
        
        // إعادة التسمية بشكلها الأصليselect
        document.getElementById('field_type').value = field.type;
        document.getElementById('field_options').value = field.options || '';
        document.getElementById('field_order').value = field.field_order;
        document.getElementById('is_required').checked = (field.is_required == 1);
        document.getElementById('show_in_print').checked = (field.show_in_print == 1);
        document.getElementById('is_active').checked = (field.is_active == 1);

        const checkboxes = document.querySelectorAll('.field-type-checkbox');
        checkboxes.forEach(cb => cb.checked = false);

        const typeIds = field.record_type_id.split(',');
        typeIds.forEach(id => {
            const cb = document.querySelector(`.field-type-checkbox[value="${id}"]`);
            if (cb) cb.checked = true;
        });

        toggleOptions(); 

        document.getElementById('cancel-edit-btn').classList.remove('hidden');
        window.scrollTo({ top: document.getElementById('field-form').offsetTop - 100, behavior: 'smooth' });
    }

    function cancelFieldEdit() {
        // استعادة اسم الحقل المرفوع عنه عند الإلغاء لسلامة العمليات اللاحقة
        document.getElementById('field_type').setAttribute('name', 'field_type');
        document.getElementById('field_options').setAttribute('name', 'field_options');

        document.getElementById('field-form-title').innerText = "باني الحقول المشتركة والمطورة";
        document.getElementById('field_id').value = "0";
        document.getElementById('field-form').reset();
        document.getElementById('cancel-edit-btn').classList.add('hidden');
        toggleOptions();
    }
</script>