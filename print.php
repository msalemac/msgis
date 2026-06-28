<?php
// print.php - محرك ومستند الطباعة الجغرافي المطور والمحدث بالكامل (نسخة توليد جدول لجنة التوقيعات الأفقية المطور)
require_once 'db.php';

// تأمين صفحة الطباعة والتحقق من صلاحية الجلسة
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالدخول. يرجى تسجيل الدخول أولاً.");
}

$record_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$template_id = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0;

if ($record_id <= 0) {
    die("رقم السجل غير صحيح.");
}

try {
    // 1. جلب بيانات السجل مع بيانات نوع المحضر والمسؤول الذي أنشأه
    $stmt = $pdo->prepare("
        SELECT r.*, rt.label AS type_label, rt.id AS type_id, rt.color, u.username 
        FROM records r
        JOIN record_types rt ON r.record_type_id = rt.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$record_id]);
    $record = $stmt->fetch();

    if (!$record) {
        die("السجل المطلوب غير موجود بقاعدة البيانات.");
    }

    // 2. جلب قالب الطباعة المختار أو جلب القالب الافتراضي الأخير
    $template = null;
    if ($template_id > 0) {
        $stmtTmpl = $pdo->prepare("SELECT * FROM print_templates WHERE id = ?");
        $stmtTmpl->execute([$template_id]);
        $template = $stmtTmpl->fetch();
    } else {
        $template = $pdo->query("SELECT * FROM print_templates ORDER BY id DESC LIMIT 1")->fetch();
    }

    // تحديد عنوان التقرير الرئيسي
    $page_title_text = ($template && !empty($template['main_title'])) ? $template['main_title'] : 'محضر معاينة ميدانية جغرافية';

    // تحديد أبعاد واتجاه الورقة بناءً على إعدادات القالب
    $orientation = ($template && !empty($template['orientation'])) ? $template['orientation'] : 'Portrait';
    $paper_size = ($template && !empty($template['paper_size'])) ? $template['paper_size'] : 'A4';
    $page_width = ($orientation === 'Landscape') ? '297mm' : '210mm';
    $page_height = ($orientation === 'Landscape') ? '210mm' : '297mm';

    // فك وتحديد نسب توزيع هيدر وفوتر التقرير الثلاثي من JSON
    $header_layout = ($template && !empty($template['header_layout'])) ? json_decode($template['header_layout'], true) : ['right' => 30, 'middle' => 40, 'left' => 30];
    $footer_layout = ($template && !empty($template['footer_layout'])) ? json_decode($template['footer_layout'], true) : ['right' => 30, 'middle' => 40, 'left' => 30];

    // 3. استدعاء الحقول الديناميكية الفعالة لهذا السجل والمخصصة للظهور بالطباعة
    $stmtFields = $pdo->prepare("
        SELECT field_name, label, type, group_name 
        FROM fields 
        WHERE FIND_IN_SET(?, record_type_id) AND show_in_print = 1 AND is_active = 1 
        ORDER BY group_name, field_order ASC
    ");
    $stmtFields->execute([$record['type_id']]);
    $fields_list = $stmtFields->fetchAll();

    $dynamic_values = json_decode($record['dynamic_values'], true) ?: [];

    // استخلاص الحقول الأساسية مع حماية التوافقية لـ PHP 8.4+
    $display_point_number = isset($dynamic_values['point_number']) ? trim((string)$dynamic_values['point_number']) : ($record['point_number'] ?? $record['id']);
    $display_transaction_number = isset($dynamic_values['transaction_number']) ? trim((string)$dynamic_values['transaction_number']) : ($record['transaction_number'] ?? $record['id']);

    // فرز الحقول افتراضياً تحت مجموعاتها ديناميكياً
    $grouped_print_fields = [];
    foreach ($fields_list as $f) {
        $grouped_print_fields[$f['group_name']][] = $f;
    }

    // جلب خيارات تصفية وترتيب المجموعات الافتراضية
    $groups_config = ($template && !empty($template['groups_config'])) ? json_decode($template['groups_config'], true) : [];

    // استخراج الهوامش المستقلة الستة من JSON الجروب لعدم تعديل SQL
    $margins_config = isset($groups_config['page_margins']) ? $groups_config['page_margins'] : [];
    $margin_top = isset($margins_config['top']) ? intval($margins_config['top']) : (isset($template['page_margin_mm']) ? intval($template['page_margin_mm']) : 8);
    $margin_bottom = isset($margins_config['bottom']) ? intval($margins_config['bottom']) : (isset($template['page_margin_mm']) ? intval($template['page_margin_mm']) : 8);
    $margin_right = isset($margins_config['right']) ? intval($margins_config['right']) : (isset($template['page_margin_mm']) ? intval($template['page_margin_mm']) : 8);
    $margin_left = isset($margins_config['left']) ? intval($margins_config['left']) : (isset($template['page_margin_mm']) ? intval($template['page_margin_mm']) : 8);
    
    $margin_header_bottom = isset($margins_config['header_bottom']) ? intval($margins_config['header_bottom']) : 12;
    $margin_footer_top = isset($margins_config['footer_top']) ? intval($margins_config['footer_top']) : 12;

    // حجب المجموعات غير المفعلة
    foreach ($grouped_print_fields as $groupName => $fields) {
        if (isset($groups_config[$groupName]['show']) && $groups_config[$groupName]['show'] == 0) {
            unset($grouped_print_fields[$groupName]);
        }
    }

    // التحقق من تفعيل ظهور الخريطة الجغرافية المصغرة
    $show_map_box = true;
    if (isset($groups_config['الموقع الجغرافي للمعاينة']['show']) && $groups_config['الموقع الجغرافي للمعاينة']['show'] == 0) {
        $show_map_box = false;
    }

    // ترتيب المجموعات الافتراضية بناءً على الأوزان المحددة باللوحة الإدارية
    uksort($grouped_print_fields, function($a, $b) use ($groups_config) {
        $orderA = isset($groups_config[$a]['order']) ? intval($groups_config[$a]['order']) : 99;
        $orderB = isset($groups_config[$b]['order']) ? intval($groups_config[$b]['order']) : 99;
        return $orderA - $orderB;
    });

    /**
     * دالة معالجة الرموز والأكواد المختصرة (Shortcode Parser)
     * تقوم بمسح واستبدال الرموز المكتوبة بقيم السجل الفعلية بذكاء تام مع التوافق التام مع PHP 8.4
     */
    function parse_print_shortcodes($text, $record, $dynamic_values, $display_point_number, $display_transaction_number) {
        if (empty($text)) {
            return '';
        }

        // 1. مصفوفة الاستبدال الافتراضية للرموز الأساسية بالنظام مع الصب القسري للنصوص (String Casting)
        $replacements = [
            '{point_number}'       => htmlspecialchars((string)($display_point_number ?? '')),
            '{transaction_number}' => htmlspecialchars((string)($display_transaction_number ?? '')),
            '{date}'               => date('Y-m-d', strtotime($record['created_at'])),
            '{time}'               => date('H:i', strtotime($record['created_at'])),
            '{latitude}'           => htmlspecialchars((string)($record['latitude'] ?? '')),
            '{longitude}'          => htmlspecialchars((string)($record['longitude'] ?? '')),
            '{type_label}'         => htmlspecialchars((string)($record['type_label'] ?? ''))
        ];

        // 2. حقن حقول السجل الديناميكية النشطة تلقائياً كرموز مختصرة متاحة للاستبدال
        foreach ($dynamic_values as $key => $val) {
            $replacements['{' . $key . '}'] = htmlspecialchars((string)($val ?? ''));
        }

        return strtr($text, $replacements);
    }

} catch (PDOException $e) {
    die("حدث خطأ غير متوقع في قاعدة البيانات: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة محضر معاينة #<?php echo $record_id; ?></title>
    
    <!-- خطوط الويب للطباعة الرسمية الموحدة -->
    <link href="https://fonts.googleapis.com/css2?family=<?php echo $template && !empty($template['header_font']) ? htmlspecialchars((string)$template['header_font']) : 'Cairo'; ?>:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Leaflet CSS للخرائط المطبوعة -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <style>
        /* تطبيق الفونت واللون والوزن العريض الصارم لضمان القراءة بوضوح بعد الطباعة الورقية */
        body, span, div, p, h1, h2, h3, h4, td, th, footer, header, table, select, input {
            font-family: '<?php echo $template && !empty($template['header_font']) ? htmlspecialchars((string)$template['header_font']) : 'Cairo'; ?>', sans-serif !important;
            color: #000000 !important;
            font-weight: 700 !important; 
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            background-color: #c7d3e0;
            font-size: <?php echo $template && intval($template['font_size_pt']) > 0 ? intval($template['font_size_pt']) : '10'; ?>pt;
        }
        .a4-container {
            width: <?php echo $page_width; ?>;
            min-height: <?php echo $page_height; ?>;
            margin: 20px auto;
            background-color: white;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border: 1px solid #bcc5d0;
            border-radius: 0.5rem;
            
            /* تفعيل هوامش الصفحة المستقلة الأربعة ديناميكياً */
            padding-top: <?php echo $margin_top; ?>mm;
            padding-bottom: <?php echo $margin_bottom; ?>mm;
            padding-right: <?php echo $margin_right; ?>mm;
            padding-left: <?php echo $margin_left; ?>mm;
            box-sizing: border-box;
        }
        .print-card {
            border: 2px solid #000000; 
            border-radius: 0.5rem;
            background-color: white;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: fit-content; 
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .avoid-break {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        @media print {
            body { background: white; color: #000000 !important; }
            .no-print { display: none !important; }
            .a4-container {
                width: 100% !important;
                min-height: 0 !important;
                margin: 0 !important;
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
            }
            @page {
                size: <?php echo $paper_size; ?> <?php echo $orientation; ?>;
                /* تطبيق هوامش الصفحة المستقلة الأربعة ديناميكياً بالطباعة */
                margin-top: <?php echo $margin_top; ?>mm; 
                margin-bottom: <?php echo $margin_bottom; ?>mm; 
                margin-left: <?php echo $margin_left; ?>mm; 
                margin-right: <?php echo $margin_right; ?>mm; 
            }
        }
        <?php if ($template && !empty($template['custom_css'])): ?>
            <?php echo $template['custom_css']; ?>
        <?php endif; ?>
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between">

    <!-- شريط أدوات التحكم العلوي (يتم إخفاءه تلقائياً أثناء الطباعة) -->
    <div class="no-print w-full bg-slate-100 border-b border-slate-200 py-3 px-6 flex justify-center items-center space-x-3 space-x-reverse shadow-sm">
        <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-5 rounded-lg text-xs flex items-center space-x-2 space-x-reverse transition shadow-sm">
            <i class="fa-solid fa-print text-white"></i>
            <span class="text-white">طباعة مستند التقرير</span>
        </button>
        <button onclick="window.close()" class="bg-slate-600 hover:bg-slate-700 text-white font-bold py-2 px-5 rounded-lg text-xs flex items-center space-x-2 space-x-reverse transition shadow-sm">
            <i class="fa-solid fa-xmark text-white"></i>
            <span class="text-white">إغلاق المعاينة</span>
        </button>
    </div>

    <!-- الحاوية الرئيسية بأبعاد الورقة المستقرة -->
    <div class="a4-container flex flex-col justify-between flex-grow">
        <div>
            <!-- الترويسة العليا ثلاثية الأعمدة المطورة بنظام العرض المئوي المباشر لتلافي أخطاء Tailwind -->
            <header class="border-b-2 border-slate-800 pb-2 avoid-break flex justify-between items-center" style="min-height: <?php echo $template && intval($template['header_height']) > 0 ? intval($template['header_height']) : '50'; ?>px; margin-bottom: <?php echo $margin_header_bottom; ?>px !important;">
                
                <!-- العمود الأيمن (بيانات الجهة الإدارية) بنسبة عرض مئوية حرة -->
                <div class="text-right text-[9px] font-black leading-relaxed text-black" style="width: <?php echo intval($header_layout['right'] ?? 30); ?>%;">
                    <?php if ($template && !empty($template['header_right_html'])): ?>
                        <?php echo $template['header_right_html']; ?>
                    <?php else: ?>
                        <?php if ($template && !empty($template['header_right_1'])): ?>
                            <?php echo htmlspecialchars((string)$template['header_right_1']); ?><br>
                            <?php echo htmlspecialchars((string)$template['header_right_2']); ?><br>
                            <?php echo htmlspecialchars((string)$template['header_right_3']); ?>
                        <?php else: ?>
                            محافظة الاسماعيلية<br>
                            حي ثان<br>
                            وحدة المتغيرات المكانية
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- العمود الأوسط (العنوان الموجه للتقرير) -->
                <div class="text-center" style="width: <?php echo intval($header_layout['middle'] ?? 40); ?>%;">
                    <?php if ($template && !empty($template['header_middle_html'])): ?>
                        <?php echo $template['header_middle_html']; ?>
                    <?php else: ?>
                        <h1 class="text-xs font-black text-black leading-normal">
                            <?php echo htmlspecialchars((string)$page_title_text); ?>
                        </h1>
                    <?php endif; ?>
                </div>

                <!-- العمود الأيسر (الشعار الرسمي وتوقيت التوثيق) -->
                <div class="text-left flex flex-col items-end" style="width: <?php echo intval($header_layout['left'] ?? 30); ?>%;">
                    <?php if ($template && !empty($template['header_left_html'])): ?>
                        <?php echo $template['header_left_html']; ?>
                    <?php else: ?>
                        <?php if ($template && $template['show_logo'] == 1 && !empty($template['logo_path'])): ?>
                            <img src="<?php echo htmlspecialchars((string)$template['logo_path']); ?>" class="w-10 h-10 object-contain mb-1">
                        <?php endif; ?>
                        <div class="text-[8px] text-gray-500 leading-normal text-left font-bold">
                            التاريخ: <?php echo date('Y-m-d', strtotime($record['created_at'])); ?><br>
                            التوقيت: <?php echo date('H:i', strtotime($record['created_at'])); ?>
                        </div>
                    <?php endif; ?>
                </div>

            </header>

            <!-- شريط المعاملة والمحاضر -->
            <div class="bg-gray-50 border border-gray-200 text-center py-1.5 px-4 rounded-xl flex justify-around items-center font-bold text-[11px] mb-4 shadow-sm text-black avoid-break">
                <div>رقم النقطة: <span class="font-mono text-xs text-blue-600 font-black"><?php echo htmlspecialchars((string)$display_point_number); ?></span></div>
                <div class="text-gray-300 font-normal">|</div>
                <div>رقم المعاملة: <span class="font-mono text-xs text-blue-600 font-black"><?php echo htmlspecialchars((string)$display_transaction_number); ?></span></div>
            </div>

            <!-- عرض النص المخصص العلوي مع تفعيل محرك الرموز المختصرة (Shortcodes) -->
            <?php if ($template && !empty($template['extra_content_above'])): ?>
                <div class="mb-3 text-[10px] leading-relaxed avoid-break">
                    <?php echo parse_print_shortcodes($template['extra_content_above'], $record, $dynamic_values, $display_point_number, $display_transaction_number); ?>
                </div>
            <?php endif; ?>

            <!-- شبكة التخطيط والتقسيم ثنائية الأعمدة -->
            <div class="grid grid-cols-3 gap-4 items-start" style="gap: <?php echo $template && intval($template['grid_gap_px']) > 0 ? intval($template['grid_gap_px']) : '12'; ?>px;">
                
                <!-- عمود البيانات الفنية الأساسية (عرض 2/3) -->
                <div class="col-span-2 space-y-4">
                    <?php 
                    // جلب وتفنيد هيكلية الجداول المخصصة المصممة من قبل المسؤول
                    $custom_tables = ($template && !empty($template['columns_config'])) ? json_decode($template['columns_config'], true) : [];
                    
                    if (is_array($custom_tables) && count($custom_tables) > 0): 
                        // عرض الجداول المخصصة المصممة مع تطبيق حجم الخط المخصص لكل جدول بشكل مستقل
                        foreach ($custom_tables as $table):
                            $table_font_size = isset($table['font_size']) ? intval($table['font_size']) : 10;
                    ?>
                        <div class="print-card w-full flex flex-col" style="font-size: <?php echo $table_font_size; ?>pt !important;">
                            <div class="flex items-center space-x-2 space-x-reverse px-4 py-1.5 bg-slate-200 text-black font-black border-b border-slate-300">
                                <i class="fa-solid fa-table-cells text-[10px] text-emerald-600"></i>
                                <span class="text-[10px]"><?php echo htmlspecialchars((string)$table['title']); ?></span>
                            </div>
                            <div class="p-2 overflow-x-auto">
                                <table class="w-full border-collapse border border-black text-right" style="font-size: <?php echo $table_font_size; ?>pt !important;">
                                    <tbody>
                                        <?php foreach ($table['rows'] as $row): 
                                            if (isset($row[0]) && is_array($row[0])) {
                                                $cells = $row;
                                                $row_margin = 8;
                                            } else {
                                                $row_margin = isset($row['margin_bottom']) ? intval($row['margin_bottom']) : 8;
                                                $cells = isset($row['cells']) ? $row['cells'] : [];
                                            }
                                        ?>
                                            <tr class="border-b border-black" style="margin-bottom: <?php echo $row_margin; ?>px;">
                                                <?php 
                                                foreach ($cells as $cell):
                                                    $cell_field_name = $cell['field'] ?? '';
                                                    $val = isset($dynamic_values[$cell_field_name]) ? trim((string)$dynamic_values[$cell_field_name]) : '-';
                                                    if ($val === '') { $val = '-'; }
                                                    
                                                    $cell_width = isset($cell['width']) ? intval($cell['width']) : 50;
                                                    $cell_align = isset($cell['align']) ? htmlspecialchars((string)$cell['align']) : 'right';
                                                    $cell_padding = isset($cell['padding']) ? intval($cell['padding']) : 8;
                                                ?>
                                                    <td class="border-r border-black font-black text-black" 
                                                        style="width: <?php echo $cell_width; ?>%; text-align: <?php echo $cell_align; ?>; padding: <?php echo $cell_padding; ?>px;">
                                                        <div class="text-slate-500 font-bold mb-0.5" style="font-size: 0.9em;"><?php echo htmlspecialchars((string)($cell['label'] ?? '')); ?>:</div>
                                                        <div class="text-black font-black" style="font-size: 1.1em;"><?php echo htmlspecialchars($val); ?></div>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php 
                        endforeach; 
                    else: 
                        // عرض المجموعات الافتراضية المفرزة تلقائياً في حال عدم تصميم جداول مخصصة
                    ?>
                        <div class="grid grid-cols-2 gap-3" style="gap: <?php echo $template && intval($template['grid_gap_px']) > 0 ? intval($template['grid_gap_px']) : '12'; ?>px;">
                            <?php if (count($grouped_print_fields) === 0): ?>
                                <div class="col-span-2 bg-white p-6 rounded-xl text-center text-gray-400 font-bold">لا توجد بيانات فنية معتمدة للطباعة.</div>
                            <?php else: ?>
                                <?php 
                                foreach ($grouped_print_fields as $groupTitle => $fields): 
                                    if ($groupTitle === "الموقع الجغرافي للمعاينة") continue;
                                    $box_width_class = isset($groups_config[$groupTitle]['width']) ? $groups_config[$groupTitle]['width'] : 'col-span-1';
                                ?>
                                    <div class="print-card <?php echo $box_width_class; ?> flex flex-col">
                                        <div class="flex items-center space-x-2 space-x-reverse px-4 py-1.5 bg-slate-200 text-black font-black border-b border-slate-300">
                                            <i class="fa-regular fa-folder-open text-[10px] text-blue-600"></i>
                                            <span class="text-[10px]"><?php echo htmlspecialchars((string)$groupTitle); ?></span>
                                        </div>
                                        <div class="p-3 flex flex-col space-y-2 text-[10px] leading-normal" style="padding: <?php echo $template && intval($template['card_padding_px']) > 0 ? intval($template['card_padding_px']) : '12'; ?>px;">
                                            <?php foreach ($fields as $field): 
                                                $val = isset($dynamic_values[$field['field_name']]) ? trim((string)$dynamic_values[$field['field_name']]) : '';
                                                if ($val === '' || $val === '-') continue;
                                            ?>
                                                <div class="flex justify-start items-center border-b border-gray-100 pb-1 text-right">
                                                    <span class="text-slate-900 font-black ml-2"><?php echo htmlspecialchars((string)$field['label']); ?>:</span>
                                                    <span class="font-black text-black"><?php echo htmlspecialchars($val); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- عمود الموقع والوسائط (عرض 1/3) -->
                <div class="col-span-1 space-y-3">
                    
                    <!-- كارت عرض الخريطة الجغرافية -->
                    <?php if ($show_map_box): ?>
                        <div class="print-card p-3 space-y-2">
                            <div class="flex items-center space-x-1.5 space-x-reverse font-black text-black text-[10px] border-b border-slate-300 pb-1">
                                <i class="fa-solid fa-location-dot text-blue-600"></i>
                                <span>الموقع الجغرافي للمعاينة</span>
                            </div>
                            <div id="mini-map" class="h-44 rounded-lg border border-gray-150 shadow-inner z-0"></div>
                            <div class="bg-slate-50 border border-slate-200 p-2 rounded-lg text-center font-mono text-[9px] space-y-0.5 text-black font-black">
                                <div><span class="font-bold text-gray-400 font-sans">خط العرض (Lat):</span> <?php echo htmlspecialchars((string)($record['latitude'] ?? '')); ?></div>
                                <div class="border-t border-slate-200/40 my-0.5"></div>
                                <div><span class="font-bold text-gray-400 font-sans">خط الطول (Lng):</span> <?php echo htmlspecialchars((string)($record['longitude'] ?? '')); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- كارت عرض الصورة الميدانية للمعاينة -->
                    <?php if (!empty($record['photo_path']) && file_exists($record['photo_path'])): ?>
                        <div class="print-card p-2 space-y-1">
                            <div class="flex items-center space-x-1.5 space-x-reverse font-black text-black text-[10px] border-b border-slate-300 pb-1">
                                <i class="fa-solid fa-camera text-amber-600"></i>
                                <span>صورة المعاينة الميدانية</span>
                            </div>
                            <div class="flex justify-center border border-gray-100 p-0.5 rounded bg-gray-50/10">
                                <img src="<?php echo htmlspecialchars((string)$record['photo_path']); ?>" class="max-h-32 w-auto object-contain rounded">
                            </div>
                        </div>
                    <?php endif; ?>
                    
                </div>

            </div>

            <!-- عرض النص المخصص السفلي مع تفعيل محرك الرموز المختصرة (Shortcodes) -->
            <?php if ($template && !empty($template['extra_content_below'])): ?>
                <div class="mt-4 text-[10px] leading-relaxed avoid-break">
                    <?php echo parse_print_shortcodes($template['extra_content_below'], $record, $dynamic_values, $display_point_number, $display_transaction_number); ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- [القسم الجديد الحصري]: توليد ورسم جدول أعضاء لجنة المعاينة المشكلة أسفل التقرير وبنفس التنسيق الصارم للمخططات -->
        <?php 
        $committee_data = ($template && !empty($template['signatures_json'])) ? json_decode($template['signatures_json'], true) : [];
        if (is_array($committee_data) && isset($committee_data['show_committee']) && $committee_data['show_committee'] == 1 && !empty($committee_data['members'])):
        ?>
            <div class="mt-6 avoid-break w-full">
                <!-- عنوان لجنة المعاينة الفرعي -->
                <div class="text-center font-black text-[10px] mb-3 text-black">
                    <?php echo htmlspecialchars((string)($committee_data['title'] ?? 'أعضاء اللجنة المشكلة للمعاينة')); ?>
                </div>
                <div class="w-full">
                    <table class="w-full border-collapse border-2 border-black text-right text-[10px] leading-normal">
                        <thead>
                            <tr class="bg-slate-200 border-b-2 border-black">
                                <th class="border-l border-black p-2 font-black text-black text-center" style="width: 45%;">أعضاء اللجنة</th>
                                <th class="border-l border-black p-2 font-black text-black text-center" style="width: 35%;">الصفة</th>
                                <th class="p-2 font-black text-black text-center" style="width: 20%;">التوقيع</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($committee_data['members'] as $member): 
                                if (isset($member['show']) && $member['show'] == 1):
                            ?>
                                <tr class="border-b border-black">
                                    <td class="border-l border-black p-2.5 font-black text-black align-middle"><?php echo htmlspecialchars((string)($member['name'] ?? '')); ?></td>
                                    <td class="border-l border-black p-2.5 font-black text-black align-middle text-center"><?php echo htmlspecialchars((string)($member['role'] ?? '')); ?></td>
                                    <td class="p-2.5 font-black text-black align-middle text-center font-mono text-[9px] text-gray-300">.......................</td>
                                </tr>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- الجزء السفلي الخاص بالتوقيعات الخمسة المعتمدة بقفل التنسيق الصارم -->
        <div class="mt-6 avoid-break">
            <div class="text-center font-black text-[10px] mb-3 text-black">
                <?php echo $template && !empty($template['signatures_title']) ? htmlspecialchars((string)$template['signatures_title']) : '_________ التوقيعات _________'; ?>
            </div>
            <div class="flex justify-around items-start text-center space-x-4 space-x-reverse">
                <?php 
                $printed_sigs_count = 0;
                if ($template) {
                    for ($i = 1; $i <= 5; $i++) {
                        if (isset($template["sig{$i}_show"]) && $template["sig{$i}_show"] == 1 && !empty($template["sig{$i}_title"])) {
                            $printed_sigs_count++;
                            echo "
                            <div class='flex-1 border-t border-dashed border-slate-800 pt-2'>
                                <span class='font-black text-black block mb-5 text-[9px]'>" . htmlspecialchars((string)($template["sig{$i}_title"] ?? '')) . "</span>";
                            if (!empty($template["sig{$i}_name"])) {
                                echo "<span class='text-[8px] text-black font-black block mb-1'>" . htmlspecialchars((string)($template["sig{$i}_name"] ?? '')) . "</span>";
                            }
                            echo "
                                <span class='text-[8px] text-gray-400 block font-normal'>التوقيع: ............................</span>
                            </div>";
                        }
                    }
                }

                if ($printed_sigs_count === 0) {
                    echo "
                    <div class='flex-1 border-t border-dashed border-slate-800 pt-2'>
                        <span class='font-black text-black block mb-5 text-[10px]'>مهندس المعاينة</span>
                        <span class='text-[8px] text-gray-300 block'>التوقيع: ............................</span>
                    </div>
                    <div class='flex-1 border-t border-dashed border-slate-800 pt-2'>
                        <span class='font-black text-black block mb-5 text-[10px]'>مدير التنظيم</span>
                        <span class='text-[8px] text-gray-300 block'>التوقيع: ............................</span>
                    </div>";
                }
                ?>
            </div>
        </div>
    </div>

    <!-- تذييل الصفحة وتوقيعات الفوتر ثلاثي الأعمدة المرن والمنسق أفقياً بنسب مئوية -->
    <footer class="text-center text-[8px] text-gray-400 font-semibold border-t border-slate-100 pt-2 no-print" style="margin-top: <?php echo $margin_footer_top; ?>px !important;">
        <div class="flex justify-between items-center max-w-6xl mx-auto w-full">
            
            <div class="text-right text-black font-bold" style="width: <?php echo intval($footer_layout['right'] ?? 30); ?>%;">
                <?php echo $template && !empty($template['footer_right_html']) ? $template['footer_right_html'] : ''; ?>
            </div>
            
            <div class="text-center" style="width: <?php echo intval($footer_layout['middle'] ?? 40); ?>%;">
                <?php if ($template && !empty($template['footer_middle_html'])): ?>
                    <?php echo $template['footer_middle_html']; ?>
                <?php else: ?>
                    <?php echo $template && !empty($template['footer_text']) ? htmlspecialchars((string)$template['footer_text']) : 'نظام GIS Manager لإدارة وتوثيق المعاينات الميدانية جغرافياً.'; ?>
                <?php endif; ?>
            </div>
            
            <div class="text-left text-black font-bold" style="width: <?php echo intval($footer_layout['left'] ?? 30); ?>%;">
                <?php echo $template && !empty($template['footer_left_html']) ? $template['footer_left_html'] : ''; ?>
            </div>
            
        </div>
    </footer>

    <!-- معالجة الخريطة الجغرافية بوضع الطباعة الساكنة غير التفاعلية -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var showMap = <?php echo $show_map_box ? 'true' : 'false'; ?>;
            if (showMap) {
                var lat = <?php echo !empty($record['latitude']) ? $record['latitude'] : '30.0444'; ?>;
                var lng = <?php echo !empty($record['longitude']) ? $record['longitude'] : '31.2357'; ?>;
                
                var miniMap = L.map('mini-map', { 
                    zoomControl: false, 
                    attributionControl: false,
                    dragging: false,
                    scrollWheelZoom: false,
                    doubleClickZoom: false,
                    boxZoom: false,
                    touchZoom: false
                }).setView([lat, lng], 15);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    keepBuffer: 100,
                    updateWhenIdle: false
                }).addTo(miniMap);
                
                var myIcon = L.divIcon({
                    html: '<i class="fa-solid fa-location-dot text-2xl text-blue-600" style="filter: drop-shadow(0px 2px 3px rgba(0,0,0,0.3));"></i>',
                    iconSize: [24, 34],
                    iconAnchor: [12, 34]
                });
                
                L.marker([lat, lng], {icon: myIcon}).addTo(miniMap);
                
                // تحديث حجم الخريطة قبل العرض النهائي لضمان عدم ظهورها مقتطعة
                setTimeout(function() {
                    miniMap.invalidateSize();
                }, 300);
            }
        });
    </script>
</body>
</html>