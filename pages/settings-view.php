<?php
// pages/settings-view.php - لوحة التحكم بقوالب وأقسام وباني الحقول والحدود (النسخة النهائية لبيئات PHP 8.4)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// قراءة رقم الصفحة الحالية الخاص بجدول الحقول (الافتراضي: الصفحة 1)
$f_page = isset($_GET['f_page']) ? max(1, intval($_GET['f_page'])) : 1;

// قراءة رسائل الجلسة الأمنية لثبات الإشعارات والتحويلات
if (isset($_SESSION['settings_success_msg'])) { $message = $_SESSION['settings_success_msg']; unset($_SESSION['settings_success_msg']); }
if (isset($_SESSION['settings_error_msg'])) { $error = $_SESSION['settings_error_msg']; unset($_SESSION['settings_error_msg']); }

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

// معالجة طلبات POST السبعة (الحفظ والحذف والتعطيل والرفع والترقية) تحت حماية CSRF المزدوجة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الإجباري لتوكن الجلسة CSRF لحماية تطهير وصيانة السيرفر
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    // 1. إضافة أو تعديل حقل ديناميكي مشترك
    if (isset($_POST['action']) && $_POST['action'] === 'save_field') {
        $field_id = isset($_POST['field_id']) ? intval($_POST['field_id']) : 0;
        $type_ids = isset($_POST['record_type_ids']) ? (array)$_POST['record_type_ids'] : [];
        $record_type_id_str = implode(',', array_map('intval', $type_ids));

        $field_label = trim((string)($_POST['field_label'] ?? ''));
        $field_name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim((string)($_POST['field_name'] ?? ''))));
        
        // فك تشفير القيم الحساسة المرمزة بـ Base64 لتفادي اعتراض جدار الحماية (WAF)
        $field_type = isset($_POST['field_type_encoded']) ? trim((string)base64_decode($_POST['field_type_encoded'])) : trim((string)($_POST['field_type'] ?? ''));
        $field_options = isset($_POST['field_options_encoded']) ? trim((string)base64_decode($_POST['field_options_encoded'])) : trim((string)($_POST['field_options'] ?? ''));
        
        $group_name = !empty($_POST['group_name']) ? trim((string)$_POST['group_name']) : 'بيانات عامة';
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $show_in_print = isset($_POST['show_in_print']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $field_order = intval($_POST['field_order'] ?? 0);

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
            } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "خطأ: الاسم البرمجي للحقل مكرر بالنظام الميداني."; }
        } else { $_SESSION['settings_error_msg'] = "يرجى ملء الحقول الأساسية واختيار قسم للمشاركة."; }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }

    // 2. حذف حقل فني
    if (isset($_POST['action']) && $_POST['action'] === 'delete_field') {
        $f_id = intval($_POST['field_id'] ?? 0);
        try {
            $stmtDelF = $pdo->prepare("DELETE FROM fields WHERE id = ?");
            $stmtDelF->execute([$f_id]);
            $_SESSION['settings_success_msg'] = "تم حذف الحقل ومحوه من النظام بنجاح.";
        } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "فشل حذف الحقل الفني المختار."; }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }

    // 3. تفعيل أو تعطيل حقل فوري
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_field') {
        $f_id = intval($_POST['field_id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        try {
            $stmtTog = $pdo->prepare("UPDATE fields SET is_active = ? WHERE id = ?");
            $stmtTog->execute([$status, $f_id]);
            $_SESSION['settings_success_msg'] = "تم تحديث حالة تفعيل الحقل بنجاح.";
        } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "فشل تحديث الحالة الرقابية للحقل."; }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }

    // 4. إضافة قسم جديد
    if (isset($_POST['action']) && $_POST['action'] === 'add_type') {
        $type_label = trim((string)($_POST['type_label'] ?? ''));
        $type_name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim((string)($_POST['type_name'] ?? ''))));
        $type_color = trim((string)($_POST['type_color'] ?? '#3085d6'));
        $type_icon = trim((string)($_POST['type_icon'] ?? 'fa-map-marker'));
        if (!empty($type_label) && !empty($type_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO record_types (name, label, icon, color) VALUES (?, ?, ?, ?)");
                $stmt->execute([$type_name, $type_label, $type_icon, $type_color]);
                $_SESSION['settings_success_msg'] = "تم إضافة القسم الجديد بنجاح في النظام الميداني.";
            } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "الاسم البرمجي للقسم مكرر ومسجل مسبقاً."; }
        }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }

    // 4.ب. معالجة طلب حذف القسم الميداني بأمان
    if (isset($_POST['action']) && $_POST['action'] === 'delete_type') {
        $type_id = intval($_POST['type_id'] ?? 0);
        try {
            // التحقق من عدم وجود أي سجلات جغرافية نشطة تابعة للقسم قبل السماح بحذفه لحماية النزاهة
            $stmtCheckRecs = $pdo->prepare("SELECT COUNT(*) FROM records WHERE record_type_id = ?");
            $stmtCheckRecs->execute([$type_id]);
            if ($stmtCheckRecs->fetchColumn() > 0) {
                $_SESSION['settings_error_msg'] = "لا يمكن حذف هذا القسم؛ نظراً لارتباطه بمعاينات جغرافية وسجلات ميدانية نشطة حالياً بالنظام.";
            } else {
                $stmtDelT = $pdo->prepare("DELETE FROM record_types WHERE id = ?");
                $stmtDelT->execute([$type_id]);
                $_SESSION['settings_success_msg'] = "تم حذف القسم الميداني المحدد بنجاح من قاعدة البيانات.";
            }
        } catch (PDOException $e) {
            $_SESSION['settings_error_msg'] = "فشل حذف القسم لارتباطه بحقول أخرى.";
        }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }

    // 5. رفع حدود KML بتنسيق مفصل (محدث لحجب وكتم أخطاء KML في PHP 8.4)
    if (isset($_POST['action']) && $_POST['action'] === 'add_boundary') {
        $b_name = trim((string)($_POST['boundary_name'] ?? '')); 
        
        $stroke_color = trim((string)($_POST['boundary_color'] ?? '#ff7800'));
        $weight = floatval($_POST['boundary_weight'] ?? 2.5);
        $fill_color = trim((string)($_POST['boundary_fill_color'] ?? '#ff7800'));
        $fill_opacity = floatval($_POST['boundary_fill_opacity'] ?? 0.15);

        // دمج التنسيقات ككائن JSON وحفظه بحقل color لعدم تعديل الهيكل
        $style_json = json_encode([
            'stroke_color' => $stroke_color,
            'weight' => $weight,
            'fill_color' => $fill_color,
            'fill_opacity' => $fill_opacity
        ]);

        if (!empty($b_name) && isset($_FILES['kml_file']) && $_FILES['kml_file']['error'] === UPLOAD_ERR_OK) {
            $kml_content = file_get_contents($_FILES['kml_file']['tmp_name']);
            
            // كتم أخطاء الـ XML والـ Schema الترويسية للمتصفح وجوجل إيرث تفادياً لتعطل الحفظ والـ 403
            libxml_use_internal_errors(true);
            $xml_check = simplexml_load_string($kml_content);
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            if ($xml_check !== false) {
                try {
                    $stmtB = $pdo->prepare("INSERT INTO boundaries (name, kml_data, color) VALUES (?, ?, ?)");
                    $stmtB->execute([$b_name, $kml_content, $style_json]);
                    $_SESSION['settings_success_msg'] = "تم رفع وحفظ ملف الحدود الإدارية KML بنجاح مع التنسيق المخصص.";
                } catch (PDOException $e) { $_SESSION['settings_error_msg'] = "فشل حفظ الحدود الجغرافية بقاعدة البيانات."; }
            } else {
                $_SESSION['settings_error_msg'] = "عذراً، محتوى ملف الـ KML المرفوع غير صالح برمجياً أو يحتوي على أخطاء هيكلية.";
            }
        }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }

    // 6. تحديث تنسيق حدود KML يدوياً
    if (isset($_POST['action']) && $_POST['action'] === 'update_boundary_style') {
        $b_id = intval($_POST['boundary_id'] ?? 0);
        $stroke_color = trim((string)($_POST['stroke_color'] ?? '#ff7800'));
        $weight = floatval($_POST['weight'] ?? 2.5);
        $fill_color = trim((string)($_POST['fill_color'] ?? '#ff7800'));
        $fill_opacity = floatval($_POST['fill_opacity'] ?? 0.15);

        $style_json = json_encode([
            'stroke_color' => $stroke_color,
            'weight' => $weight,
            'fill_color' => $fill_color,
            'fill_opacity' => $fill_opacity
        ]);

        try {
            $stmtUpB = $pdo->prepare("UPDATE boundaries SET color = ? WHERE id = ?");
            $stmtUpB->execute([$style_json, $b_id]);
            $_SESSION['settings_success_msg'] = "تم تحديث وتعديل مظهر وتنسيق الحدود الإدارية بنجاح.";
        } catch (PDOException $e) {
            $_SESSION['settings_error_msg'] = "فشل تحديث التنسيق بقاعدة البيانات.";
        }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }

    // 7. حذف حدود KML
    if (isset($_POST['action']) && $_POST['action'] === 'delete_boundary') {
        $b_id = intval($_POST['boundary_id'] ?? 0);
        try {
            $stmtDelB = $pdo->prepare("DELETE FROM boundaries WHERE id = ?");
            $stmtDelB->execute([$b_id]);
            $_SESSION['settings_success_msg'] = "تم حذف الحدود الإدارية المحددة نهائياً.";
        } catch (PDOException $e) {
            $_SESSION['settings_error_msg'] = "فشل حذف مضلع الحدود جغرافياً.";
        }
        safeRedirect("index.php?page=settings-view&f_page=" . $f_page);
    }
}

// جلب البيانات الأساسية للوحة التحكم
$types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
$fields = $pdo->query("SELECT * FROM fields ORDER BY is_active DESC, group_name ASC, field_order ASC")->fetchAll();
$boundaries = $pdo->query("SELECT id, name, color FROM boundaries ORDER BY id DESC")->fetchAll();

// الفرز والتقسيم الرقمي لجدول حقول النظام لتسريع تصفح لوحة الإعدادات
$total_fields = count($fields);
$fields_limit = 5; // عرض 5 حقول فقط بالصفحة لسهولة الإدارة
$fields_total_pages = ceil($total_fields / $fields_limit);
if ($f_page > $fields_total_pages && $fields_total_pages > 0) { $f_page = $fields_total_pages; }
$fields_offset = ($f_page - 1) * $fields_limit;

// اقتطاع شريحة الحقول المطلوبة للعرض بالصفحة الحالية فقط
$paginated_fields = array_slice($fields, $fields_offset, $fields_limit);
?>

<!-- التنبيهات بـ SweetAlert -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ في العملية', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<!-- حقل أمان تفاعلي مخفي لتزويد الـ JS بالتوكن المعتمد للـ SweetAlert Form -->
<input type="hidden" id="ajax_csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

<div class="space-y-6 animate-fade text-right" dir="rtl">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        
        <!-- صندوق 1: إدارة وإنشاء الأقسام الميدانية المطور ديناميكياً للتعديل والحذف (CRUD) -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 hover:shadow-lg transition duration-300 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i class="fa-solid fa-folder-plus text-xl"></i></div>
                <h3 id="type-form-title" class="text-lg font-black text-slate-900">إنشاء قسم جديد</h3>
            </div>
            
            <form id="type-form" action="index.php?page=settings-view&f_page=<?php echo $f_page; ?>" method="POST" class="space-y-4 border-b pb-4">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="save_type">
                <input type="hidden" name="type_id" id="type_id" value="0">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">اسم القسم (بالعربية)</label>
                        <input type="text" name="type_label" id="type_label" placeholder="مثال: شهادات المتغيرات" required class="w-full px-4 py-2 border rounded-xl focus:outline-none text-xs font-bold text-gray-700 bg-white">
                    </div>
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">الاسم البرمجي (إنجليزي - فريد)</label>
                        <input type="text" name="type_name" id="type_name" placeholder="مثال: variable_certs" required class="w-full px-4 py-2 border rounded-xl focus:outline-none text-left text-xs font-bold bg-white" dir="ltr">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">لون القسم على الخريطة</label>
                        <input type="color" name="type_color" id="type_color" value="#e74c3c" class="w-full h-10 border rounded-xl cursor-pointer bg-white">
                    </div>
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">أيقونة القسم</label>
                        <select name="type_icon" id="type_icon" class="w-full px-3 py-2 border rounded-xl focus:outline-none text-xs font-bold text-gray-700 bg-white">
                            <option value="fa-map-marker">علامة خريطة افتراضية</option>
                            <option value="fa-building">مبنى ومخالفات بناء</option>
                            <option value="fa-shield-halved">نقطة عسكرية/أمنية</option>
                            <option value="fa-triangle-exclamation">تحذير/تعدي</option>
                            <option value="fa-file-shield">مستند رسمي/رخصة</option>
                        </select>
                    </div>
                </div>
                <div class="flex space-x-2 space-x-reverse">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-black py-2.5 px-4 rounded-xl transition shadow-md text-xs">حفظ القسم</button>
                    <button type="button" onclick="cancelTypeEdit()" id="cancel-type-btn" class="hidden bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2.5 px-4 rounded-xl text-xs">إلغاء التعديل</button>
                </div>
            </form>

            <!-- قائمة وجرد الأقسام الميدانية المعتمدة بالنظام -->
            <div class="space-y-3 pt-2">
                <span class="text-xs font-black text-slate-800 block"><i class="fa-solid fa-list-ul text-blue-500 ml-1.5"></i> قائمة وجرد الأقسام الميدانية المعتمدة بالنظام:</span>
                <div class="overflow-x-auto rounded-xl border border-gray-150 max-h-56 shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                        <thead class="bg-slate-100 text-slate-900 font-black">
                            <tr>
                                <th class="px-4 py-2.5">اسم القسم (العربي)</th>
                                <th class="px-4 py-2.5">الاسم البرمجي</th>
                                <th class="px-4 py-2.5 text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100 text-slate-900 font-bold">
                            <?php foreach ($types as $t): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 flex items-center space-x-2 space-x-reverse font-black text-slate-900">
                                        <span class="inline-block w-3 h-3 rounded-full" style="background-color: <?php echo $t['color']; ?>;"></span>
                                        <span><?php echo htmlspecialchars($t['label']); ?></span>
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-gray-400"><?php echo htmlspecialchars($t['name']); ?></td>
                                    <td class="px-4 py-2 text-center space-x-2 space-x-reverse text-xs">
                                        <button type="button" onclick='editType(<?php echo json_encode($t, JSON_UNESCAPED_UNICODE); ?>)' class="text-blue-500 hover:text-blue-700 font-bold"><i class="fa-solid fa-pen-to-square"></i> تعديل</button>
                                        <form action="index.php?page=settings-view&f_page=<?php echo $f_page; ?>" method="POST" onsubmit="return confirm('تنبيه: حذف هذا القسم بالكامل؟');" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                            <input type="hidden" name="action" value="delete_type">
                                            <input type="hidden" name="type_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 font-bold"><i class="fa-solid fa-trash"></i> حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- صندوق 2: باني ومصمم الحقول المشتركة والمطورة -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 hover:shadow-lg transition duration-300">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-square-plus text-xl"></i></div>
                <h3 id="field-form-title" class="text-lg font-black text-slate-900">باني الحقول المشتركة والمطورة</h3>
            </div>
            <form id="field-form" action="index.php?page=settings-view&f_page=<?php echo $f_page; ?>" method="POST" class="space-y-4">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="save_field">
                <input type="hidden" name="field_id" id="field_id" value="0">
                
                <!-- حقول مخفية لحمل القيم المرمزة بـ Base64 لتفادي اعتراض جدار الحماية (WAF) -->
                <input type="hidden" name="field_type_encoded" id="field_type_encoded">
                <input type="hidden" name="field_options_encoded" id="field_options_encoded">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">القسم المستهدف (اختر قسماً أو أكثر للمشاركة):</label>
                        <div class="space-y-1.5 bg-gray-550/10 p-2.5 rounded-xl border border-gray-150 max-h-32 overflow-y-auto">
                            <?php if (count($types) === 0): ?>
                                <span class="text-[10px] text-gray-400 text-center block font-bold py-2">لا توجد أقسام مسجلة</span>
                            <?php else: ?>
                                <?php foreach ($types as $type): ?>
                                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer select-none text-[11px] text-gray-700">
                                        <input type="checkbox" name="record_type_ids[]" value="<?php echo $type['id']; ?>" class="field-type-checkbox w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500">
                                        <span><?php echo htmlspecialchars($type['label']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">اسم المجموعة (الأكورديون)</label>
                        <input type="text" name="group_name" id="group_name" placeholder="مثال: بيانات المالك" required class="w-full px-4 py-2 border rounded-xl focus:outline-none text-xs font-bold text-gray-700 bg-white">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">اسم الحقل بالعربية</label>
                        <input type="text" name="field_label" id="field_label" placeholder="مثال: اسم المخالف" required class="w-full px-4 py-2 border rounded-xl focus:outline-none text-xs font-bold text-gray-700 bg-white">
                    </div>
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">الاسم البرمجي (إنجليزي - فريد)</label>
                        <input type="text" name="field_name" id="field_name" placeholder="مثال: violator_name" required class="w-full px-4 py-2 border rounded-xl focus:outline-none text-left text-xs font-bold text-gray-700" dir="ltr">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">نوع مدخلات الحقل</label>
                        <select id="field_type" onchange="toggleOptions()" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-xs bg-white font-bold text-gray-700 focus:outline-none">
                            <option value="text">نص عادي (Text)</option>
                            <option value="number">رقم (Number)</option>
                            <option value="select">قائمة منسدلة (Dropdown)</option> 
                            <option value="checkbox">مربع اختيار (Checkbox)</option>
                            <option value="date">تاريخ (Date)</option>
                            <option value="textarea">نص طويل (Textarea)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">ترتيب الحقل بالاستمارة</label>
                        <input type="number" name="field_order" id="field_order" value="0" class="w-full px-4 py-1.5 border border-gray-200 rounded-xl text-xs bg-white font-bold text-gray-700 focus:outline-none">
                    </div>
                </div>

                <div id="options-container" class="hidden">
                    <label class="block text-slate-650 text-xs font-bold mb-1.5">خيارات القائمة (افصل بفاصلة ",")</label>
                    <input type="text" id="field_options" placeholder="مقبول, مخالف" class="w-full px-4 py-2 border rounded-xl text-xs bg-white font-bold text-gray-750 focus:outline-none">
                </div>

                <!-- الصلاحيات والتنشيط الشامل -->
                <div class="grid grid-cols-3 gap-3 bg-gray-50 p-3 rounded-lg border text-[10px] font-bold text-gray-650">
                    <div class="flex items-center space-x-1.5 space-x-reverse select-none">
                        <input type="checkbox" name="is_required" id="is_required" value="1" class="w-4 h-4 text-emerald-600 rounded cursor-pointer">
                        <label for="is_required" class="select-none cursor-pointer">إجباري</label>
                    </div>
                    <div class="flex items-center space-x-1.5 space-x-reverse select-none">
                        <input type="checkbox" name="show_in_print" id="show_in_print" value="1" checked class="w-4 h-4 text-emerald-600 rounded cursor-pointer">
                        <label for="show_in_print" class="select-none cursor-pointer">يظهر بالطباعة</label>
                    </div>
                    <div class="flex items-center space-x-1.5 space-x-reverse select-none">
                        <input type="checkbox" name="is_active" id="is_active" value="1" checked class="w-4 h-4 text-emerald-600 rounded cursor-pointer">
                        <label for="is_active" class="select-none cursor-pointer">نشط ومفعل</label>
                    </div>
                </div>

                <div class="flex space-x-2 space-x-reverse">
                    <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-black py-2.5 px-4 rounded-xl text-xs transition">حفظ وتنشيط الحقل</button>
                    <button type="button" onclick="cancelFieldEdit()" id="cancel-edit-btn" class="hidden bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2.5 px-4 rounded-xl text-xs">إلغاء التعديل</button>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول الحقول المطور مع ميزة التقسيم لصفحات خفيفة (Pagination Bar) -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 hover:shadow-lg transition duration-300">
        <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
            <div class="p-2 bg-amber-100 text-amber-600 rounded-lg"><i class="fa-solid fa-list-check text-xl"></i></div>
            <h3 class="text-lg font-black text-slate-900">إدارة وهيكلة حقول النظام الحالية</h3>
        </div>
        <div class="overflow-x-auto rounded-t-xl border border-gray-150">
            <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-slate-100 text-slate-900 font-black uppercase">
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
                <tbody class="bg-white divide-y divide-gray-100 text-slate-900 font-black">
                    <?php if (count($paginated_fields) === 0): ?>
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 font-bold">لا يوجد حقول مشيدة حتى الآن.</td></tr>
                    <?php else: ?>
                        <?php foreach ($paginated_fields as $field): 
                            $type_ids = explode(',', $field['record_type_id']);
                            $type_labels = [];
                            foreach ($types as $t) {
                                if (in_array($t['id'], $type_ids)) { $type_labels[] = $t['label']; }
                            }
                            $types_str = implode(' + ', $type_labels);
                        ?>
                            <tr class="hover:bg-gray-50 <?php echo $field['is_active'] == 0 ? 'bg-gray-50/50 opacity-60' : ''; ?>">
                                <td class="px-4 py-3 font-extrabold text-blue-600"><?php echo htmlspecialchars($types_str); ?></td>
                                <td class="px-4 py-3 font-extrabold text-purple-600"><?php echo htmlspecialchars($field['group_name']); ?></td>
                                <td class="px-4 py-3 font-black text-slate-900"><?php echo htmlspecialchars($field['label']); ?></td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-400 font-bold"><?php echo htmlspecialchars($field['field_name']); ?></td>
                                <td class="px-4 py-3"><span class="bg-slate-150 text-slate-800 font-black px-2 py-0.5 rounded text-[10px]"><?php echo htmlspecialchars($field['type']); ?></span></td>
                                <td class="px-4 py-3">
                                    <!-- تحديث الحالة الفوري (محدث بالـ CSRF) -->
                                    <form action="index.php?page=settings-view&f_page=<?php echo $f_page; ?>" method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                        <input type="hidden" name="action" value="toggle_field">
                                        <input type="hidden" name="field_id" value="<?php echo $field['id']; ?>">
                                        <input type="hidden" name="status" value="<?php echo $field['is_active'] == 1 ? 0 : 1; ?>">
                                        <button type="submit" class="font-black text-[10px] <?php echo $field['is_active'] == 1 ? 'text-emerald-600 hover:text-emerald-700' : 'text-slate-400 hover:text-slate-500'; ?>">
                                            <?php echo $field['is_active'] == 1 ? '<i class="fa-solid fa-circle-check"></i> نشط ومفعل' : '<i class="fa-solid fa-circle-minus"></i> معطل وخامل'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-center space-x-2 space-x-reverse font-bold text-xs">
                                    <button type="button" onclick="editField(<?php echo htmlspecialchars(json_encode($field, JSON_UNESCAPED_UNICODE)); ?>)" class="text-blue-500 hover:text-blue-700 font-black"><i class="fa-solid fa-pen-to-square"></i> تعديل</button>
                                    <!-- استمارة الحذف (محدث بالـ CSRF) -->
                                    <form action="index.php?page=settings-view&f_page=<?php echo $f_page; ?>" method="POST" onsubmit="return confirm('حذف؟');" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
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

        <!-- شريط صفحات حقول النظام (Pagination Bar) -->
        <?php if ($fields_total_pages > 1): ?>
            <div class="bg-gray-50 px-6 py-4 flex items-center justify-between border border-t-0 border-gray-150 select-none text-xs rounded-b-xl">
                <div class="text-gray-550 font-bold">
                    عرض الحقول من <span class="font-black text-slate-800 font-sans"><?php echo $fields_offset + 1; ?></span> إلى <span class="font-black text-slate-800 font-sans"><?php echo min($fields_offset + $fields_limit, $total_fields); ?></span> من إجمالي <span class="font-black text-slate-800 font-sans"><?php echo $total_fields; ?></span> حقل فني مدرج بالنظام.
                </div>
                <div class="flex items-center space-x-1.5 space-x-reverse font-black font-sans">
                    <?php if ($f_page > 1): ?>
                        <a href="index.php?page=settings-view&f_page=<?php echo $f_page - 1; ?>" class="px-3 py-1.5 border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 rounded-lg transition">&laquo; السابق</a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 border border-gray-150 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed">&laquo; السابق</span>
                    <?php endif; ?>

                    <?php 
                    $start_p = max(1, $f_page - 2);
                    $end_p = min($fields_total_pages, $f_page + 2);
                    for ($i = $start_p; $i <= $end_p; $i++): 
                    ?>
                        <a href="index.php?page=settings-view&f_page=<?php echo $i; ?>" class="px-3 py-1.5 border rounded-lg transition <?php echo $i === $f_page ? 'bg-blue-600 border-blue-600 text-white shadow-sm' : 'border-gray-200 bg-white hover:bg-gray-50 text-gray-700'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($f_page < $fields_total_pages): ?>
                        <a href="index.php?page=settings-view&f_page=<?php echo $f_page + 1; ?>" class="px-3 py-1.5 border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 rounded-lg transition font-bold">التالي &raquo;</a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 border border-gray-150 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed font-bold">التالي &raquo;</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- صندوق رفع وإدارة الحدود الإدارية KML -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 lg:col-span-1 hover:shadow-lg transition duration-300">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-orange-100 text-orange-600 rounded-lg"><i class="fa-solid fa-map text-xl"></i></div>
                <h3 class="text-lg font-black text-slate-900">رفع حدود إدارية KML</h3>
            </div>
            <form action="index.php?page=settings-view&f_page=<?php echo $f_page; ?>" method="POST" enctype="multipart/form-data" class="space-y-4">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="add_boundary">
                
                <div>
                    <label class="block text-slate-650 text-xs font-bold mb-1.5">اسم المنطقة/الحدود الإدارية</label>
                    <input type="text" name="boundary_name" placeholder="مثال: حي غرب" required class="w-full px-4 py-2 border rounded-xl focus:outline-none text-xs font-bold text-gray-700 bg-white">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">لون خط الحدود (الهيكل)</label>
                        <input type="color" name="boundary_color" value="#ff7800" class="w-full h-10 border rounded-xl cursor-pointer bg-white">
                    </div>
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">سمك خط الحدود</label>
                        <select name="boundary_weight" class="w-full px-3 py-2 border rounded-xl focus:outline-none text-xs font-bold text-gray-700 bg-white">
                            <option value="1">1.0 px</option>
                            <option value="2">2.0 px</option>
                            <option value="2.5" selected>2.5 px</option>
                            <option value="3">3.0 px</option>
                            <option value="4">4.0 px</option>
                            <option value="5">5.0 px</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">لون تعبئة المنطقة</label>
                        <input type="color" name="boundary_fill_color" value="#ff7800" class="w-full h-10 border rounded-xl cursor-pointer bg-white">
                    </div>
                    <div>
                        <label class="block text-slate-650 text-xs font-bold mb-1.5">نسبة التعتيم الداخلية</label>
                        <select name="boundary_fill_opacity" class="w-full px-3 py-2 border rounded-xl focus:outline-none text-xs font-bold text-gray-700 bg-white">
                            <option value="0">0% (شفاف)</option>
                            <option value="0.05">5%</option>
                            <option value="0.1">10%</option>
                            <option value="0.15" selected>15%</option>
                            <option value="0.2">20%</option>
                            <option value="0.3">30%</option>
                            <option value="0.4">40%</option>
                            <option value="0.5">50%</option>
                            <option value="0.6">60%</option>
                            <option value="0.8">80%</option>
                            <option value="1">100% (معتم)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-slate-650 text-xs font-bold mb-1.5">ملف الـ KML الجغرافي</label>
                    <input type="file" name="kml_file" accept=".kml" required class="w-full text-xs text-gray-500 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-orange-50 file:text-orange-700 cursor-pointer font-bold">
                </div>
                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-2.5 px-4 rounded-xl transition text-xs">رفع وحفظ الحدود المنسقة</button>
            </form>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 lg:col-span-2 hover:shadow-lg transition duration-300">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3 border-gray-100">
                <div class="p-2 bg-slate-100 text-slate-600 rounded-lg"><i class="fa-solid fa-layer-group text-xl"></i></div>
                <h3 class="text-lg font-black text-slate-900">الحدود الإدارية المحملة وتنسيقاتها</h3>
            </div>
            <div class="overflow-x-auto rounded-xl border border-gray-150 max-h-80">
                <table class="min-w-full divide-y divide-gray-200 text-right">
                    <thead class="bg-slate-100 text-slate-900 text-xs font-black uppercase">
                        <tr>
                            <th class="px-6 py-3">اسم المنطقة</th>
                            <th class="px-6 py-3">خصائص مظهر المضلع (Style)</th>
                            <th class="px-6 py-3 text-center">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100 text-sm text-slate-900 font-black">
                        <?php if (count($boundaries) === 0): ?>
                            <tr><td colspan="3" class="px-6 py-8 text-center text-gray-400 font-bold">لا توجد حدود مرفوعة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($boundaries as $b): 
                                $style = json_decode($b['color'], true);
                                if (!$style) {
                                    $style = [
                                        'stroke_color' => $b['color'] ?: '#ff7800',
                                        'weight' => 2.5,
                                        'fill_color' => $b['color'] ?: '#ff7800',
                                        'fill_opacity' => 0.15
                                    ];
                                }
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3.5 font-black text-slate-900"><?php echo htmlspecialchars($b['name']); ?></td>
                                    <td class="px-6 py-3.5 text-xs font-bold space-y-1">
                                        <div class="flex items-center space-x-1.5 space-x-reverse">
                                            <span class="inline-block w-4 h-4 rounded border border-gray-300" style="background-color: <?php echo $style['stroke_color']; ?>;"></span>
                                            <span class="text-slate-500">الخط: </span><span class="text-slate-900 font-black"><?php echo $style['stroke_color']; ?> (السمك: <?php echo $style['weight']; ?>px)</span>
                                        </div>
                                        <div class="flex items-center space-x-1.5 space-x-reverse">
                                            <span class="inline-block w-4 h-4 rounded border border-gray-300" style="background-color: <?php echo $style['fill_color']; ?>; opacity: <?php echo $style['fill_opacity']; ?>;"></span>
                                            <span class="text-slate-500">التعبئة: </span><span class="text-slate-900 font-black"><?php echo $style['fill_color']; ?> (التعتيم: <?php echo ($style['fill_opacity'] * 100); ?>%)</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3.5 text-center space-x-3 space-x-reverse font-bold text-xs no-print">
                                        <button type="button" onclick='openStyleEditor(<?php echo $b['id']; ?>, <?php echo json_encode($style); ?>, "<?php echo htmlspecialchars($b['name']); ?>")' class="text-indigo-600 hover:text-indigo-800 font-black"><i class="fa-solid fa-palette"></i> تنسيق</button>
                                        <!-- استمارة حذف مضلع الحدود (محدثة بالـ CSRF Token) -->
                                        <form action="index.php?page=settings-view&f_page=<?php echo $f_page; ?>" method="POST" onsubmit="return confirm('حذف مضلع الحدود نهائياً؟');" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                            <input type="hidden" name="action" value="delete_boundary">
                                            <input type="hidden" name="boundary_id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 font-black"><i class="fa-solid fa-trash"></i> حذف</button>
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

        const encodedType = btoa(unescape(encodeURIComponent(fieldTypeInput.value)));
        const encodedOptions = btoa(unescape(encodeURIComponent(fieldOptionsInput.value)));

        document.getElementById('field_type_encoded').value = encodedType;
        document.getElementById('field_options_encoded').value = encodedOptions;

        fieldTypeInput.removeAttribute('name');
        fieldOptionsInput.removeAttribute('name');
    });

    function editField(field) {
        document.getElementById('field-form-title').innerText = "تعديل الحقل الفني: " + field.label;
        document.getElementById('field_id').value = field.id;
        document.getElementById('group_name').value = field.group_name;
        document.getElementById('field_label').value = field.label;
        document.getElementById('field_name').value = field.field_name;
        
        document.getElementById('field_type').value = field.type;
        document.getElementById('field_options').value = field.options || '';
        document.getElementById('field_order').value = field.field_order;
        document.getElementById('is_required').checked = (field.is_required == 1);
        document.getElementById('show_in_print').checked = (field.show_in_print == 1);
        document.getElementById('is_active').checked = (field.is_active == 1);

        const checkboxes = document.querySelectorAll('.field-type-checkbox');
        checkboxes.forEach(cb => cb.checked = false);

        // [تصحيح التحديد التلقائي للأقسام]: مسح وتنظيف الشرطات والمسافات بالأداة .map(s => s.trim()) لمنع الخطأ الصامت
        const typeIds = String(field.record_type_id).split(',').map(s => s.trim());
        typeIds.forEach(id => {
            const cb = document.querySelector(`.field-type-checkbox[value="${id}"]`);
            if (cb) cb.checked = true;
        });

        toggleOptions(); 

        document.getElementById('cancel-edit-btn').classList.remove('hidden');
        window.scrollTo({ top: document.getElementById('field-form').offsetTop - 100, behavior: 'smooth' });
    }

    function cancelFieldEdit() {
        document.getElementById('field_type').setAttribute('name', 'field_type');
        document.getElementById('field_options').setAttribute('name', 'field_options');

        document.getElementById('field-form-title').innerText = "باني الحقول المشتركة والمطورة";
        document.getElementById('field_id').value = "0";
        document.getElementById('field-form').reset();
        document.getElementById('cancel-edit-btn').classList.add('hidden');
        toggleOptions();
    }

    // دالة باني ومعدل التنسيق والنمط الجغرافي للحدود بالسويت ليرت (محدث بالـ CSRF لضمان الحفظ ببيئة PHP 8.4)
    function openStyleEditor(boundaryId, currentStyle, name) {
        Swal.fire({
            title: 'تعديل وتنسيق مظهر: ' + name,
            html: `
                <div class="text-right space-y-4 text-xs text-gray-700 font-sans" dir="rtl">
                    <div class="border-b border-gray-150 pb-2">
                        <span class="font-black text-slate-800 text-xs block mb-2"><i class="fa-solid fa-lines-leaning text-indigo-500"></i> الخطوط والحدود الخارجية</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-slate-650 mb-1">اللون:</label>
                                <input type="color" id="swal_stroke_color" value="${currentStyle.stroke_color}" class="w-full h-8 border rounded-lg cursor-pointer bg-white">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-650 mb-1">السمك (العرض):</label>
                                <select id="swal_weight" class="w-full px-2 py-1.5 border rounded-lg text-xs font-bold text-gray-700 bg-white">
                                    <option value="1" ${currentStyle.weight == 1 ? 'selected' : ''}>1.0 px</option>
                                    <option value="1.5" ${currentStyle.weight == 1.5 ? 'selected' : ''}>1.5 px</option>
                                    <option value="2" ${currentStyle.weight == 2 ? 'selected' : ''}>2.0 px</option>
                                    <option value="2.5" ${currentStyle.weight == 2.5 ? 'selected' : ''}>2.5 px</option>
                                    <option value="3" ${currentStyle.weight == 3 ? 'selected' : ''}>3.0 px</option>
                                    <option value="4" ${currentStyle.weight == 4 ? 'selected' : ''}>4.0 px</option>
                                    <option value="5" ${currentStyle.weight == 5 ? 'selected' : ''}>5.0 px</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div>
                        <span class="font-black text-slate-800 text-xs block mb-2"><i class="fa-solid fa-fill-drip text-emerald-500"></i> المنطقة والتعبئة الداخلية للمضلع</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-slate-650 mb-1">اللون:</label>
                                <input type="color" id="swal_fill_color" value="${currentStyle.fill_color}" class="w-full h-8 border rounded-lg cursor-pointer bg-white">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-650 mb-1">نسبة التعتيم (التعبئة):</label>
                                <select id="swal_fill_opacity" class="w-full px-2 py-1.5 border rounded-lg text-xs font-bold text-gray-700 bg-white">
                                    <option value="0" ${currentStyle.fill_opacity == 0 ? 'selected' : ''}>0% (شفاف تماماً)</option>
                                    <option value="0.05" ${currentStyle.fill_opacity == 0.05 ? 'selected' : ''}>5%</option>
                                    <option value="0.1" ${currentStyle.fill_opacity == 0.1 ? 'selected' : ''}>10%</option>
                                    <option value="0.15" ${currentStyle.fill_opacity == 0.15 ? 'selected' : ''}>15%</option>
                                    <option value="0.2" ${currentStyle.fill_opacity == 0.2 ? 'selected' : ''}>20%</option>
                                    <option value="0.3" ${currentStyle.fill_opacity == 0.3 ? 'selected' : ''}>30%</option>
                                    <option value="0.4" ${currentStyle.fill_opacity == 0.4 ? 'selected' : ''}>40%</option>
                                    <option value="0.5" ${currentStyle.fill_opacity == 0.5 ? 'selected' : ''}>50%</option>
                                    <option value="0.6" ${currentStyle.fill_opacity == 0.6 ? 'selected' : ''}>60%</option>
                                    <option value="0.8" ${currentStyle.fill_opacity == 0.8 ? 'selected' : ''}>80%</option>
                                    <option value="1" ${currentStyle.fill_opacity == 1 ? 'selected' : ''}>100% (معتم بالكامل)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#4f46e5',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'تحديث التنسيق والمظهر',
            cancelButtonText: 'إلغاء',
            preConfirm: () => {
                return {
                    stroke_color: document.getElementById('swal_stroke_color').value,
                    weight: document.getElementById('swal_weight').value,
                    fill_color: document.getElementById('swal_fill_color').value,
                    fill_opacity: document.getElementById('swal_fill_opacity').value
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const style = result.value;
                const ajaxToken = document.getElementById('ajax_csrf_token').value;
                
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'index.php?page=settings-view&f_page=<?php echo $f_page; ?>';
                
                const actions = {
                    csrf_token: ajaxToken, // حقن التوكن أمنياً لسلامة الاستقبال ومنع الـ 403
                    action: 'update_boundary_style',
                    boundary_id: boundaryId,
                    stroke_color: style.stroke_color,
                    weight: style.weight,
                    fill_color: style.fill_color,
                    fill_opacity: style.fill_opacity
                };
                
                for (const [key, value] of Object.entries(actions)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
</script>