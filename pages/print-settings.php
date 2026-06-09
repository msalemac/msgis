<?php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية
if (isset($_SESSION['print_success_msg'])) { $message = $_SESSION['print_success_msg']; unset($_SESSION['print_success_msg']); }
if (isset($_SESSION['print_error_msg'])) { $error = $_SESSION['print_error_msg']; unset($_SESSION['print_error_msg']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. إضافة أو تعديل قالب طباعة مستقل
    if (isset($_POST['action']) && $_POST['action'] === 'save_print_template') {
        $template_id = intval($_POST['template_id']);
        $template_name = trim($_POST['template_name']);
        $header_right_1 = trim($_POST['header_right_1']);
        $header_right_2 = trim($_POST['header_right_2']);
        $header_right_3 = trim($_POST['header_right_3']);
        $main_title = trim($_POST['main_title']);
        $show_logo = isset($_POST['show_logo']) ? 1 : 0;
        $signatures_title = trim($_POST['signatures_title']);

        $sig1_title = trim($_POST['sig1_title']); $sig1_name = trim($_POST['sig1_name']); $sig1_show = isset($_POST['sig1_show']) ? 1 : 0;
        $sig2_title = trim($_POST['sig2_title']); $sig2_name = trim($_POST['sig2_name']); $sig2_show = isset($_POST['sig2_show']) ? 1 : 0;
        $sig3_title = trim($_POST['sig3_title']); $sig3_name = trim($_POST['sig3_name']); $sig3_show = isset($_POST['sig3_show']) ? 1 : 0;
        $sig4_title = trim($_POST['sig4_title']); $sig4_name = trim($_POST['sig4_name']); $sig4_show = isset($_POST['sig4_show']) ? 1 : 0;
        $sig5_title = trim($_POST['sig5_title']); $sig5_name = trim($_POST['sig5_name']); $sig5_show = isset($_POST['sig5_show']) ? 1 : 0;

        $footer_text = trim($_POST['footer_text']);

        // بناء وحفظ مصفوفة ترتيب ومقاسات الكروت كـ JSON
        $groups_config_arr = [];
        if (isset($_POST['group_names_list'])) {
            foreach ($_POST['group_names_list'] as $g_name) {
                $groups_config_arr[$g_name] = [
                    'order' => intval($_POST['group_order'][$g_name]),
                    'width' => trim($_POST['group_width'][$g_name]) 
                ];
            }
        }
        $groups_config_json = json_encode($groups_config_arr, JSON_UNESCAPED_UNICODE);

        if (!empty($template_name) && !empty($main_title)) {
            try {
                $logo_path = null;
                $old_template = null;

                if ($template_id > 0) {
                    $stmtCheck = $pdo->prepare("SELECT id, logo_path FROM print_templates WHERE id = ?");
                    $stmtCheck->execute([$template_id]);
                    $old_template = $stmtCheck->fetch();
                    if ($old_template) { $logo_path = $old_template['logo_path']; }
                }

                if (isset($_POST['remove_logo']) && $_POST['remove_logo'] == 1) {
                    if ($logo_path && file_exists($logo_path)) { unlink($logo_path); }
                    $logo_path = null;
                }

                if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = 'uploads/logos/';
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
                    if ($logo_path && file_exists($logo_path)) { unlink($logo_path); }

                    $file_extension = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $new_filename = 'logo_' . uniqid() . '.' . $file_extension;
                        $target_file = $upload_dir . $new_filename;
                        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_file)) {
                            $logo_path = $target_file;
                        }
                    }
                }

                if ($old_template) {
                    $updateSql = "
                        UPDATE print_templates SET 
                            template_name = ?, header_right_1 = ?, header_right_2 = ?, header_right_3 = ?, 
                            main_title = ?, logo_path = ?, show_logo = ?, signatures_title = ?,
                            sig1_title = ?, sig1_name = ?, sig1_show = ?,
                            sig2_title = ?, sig2_name = ?, sig2_show = ?,
                            sig3_title = ?, sig3_name = ?, sig3_show = ?,
                            sig4_title = ?, sig4_name = ?, sig4_show = ?,
                            sig5_title = ?, sig5_name = ?, sig5_show = ?, footer_text = ?, groups_config = ?
                        WHERE id = ?
                    ";
                    $stmtUp = $pdo->prepare($updateSql);
                    $stmtUp->execute([
                        $template_name, $header_right_1, $header_right_2, $header_right_3, 
                        $main_title, $logo_path, $show_logo, $signatures_title,
                        $sig1_title, $sig1_name, $sig1_show,
                        $sig2_title, $sig2_name, $sig2_show,
                        $sig3_title, $sig3_name, $sig3_show,
                        $sig4_title, $sig4_name, $sig4_show,
                        $sig5_title, $sig5_name, $sig5_show, $footer_text, $groups_config_json, $template_id
                    ]);
                } else {
                    // [تم الحل]: إضافة علامة الاستفهام الـ 25 المفقودة في الـ VALUES ليتطابق مع الـ 25 عموداً المعينين بالأعلى
                    $insertSql = "
                        INSERT INTO print_templates (
                            template_name, header_right_1, header_right_2, header_right_3, 
                            main_title, logo_path, show_logo, signatures_title,
                            sig1_title, sig1_name, sig1_show,
                            sig2_title, sig2_name, sig2_show,
                            sig3_title, sig3_name, sig3_show,
                            sig4_title, sig4_name, sig4_show,
                            sig5_title, sig5_name, sig5_show, footer_text, groups_config
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ";
                    $stmtIns = $pdo->prepare($insertSql);
                    $stmtIns->execute([
                        $template_name, $header_right_1, $header_right_2, $header_right_3, 
                        $main_title, $logo_path, $show_logo, $signatures_title,
                        $sig1_title, $sig1_name, $sig1_show,
                        $sig2_title, $sig2_name, $sig2_show,
                        $sig3_title, $sig3_name, $sig3_show,
                        $sig4_title, $sig4_name, $sig4_show,
                        $sig5_title, $sig5_name, $sig5_show, $footer_text, $groups_config_json
                    ]);
                }
                $_SESSION['print_success_msg'] = "تم حفظ وتثبيت إعدادات ومقاسات قالب الطباعة بنجاح للنموذج المختار.";
            } catch (PDOException $e) { $_SESSION['print_error_msg'] = "حدث خطأ في قاعدة البيانات: " . $e->getMessage(); }
        } else { $_SESSION['print_error_msg'] = "يرجى ملء الحقول المطلوبة لقالب الطباعة."; }
        
        header("Location: index.php?page=print-settings");
        exit;
    }

    // 2. حذف قالب طباعة
    if (isset($_POST['action']) && $_POST['action'] === 'delete_template') {
        $t_id = intval($_POST['template_id']);
        try {
            $stmtDelT = $pdo->prepare("DELETE FROM print_templates WHERE id = ?");
            $stmtDelT->execute([$t_id]);
            $_SESSION['print_success_msg'] = "تم حذف قالب الطباعة المحدد من السيستم الكلي.";
        } catch (PDOException $e) { $_SESSION['print_error_msg'] = "فشل حذف قالب الطباعة."; }
        header("Location: index.php?page=print-settings");
        exit;
    }
}

// جلب البيانات
$types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
$all_templates = $pdo->query("SELECT * FROM print_templates ORDER BY id DESC")->fetchAll();
$all_db_groups = $pdo->query("SELECT DISTINCT group_name FROM fields WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
?>

<!-- التنبيهات باستخدام SweetAlert2 الفاخرة -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تم الحفظ', text: '<?php echo $message; ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'تنبيه خطأ', text: '<?php echo $error; ?>' }); });</script>
<?php endif; ?>

<!-- تصميم محاكاة لطلبك بأسلوب انسيابي عالي ومظهر عصري -->
<div class="max-w-6xl mx-auto space-y-6 animate-fade">

    <!-- كارت التحكم واختيار القالب -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100">
        <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
            <div class="p-2 bg-indigo-100 text-indigo-600 rounded-lg"><i class="fa-solid fa-file-invoice text-xl"></i></div>
            <h3 class="text-sm font-bold text-gray-800 font-sans">إدارة قوالب ونماذج الطباعة العامة</h3>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-gray-600 text-xs font-semibold mb-1">اختر قالب الطباعة للتعديل أو إنشاء قالب جديد:</label>
                <select id="record_type_selector" onchange="loadTemplateData(this.value)" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-bold text-gray-700 focus:outline-none bg-white">
                    <option value="">-- اختر قالب الطباعة --</option>
                    <option value="new">++ إنشاء قالب طباعة جديد ++</option>
                    <?php foreach ($all_templates as $tmpl): ?>
                        <option value="<?php echo $tmpl['id']; ?>"><?php echo htmlspecialchars($tmpl['template_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hidden" id="template-name-container">
                <label class="block text-gray-600 text-xs font-semibold mb-1">اسم قالب الطباعة (مثال: نموذج 1 - إثبات حالة):</label>
                <input type="text" id="template_name_input" placeholder="اسم النموذج" class="w-full px-4 py-2 border rounded-lg text-xs focus:outline-none bg-white font-bold">
            </div>
        </div>
    </div>

    <!-- النموذج الرئيسي لضبط الهيدر والفوتر والتوقيعات والمقاسات -->
    <form id="print-settings-form" action="index.php?page=print-settings" method="POST" enctype="multipart/form-data" class="hidden space-y-6">
        <input type="hidden" name="action" value="save_print_template">
        <input type="hidden" name="template_id" id="hidden_template_id" value="0">
        <input type="hidden" name="template_name" id="hidden_template_name">

        <!-- 1. بوكس إعدادات رأس الصفحة (الهيدر) -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i class="fa-solid fa-heading text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800 font-sans">إعدادات رأس الصفحة (الهيدر)</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">السطر الأيمن 1:</label>
                        <input type="text" name="header_right_1" id="header_right_1" placeholder="مثال: محافظة الاسماعيلية" class="w-full px-4 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none text-right bg-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">السطر الأيمن 2:</label>
                        <input type="text" name="header_right_2" id="header_right_2" placeholder="مثال: حي ثان" class="w-full px-4 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none text-right bg-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">السطر الأيمن 3:</label>
                        <input type="text" name="header_right_3" id="header_right_3" placeholder="مثال: وحدة المتغيرات المكانية" class="w-full px-4 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none text-right bg-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">العنوان الرئيسي (وسط):</label>
                        <input type="text" name="main_title" id="main_title" placeholder="مثال: محضر إثبات حالة معاينة ميدانية" required class="w-full px-4 py-2 border border-gray-200 rounded-lg text-xs font-bold text-gray-800 focus:outline-none text-center bg-white">
                    </div>
                </div>

                <div class="space-y-4 bg-gray-50/50 p-6 rounded-2xl border border-gray-100">
                    <label class="block text-gray-600 text-xs font-bold mb-1">الشعار (يسار):</label>
                    <input type="file" name="logo_file" accept="image/*" class="w-full text-xs text-gray-500 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-blue-50 file:text-blue-700 cursor-pointer">
                    
                    <div id="logo-preview-container" class="hidden flex items-center justify-between border-t pt-3 mt-3">
                        <div class="flex items-center space-x-2 space-x-reverse">
                            <input type="checkbox" name="remove_logo" id="remove_logo" value="1" class="w-4 h-4 text-red-600 rounded cursor-pointer">
                            <label for="remove_logo" class="text-xs text-red-600 font-bold cursor-pointer select-none">إزالة الشعار الحالي</label>
                        </div>
                        <img id="logo-preview" class="w-12 h-12 object-contain rounded-lg border bg-white shadow">
                    </div>

                    <div class="flex items-center justify-between border-t pt-3 mt-3">
                        <span class="text-xs text-gray-600 font-bold">حالة الشعار في الطباعة:</span>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="show_logo" id="show_logo" value="1" checked class="sr-only peer">
                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            <span class="mr-2 text-xs font-semibold text-gray-500 select-none cursor-pointer">الشعار ظاهر</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. بوكس تحديد ترتيب ومقاس وعرض كل مجموعة/بوكس بالطباعة بالـ JSON فائق الديناميكية -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-purple-100 text-purple-600 rounded-lg"><i class="fa-solid fa-arrows-up-down-left-right text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800 font-sans">تنسيق مقاسات وترتيب كروت المجموعات بالورقة (A4)</h3>
            </div>
            
            <p class="text-[10px] text-gray-400">تظهر هنا تلقائياً كافة المجموعات (الجروبات) المحددة في حقولك الميدانية. يمكنك تحديد مقاس عرض كل كرت وترتيب ظهوره بالطباعة لضمان ملاءمتها في ورقة واحدة:</p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="groups_layout_configs_grid">
                <?php foreach ($all_db_groups as $g_name): 
                    // تشفير وحماية مسمى الجروب ليتوافق مع الـ IDs
                    $cleanGName = base64_encode($g_name);
                    $cleanGName = str_replace('=', '', $cleanGName); // تنظيف لتلافي مشاكل الجافا سكريبت في الرموز
                ?>
                    <!-- كرت مجموعة فرعي للتحكم بمقاسها وترتيبها -->
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 flex flex-col justify-between space-y-3">
                        <span class="text-xs font-bold text-slate-800"><i class="fa-solid fa-folder text-blue-500 ml-1.5"></i> صندوق: ( <?php echo htmlspecialchars($g_name); ?> )</span>
                        <input type="hidden" name="group_names_list[]" value="<?php echo htmlspecialchars($g_name); ?>">
                        
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">عرض الصندوق:</label>
                                <select name="group_width[<?php echo htmlspecialchars($g_name); ?>]" id="width_of_<?php echo $cleanGName; ?>" class="w-full px-2 py-1 bg-white border rounded text-[10px] focus:outline-none">
                                    <option value="col-span-1">نصف عرض الصفحة (50%)</option>
                                    <option value="col-span-2">عرض كامل للصفحة (100%)</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">ترتيب الظهور بالورقة:</label>
                                <input type="number" name="group_order[<?php echo htmlspecialchars($g_name); ?>]" id="order_of_<?php echo $cleanGName; ?>" value="1" class="w-full px-2 py-1 bg-white border rounded text-[10px] focus:outline-none">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 3. بوكس إعدادات ذيل الصفحة والتوقيعات الخمسة -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-orange-100 text-orange-600 rounded-lg"><i class="fa-solid fa-signature text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800 font-sans">إعدادات ذيل الصفحة (الفوتر والتوقيعات)</h3>
            </div>

            <div class="space-y-4">
                <div class="max-w-md">
                    <label class="block text-gray-600 text-xs font-bold mb-1">عنوان قسم التوقيعات بالأعلى:</label>
                    <input type="text" name="signatures_title" id="signatures_title" placeholder="مثال: التوقيعات،،" class="w-full px-4 py-2 border rounded-lg text-xs focus:outline-none bg-white font-semibold">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <!-- التوقيع 1 -->
                    <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-100 space-y-3 flex flex-col justify-between">
                        <div class="space-y-2">
                            <span class="text-[10px] text-indigo-600 font-extrabold block border-b pb-1">التوقيع الأول</span>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الوظيفة 1:</label>
                                <input type="text" name="sig1_title" id="sig1_title" placeholder="الوظيفة" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الاسم 1:</label>
                                <input type="text" name="sig1_name" id="sig1_name" placeholder="الاسم" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t select-none">
                            <span class="text-[10px] text-gray-500 font-bold">ظاهر بالطباعة</span>
                            <input type="checkbox" name="sig1_show" id="sig1_show" value="1" class="w-4 h-4 text-emerald-600 rounded">
                        </div>
                    </div>

                    <!-- التوقيع 2 -->
                    <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-100 space-y-3 flex flex-col justify-between">
                        <div class="space-y-2">
                            <span class="text-[10px] text-indigo-600 font-extrabold block border-b pb-1">التوقيع الثاني</span>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الوظيفة 2:</label>
                                <input type="text" name="sig2_title" id="sig2_title" placeholder="الوظيفة" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الاسم 2:</label>
                                <input type="text" name="sig2_name" id="sig2_name" placeholder="الاسم" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t select-none">
                            <span class="text-[10px] text-gray-500 font-bold">ظاهر بالطباعة</span>
                            <input type="checkbox" name="sig2_show" id="sig2_show" value="1" class="w-4 h-4 text-emerald-600 rounded">
                        </div>
                    </div>

                    <!-- التوقيع 3 -->
                    <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-100 space-y-3 flex flex-col justify-between">
                        <div class="space-y-2">
                            <span class="text-[10px] text-indigo-600 font-extrabold block border-b pb-1">التوقيع الثالث</span>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الوظيفة 3:</label>
                                <input type="text" name="sig3_title" id="sig3_title" placeholder="الوظيفة" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الاسم 3:</label>
                                <input type="text" name="sig3_name" id="sig3_name" placeholder="الاسم" class="w-full px-2 py-1 border rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t select-none">
                            <span class="text-[10px] text-gray-500 font-bold">ظاهر بالطباعة</span>
                            <input type="checkbox" name="sig3_show" id="sig3_show" value="1" class="w-4 h-4 text-emerald-600 rounded">
                        </div>
                    </div>

                    <!-- التوقيع 4 -->
                    <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-100 space-y-3 flex flex-col justify-between">
                        <div class="space-y-2">
                            <span class="text-[10px] text-indigo-600 font-extrabold block border-b pb-1">التوقيع الرابع</span>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الوظيفة 4:</label>
                                <input type="text" name="sig4_title" id="sig4_title" placeholder="الوظيفة" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الاسم 4:</label>
                                <input type="text" name="sig4_name" id="sig4_name" placeholder="الاسم" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t select-none">
                            <span class="text-[10px] text-gray-500 font-bold">ظاهر بالطباعة</span>
                            <input type="checkbox" name="sig4_show" id="sig4_show" value="1" class="w-4 h-4 text-emerald-600 rounded">
                        </div>
                    </div>

                    <!-- التوقيع 5 -->
                    <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-100 space-y-3 flex flex-col justify-between">
                        <div class="space-y-2">
                            <span class="text-[10px] text-indigo-600 font-extrabold block border-b pb-1">التوقيع الخامس</span>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الوظيفة 5:</label>
                                <input type="text" name="sig5_title" id="sig5_title" placeholder="الوظيفة" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-400 block font-bold mb-1">الاسم 5:</label>
                                <input type="text" name="sig5_name" id="sig5_name" placeholder="الاسم" class="w-full px-2 py-1 border border-gray-200 rounded text-[10px] focus:outline-none bg-white font-semibold">
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t select-none">
                            <span class="text-[10px] text-gray-500 font-bold">ظاهر بالطباعة</span>
                            <input type="checkbox" name="sig5_show" id="sig5_show" value="1" class="w-4 h-4 text-emerald-600 rounded">
                        </div>
                    </div>
                </div>

                <div class="pt-2">
                    <label class="block text-gray-600 text-xs font-bold mb-1">نص تذييل الصفحة (الفوتر السفلي بالكامل):</label>
                    <input type="text" name="footer_text" id="footer_text" placeholder="ملاحظات أسفل التقرير" class="w-full px-4 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none bg-white font-semibold">
                </div>
            </div>
        </div>

        <!-- حفظ الإعدادات الشامل -->
        <div class="flex justify-center">
            <button type="submit" onclick="syncFormSubmit(event)" class="bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold py-3 px-8 rounded-xl transition shadow-md flex items-center space-x-2 space-x-reverse text-sm">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>حفظ وتثبيت إعدادات ومقاسات قالب الطباعة</span>
            </button>
        </div>
    </form>
    
    <!-- كارت حذف القوالب -->
    <?php if (count($all_templates) > 0): ?>
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100">
        <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
            <div class="p-2 bg-red-50 text-red-600 rounded-lg"><i class="fa-solid fa-trash-can text-lg"></i></div>
            <h3 class="text-sm font-bold text-gray-800">حذف نماذج وقوالب الطباعة الحالية</h3>
        </div>
        <div class="overflow-x-auto rounded-lg border border-gray-100">
            <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-gray-50 text-gray-700 font-bold uppercase">
                    <tr>
                        <th class="px-6 py-3">اسم النموذج</th>
                        <th class="px-6 py-3">العنوان الرئيسي المطبوع</th>
                        <th class="px-6 py-3 text-center">العمليات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 text-gray-600 font-semibold">
                    <?php foreach ($all_templates as $tmpl): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 font-bold text-slate-800"><?php echo htmlspecialchars($tmpl['template_name']); ?></td>
                            <td class="px-6 py-3"><?php echo htmlspecialchars($tmpl['main_title']); ?></td>
                            <td class="px-6 py-3 text-center">
                                <form action="index.php?page=print-settings" method="POST" onsubmit="return confirm('هل تريد حذف قالب الطباعة هذا نهائياً من السيستم؟');" class="inline">
                                    <input type="hidden" name="action" value="delete_template">
                                    <input type="hidden" name="template_id" value="<?php echo $tmpl['id']; ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 font-bold"><i class="fa-solid fa-trash-can"></i> حذف النموذج</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    const templatesList = <?php echo json_encode($all_templates, JSON_UNESCAPED_UNICODE); ?>;

    function loadTemplateData(templateId) {
        const form = document.getElementById('print-settings-form');
        const nameContainer = document.getElementById('template-name-container');
        const templateNameInput = document.getElementById('template_name_input');
        
        form.reset();
        document.getElementById('logo-preview-container').classList.add('hidden');
        document.getElementById('remove_logo').checked = false;

        // تصفير فلاتر المقاسات الافتراضية
        document.querySelectorAll('[id^="width_of_"]').forEach(sel => sel.value = "col-span-1");
        document.querySelectorAll('[id^="order_of_"]').forEach(inp => inp.value = "1");

        if (!templateId) {
            form.classList.add('hidden');
            nameContainer.classList.add('hidden');
            return;
        }

        form.classList.remove('hidden');
        nameContainer.classList.remove('hidden');

        if (templateId === 'new') {
            document.getElementById('hidden_template_id').value = "0";
            templateNameInput.value = "نموذج طباعة جديد";
            document.getElementById('main_title').value = "محضر معاينة ميدانية";
            document.getElementById('signatures_title').value = "التوقيعات،،";
        } else {
            const currentTmpl = templatesList.find(t => t.id == templateId);
            if (currentTmpl) {
                document.getElementById('hidden_template_id').value = currentTmpl.id;
                templateNameInput.value = currentTmpl.template_name;
                document.getElementById('header_right_1').value = currentTmpl.header_right_1 || '';
                document.getElementById('header_right_2').value = currentTmpl.header_right_2 || '';
                document.getElementById('header_right_3').value = currentTmpl.header_right_3 || '';
                document.getElementById('main_title').value = currentTmpl.main_title || '';
                document.getElementById('signatures_title').value = currentTmpl.signatures_title || '';
                document.getElementById('footer_text').value = currentTmpl.footer_text || '';
                document.getElementById('show_logo').checked = (currentTmpl.show_logo == 1);

                for (let i = 1; i <= 5; i++) {
                    document.getElementById(`sig${i}_title`).value = currentTmpl[`sig${i}_title`] || '';
                    document.getElementById(`sig${i}_name`).value = currentTmpl[`sig${i}_name`] || '';
                    document.getElementById(`sig${i}_show`).checked = (currentTmpl[`sig${i}_show`] == 1);
                }

                if (currentTmpl.logo_path) {
                    const previewContainer = document.getElementById('logo-preview-container');
                    const previewImg = document.getElementById('logo-preview');
                    previewImg.src = currentTmpl.logo_path;
                    previewContainer.classList.remove('hidden');
                }

                // [تم التحديث]: فك واسترجاع إعدادات المقاسات والترتيب المحفوظة بالـ JSON وشحنها بالفورم تلقائياً بأكواد آمنة
                if (currentTmpl.groups_config) {
                    try {
                        const gConfigs = JSON.parse(currentTmpl.groups_config);
                        for (const [gName, config] of Object.entries(gConfigs)) {
                            // تحويل وترميز مسمى الجروب بأكواد جافا سكريبت مأمنة لعدم كسر الاسترجاع بالمتصفح
                            const cleanGName = btoa(unescape(encodeURIComponent(gName))).replace(/=/g, '');
                            const selWidth = document.getElementById("width_of_" + cleanGName);
                            const inpOrder = document.getElementById("order_of_" + cleanGName);
                            if (selWidth) selWidth.value = config.width || "col-span-1";
                            if (inpOrder) inpOrder.value = config.order || "1";
                        }
                    } catch (e) { console.error("فشل قراءة مخطط المقاسات الجيوفيزيائي", e); }
                }
            }
        }
    }

    function syncFormSubmit(event) {
        event.preventDefault();
        const selectorVal = document.getElementById('record_type_selector').value;
        const templateName = document.getElementById('template_name_input').value.trim();

        if (!selectorVal || templateName === '') {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى تحديد القالب أو اختيار إنشاء قالب جديد أولاً.' });
            return;
        }

        document.getElementById('hidden_template_name').value = templateName;
        document.getElementById('print-settings-form').submit();
    }
</script>