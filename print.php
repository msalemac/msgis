<?php
require_once 'db.php';

// تأمين صفحة الطباعة
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالدخول.");
}

$record_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$template_id = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0; // قراءة قالب الطباعة المختار

if ($record_id <= 0) {
    die("رقم السجل غير صحيح.");
}

try {
    // 1. جلب بيانات السجل مع القسم والموظف
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
        die("السجل المطلوب غير موجود.");
    }

    // 2. جلب قالب الطباعة المختار
    $template = null;
    if ($template_id > 0) {
        $stmtTmpl = $pdo->prepare("SELECT * FROM print_templates WHERE id = ?");
        $stmtTmpl->execute([$template_id]);
        $template = $stmtTmpl->fetch();
    } else {
        $template = $pdo->query("SELECT * FROM print_templates ORDER BY id DESC LIMIT 1")->fetch();
    }

    $page_title_text = ($template && !empty($template['page_title'])) ? $template['page_title'] : 'محضر معاينة ميدانية جغرافية';

    // 3. جلب الحقول الديناميكية النشطة والمخصصة للطباعة (شاملة الـ type للـ Layout)
    $stmtFields = $pdo->prepare("
        SELECT field_name, label, type, group_name 
        FROM fields 
        WHERE FIND_IN_SET(?, record_type_id) AND show_in_print = 1 AND is_active = 1 
        ORDER BY group_name, field_order ASC
    ");
    $stmtFields->execute([$record['type_id']]);
    $fields_list = $stmtFields->fetchAll();

    $dynamic_values = json_decode($record['dynamic_values'], true) ?: [];

    // تصنيف الحقول المطبوعة تلقائياً تحت مجموعاتها ديناميكياً
    $grouped_print_fields = [];
    foreach ($fields_list as $f) {
        $grouped_print_fields[$f['group_name']][] = $f;
    }

    // [تحديث أمني ورقابي ذكي]: قراءة وترجمة مصفوفة المقاسات والترتيب المحفوظة بالـ JSON
    $groups_config = ($template && !empty($template['groups_config'])) ? json_decode($template['groups_config'], true) : [];

    // إعادة ترتيب المجموعات (الجروبات) تلقائياً بالكامل بناءً على "ترتيب الظهور بالورقة" الذي حددته أنت في الإعدادات
    uksort($grouped_print_fields, function($a, $b) use ($groups_config) {
        $orderA = isset($groups_config[$a]['order']) ? intval($groups_config[$a]['order']) : 99;
        $orderB = isset($groups_config[$b]['order']) ? intval($groups_config[$b]['order']) : 99;
        return $orderA - $orderB;
    });

} catch (PDOException $e) {
    die("خطأ قاعدة البيانات: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة محضر معاينة #<?php echo $record_id; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- تضمين مكتبة FontAwesome لتظهر أيقونة تحديد الموقع زرقاء وجميلة داخل الخريطة المطبوعة -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Leaflet CSS للخرائط المطبوعة -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: white;
            color: #000000; /* لون أسود صريح غامق وواضح جداً للطباعة */
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        /* تصميم الكروت المخصصة للطباعة بظلال ناعمة وإطار أسود داكن صريح */
        .print-card {
            border: 2px solid #000000; 
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.07);
            border-radius: 1rem;
            background-color: white;
            overflow: hidden;
        }
        @media print {
            body { background: white; color: #000000; }
            .no-print { display: none !important; }
            @page {
                size: A4;
                margin: 6mm; 
            }
            footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                text-align: center;
                border-top: 1px solid #cbd5e1;
                padding-top: 4px;
                font-size: 8px;
                color: #94a3b8;
            }
        }
    </style>
</head>
<body class="p-3 text-[11px] flex flex-col justify-between min-h-screen">

    <div>
        <!-- الجزء العلوي: ترويسة هيدر القالب (الشعار والأقسام يمين ويسار) من الإعدادات -->
        <header class="flex justify-between items-center border-b-2 border-slate-800 pb-2 mb-3">
            <!-- اليمين: الأسطر الثلاثة المخصصة بالخط الأسود السميك -->
            <div class="text-right text-[9px] font-black leading-normal text-black">
                <?php if ($template && !empty($template['header_right_1'])): ?>
                    <?php echo htmlspecialchars($template['header_right_1']); ?><br>
                    <?php echo htmlspecialchars($template['header_right_2']); ?><br>
                    <?php echo htmlspecialchars($template['header_right_3']); ?>
                <?php else: ?>
                    محافظة الاسماعيلية<br>
                    حي ثان<br>
                    وحدة المتغيرات المكانية
                <?php endif; ?>
            </div>
            
            <!-- وسط الهيدر المصحح والآمن تماماً -->
            <div class="text-center">
                <h1 class="text-xs font-black text-black leading-normal">
                    <?php echo htmlspecialchars($page_title_text); ?>
                </h1>
            </div>

            <!-- يسار الهيدر -->
            <div class="text-left flex flex-col items-end">
                <?php if ($template && $template['show_logo'] == 1 && !empty($template['logo_path'])): ?>
                    <img src="<?php echo htmlspecialchars($template['logo_path']); ?>" class="w-10 h-10 object-contain mb-1">
                <?php endif; ?>
                <div class="text-[8px] text-gray-500 leading-normal text-left font-bold">
                    تاريخ التوثيق: <?php echo date('Y-m-d', strtotime($record['created_at'])); ?><br>
                    توقيت التوثيق: <?php echo date('H:i', strtotime($record['created_at'])); ?>
                </div>
            </div>
        </header>

        <!-- شريط البيانات الموحد بالأعلى -->
        <div class="bg-gray-50 border border-gray-200 text-center py-1.5 px-4 rounded-xl flex justify-around items-center font-bold text-[11px] mb-4 shadow-sm text-black">
            <div>رقم النقطة: <span class="font-mono text-xs text-blue-600 font-black"><?php echo htmlspecialchars($record['point_number'] ?? $record['id']); ?></span></div>
            <div class="text-gray-300 font-normal">|</div>
            <div>رقم المعاملة: <span class="font-mono text-xs text-blue-600 font-black"><?php echo htmlspecialchars($record['transaction_number'] ?? $record['id']); ?></span></div>
        </div>

        <!-- شبكة التقسيم الثنائية للبوكسات المفرزة -->
        <div class="grid grid-cols-3 gap-4 items-start">
            
            <!-- العمود الأيمن (عرض 2/3): [تم التحديث]: جلب وبناء وعرض مقاسات وعرض كل كرت ديناميكياً بناءً على اختيارك بالـ JSON -->
            <div class="col-span-2 grid grid-cols-2 gap-3">
                <?php if (count($grouped_print_fields) === 0): ?>
                    <div class="col-span-2 bg-white p-6 rounded-xl text-center text-gray-400 font-bold">لا توجد بيانات فنية معتمدة للطباعة.</div>
                <?php else: ?>
                    <?php 
                    foreach ($grouped_print_fields as $groupTitle => $fields): 
                        // قراءة وتحديد عرض ومقاس هذا الصندوق الفردي (col-span-1 = 50% أو col-span-2 = 100%)
                        $box_width_class = isset($groups_config[$groupTitle]['width']) ? $groups_config[$groupTitle]['width'] : 'col-span-1';
                    ?>
                        <!-- كارت المجموعة المنسق بالمقاس والعرض الذي اخترته أنت بنفسك من إعدادات الطباعة -->
                        <div class="print-card <?php echo $box_width_class; ?> flex flex-col justify-between">
                            <!-- عنوان البوكس المظلل الأنيق باللون الداكن والخط العريض -->
                            <div class="flex items-center space-x-2 space-x-reverse px-4 py-1.5 bg-slate-200 text-black font-black border-b border-slate-300">
                                <i class="fa-regular fa-folder-open text-[10px] text-blue-600"></i>
                                <span class="text-[10px]"><?php echo htmlspecialchars($groupTitle); ?></span>
                            </div>
                            <!-- الحقول المعبأة مسبقاً بالخط الأسود الغامق والواضح جداً (Bold & Black) -->
                            <div class="p-3 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1.5 text-[10px] leading-normal">
                                <?php foreach ($fields as $field): 
                                    $val = isset($dynamic_values[$field['field_name']]) ? $dynamic_values[$field['field_name']] : '-';
                                ?>
                                    <div class="flex justify-between border-b border-gray-100 pb-1 <?php echo $field['type'] === 'textarea' || $box_width_class === 'col-span-1' ? 'md:col-span-2' : ''; ?>">
                                        <span class="text-slate-900 font-black"><?php echo htmlspecialchars($field['label']); ?>:</span>
                                        <span class="font-black text-black text-left"><?php echo htmlspecialchars($val); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- العمود الأيسر (عرض 1/3): الموقع والـ GPS والصورة بكروت مفرزة ومظللة ومستقلة وإطار أسود -->
            <div class="col-span-1 space-y-3">
                
                <!-- كارت الموقع الجغرافي ثلاثي الأبعاد -->
                <div class="print-card p-3 space-y-2">
                    <div class="flex items-center space-x-1.5 space-x-reverse font-black text-black text-[10px] border-b border-slate-300 pb-1">
                        <i class="fa-solid fa-location-dot text-blue-600"></i>
                        <span>الموقع الجغرافي للمعاينة</span>
                    </div>
                    <div id="mini-map" class="h-44 rounded-lg border border-gray-100 shadow-inner z-0"></div> <!-- تقليص خفيف لارتفاع الخريطة لضغط الورقة -->
                    <div class="bg-slate-50 border border-slate-200 p-2 rounded-lg text-center font-mono text-[9px] space-y-0.5 text-black font-black">
                        <div><span class="font-bold text-gray-400 font-sans">خط العرض (Latitude):</span> <?php echo htmlspecialchars($record['latitude']); ?></div>
                        <div class="border-t border-slate-200/40 my-0.5"></div>
                        <div><span class="font-bold text-gray-400 font-sans">خط الطول (Longitude):</span> <?php echo htmlspecialchars($record['longitude']); ?></div>
                    </div>
                </div>

                <!-- كارت المعاينة البصرية الميدانية ثلاثي الأبعاد -->
                <?php if ($record['photo_path']): ?>
                    <div class="print-card p-2 space-y-1 page-break-inside-avoid">
                        <div class="flex items-center space-x-1.5 space-x-reverse font-black text-black text-[10px] border-b border-slate-300 pb-1">
                            <i class="fa-solid fa-camera text-amber-600"></i>
                            <span>صورة المعاينة الميدانية</span>
                        </div>
                        <div class="flex justify-center border border-gray-100 p-0.5 rounded bg-gray-50/10">
                            <img src="<?php echo htmlspecialchars($record['photo_path']); ?>" class="max-h-32 w-auto object-contain rounded">
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- الجزء السفلي: قسم التوقيعات الخماسي -->
    <div class="mt-6 page-break-inside-avoid">
        <div class="text-center font-black text-[10px] mb-3 text-black">
            <?php echo $template ? htmlspecialchars($template['signatures_title']) : '_________ التوقيعات _________'; ?>
        </div>
        <div class="flex justify-around items-start text-center space-x-4 space-x-reverse">
            <?php 
            $printed_sigs_count = 0;
            if ($template) {
                for ($i = 1; $i <= 5; $i++) {
                    if ($template["sig{$i}_show"] == 1 && !empty($template["sig{$i}_title"])) {
                        $printed_sigs_count++;
                        echo "
                        <div class='flex-1 border-t border-dashed border-slate-800 pt-2'>
                            <span class='font-black text-black block mb-5 text-[9px]'>" . htmlspecialchars($template["sig{$i}_title"]) . "</span>";
                        if (!empty($template["sig{$i}_name"])) {
                            echo "<span class='text-[8px] text-black font-black block mb-1'>" . htmlspecialchars($template["sig{$i}_name"]) . "</span>";
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

    <!-- الفوتر وتذييل الصفحة المخصص -->
    <footer class="mt-4 text-center text-[8px] text-gray-400 font-semibold">
        <?php echo $template && !empty($template['footer_text']) ? htmlspecialchars($template['footer_text']) : 'إن هذا التقرير جيو-معتمد ومستخرج آلياً بنظم المعلومات الجغرافية GIS Manager.'; ?>
    </footer>

    <!-- سكريبت تفعيل الخريطة والطباعة التلقائية -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var lat = <?php echo $record['latitude'] ?: '30.0444'; ?>;
            var lng = <?php echo $record['longitude'] ?: '31.2357'; ?>;
            
            var miniMap = L.map('mini-map', { zoomControl: false, attributionControl: false }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(miniMap);
            
            var myIcon = L.divIcon({
                html: '<i class="fa-solid fa-location-dot text-2xl text-blue-600" style="filter: drop-shadow(0px 2px 3px rgba(0,0,0,0.3));"></i>',
                iconSize: [24, 34],
                iconAnchor: [12, 34]
            });
            L.marker([lat, lng], {icon: myIcon}).addTo(miniMap);

            setTimeout(() => { window.print(); }, 400);
        });
    </script>
</body>
</html>