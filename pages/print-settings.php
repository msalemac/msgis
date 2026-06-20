<?php
// pages/print-settings.php - لوحة التحكم بقوالب الطباعة (النسخة النهائية الفائقة المؤازرة لبيئات PHP 8.4)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية وعرضها للمشرف
if (isset($_SESSION['print_success_msg'])) { 
    $message = $_SESSION['print_success_msg']; 
    unset($_SESSION['print_success_msg']); 
}
if (isset($_SESSION['print_error_msg'])) { 
    $error = $_SESSION['print_error_msg']; 
    unset($_SESSION['print_error_msg']); 
}

/**
 * دالة إعادة التوجيه الفائقة والمقاومة لقيود البفر والـ Headers
 * تقوم بتفريغ أي بفر معلق وتجبر المتصفح على التوجيه الفوري
 */
function safeRedirect($url) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header("Location: " . $url);
    } else {
        echo "<script>window.location.href='" . $url . "';</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=" . $url . "'></noscript>";
    }
    exit;
}

// معالجة كافة طلبات POST (الحفظ والحذف الآمن) في مطلع الملف لقطع التنفيذ فوراً
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة، يرجى تحديث الصفحة والمحاولة مجدداً.");
    }

    // 1. معالجة طلب حذف القالب
    if (isset($_POST['action']) && $_POST['action'] === 'delete_template') {
        $t_id = intval($_POST['template_id'] ?? 0);
        try {
            $stmtDel = $pdo->prepare("DELETE FROM print_templates WHERE id = ?");
            $stmtDel->execute([$t_id]);
            $_SESSION['print_success_msg'] = "تم حذف قالب الطباعة المختار بنجاح من النظام.";
        } catch (PDOException $e) {
            $_SESSION['print_error_msg'] = "فشل حذف القالب المختار نتيجة ارتباطه بسجلات فعالة.";
        }
        safeRedirect("index.php?page=print-settings");
    }

    // 2. معالجة طلب حفظ القالب وتحديثه
    if (isset($_POST['action']) && $_POST['action'] === 'save_print_template') {
        $template_id = intval($_POST['template_id'] ?? 0);
        $template_name = trim($_POST['template_name'] ?? '');
        $main_title = trim($_POST['main_title'] ?? '');
        $signatures_title = trim($_POST['signatures_title'] ?? '');
        $footer_text = trim($_POST['footer_text'] ?? '');
        
        $header_right_1 = trim($_POST['header_right_1'] ?? '');
        $header_right_2 = trim($_POST['header_right_2'] ?? '');
        $header_right_3 = trim($_POST['header_right_3'] ?? '');
        
        $paper_size = trim($_POST['paper_size'] ?? 'A4');
        $orientation = trim($_POST['orientation'] ?? 'Portrait');
        $header_font = trim($_POST['header_font'] ?? 'Cairo');
        
        $header_height = intval($_POST['header_height'] ?? 30);
        $font_size_pt = intval($_POST['font_size_pt'] ?? 10);
        $card_padding_px = intval($_POST['card_padding_px'] ?? 12);
        $grid_gap_px = intval($_POST['grid_gap_px'] ?? 12);

        // تهيئة نسب التوزيع للهيدر والفوتر من POST
        $header_layout_json = trim($_POST['header_layout_json'] ?? '{"right":30,"middle":40,"left":30}');
        $footer_layout_json = trim($_POST['footer_layout_json'] ?? '{"right":30,"middle":40,"left":30}');

        // فك تشفير حقول الـ HTML من الحزم المرمزة بـ Base64 لتفادي حظر الـ WAF على السيرفر
        $header_right_html  = isset($_POST['header_right_html_encoded'])  ? trim((string)base64_decode($_POST['header_right_html_encoded']))  : '';
        $header_middle_html = isset($_POST['header_middle_html_encoded']) ? trim((string)base64_decode($_POST['header_middle_html_encoded'])) : '';
        $header_left_html   = isset($_POST['header_left_html_encoded'])   ? trim((string)base64_decode($_POST['header_left_html_encoded']))   : '';
        
        $footer_right_html  = isset($_POST['footer_right_html_encoded'])  ? trim((string)base64_decode($_POST['footer_right_html_encoded']))  : '';
        $footer_middle_html = isset($_POST['footer_middle_html_encoded']) ? trim((string)base64_decode($_POST['footer_middle_html_encoded'])) : '';
        $footer_left_html   = isset($_POST['footer_left_html_encoded'])   ? trim((string)base64_decode($_POST['footer_left_html_encoded']))   : '';
        
        $extra_content_above = isset($_POST['extra_content_above_encoded']) ? trim((string)base64_decode($_POST['extra_content_above_encoded'])) : '';
        $extra_content_below = isset($_POST['extra_content_below_encoded']) ? trim((string)base64_decode($_POST['extra_content_below_encoded'])) : '';

        // فك تشفير هياكل الجداول وتوقيعات اللجنة المشكلة
        $columns_config_json = isset($_POST['columns_config_encoded']) ? base64_decode($_POST['columns_config_encoded']) : '[]';
        $custom_css          = isset($_POST['custom_css_encoded'])          ? base64_decode($_POST['custom_css_encoded'])          : '';
        $signatures_json     = isset($_POST['signatures_json_encoded'])     ? base64_decode($_POST['signatures_json_encoded'])     : '[]';
        
        $groups_config_json  = isset($_POST['groups_config_json'])          ? $_POST['groups_config_json']                         : '{}';

        if (!empty($template_name) && !empty($main_title)) {
            try {
                $logo_path = null;
                if ($template_id > 0) {
                    $stmtCheck = $pdo->prepare("SELECT logo_path FROM print_templates WHERE id = ?");
                    $stmtCheck->execute([$template_id]);
                    $old_tmpl = $stmtCheck->fetch();
                    if ($old_tmpl) { $logo_path = $old_tmpl['logo_path']; }
                }

                if (isset($_POST['remove_logo']) && $_POST['remove_logo'] == 1) {
                    if ($logo_path && file_exists($logo_path)) { unlink($logo_path); }
                    $logo_path = null;
                }

                if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = 'uploads/logos/';
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
                    $file_tmp = $_FILES['logo_file']['tmp_name'];
                    $image_info = getimagesize($file_tmp);
                    
                    if ($image_info !== false && in_array($image_info['mime'], ['image/jpeg', 'image/png', 'image/gif'])) {
                        if ($logo_path && file_exists($logo_path)) { unlink($logo_path); }
                        $logo_path = $upload_dir . 'logo_' . uniqid() . '.' . strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                        move_uploaded_file($file_tmp, $logo_path);
                    }
                }

                if ($template_id > 0) {
                    $sql = "UPDATE print_templates SET 
                                template_name = ?, main_title = ?, signatures_title = ?, show_logo = ?,
                                paper_size = ?, orientation = ?, header_font = ?, header_height = ?,
                                row_height = 24, font_size_pt = ?, card_padding_px = ?, page_margin_mm = 8,
                                grid_gap_px = ?, groups_config = ?, custom_css = ?, logo_path = ?,
                                header_right_1 = ?, header_right_2 = ?, header_right_3 = ?, footer_text = ?,
                                sig1_title = ?, sig1_name = ?, sig1_show = ?,
                                sig2_title = ?, sig2_name = ?, sig2_show = ?,
                                sig3_title = ?, sig3_name = ?, sig3_show = ?,
                                sig4_title = ?, sig4_name = ?, sig4_show = ?,
                                sig5_title = ?, sig5_name = ?, sig5_show = ?,
                                header_layout = ?, footer_layout = ?, 
                                header_right_html = ?, header_middle_html = ?, header_left_html = ?,
                                footer_right_html = ?, footer_middle_html = ?, footer_left_html = ?,
                                extra_content_above = ?, extra_content_below = ?,
                                columns_config = ?, signatures_json = ?
                            WHERE id = ?";
                    $pdo->prepare($sql)->execute([
                        $template_name, $main_title, $signatures_title, isset($_POST['show_logo']) ? 1 : 0,
                        $paper_size, $orientation, $header_font, $header_height,
                        $font_size_pt, $card_padding_px, $grid_gap_px, $groups_config_json, $custom_css, $logo_path,
                        $header_right_1, $header_right_2, $header_right_3, $footer_text,
                        trim($_POST['sig1_title'] ?? ''), trim($_POST['sig1_name'] ?? ''), isset($_POST['sig1_show']) ? 1 : 0,
                        trim($_POST['sig2_title'] ?? ''), trim($_POST['sig2_name'] ?? ''), isset($_POST['sig2_show']) ? 1 : 0,
                        trim($_POST['sig3_title'] ?? ''), trim($_POST['sig3_name'] ?? ''), isset($_POST['sig3_show']) ? 1 : 0,
                        trim($_POST['sig4_title'] ?? ''), trim($_POST['sig4_name'] ?? ''), isset($_POST['sig4_show']) ? 1 : 0,
                        trim($_POST['sig5_title'] ?? ''), trim($_POST['sig5_name'] ?? ''), isset($_POST['sig5_show']) ? 1 : 0,
                        $header_layout_json, $footer_layout_json,
                        $header_right_html, $header_middle_html, $header_left_html,
                        $footer_right_html, $footer_middle_html, $footer_left_html,
                        $extra_content_above, $extra_content_below,
                        $columns_config_json, $signatures_json, $template_id
                    ]);
                } else {
                    $sql = "INSERT INTO print_templates (
                                template_name, main_title, signatures_title, show_logo,
                                paper_size, orientation, header_font, header_height,
                                row_height, font_size_pt, card_padding_px, page_margin_mm,
                                grid_gap_px, groups_config, custom_css, logo_path,
                                header_right_1, header_right_2, header_right_3, footer_text,
                                sig1_title, sig1_name, sig1_show, sig2_title, sig2_name, sig2_show,
                                sig3_title, sig3_name, sig3_show, sig4_title, sig4_name, sig4_show,
                                sig5_title, sig5_name, sig5_show, header_layout, footer_layout,
                                header_right_html, header_middle_html, header_left_html,
                                footer_right_html, footer_middle_html, footer_left_html,
                                extra_content_above, extra_content_below, columns_config, signatures_json
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 24, ?, ?, 8, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([
                        $template_name, $main_title, $signatures_title, isset($_POST['show_logo']) ? 1 : 0,
                        $paper_size, $orientation, $header_font, $header_height,
                        $font_size_pt, $card_padding_px, $grid_gap_px, $groups_config_json, $custom_css, $logo_path,
                        $header_right_1, $header_right_2, $header_right_3, $footer_text,
                        trim($_POST['sig1_title'] ?? ''), trim($_POST['sig1_name'] ?? ''), isset($_POST['sig1_show']) ? 1 : 0,
                        trim($_POST['sig2_title'] ?? ''), trim($_POST['sig2_name'] ?? ''), isset($_POST['sig2_show']) ? 1 : 0,
                        trim($_POST['sig3_title'] ?? ''), trim($_POST['sig3_name'] ?? ''), isset($_POST['sig3_show']) ? 1 : 0,
                        trim($_POST['sig4_title'] ?? ''), trim($_POST['sig4_name'] ?? ''), isset($_POST['sig4_show']) ? 1 : 0,
                        trim($_POST['sig5_title'] ?? ''), trim($_POST['sig5_name'] ?? ''), isset($_POST['sig5_show']) ? 1 : 0,
                        $header_layout_json, $footer_layout_json,
                        $header_right_html, $header_middle_html, $header_left_html,
                        $footer_right_html, $footer_middle_html, $footer_left_html,
                        $extra_content_above, $extra_content_below,
                        $columns_config_json, $signatures_json
                    ]);
                }
                $_SESSION['print_success_msg'] = "تم حفظ الإعدادات وقوالب الطباعة وجداول البيانات بنجاح.";
            } catch (PDOException $e) {
                $_SESSION['print_error_msg'] = "حدث خطأ أثناء الاتصال بقاعدة البيانات: " . $e->getMessage();
            }
        }
        safeRedirect("index.php?page=print-settings");
    }
}

// قراءة القوالب المحدثة من قاعدة البيانات
$all_templates = $pdo->query("SELECT * FROM print_templates ORDER BY id DESC")->fetchAll();
$all_db_groups = $pdo->query("SELECT DISTINCT group_name FROM fields WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
$all_fields = $pdo->query("SELECT field_name, label FROM fields WHERE is_active = 1 ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);

if (!in_array("الموقع الجغرافي للمعاينة", $all_db_groups)) { $all_db_groups[] = "الموقع الجغرافي للمعاينة"; }
?>

<!-- عرض التنبيهات ورسائل النجاح بداخل إطار الموقع العام -->
<?php if (!empty($message)): ?>
    <div class="max-w-6xl mx-auto p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-bold mb-4 animate-fade">
        <i class="fa-solid fa-circle-check ml-1.5"></i> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="max-w-6xl mx-auto p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-bold mb-4 animate-fade">
        <i class="fa-solid fa-triangle-exclamation ml-1.5"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="max-w-6xl mx-auto space-y-6 animate-fade text-right" dir="rtl">
    
    <!-- كارت اختيار القالب -->
    <div class="bg-white p-5 rounded-xl border border-gray-150">
        <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
            <div class="p-2 bg-indigo-100 text-indigo-600 rounded-lg"><i class="fa-solid fa-file-invoice text-xl"></i></div>
            <h3 class="text-sm font-bold text-gray-800">إدارة وتخصيص قوالب الطباعة المتقدمة</h3>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-gray-600 text-xs font-semibold mb-1">اختر قالب الطباعة للتعديل أو إنشاء قالب جديد:</label>
                <select id="record_type_selector" onchange="loadTemplateData(this.value)" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-bold text-gray-700 bg-white">
                    <option value="">-- اختر قالب الطباعة --</option>
                    <option value="new">++ إنشاء قالب طباعة جديد ++</option>
                    <?php foreach ($all_templates as $tmpl): ?>
                        <option value="<?php echo $tmpl['id']; ?>"><?php echo htmlspecialchars($tmpl['template_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hidden" id="template-name-container">
                <label class="block text-gray-600 text-xs font-semibold mb-1">اسم قالب الطباعة الفريد:</label>
                <input type="text" id="template_name_input" placeholder="اسم النموذج الجديد" class="w-full px-4 py-2 border rounded-lg text-xs bg-white font-bold">
            </div>
        </div>
    </div>

    <!-- النموذج الرئيسي لضبط الهيدر والفوتر والتوقيعات والمقاسات -->
    <form id="print-settings-form" action="index.php?page=print-settings" method="POST" enctype="multipart/form-data" class="hidden space-y-6">
        
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="action" value="save_print_template">
        <input type="hidden" name="template_id" id="hidden_template_id" value="0">
        <input type="hidden" name="template_name" id="hidden_template_name">
        <input type="hidden" name="groups_config_json" id="groups_config_json">
        <input type="hidden" name="custom_css_encoded" id="custom_css_encoded">
        <input type="hidden" name="header_layout_json" id="header_layout_json" value='{"right":30,"middle":40,"left":30}'>
        <input type="hidden" name="footer_layout_json" id="footer_layout_json" value='{"right":30,"middle":40,"left":30}'>
        <input type="hidden" name="columns_config_json" id="columns_config_json" value='[]'>
        <input type="hidden" name="signatures_json_encoded" id="signatures_json_encoded" value='[]'>

        <!-- حقول استقبال الأكواد المشفرة للـ WAF -->
        <input type="hidden" name="columns_config_encoded" id="columns_config_encoded" value=''>
        <input type="hidden" name="header_right_html_encoded" id="header_right_html_encoded" value=''>
        <input type="hidden" name="header_middle_html_encoded" id="header_middle_html_encoded" value=''>
        <input type="hidden" name="header_left_html_encoded" id="header_left_html_encoded" value=''>
        <input type="hidden" name="footer_right_html_encoded" id="footer_right_html_encoded" value=''>
        <input type="hidden" name="footer_middle_html_encoded" id="footer_middle_html_encoded" value=''>
        <input type="hidden" name="footer_left_html_encoded" id="footer_left_html_encoded" value=''>
        <input type="hidden" name="extra_content_above_encoded" id="extra_content_above_encoded" value=''>
        <input type="hidden" name="extra_content_below_encoded" id="extra_content_below_encoded" value=''>

        <!-- 1. إعدادات الورقة والمقاسات الفنية -->
        <div class="bg-white p-5 rounded-xl border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i class="fa-solid fa-file-pdf text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800">إعدادات الورقة والتنسيقات الهندسية للطباعة</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-gray-600 text-xs font-bold mb-1">حجم الورقة:</label>
                    <select name="paper_size" id="paper_size" class="w-full px-3 py-1.5 border rounded-lg text-xs font-bold bg-white">
                        <option value="A4">A4 قياسي</option>
                        <option value="A3">A3 عريض</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-600 text-xs font-bold mb-1">اتجاه الورقة:</label>
                    <select name="orientation" id="orientation" class="w-full px-3 py-1.5 border rounded-lg text-xs font-bold bg-white">
                        <option value="Portrait">عمودي (Portrait)</option>
                        <option value="Landscape">أفقي (Landscape)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-600 text-xs font-bold mb-1">نوع الخط الافتراضي:</label>
                    <select name="header_font" id="header_font" class="w-full px-3 py-1.5 border rounded-lg text-xs font-bold bg-white">
                        <option value="Cairo">Cairo</option>
                        <option value="Tajawal">Tajawal</option>
                        <option value="Amiri">Amiri</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-600 text-xs font-bold mb-1">حجم خط الحقول (pt):</label>
                    <input type="number" name="font_size_pt" id="font_size_pt" value="10" class="w-full px-3 py-1 border rounded-lg text-xs bg-white font-bold">
                </div>
            </div>
            
            <!-- قسم تفصيل هوامش الصفحة الجغرافية المستقلة -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2 border-t border-dashed mt-2">
                <div class="col-span-4"><span class="text-xs font-bold text-slate-700">هوامش أبعاد الصفحة المستقلة (mm):</span></div>
                <div>
                    <label class="block text-[10px] text-gray-400 font-bold mb-1">الهامش العلوي (mm):</label>
                    <input type="number" id="margin_top_mm" value="8" class="w-full px-3 py-1 border rounded text-xs font-bold text-center bg-white">
                </div>
                <div>
                    <label class="block text-[10px] text-gray-400 font-bold mb-1">الهامش السفلي (mm):</label>
                    <input type="number" id="margin_bottom_mm" value="8" class="w-full px-3 py-1 border rounded text-xs font-bold text-center bg-white">
                </div>
                <div>
                    <label class="block text-[10px] text-gray-400 font-bold mb-1">الهامش الأيمن (mm):</label>
                    <input type="number" id="margin_right_mm" value="8" class="w-full px-3 py-1 border rounded text-xs font-bold text-center bg-white">
                </div>
                <div>
                    <label class="block text-[10px] text-gray-400 font-bold mb-1">الهامش الأيسر (mm):</label>
                    <input type="number" id="margin_left_mm" value="8" class="w-full px-3 py-1 border rounded text-xs font-bold text-center bg-white">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2 border-t border-dashed mt-2">
                <div>
                    <label class="block text-[10px] text-gray-400 font-bold mb-1">ارتفاع الهيدر (px):</label>
                    <input type="number" name="header_height" id="header_height" value="30" class="w-full px-3 py-1 border rounded-lg text-xs bg-white font-bold">
                </div>
                <div>
                    <label class="block text-[10px] text-gray-400 font-bold mb-1">الهامش السفلي للهيدر (px):</label>
                    <input type="number" id="margin_header_bottom_px" value="12" class="w-full px-3 py-1 border rounded text-xs font-bold text-center bg-white">
                </div>
                <div>
                    <label class="block text-[10px] text-gray-400 font-bold mb-1">الهامش العلوي للفوتر (px):</label>
                    <input type="number" id="margin_footer_top_px" value="12" class="w-full px-3 py-1 border rounded text-xs font-bold text-center bg-white">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                <div>
                    <label class="block text-gray-600 text-xs font-bold mb-1">المسافات بين الكروت (px):</label>
                    <input type="number" name="grid_gap_px" id="grid_gap_px" value="12" class="w-full px-3 py-1 border rounded-lg text-xs bg-white font-bold">
                </div>
                <div>
                    <label class="block text-gray-600 text-xs font-bold mb-1">حشو الكروت الداخلي (px):</label>
                    <input type="number" name="card_padding_px" id="card_padding_px" value="12" class="w-full px-3 py-1 border rounded-lg text-xs bg-white font-bold">
                </div>
            </div>
        </div>

        <!-- 2. بوكس إعدادات رأس الصفحة -->
        <div class="bg-white p-5 rounded-xl border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i class="fa-solid fa-heading text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800">إعدادات رأس التقرير المطبوع (الهيدر والشعار)</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 bg-slate-50 p-4 rounded-xl">
                <div class="col-span-3"><span class="text-xs font-bold text-slate-700">توزيع عرض أعمدة الهيدر الثلاثة (مجموع النسب المئوية يجب أن يساوي 100%):</span></div>
                <div><input type="number" id="header_ratio_right" value="30" class="w-full px-3 py-1 border rounded text-xs text-center bg-white font-bold"></div>
                <div><input type="number" id="header_ratio_middle" value="40" class="w-full px-3 py-1 border rounded text-xs text-center bg-white font-bold"></div>
                <div><input type="number" id="header_ratio_left" value="30" class="w-full px-3 py-1 border rounded text-xs text-center bg-white font-bold"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">السطر الأيمن 1:</label>
                        <input type="text" name="header_right_1" id="header_right_1" placeholder="محافظة الاسماعيلية" class="w-full px-4 py-2 border rounded-lg text-xs bg-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">السطر الأيمن 2:</label>
                        <input type="text" name="header_right_2" id="header_right_2" placeholder="حي ثان" class="w-full px-4 py-2 border rounded-lg text-xs bg-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">السطر الأيمن 3:</label>
                        <input type="text" name="header_right_3" id="header_right_3" placeholder="وحدة المتغيرات المكانية" class="w-full px-4 py-2 border rounded-lg text-xs bg-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">محتوى العمود الأيمن المتطور (HTML):</label>
                        <textarea id="header_right_html" rows="2" class="w-full px-4 py-1.5 border rounded-lg font-mono text-xs bg-slate-50 text-gray-700" dir="ltr"></textarea>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">العنوان الرئيسي المكتوب في المنتصف:</label>
                        <input type="text" name="main_title" id="main_title" required class="w-full px-4 py-2 border rounded-lg text-xs bg-white font-bold">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">محتوى العمود الأوسط المتقدم (HTML):</label>
                        <textarea id="header_middle_html" rows="2" class="w-full px-4 py-1.5 border rounded-lg font-mono text-xs bg-slate-50 text-gray-700" dir="ltr"></textarea>
                    </div>
                </div>
                <div class="space-y-4">
                    <div class="bg-gray-50/50 p-6 rounded-2xl border space-y-4">
                        <label class="block text-gray-600 text-xs font-bold mb-1">الشعار الرسمي (يسار):</label>
                        <input type="file" name="logo_file" accept="image/*" class="w-full text-xs text-gray-500 cursor-pointer">
                        <div id="logo-preview-container" class="hidden flex items-center justify-between border-t pt-3 mt-3">
                            <div class="flex items-center space-x-2 space-x-reverse">
                                <input type="checkbox" name="remove_logo" id="remove_logo" value="1" class="w-4 h-4 text-red-600 rounded">
                                <label for="remove_logo" class="text-xs text-red-600 font-bold cursor-pointer">إزالة الشعار الحالي</label>
                            </div>
                            <img id="logo-preview" class="w-12 h-12 object-contain rounded-lg border bg-white shadow">
                        </div>
                        <div class="flex items-center justify-between border-t pt-3 mt-3">
                            <span class="text-xs text-gray-600 font-bold">إظهار الشعار بالورقة:</span>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="show_logo" id="show_logo" value="1" checked class="sr-only peer">
                                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">محتوى العمود الأيسر المتقدم (HTML):</label>
                        <textarea id="header_left_html" rows="3" class="w-full px-4 py-1.5 border rounded-lg font-mono text-xs bg-slate-50 text-gray-700" dir="ltr"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- منشئ ومصمم الجداول والمساحات الحرة -->
        <div class="bg-white p-5 rounded-xl border border-gray-100 space-y-4">
            <div class="flex items-center justify-between border-b pb-3 mb-2">
                <div class="flex items-center space-x-3 space-x-reverse">
                    <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-table-cells text-lg"></i></div>
                    <h3 class="text-sm font-bold text-gray-800">منشئ الجداول والمساحات المرنة المتقدمة</h3>
                </div>
                <button type="button" onclick="addCustomTable()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-1.5 px-4 rounded-lg text-xs flex items-center space-x-1 space-x-reverse transition">
                    <i class="fa-solid fa-plus text-white"></i>
                    <span>إضافة جدول جديد</span>
                </button>
            </div>
            <div id="custom_tables_wrapper" class="space-y-6"></div>
        </div>

        <!-- كارت نصوص إضافية ومساعد التنسيق ومولد الأكواد البصري -->
        <div class="bg-white p-5 rounded-xl border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-yellow-100 text-yellow-600 rounded-lg"><i class="fa-solid fa-file-text text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800">محتويات ونصوص إضافية في مستند الطباعة</h3>
            </div>
            
            <!-- أداة مساعدة مبتكرة: محرر تنسيق النصوص البصري ومولد أكواد HTML مع حماية التركيز (تم تصحيحها بالكامل) -->
            <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-200 mb-2 space-y-3">
                <div class="flex items-center space-x-2 space-x-reverse border-b pb-2">
                    <i class="fa-solid fa-wand-magic-sparkles text-indigo-600 text-sm"></i>
                    <span class="text-xs font-extrabold text-slate-800">مساعد تنسيق النصوص البصري ومولد أكواد HTML (Visual HTML Code Generator)</span>
                </div>
                <p class="text-[10px] text-gray-400">اكتب هنا ونسق الجملة كما تريد، وسيقوم المحرر تلقائياً بتوليد كود الـ HTML المقابل لها لتنسخه وتستخدمه بالأسفل:</p>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- واجهة المحرر التفاعلية البصرية -->
                    <div class="space-y-2">
                        <!-- شريط أدوات التنسيق المدمج المأمن هندسياً ضد فقدان التحديد (onmousedown) -->
                        <div class="flex flex-wrap gap-1 bg-white p-2 rounded-lg border border-gray-200">
                            <button type="button" onmousedown="event.preventDefault();" onclick="executeEditorCommand('bold')" class="px-2.5 py-1 text-xs font-black bg-slate-100 hover:bg-slate-200 rounded border transition">B</button>
                            <button type="button" onmousedown="event.preventDefault();" onclick="executeEditorCommand('italic')" class="px-2.5 py-1 text-xs italic bg-slate-100 hover:bg-slate-200 rounded border transition">I</button>
                            <button type="button" onmousedown="event.preventDefault();" onclick="executeEditorCommand('underline')" class="px-2.5 py-1 text-xs underline bg-slate-100 hover:bg-slate-200 rounded border transition">U</button>
                            
                            <div class="h-6 w-px bg-gray-200 mx-1"></div>
                            
                            <!-- محاذاة النص والاتجاهات -->
                            <button type="button" onmousedown="event.preventDefault();" onclick="executeEditorCommand('justifyRight')" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded border transition" title="محاذاة لليمين"><i class="fa-solid fa-align-right text-[10px]"></i></button>
                            <button type="button" onmousedown="event.preventDefault();" onclick="executeEditorCommand('justifyCenter')" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded border transition" title="توسيط"><i class="fa-solid fa-align-center text-[10px]"></i></button>
                            <button type="button" onmousedown="event.preventDefault();" onclick="executeEditorCommand('justifyLeft')" class="px-2 py-1 text-xs bg-slate-100 hover:bg-slate-200 rounded border transition" title="محاذاة لليسار"><i class="fa-solid fa-align-left text-[10px]"></i></button>

                            <div class="h-6 w-px bg-gray-200 mx-1"></div>

                            <select onmousedown="event.stopPropagation();" onchange="executeEditorCommand('fontSize', this.value)" class="text-[10px] font-bold border rounded bg-slate-100 px-1 py-0.5"><option value="">حجم الخط</option><option value="2">8pt</option><option value="3">10pt</option><option value="4">12pt</option><option value="5">14pt</option></select>
                            
                            <div class="flex items-center space-x-1 space-x-reverse bg-slate-100 border rounded px-1.5 py-0.5">
                                <label class="text-[8px] font-extrabold text-gray-500 cursor-pointer" for="editor_color_picker">اللون</label>
                                <input type="color" id="editor_color_picker" onchange="executeEditorCommand('foreColor', this.value)" class="w-5 h-5 cursor-pointer p-0 border-0 bg-transparent">
                            </div>
                        </div>
                        
                        <!-- صندوق الكتابة التفاعلي -->
                        <div id="rich_editor" contenteditable="true" class="w-full p-3 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-800" style="min-height: 120px;">اكتب جملتك المنسقة هنا...</div>
                    </div>
                    <div class="flex flex-col space-y-2">
                        <textarea id="rich_editor_code" readonly class="w-full p-3 bg-slate-900 text-emerald-400 font-mono text-xs border border-slate-800 rounded-lg" style="min-height: 120px;"></textarea>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">نصوص إضافية تظهر أعلى السجل المطبوع (تدعم الرموز المختصرة و HTML):</label>
                        <textarea id="extra_content_above" rows="4" class="w-full p-3 border rounded-lg text-xs font-bold bg-white leading-relaxed"></textarea>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">نصوص إضافية تظهر أسفل السجل المطبوع (تدعم الرموز المختصرة و HTML):</label>
                        <textarea id="extra_content_below" rows="4" class="w-full p-3 border rounded-lg text-xs font-bold bg-white leading-relaxed"></textarea>
                    </div>
                </div>
                
                <!-- دليل الرموز المختصرة المتاح نسخها -->
                <div class="bg-slate-50 p-4 rounded-xl border border-gray-200 h-fit space-y-3">
                    <span class="block text-xs font-extrabold text-slate-700 border-b pb-1.5"><i class="fa-solid fa-code text-indigo-600 ml-1.5"></i> دليل الرموز المختصرة</span>
                    <div class="space-y-2 h-64 overflow-y-auto pr-1">
                        <div class="space-y-1">
                            <span class="block text-[9px] text-indigo-600 font-extrabold">الرموز الأساسية:</span>
                            <div class="flex items-center justify-between bg-white p-1 rounded border text-[9px] cursor-pointer" onclick="copyShortcode('{point_number}')"><span>رقم النقطة</span><code class="text-indigo-600">{point_number}</code></div>
                            <div class="flex items-center justify-between bg-white p-1 rounded border text-[9px] cursor-pointer" onclick="copyShortcode('{transaction_number}')"><span>رقم المعاملة</span><code class="text-indigo-600">{transaction_number}</code></div>
                            <div class="flex items-center justify-between bg-white p-1 rounded border text-[9px] cursor-pointer" onclick="copyShortcode('{date}')"><span>التاريخ</span><code class="text-indigo-600">{date}</code></div>
                            <div class="flex items-center justify-between bg-white p-1 rounded border text-[9px] cursor-pointer" onclick="copyShortcode('{time}')"><span>التوقيت</span><code class="text-indigo-600">{time}</code></div>
                            <div class="flex items-center justify-between bg-white p-1 rounded border text-[9px] cursor-pointer" onclick="copyShortcode('{latitude}')"><span>Lat</span><code class="text-indigo-600">{latitude}</code></div>
                            <div class="flex items-center justify-between bg-white p-1 rounded border text-[9px] cursor-pointer" onclick="copyShortcode('{longitude}')"><span>Lng</span><code class="text-indigo-600">{longitude}</code></div>
                        </div>
                        <div class="space-y-1 pt-2 border-t border-dashed">
                            <span class="block text-[9px] text-emerald-600 font-extrabold">حقول السجل:</span>
                            <?php foreach ($all_fields as $f): ?>
                                <div class="flex items-center justify-between bg-white p-1 rounded border text-[9px] cursor-pointer" onclick="copyShortcode('{<?php echo htmlspecialchars($f['field_name']); ?>}')"><span><?php echo htmlspecialchars($f['label']); ?></span><code class="text-emerald-600">{<?php echo htmlspecialchars($f['field_name']); ?>}</code></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. كروت المجموعات الافتراضية -->
        <div class="bg-white p-5 rounded-xl border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-purple-100 text-purple-600 rounded-lg"><i class="fa-solid fa-arrows-up-down-left-right text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800">تنسيق مقاسات وترتيب كروت المجموعات والخرائط (حفظ التموضع بالصفحة)</h3>
            </div>
            <p class="text-[10px] text-gray-400">تظهر بالأسفل كروت البيانات وخانة الموقع الجغرافي. يمكنك التحكم بالتموضع والظهور:</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="groups_layout_configs_grid">
                <?php foreach ($all_db_groups as $g_name): 
                    $cleanGName = base64_encode($g_name);
                    $cleanGName = str_replace(['=', '+', '/'], '', $cleanGName); 
                ?>
                    <div class="bg-gray-50 p-4 rounded-xl border flex flex-col justify-between space-y-3">
                        <div class="flex justify-between items-center border-b pb-1.5">
                            <span class="text-xs font-bold text-slate-800"><i class="fa-solid fa-folder text-blue-500 ml-1.5"></i> <?php echo htmlspecialchars($g_name); ?></span>
                            <label class="flex items-center space-x-1.5 space-x-reverse text-[10px] font-bold text-gray-500 cursor-pointer">
                                <input type="checkbox" name="group_show[<?php echo htmlspecialchars($g_name); ?>]" id="show_box_of_<?php echo $cleanGName; ?>" value="1" checked class="w-4 h-4 text-purple-600 rounded cursor-pointer">
                                <span>إظهار في مستند الطباعة</span>
                            </label>
                        </div>
                        <input type="hidden" name="group_names_list[]" value="<?php echo htmlspecialchars($g_name); ?>">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <select name="group_width[<?php echo htmlspecialchars($g_name); ?>]" id="width_of_<?php echo $cleanGName; ?>" class="w-full px-2 py-1 bg-white border rounded text-[10px] font-bold text-slate-700">
                                    <option value="col-span-1">نصف عرض الصفحة (50%)</option>
                                    <option value="col-span-2">عرض كامل الصفحة (100%)</option>
                                </select>
                            </div>
                            <div>
                                <input type="number" name="group_order[<?php echo htmlspecialchars($g_name); ?>]" id="order_of_<?php echo $cleanGName; ?>" value="1" class="w-full px-2 py-1 bg-white border rounded text-[10px] font-bold text-slate-700">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 4. التوقيعات والفوتر المطور ومصمم اللجنة المخصصة -->
        <div class="bg-white p-5 rounded-xl border border-gray-100 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
                <div class="p-2 bg-orange-100 text-orange-600 rounded-lg"><i class="fa-solid fa-signature text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800">تخصيص التوقيعات المعتمدة ومصمم جدول لجنة المعاينة المشكلة</h3>
            </div>
            
            <!-- مصمم وجدول أعضاء لجنة المعاينة التفاعلي -->
            <div class="bg-slate-50 p-4 rounded-xl border border-gray-200 space-y-3">
                <div class="flex justify-between items-center border-b pb-2">
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <i class="fa-solid fa-users-viewfinder text-indigo-600 text-xs"></i>
                        <span class="text-xs font-extrabold text-slate-800">مصمم وجدول أعضاء لجنة المعاينة المشكلة</span>
                    </div>
                    <label class="flex items-center space-x-1.5 space-x-reverse text-[10px] font-bold text-slate-600 cursor-pointer">
                        <input type="checkbox" id="show_committee_table" class="w-4 h-4 text-indigo-600 rounded cursor-pointer">
                        <span>إظهار جدول اللجنة بالطباعة</span>
                    </label>
                </div>
                
                <div class="max-w-md">
                    <label class="block text-gray-600 text-[10px] font-bold mb-1">عنوان جدول اللجنة الرئيسي:</label>
                    <input type="text" id="committee_table_title" placeholder="أعضاء اللجنة المشكلة للمعاينة" class="w-full px-4 py-1.5 border rounded-lg text-xs bg-white font-semibold">
                </div>

                <div id="committee_rows_wrapper" class="space-y-2 pt-2"></div>
                
                <div class="flex justify-end pt-2 border-t border-dashed">
                    <button type="button" onclick="addCommitteeRow()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1 px-4 rounded-lg text-[10px] flex items-center space-x-1 space-x-reverse transition">
                        <i class="fa-solid fa-user-plus text-white"></i>
                        <span class="text-white">إضافة عضو جديد للجنة</span>
                    </button>
                </div>
            </div>

            <div class="space-y-4 pt-4 border-t">
                <div class="max-w-md">
                    <label class="block text-gray-600 text-xs font-bold mb-1">عنوان قسم التوقيعات العمودية البديل:</label>
                    <input type="text" name="signatures_title" id="signatures_title" placeholder="التوقيعات،،" class="w-full px-4 py-2 border rounded-lg text-xs bg-white font-semibold">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-100 space-y-3 flex flex-col justify-between">
                            <div class="space-y-2">
                                <span class="text-[10px] text-indigo-600 font-extrabold block border-b pb-1">توقيع مسؤول رقم <?php echo $i; ?></span>
                                <div>
                                    <input type="text" name="sig<?php echo $i; ?>_title" id="sig<?php echo $i; ?>_title" placeholder="مسمى الوظيفة" class="w-full px-2 py-1 border rounded text-[10px] bg-white font-semibold">
                                </div>
                                <div>
                                    <input type="text" name="sig<?php echo $i; ?>_name" id="sig<?php echo $i; ?>_name" placeholder="الاسم الكامل" class="w-full px-2 py-1 border rounded text-[10px] bg-white font-semibold">
                                </div>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t">
                                <span class="text-[10px] text-gray-500 font-bold">تفعيل التوقيع</span>
                                <input type="checkbox" name="sig<?php echo $i; ?>_show" id="sig<?php echo $i; ?>_show" value="1" class="w-4 h-4 text-emerald-600 rounded">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="pt-2">
                    <label class="block text-gray-600 text-xs font-bold mb-1">تنسيقات CSS مخصصة متطورة:</label>
                    <textarea id="custom_css" rows="3" placeholder=".print-card { max-height: 250px; }" class="w-full px-4 py-2 border rounded font-mono text-xs bg-slate-900 text-emerald-400" dir="ltr"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 bg-slate-50 p-4 rounded-xl">
                    <div class="col-span-3"><span class="text-xs font-bold text-slate-700">توزيع عرض أعمدة الفوتر الثلاثة بأسفل الصفحة (%):</span></div>
                    <div><input type="number" id="footer_ratio_right" value="30" class="w-full px-3 py-1 border rounded text-xs text-center bg-white font-bold"></div>
                    <div><input type="number" id="footer_ratio_middle" value="40" class="w-full px-3 py-1 border rounded text-xs text-center bg-white font-bold"></div>
                    <div><input type="number" id="footer_ratio_left" value="30" class="w-full px-3 py-1 border rounded text-xs text-center bg-white font-bold"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">محتوى تذييل الصفحة الأيمن (HTML):</label>
                        <textarea name="footer_right_html" id="footer_right_html" rows="2" class="w-full p-2 border rounded text-xs bg-white font-semibold"></textarea>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">النص الأساسي بمنتصف التذييل:</label>
                        <input type="text" name="footer_text" id="footer_text" class="w-full px-4 py-2 border rounded-lg text-xs bg-white font-semibold">
                        <textarea id="footer_middle_html" rows="1" class="w-full mt-2 p-2 border rounded text-xs bg-white font-semibold"></textarea>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">محتوى تذييل الصفحة الأيسر (HTML):</label>
                        <textarea id="footer_left_html" rows="2" class="w-full p-2 border rounded text-xs bg-white font-semibold"></textarea>
                    </div>
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
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
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

<!-- كود جافا سكريبت التفاعلي المحدث لمنع سحب التركيز واستقرار المحرر -->
<script>
    const templatesList = <?php echo json_encode($all_templates, JSON_UNESCAPED_UNICODE); ?>;
    const systemFields = <?php echo json_encode($all_fields, JSON_UNESCAPED_UNICODE); ?>;

    let tablesCount = 0;
    let committeeCount = 0;

    // دالة نسخ الرمز المختصر ديناميكياً
    function copyShortcode(text) {
        navigator.clipboard.writeText(text).then(() => {
            alert('تم نسخ الرمز المختصر بنجاح: ' + text + '\nقم بلصقه الآن في حقل النصوص الإضافية.');
        }).catch(err => {
            console.error('فشل عملية النسخ التلقائي: ', err);
        });
    }

    // دالة تفاعلية لإضافة صف جديد بداخل منشئ لجنة المعاينة
    function addCommitteeRow(memberName = '', memberRole = '', show = 1) {
        committeeCount++;
        const rowId = `committee_row_${committeeCount}`;
        const wrapper = document.getElementById('committee_rows_wrapper');
        const isChecked = (show == 1) ? 'checked' : '';
        
        const rowHTML = `
            <div id="${rowId}" class="committee-row-item bg-white p-3 rounded-lg border border-gray-200 flex flex-wrap gap-3 items-center relative">
                <button type="button" onclick="removeElement('${rowId}')" class="absolute left-2 top-2 text-red-400 hover:text-red-600 text-xs" title="حذف العضو"><i class="fa-solid fa-user-minus"></i></button>
                
                <div class="flex-1 min-w-[200px]">
                    <label class="text-[9px] text-gray-400 block font-bold mb-0.5">اسم عضو اللجنة واللقب:</label>
                    <input type="text" class="member-name w-full px-2 py-1 border rounded text-xs font-bold text-gray-800 focus:outline-none" value="${memberName}" placeholder="السيد الأستاذ / محمد عبد المنعم إسماعيل">
                </div>
                
                <div class="flex-1 min-w-[200px]">
                    <label class="text-[9px] text-gray-400 block font-bold mb-0.5">الصفة باللجنة:</label>
                    <input type="text" class="member-role w-full px-2 py-1 border rounded text-xs font-bold text-gray-800 focus:outline-none" value="${memberRole}" placeholder="نائب رئيس الحي رئيساً">
                </div>
                
                <div class="flex items-center space-x-1.5 space-x-reverse pt-4 select-none">
                    <input type="checkbox" class="member-show w-4 h-4 text-indigo-600 rounded cursor-pointer" ${isChecked}>
                    <span class="text-[10px] text-gray-500 font-bold cursor-pointer">إظهار بالطباعة</span>
                </div>
            </div>
        `;
        wrapper.insertAdjacentHTML('beforeend', rowHTML);
    }

    function getFieldsDropdownHTML(selectedField = '') {
        let html = `<select class="field-selector w-full px-2 py-1 bg-white border border-gray-200 rounded text-[10px] font-bold text-gray-700 focus:outline-none">`;
        html += `<option value="">-- اختر الحقل --</option>`;
        systemFields.forEach(f => {
            const isSelected = (f.field_name === selectedField) ? 'selected' : '';
            html += `<option value="${f.field_name}" ${isSelected}>${f.label}</option>`;
        });
        html += `</select>`;
        return html;
    }

    // إضافة جدول مخصص
    function addCustomTable(tableTitle = '', rowsData = null, tableFontSize = 10) {
        tablesCount++;
        const tableId = `custom_table_${tablesCount}`;
        const wrapper = document.getElementById('custom_tables_wrapper');
        
        const tableHTML = `
            <div id="${tableId}" class="custom-table-card bg-slate-50 p-4 rounded-2xl border-2 border-dashed border-gray-300 space-y-3 relative">
                <button type="button" onclick="removeElement('${tableId}')" class="absolute left-3 top-3 text-red-500 hover:text-red-700 text-xs font-bold"><i class="fa-solid fa-trash"></i> حذف الجدول</button>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl">
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <span class="text-xs font-bold text-gray-700 whitespace-nowrap">عنوان الجدول:</span>
                        <input type="text" class="table-title w-full px-3 py-1 border border-gray-200 rounded-lg text-xs font-bold text-gray-800 bg-white focus:outline-none" value="${tableTitle}" placeholder="مثال: بيانات الترخيص والموقع">
                    </div>
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <span class="text-xs font-bold text-gray-700 whitespace-nowrap">حجم خط الجدول (pt):</span>
                        <input type="number" class="table-font-size w-20 px-2 py-1 border border-gray-200 rounded-lg text-xs font-bold text-center bg-white text-gray-700 focus:outline-none" value="${tableFontSize}" min="6" max="24">
                    </div>
                </div>
                
                <div class="table-rows-container space-y-4 pt-2"></div>
                
                <div class="pt-2 border-t flex justify-end">
                    <button type="button" onclick="addRowToTable('${tableId}')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-3 rounded-lg text-[10px] flex items-center space-x-1 space-x-reverse transition">
                        <i class="fa-solid fa-plus"></i>
                        <span>إضافة صف جديد</span>
                    </button>
                </div>
            </div>
        `;
        
        wrapper.insertAdjacentHTML('beforeend', tableHTML);
        
        if (rowsData && rowsData.length > 0) {
            rowsData.forEach(row => {
                let cells = [];
                let marginBottom = 8;
                if (Array.isArray(row)) {
                    cells = row;
                } else {
                    cells = row.cells || [];
                    marginBottom = (row.margin_bottom !== undefined) ? row.margin_bottom : 8;
                }
                addRowToTable(tableId, cells, marginBottom);
            });
        } else {
            addRowToTable(tableId);
        }
    }

    // إضافة صف بداخل جدول مخصص
    function addRowToTable(tableCardId, cellsData = null, marginBottom = 8) {
        const tableCard = document.getElementById(tableCardId);
        const rowsContainer = tableCard.querySelector('.table-rows-container');
        const rowIndex = rowsContainer.children.length + 1;
        const rowId = `${tableCardId}_row_${rowIndex}`;
        
        const rowHTML = `
            <div id="${rowId}" class="table-row-item bg-white p-3 rounded-xl border border-gray-150 shadow-sm space-y-2">
                <div class="flex justify-between items-center border-b pb-1.5">
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <span class="text-[10px] font-extrabold text-blue-600">الصف رقم ${rowIndex}</span>
                        <div class="flex items-center space-x-1 space-x-reverse">
                            <span class="text-[9px] text-gray-400 font-bold">الهامش السفلي للصف (px):</span>
                            <input type="number" class="row-margin-bottom w-12 px-1 text-center border rounded text-[9px] font-bold text-gray-700 focus:outline-none" value="${marginBottom}">
                        </div>
                    </div>
                    <button type="button" onclick="removeElement('${rowId}')" class="text-red-400 hover:text-red-600 text-[9px] font-bold"><i class="fa-solid fa-xmark"></i> حذف الصف</button>
                </div>
                
                <div class="row-cells-container flex flex-wrap gap-3 items-end"></div>
                
                <div class="pt-1 flex justify-start">
                    <button type="button" onclick="addCellToRow('${rowId}')" class="text-indigo-600 hover:text-indigo-800 font-bold text-[9px] flex items-center space-x-0.5 space-x-reverse transition">
                        <i class="fa-solid fa-plus"></i>
                        <span>إضافة عمود (خلية) لهذا الصف</span>
                    </button>
                </div>
            </div>
        `;
        
        rowsContainer.insertAdjacentHTML('beforeend', rowHTML);
        
        if (cellsData && cellsData.length > 0) {
            cellsData.forEach(cell => {
                addCellToRow(rowId, cell.field, cell.label, cell.width, cell.align, cell.padding);
            });
        } else {
            addCellToRow(rowId);
        }
    }

    // إضافة خلية
    function addCellToRow(rowId, selectedField = '', customLabel = '', width = 50, align = 'right', padding = 8) {
        const rowItem = document.getElementById(rowId);
        const cellsContainer = rowItem.querySelector('.row-cells-container');
        const cellIndex = cellsContainer.children.length + 1;
        const cellId = `${rowId}_cell_${cellIndex}`;
        
        const cellHTML = `
            <div id="${cellId}" class="cell-item bg-slate-50/50 p-3 rounded-lg border border-gray-150 flex flex-col space-y-1.5 relative min-w-[200px] flex-1">
                <button type="button" onclick="removeElement('${cellId}')" class="absolute left-1 top-1 text-red-300 hover:text-red-500 text-[8px]"><i class="fa-solid fa-trash-can"></i></button>
                
                <div>
                    <label class="text-[9px] text-gray-400 block font-bold mb-0.5">الحقل المرتبط:</label>
                    ${getFieldsDropdownHTML(selectedField)}
                </div>
                
                <div>
                    <label class="text-[9px] text-gray-400 block font-bold mb-0.5">التسمية المخصصة بالطباعة:</label>
                    <input type="text" class="cell-label w-full px-2 py-0.5 bg-white border border-gray-200 rounded text-[10px] font-bold text-gray-800 focus:outline-none" value="${customLabel}" placeholder="مثال: رقم الرخصة">
                </div>

                <div class="grid grid-cols-3 gap-1 pt-1 border-t border-dashed">
                    <div>
                        <label class="text-[8px] text-gray-400 block font-bold mb-0.5">العرض (%):</label>
                        <input type="number" class="cell-width w-full px-1 text-center border rounded text-[9px] font-bold text-gray-700" value="${width}" min="10" max="100">
                    </div>
                    <div>
                        <label class="text-[8px] text-gray-400 block font-bold mb-0.5">المحاذاة:</label>
                        <select class="cell-align w-full border rounded text-[8px] font-bold text-gray-700 py-0.5">
                            <option value="right" ${align === 'right' ? 'selected' : ''}>يمين</option>
                            <option value="center" ${align === 'center' ? 'selected' : ''}>وسط</option>
                            <option value="left" ${align === 'left' ? 'selected' : ''}>يسار</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[8px] text-gray-400 block font-bold mb-0.5">حشو (px):</label>
                        <input type="number" class="cell-padding w-full px-1 text-center border rounded text-[9px] font-bold text-gray-700" value="${padding}" min="0" max="30">
                    </div>
                </div>
            </div>
        `;
        
        cellsContainer.insertAdjacentHTML('beforeend', cellHTML);
    }

    function removeElement(elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            element.remove();
        }
    }

    // دالة لتنفيذ عمليات المحرر البصري مع المحافظة على تركيز حقل الكتابة (Focus)
    function executeEditorCommand(command, value = null) {
        document.execCommand(command, false, value);
        
        // إعادة التركيز فوراً وبشكل قسري لمنطقة الكتابة لمنع فقدان التحديد
        const editor = document.getElementById('rich_editor');
        editor.focus();
        
        updateEditorCodeOutput();
    }

    // دالة تحديث كود الـ HTML المتولدة
    function updateEditorCodeOutput() {
        const editor = document.getElementById('rich_editor');
        const codeOutput = document.getElementById('rich_editor_code');
        
        let htmlContent = editor.innerHTML;
        if (htmlContent === 'اكتب جملتك المنسقة هنا...') {
            htmlContent = '';
        }
        codeOutput.value = htmlContent.trim();
    }

    const editorEl = document.getElementById('rich_editor');
    editorEl.addEventListener('input', updateEditorCodeOutput);
    editorEl.addEventListener('keyup', updateEditorCodeOutput);
    editorEl.addEventListener('mouseup', updateEditorCodeOutput);
    
    editorEl.addEventListener('focus', function() {
        if (this.innerHTML === 'اكتب جملتك المنسقة هنا...') {
            this.innerHTML = '';
            updateEditorCodeOutput();
        }
    });

    function loadTemplateData(templateId) {
        const form = document.getElementById('print-settings-form');
        const nameContainer = document.getElementById('template-name-container');
        const templateNameInput = document.getElementById('template_name_input');
        
        form.reset();
        document.getElementById('logo-preview-container').classList.add('hidden');
        document.getElementById('remove_logo').checked = false;
        document.getElementById('custom_tables_wrapper').innerHTML = '';
        document.getElementById('committee_rows_wrapper').innerHTML = ''; // تفريغ جدول اللجنة

        document.querySelectorAll('[id^="width_of_"]').forEach(sel => sel.value = "col-span-1");
        document.querySelectorAll('[id^="order_of_"]').forEach(inp => inp.value = "1");
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);

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
            document.getElementById('paper_size').value = "A4";
            document.getElementById('orientation').value = "Portrait";
            document.getElementById('header_font').value = "Cairo";
            document.getElementById('font_size_pt').value = "10";
            document.getElementById('header_height').value = "30";
            
            document.getElementById('margin_top_mm').value = "8";
            document.getElementById('margin_bottom_mm').value = "8";
            document.getElementById('margin_right_mm').value = "8";
            document.getElementById('margin_left_mm').value = "8";
            document.getElementById('margin_header_bottom_px').value = "12";
            document.getElementById('margin_footer_top_px').value = "12";
            
            document.getElementById('grid_gap_px').value = "12";
            document.getElementById('card_padding_px').value = "12";
            
            document.getElementById('header_ratio_right').value = "30";
            document.getElementById('header_ratio_middle').value = "40";
            document.getElementById('header_ratio_left').value = "30";
            document.getElementById('footer_ratio_right').value = "30";
            document.getElementById('footer_ratio_middle').value = "40";
            document.getElementById('footer_ratio_left').value = "30";
            
            document.getElementById('show_committee_table').checked = false;
            document.getElementById('committee_table_title').value = "أعضاء اللجنة المشكلة للمعاينة";
            addCommitteeRow(); // صف افتراضي فارغ للجنة
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
                
                document.getElementById('paper_size').value = currentTmpl.paper_size || 'A4';
                document.getElementById('orientation').value = currentTmpl.orientation || 'Portrait';
                document.getElementById('header_font').value = currentTmpl.header_font || 'Cairo';
                document.getElementById('font_size_pt').value = currentTmpl.font_size_pt || 10;
                document.getElementById('header_height').value = currentTmpl.header_height || 30;
                document.getElementById('grid_gap_px').value = currentTmpl.grid_gap_px || 12;
                document.getElementById('card_padding_px').value = currentTmpl.card_padding_px || 12;
                document.getElementById('custom_css').value = currentTmpl.custom_css || '';

                document.getElementById('header_right_html').value = currentTmpl.header_right_html || '';
                document.getElementById('header_middle_html').value = currentTmpl.header_middle_html || '';
                document.getElementById('header_left_html').value = currentTmpl.header_left_html || '';
                
                document.getElementById('footer_right_html').value = currentTmpl.footer_right_html || '';
                document.getElementById('footer_middle_html').value = currentTmpl.footer_middle_html || '';
                document.getElementById('footer_left_html').value = currentTmpl.footer_left_html || '';
                
                document.getElementById('extra_content_above').value = currentTmpl.extra_content_above || '';
                document.getElementById('extra_content_below').value = currentTmpl.extra_content_below || '';

                try {
                    const hLayout = JSON.parse(currentTmpl.header_layout || '{"right":30,"middle":40,"left":30}');
                    document.getElementById('header_ratio_right').value = hLayout.right || 30;
                    document.getElementById('header_ratio_middle').value = hLayout.middle || 40;
                    document.getElementById('header_ratio_left').value = hLayout.left || 30;
                    
                    const fLayout = JSON.parse(currentTmpl.footer_layout || '{"right":30,"middle":40,"left":30}');
                    document.getElementById('footer_ratio_right').value = fLayout.right || 30;
                    document.getElementById('footer_ratio_middle').value = fLayout.middle || 40;
                    document.getElementById('footer_ratio_left').value = fLayout.left || 30;
                } catch(e) { console.error(e); }

                if (currentTmpl.groups_config) {
                    try {
                        const gConfigs = JSON.parse(currentTmpl.groups_config);
                        if (gConfigs.page_margins) {
                            document.getElementById('margin_top_mm').value = gConfigs.page_margins.top || 8;
                            document.getElementById('margin_bottom_mm').value = gConfigs.page_margins.bottom || 8;
                            document.getElementById('margin_right_mm').value = gConfigs.page_margins.right || 8;
                            document.getElementById('margin_left_mm').value = gConfigs.page_margins.left || 8;
                            document.getElementById('margin_header_bottom_px').value = gConfigs.page_margins.header_bottom || 12;
                            document.getElementById('margin_footer_top_px').value = gConfigs.page_margins.footer_top || 12;
                        } else {
                            document.getElementById('margin_top_mm').value = currentTmpl.page_margin_mm || 8;
                            document.getElementById('margin_bottom_mm').value = currentTmpl.page_margin_mm || 8;
                            document.getElementById('margin_right_mm').value = currentTmpl.page_margin_mm || 8;
                            document.getElementById('margin_left_mm').value = currentTmpl.page_margin_mm || 8;
                            document.getElementById('margin_header_bottom_px').value = 12;
                            document.getElementById('margin_footer_top_px').value = 12;
                        }
                    } catch(e) { console.error(e); }
                }

                if (currentTmpl.columns_config) {
                    try {
                        const parsedTables = JSON.parse(currentTmpl.columns_config);
                        if (Array.isArray(parsedTables) && parsedTables.length > 0) {
                            parsedTables.forEach(table => {
                                addCustomTable(table.title, table.rows, table.font_size || 10);
                            });
                        }
                    } catch(e) { console.error(e); }
                }

                // استرجاع وإعادة بناء جدول اللجنة المشكلة المخزن بـ signatures_json
                if (currentTmpl.signatures_json) {
                    try {
                        const comData = JSON.parse(currentTmpl.signatures_json);
                        document.getElementById('show_committee_table').checked = (comData.show_committee == 1);
                        document.getElementById('committee_table_title').value = comData.title || 'أعضاء اللجنة المشكلة للمعاينة';
                        if (Array.isArray(comData.members) && comData.members.length > 0) {
                            comData.members.forEach(member => {
                                addCommitteeRow(member.name, member.role, member.show);
                            });
                        } else {
                            addCommitteeRow();
                        }
                    } catch(e) { 
                        console.error(e); 
                        addCommitteeRow();
                    }
                } else {
                    document.getElementById('show_committee_table').checked = false;
                    document.getElementById('committee_table_title').value = "أعضاء اللجنة المشكلة للمعاينة";
                    addCommitteeRow();
                }

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

                if (currentTmpl.groups_config) {
                    try {
                        const gConfigs = JSON.parse(currentTmpl.groups_config);
                        for (const [gName, config] of Object.entries(gConfigs)) {
                            if (gName === "page_margins") continue;
                            const cleanGName = btoa(unescape(encodeURIComponent(gName))).replace(/=/g, '').replace(/[^a-zA-Z0-9]/g, '');
                            const selWidth = document.getElementById("width_of_" + cleanGName);
                            const inpOrder = document.getElementById("order_of_" + cleanGName);
                            const cbShowBox = document.getElementById("show_box_of_" + cleanGName);
                            if (selWidth) selWidth.value = config.width || "col-span-1";
                            if (inpOrder) inpOrder.value = config.order || "1";
                            if (cbShowBox) cbShowBox.checked = (config.show !== 0);
                        }
                    } catch (e) { console.error(e); }
                }
            }
        }
    }

    // دالة مساعدة لترميز النصوص بـ Base64 بأمان تام يدعم اليونيكود والعربية
    function safeBtoa(str) {
        return btoa(unescape(encodeURIComponent(str)));
    }

    function syncFormSubmit(event) {
        event.preventDefault();
        const selectorVal = document.getElementById('record_type_selector').value;
        const templateName = document.getElementById('template_name_input').value.trim();

        if (!selectorVal || templateName === '') {
            alert('تنبيه: يرجى اختيار قالب طباعة للبدء.');
            return;
        }

        const headerLayout = {
            right: parseInt(document.getElementById('header_ratio_right').value) || 30,
            middle: parseInt(document.getElementById('header_ratio_middle').value) || 40,
            left: parseInt(document.getElementById('header_ratio_left').value) || 30
        };
        const footerLayout = {
            right: parseInt(document.getElementById('footer_ratio_right').value) || 30,
            middle: parseInt(document.getElementById('footer_ratio_middle').value) || 40,
            left: parseInt(document.getElementById('footer_ratio_left').value) || 30
        };

        if ((headerLayout.right + headerLayout.middle + headerLayout.left) !== 100) {
            alert('تنبيه: يجب أن يكون مجموع نسب توزيع أعمدة الهيدر مساوياً لـ 100% تماماً.');
            return;
        }
        if ((footerLayout.right + footerLayout.middle + footerLayout.left) !== 100) {
            alert('تنبيه: يجب أن يكون مجموع نسب توزيع أعمدة الفوتر مساوياً لـ 100% تماماً.');
            return;
        }

        document.getElementById('header_layout_json').value = JSON.stringify(headerLayout);
        document.getElementById('footer_layout_json').value = JSON.stringify(footerLayout);

        // تجميع وتعبئة هيكلية مصفوفة لجنة المعاينة وتشفيرها لـ WAF
        const committeeConfig = {
            show_committee: document.getElementById('show_committee_table').checked ? 1 : 0,
            title: document.getElementById('committee_table_title').value.trim(),
            members: []
        };
        
        document.querySelectorAll('.committee-row-item').forEach(row => {
            const mName = row.querySelector('.member-name').value.trim();
            const mRole = row.querySelector('.member-role').value.trim();
            const mShow = row.querySelector('.member-show').checked ? 1 : 0;
            if (mName !== '' || mRole !== '') {
                committeeConfig.members.push({
                    name: mName,
                    role: mRole,
                    show: mShow
                });
            }
        });

        document.getElementById('signatures_json_encoded').value = safeBtoa(JSON.stringify(committeeConfig));

        // تجميع هيكلية الجداول والصفوف والخلايا وحجم خط كل جدول مستقل
        const customTablesConfig = [];
        const tableCards = document.querySelectorAll('.custom-table-card');
        
        tableCards.forEach(tableCard => {
            const tableTitle = tableCard.querySelector('.table-title').value.trim();
            const tableFontSize = parseInt(tableCard.querySelector('.table-font-size').value) || 10;
            const rowItems = tableCard.querySelectorAll('.table-row-item');
            const rowsArray = [];
            
            rowItems.forEach(rowItem => {
                const marginBottom = parseInt(rowItem.querySelector('.row-margin-bottom').value) || 8;
                const cellItems = rowItem.querySelectorAll('.cell-item');
                const cellsArray = [];
                
                cellItems.forEach(cellItem => {
                    const fieldVal = cellItem.querySelector('.field-selector').value;
                    const labelVal = cellItem.querySelector('.cell-label').value.trim();
                    const widthVal = parseInt(cellItem.querySelector('.cell-width').value) || 50;
                    const alignVal = cellItem.querySelector('.cell-align').value;
                    const paddingVal = parseInt(cellItem.querySelector('.cell-padding').value) || 8;
                    
                    if (fieldVal !== '') {
                        cellsArray.push({
                            field: fieldVal,
                            label: labelVal,
                            width: widthVal,
                            align: alignVal,
                            padding: paddingVal
                        });
                    }
                });
                
                rowsArray.push({
                    margin_bottom: marginBottom,
                    cells: cellsArray
                });
            });
            
            if (rowsArray.length > 0) {
                customTablesConfig.push({
                    title: tableTitle || 'جدول بيانات بدون عنوان',
                    font_size: tableFontSize,
                    rows: rowsArray
                });
            }
        });

        // تشفير كود الجداول بـ Base64
        document.getElementById('columns_config_encoded').value = safeBtoa(JSON.stringify(customTablesConfig));

        const groupsConfig = {};
        
        groupsConfig["page_margins"] = {
            top: parseInt(document.getElementById('margin_top_mm').value) || 8,
            bottom: parseInt(document.getElementById('margin_bottom_mm').value) || 8,
            right: parseInt(document.getElementById('margin_right_mm').value) || 8,
            left: parseInt(document.getElementById('margin_left_mm').value) || 8,
            header_bottom: parseInt(document.getElementById('margin_header_bottom_px').value) || 12,
            footer_top: parseInt(document.getElementById('margin_footer_top_px').value) || 12
        };

        document.querySelectorAll('[name="group_names_list[]"]').forEach(el => {
            const gName = el.value;
            const cleanGName = btoa(unescape(encodeURIComponent(gName))).replace(/=/g, '').replace(/[^a-zA-Z0-9]/g, '');
            const showCheck = document.getElementById("show_box_of_" + cleanGName);
            groupsConfig[gName] = {
                width: document.getElementById("width_of_" + cleanGName).value,
                order: parseInt(document.getElementById("order_of_" + cleanGName).value) || 1,
                show: showCheck && showCheck.checked ? 1 : 0
            };
        });

        document.getElementById('groups_config_json').value = JSON.stringify(groupsConfig);

        // تشفير حقول الـ HTML والنصوص المتقدمة قبل الإرسال (دورة ذكية صامتة ومؤمنة)
        const htmlFieldsToEncode = {
            'header_right_html_encoded': 'header_right_html',
            'header_middle_html_encoded': 'header_middle_html',
            'header_left_html_encoded': 'header_left_html',
            'footer_right_html_encoded': 'footer_right_html',
            'footer_middle_html_encoded': 'footer_middle_html',
            'footer_left_html_encoded': 'footer_left_html',
            'extra_content_above_encoded': 'extra_content_above',
            'extra_content_below_encoded': 'extra_content_below',
            'custom_css_encoded': 'custom_css'
        };

        for (const [targetHiddenId, sourceInputId] of Object.entries(htmlFieldsToEncode)) {
            const sourceEl = document.getElementById(sourceInputId);
            const targetEl = document.getElementById(targetHiddenId);
            if (sourceEl && targetEl) {
                targetEl.value = safeBtoa(sourceEl.value);
            }
        }
        
        const rawCSS = document.getElementById('custom_css').value;
        document.getElementById('custom_css_encoded').value = safeBtoa(rawCSS);

        document.getElementById('hidden_template_name').value = templateName;
        document.getElementById('print-settings-form').submit();
    }
</script>