<?php
// api/save-template.php - واجهة برمجية خلفية لتحديث قوالب الطباعة (النسخة النهائية الآمنة لبيئات PHP 8.4)

// 1. استدعاء النواة البرمجية وقاعدة البيانات والإعدادات لتشغيل الجلسة المؤمنة فورياً
require_once '../db.php';

header('Content-Type: application/json');

// 2. التحقق من صلاحية دخول حساب مدير النظام
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'عذراً، غير مصرح لك بالوصول لإجراء هذه العملية الإدارية.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        
        // 3. التحقق الأمني الإجباري من توكن الجلسة CSRF لحماية تحديث القوالب
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).']);
            exit;
        }

        $id = intval($_POST['template_id'] ?? 0);
        
        // تجهيز مصفوفات البيانات الديناميكية للجداول المخصصة
        $columns_config = [];
        if (isset($_POST['column_names']) && is_array($_POST['column_names'])) {
            foreach ($_POST['column_names'] as $name) {
                $columns_config[$name] = [
                    'width' => $_POST['col_width'][$name] ?? 'auto',
                    'color' => $_POST['col_color'][$name] ?? '#ffffff',
                    'order' => intval($_POST['col_order'][$name] ?? 0)
                ];
            }
        }

        // تجهيز مصفوفات بيانات التوقيعات بدقة
        $sigs = [];
        if (isset($_POST['sig_titles']) && is_array($_POST['sig_titles'])) {
            foreach ($_POST['sig_titles'] as $i => $t) {
                $sigs[] = [
                    'title' => $t,
                    'name' => $_POST['sig_names'][$i] ?? '',
                    'size' => intval($_POST['sig_sizes'][$i] ?? 12),
                    'weight' => $_POST['sig_weights'][$i] ?? 'normal'
                ];
            }
        }

        $sql = "UPDATE print_templates SET 
                template_name = :name, main_title = :m_title, signatures_title = :s_title,
                show_logo = :s_logo, paper_size = :p_size, orientation = :ori, 
                header_font = :h_font, header_height = :h_h, row_height = :r_h,
                font_size_pt = :f_size, card_padding_px = :c_pad, page_margin_mm = :p_mar,
                grid_gap_px = :g_gap, header_layout = :h_lay, footer_layout = :f_lay,
                columns_config = :cols, groups_config = :groups, signatures_json = :sigs,
                header_right_html = :h_r, header_middle_html = :h_m, header_left_html = :h_l,
                extra_content_above = :e_a, extra_content_below = :e_b,
                footer_right_html = :f_r, footer_middle_html = :f_m, footer_left_html = :f_l,
                custom_css = :css WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'name' => $_POST['template_name'] ?? 'نموذج طباعة جديد', 
            'm_title' => $_POST['main_title'] ?? 'محضر معاينة ميدانية', 
            's_title' => $_POST['signatures_title'] ?? 'التوقيعات،،',
            's_logo' => isset($_POST['show_logo']) ? 1 : 0, 
            'p_size' => $_POST['paper_size'] ?? 'A4', 
            'ori' => $_POST['orientation'] ?? 'Portrait',
            'h_font' => $_POST['header_font'] ?? 'Cairo', 
            'h_h' => intval($_POST['header_height'] ?? 30), 
            'r_h' => intval($_POST['row_height'] ?? 24),
            'f_size' => intval($_POST['font_size_pt'] ?? 10), 
            'c_pad' => intval($_POST['card_padding_px'] ?? 12), 
            'p_mar' => intval($_POST['page_margin_mm'] ?? 8),
            'g_gap' => intval($_POST['grid_gap_px'] ?? 12), 
            'h_lay' => json_encode(['right'=> intval($_POST['header_w_right'] ?? 30), 'middle'=> intval($_POST['header_w_middle'] ?? 40), 'left'=> intval($_POST['header_w_left'] ?? 30)]),
            'f_lay' => json_encode(['right'=> intval($_POST['footer_w_right'] ?? 30), 'middle'=> intval($_POST['footer_w_middle'] ?? 40), 'left'=> intval($_POST['footer_w_left'] ?? 30)]),
            'cols' => json_encode($columns_config, JSON_UNESCAPED_UNICODE),
            'groups' => json_encode($_POST['group_show'] ?? [], JSON_UNESCAPED_UNICODE),
            'sigs' => json_encode($sigs, JSON_UNESCAPED_UNICODE),
            // فك التشفير الآمن لحماية الـ WAF وتلافي تحذيرات ومخالفات PHP 8.4 باستخدام دمج معامل Null
            'h_r' => base64_decode($_POST['header_right_html_encoded'] ?? ''), 
            'h_m' => base64_decode($_POST['header_middle_html_encoded'] ?? ''),
            'h_l' => base64_decode($_POST['header_left_html_encoded'] ?? ''), 
            'e_a' => base64_decode($_POST['extra_content_above_encoded'] ?? ''),
            'e_b' => base64_decode($_POST['extra_content_below_encoded'] ?? ''), 
            'f_r' => base64_decode($_POST['footer_right_html_encoded'] ?? ''),
            'f_m' => base64_decode($_POST['footer_middle_html_encoded'] ?? ''), 
            'f_l' => base64_decode($_POST['footer_left_html_encoded'] ?? ''),
            'css' => base64_decode($_POST['custom_css_encoded'] ?? ''), 
            'id' => $id
        ]);

        echo json_encode(['success' => true, 'message' => 'تم تحديث الإعدادات وقوالب الطباعة وجداول البيانات بنجاح']);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()]);
    }
}