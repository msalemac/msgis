<?php
// حماية الملف من الوصول المباشر
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

// جلب جميع الحقول المخصصة النشطة في السيستم لبناء فلاتر متقدمة لها ديناميكياً
$fields_list = $pdo->query("SELECT field_name, label, type, options, record_type_id FROM fields WHERE is_active = 1 ORDER BY group_name, field_order ASC")->fetchAll(PDO::FETCH_ASSOC);

// ----------------- [الجزء الأول: فلاتر الداش بورد الأساسية] -----------------
$where_clauses = [];
$params = [];

$filter_type = isset($_GET['filter_type']) ? intval($_GET['filter_type']) : 0;
$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

if ($filter_type > 0) { $where_clauses[] = "r.record_type_id = ?"; $params[] = $filter_type; }
if ($filter_user > 0) { $where_clauses[] = "r.user_id = ?"; $params[] = $filter_user; }
if (!empty($date_from)) { $where_clauses[] = "DATE(r.created_at) >= ?"; $params[] = $date_from; }
if (!empty($date_to)) { $where_clauses[] = "DATE(r.created_at) <= ?"; $params[] = $date_to; }

// قراءة فلاتر الـ JSON المخصصة الممررة في الرابط لتصفية الرسوم البيانية بها
foreach ($fields_list as $field) {
    $f_name = $field['field_name'];
    $getParam = 'col_' . $f_name;
    
    if (isset($_GET[$getParam]) && $_GET[$getParam] !== '') {
        $val = trim($_GET[$getParam]);
        $dbCol = "JSON_UNQUOTE(JSON_EXTRACT(r.dynamic_values, '$.$f_name'))";
        
        if ($field['type'] === 'checkbox') {
            $where_clauses[] = "$dbCol = ?";
            $params[] = ($val === '1' || $val === 'نعم') ? 'نعم' : 'لا';
        } else {
            $where_clauses[] = "$dbCol LIKE ?";
            $params[] = '%' . $val . '%';
        }
    }
}

try {
    // أ. إجمالي السجلات المصفاة
    $total_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
    $stmtTotal = $pdo->prepare("SELECT COUNT(r.id) FROM records r JOIN users u ON r.user_id = u.id $total_sql");
    $stmtTotal->execute($params);
    $total_count = $stmtTotal->fetchColumn() ?: 0;

    // ب. السجلات المصفاة التي تحتوي على صور ميدانية
    $photos_clauses = $where_clauses;
    $photos_clauses[] = "r.photo_path IS NOT NULL";
    $photos_sql = count($photos_clauses) > 0 ? "WHERE " . implode(" AND ", $photos_clauses) : "";
    $stmtPhotos = $pdo->prepare("SELECT COUNT(r.id) FROM records r JOIN users u ON r.user_id = u.id $photos_sql");
    $stmtPhotos->execute($params);
    $photos_count = $stmtPhotos->fetchColumn() ?: 0;

    // جـ. السجلات المصفاة التي تحتوي على ملفات PDF
    $pdfs_clauses = $where_clauses;
    $pdfs_clauses[] = "r.pdf_path IS NOT NULL";
    $pdfs_sql = count($pdfs_clauses) > 0 ? "WHERE " . implode(" AND ", $pdfs_clauses) : "";
    $stmtPdfs = $pdo->prepare("SELECT COUNT(r.id) FROM records r JOIN users u ON r.user_id = u.id $pdfs_sql");
    $stmtPdfs->execute($params);
    $pdfs_count = $stmtPdfs->fetchColumn() ?: 0;

    // 2. إعادة توليد الرسم الدائري للأقسام
    $stmtChartTypes = $pdo->prepare("
        SELECT rt.label, COUNT(r.id) AS cnt 
        FROM records r 
        JOIN record_types rt ON r.record_type_id = rt.id 
        JOIN users u ON r.user_id = u.id
        $total_sql
        GROUP BY r.record_type_id
    ");
    $stmtChartTypes->execute($params);
    $chart_types_data = $stmtChartTypes->fetchAll();

    $chart_labels = []; $chart_counts = [];
    foreach ($chart_types_data as $row) {
        $chart_labels[] = $row['label'];
        $chart_counts[] = $row['cnt'];
    }

    // 3. إعادة توليد الرسم الخطي للنمو الشهري
    $stmtChartMonths = $pdo->prepare("
        SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS m_date, COUNT(r.id) AS cnt 
        FROM records r 
        JOIN users u ON r.user_id = u.id
        $total_sql
        GROUP BY m_date 
        ORDER BY m_date ASC LIMIT 6
    ");
    $stmtChartMonths->execute($params);
    $chart_months_data = $stmtChartMonths->fetchAll();

    $line_labels = []; $line_counts = [];
    foreach ($chart_months_data as $row) {
        $line_labels[] = $row['m_date'];
        $line_counts[] = $row['cnt'];
    }

    // ----------------- [الجزء الثاني: باني ومصمم الكروت الإحصائية المخصصة (KPIs)] -----------------
    $widget_type_id = isset($_GET['widget_type_id']) ? intval($_GET['widget_type_id']) : 0;
    $widget_fields = isset($_GET['widget_fields']) ? $_GET['widget_fields'] : []; 

    $widget_results = [];
    $widget_total_count = 0;

    if ($widget_type_id > 0) {
        $stmtWTotal = $pdo->prepare("SELECT COUNT(*) FROM records WHERE record_type_id = ?");
        $stmtWTotal->execute([$widget_type_id]);
        $widget_total_count = $stmtWTotal->fetchColumn() ?: 0;

        foreach ($widget_fields as $f_name) {
            $f_name = preg_replace('/[^a-zA-Z0-9_]/', '', $f_name); 
            $stmtCheckF = $pdo->prepare("SELECT label, type FROM fields WHERE field_name = ? AND is_active = 1 LIMIT 1");
            $stmtCheckF->execute([$f_name]);
            $f_info = $stmtCheckF->fetch();

            if ($f_info) {
                // [تحسين الأداء]: استخدام العمود المولد والمفهرس لتسريع الاستعلام الجغرافي
                $dbCol = "JSON_UNQUOTE(JSON_EXTRACT(dynamic_values, '$.$f_name'))";
                if ($f_name === 'point_number') {
                    $dbCol = "extracted_point_number";
                } elseif ($f_name === 'owner_name') {
                    $dbCol = "extracted_owner_name";
                }

                $stmtFDist = $pdo->prepare("
                    SELECT $dbCol AS val, COUNT(id) AS cnt 
                    FROM records 
                    WHERE record_type_id = ? AND $dbCol IS NOT NULL AND $dbCol != '' AND $dbCol != 'null'
                    GROUP BY val
                    ORDER BY cnt DESC
                ");
                $stmtFDist->execute([$widget_type_id]);
                $dist_data = $stmtFDist->fetchAll();

                $widget_results[] = [
                    'label' => $f_info['label'],
                    'field_name' => $f_name,
                    'type' => $f_info['type'],
                    'data' => $dist_data
                ];
            }
        }
    }

    // ----------------- [الجزء الثالث: محرك التحليل التفصيلي الإحصائي للحقول المخصصة] -----------------
    $rf_field = isset($_GET['report_field']) ? trim($_GET['report_field']) : '';
    $rf_user = isset($_GET['report_user']) ? intval($_GET['report_user']) : 0;
    $rf_from = isset($_GET['report_date_from']) ? trim($_GET['report_date_from']) : '';
    $rf_to = isset($_GET['report_date_to']) ? trim($_GET['report_date_to']) : '';

    $rf_clauses = [];
    $rf_params = [];

    if ($rf_user > 0) { $rf_clauses[] = "r.user_id = ?"; $rf_params[] = $rf_user; }
    if (!empty($rf_from)) { $rf_clauses[] = "DATE(r.created_at) >= ?"; $rf_params[] = $rf_from; }
    if (!empty($rf_to)) { $rf_clauses[] = "DATE(r.created_at) <= ?"; $rf_params[] = $rf_to; }

    $field_analysis_data = [];
    $field_labels_js = [];
    $field_counts_js = [];
    $total_rf_records = 0;

    if (!empty($rf_field)) {
        $dbCol = "JSON_UNQUOTE(JSON_EXTRACT(r.dynamic_values, '$.$rf_field'))";
        $rf_clauses[] = "$dbCol IS NOT NULL AND $dbCol != '' AND $dbCol != 'null'";
        $rf_sql = "WHERE " . implode(" AND ", $rf_clauses);

        // استعلام تجميع وعد القيم الفريدة داخل الحقل الـ JSON المحدد
        $stmtRF = $pdo->prepare("
            SELECT $dbCol AS val, COUNT(r.id) AS cnt 
            FROM records r
            JOIN users u ON r.user_id = u.id
            $rf_sql
            GROUP BY val
            ORDER BY cnt DESC
        ");
        $stmtRF->execute($rf_params);
        $field_analysis_data = $stmtRF->fetchAll();

        foreach ($field_analysis_data as $row) {
            $field_labels_js[] = $row['val'];
            $field_counts_js[] = $row['cnt'];
            $total_rf_records += $row['cnt'];
        }
    }

} catch (PDOException $e) {
    die("حدث خطأ في معالجة الإحصائيات: " . $e->getMessage());
}

// جلب قوائم الفلترة الأساسية
$types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
$users = $pdo->query("SELECT id, username FROM users ORDER BY id DESC")->fetchAll();
?>

<!-- ترويسة الرسوم والمخططات البيانية المستدعية بـ Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="space-y-6">

    <!-- أولاً: لوحة التصفية الأساسية والمتقدمة -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 hover:shadow-lg transition duration-300">
        <form method="GET" action="index.php" class="space-y-4">
            <input type="hidden" name="page" value="dashboard-view">
            
            <!-- تمرير قيم الفلترة للحقول الإحصائية المخصصة لثباتها -->
            <input type="hidden" name="widget_type_id" value="<?php echo $widget_type_id; ?>">
            <?php foreach ($widget_fields as $wf): ?>
                <input type="hidden" name="widget_fields[]" value="<?php echo htmlspecialchars($wf); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="report_field" value="<?php echo htmlspecialchars($rf_field); ?>">
            <input type="hidden" name="report_user" value="<?php echo $rf_user; ?>">
            <input type="hidden" name="report_date_from" value="<?php echo htmlspecialchars($rf_from); ?>">
            <input type="hidden" name="report_date_to" value="<?php echo htmlspecialchars($rf_to); ?>">

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-gray-500 text-xs font-semibold mb-1">القسم الميداني</label>
                    <select name="filter_type" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none text-xs font-bold text-gray-700 bg-white">
                        <option value="">كل الأقسام</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo $filter_type === intval($type['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-xs font-semibold mb-1">الموظف المسؤول</label>
                    <select name="filter_user" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none text-xs font-bold text-gray-700 bg-white">
                        <option value="">كل الموظفين</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filter_user === intval($user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-xs font-semibold mb-1">من تاريخ</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-3 py-1.5 border border-gray-200 rounded-lg focus:outline-none text-xs text-gray-700 bg-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-xs font-semibold mb-1">إلى تاريخ</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-3 py-1.5 border border-gray-200 rounded-lg focus:outline-none text-xs text-gray-700 bg-white">
                </div>
            </div>

            <!-- صف مخصص لتصفية الحقول المتقدمة -->
            <?php if (count($fields_list) > 0): ?>
                <div class="border-t pt-3">
                    <button type="button" onclick="toggleAdvancedFilters()" class="text-xs text-blue-600 hover:text-blue-800 font-bold flex items-center space-x-1 space-x-reverse focus:outline-none">
                        <i class="fa-solid fa-sliders text-sm"></i>
                        <span>تصفية الداش بورد بالحقول والبيانات المتقدمة</span>
                        <i id="filters-arrow" class="fa-solid fa-chevron-down text-[10px] transition-transform duration-300"></i>
                    </button>
                    <div id="advanced-filters-container" class="hidden grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 p-4 bg-gray-50 rounded-xl border border-gray-100">
                        <?php foreach ($fields_list as $field): 
                            $getVal = isset($_GET['col_' . $field['field_name']]) ? trim($_GET['col_' . $field['field_name']]) : '';
                        ?>
                            <div>
                                <label class="block text-gray-500 text-[10px] font-bold mb-1"><?php echo htmlspecialchars($field['label']); ?></label>
                                <?php if ($field['type'] === 'select'): ?>
                                    <select name="col_<?php echo $field['field_name']; ?>" class="w-full px-2 py-1 bg-white border border-gray-200 rounded text-xs focus:outline-none">
                                        <option value="">-- الكل --</option>
                                        <?php 
                                        $opts = explode(',', $field['options']);
                                        foreach ($opts as $opt): 
                                            $opt = trim($opt);
                                        ?>
                                            <option value="<?php echo $opt; ?>" <?php echo $getVal === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field['type'] === 'checkbox'): ?>
                                    <select name="col_<?php echo $field['field_name']; ?>" class="w-full px-2 py-1 bg-white border border-gray-200 rounded text-xs focus:outline-none">
                                        <option value="">-- الكل --</option>
                                        <option value="نعم" <?php echo $getVal === 'نعم' ? 'selected' : ''; ?>>نعم</option>
                                        <option value="لا" <?php echo $getVal === 'لا' ? 'selected' : ''; ?>>لا</option>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="col_<?php echo $field['field_name']; ?>" value="<?php echo htmlspecialchars($getVal); ?>" placeholder="اكتب للفرز..." class="w-full px-2 py-1 bg-white border border-gray-200 rounded text-xs focus:outline-none">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex space-x-2 space-x-reverse pt-2 border-t border-gray-100 justify-end">
                <a href="index.php?page=dashboard-view" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2 px-4 rounded-lg text-xs transition">إعادة تعيين</a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm">
                    <i class="fa-solid fa-sync"></i>
                    <span>تحديث المؤشرات والرسوم</span>
                </button>
            </div>
        </form>
    </div>

    <!-- ثانياً: كروت المؤشرات الإحصائية المصفاة (KPI Cards) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 flex items-center justify-between hover:shadow-lg transition">
            <div class="space-y-1">
                <span class="text-xs font-semibold text-gray-400 block">إجمالي السجلات المطابقة للتصفية</span>
                <span class="text-3xl font-extrabold text-slate-800"><?php echo $total_count; ?></span>
            </div>
            <div class="p-3 bg-blue-50 text-blue-600 rounded-xl"><i class="fa-solid fa-folder-closed text-2xl"></i></div>
        </div>
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 flex items-center justify-between hover:shadow-lg transition">
            <div class="space-y-1">
                <span class="text-xs font-semibold text-gray-400 block">المعاينات بالصور المرفقة</span>
                <span class="text-3xl font-extrabold text-amber-600"><?php echo $photos_count; ?></span>
            </div>
            <div class="p-3 bg-amber-50 text-amber-600 rounded-xl"><i class="fa-solid fa-camera text-2xl"></i></div>
        </div>
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 flex items-center justify-between hover:shadow-lg transition">
            <div class="space-y-1">
                <span class="text-xs font-semibold text-gray-400 block">الملفات والمستندات PDF</span>
                <span class="text-3xl font-extrabold text-red-600"><?php echo $pdfs_count; ?></span>
            </div>
            <div class="p-3 bg-red-50 text-red-600 rounded-xl"><i class="fa-solid fa-file-pdf text-2xl"></i></div>
        </div>
    </div>

    <!-- ثالثاً: الرسوم البيانية الأساسية المصفاة -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 flex flex-col justify-between h-96">
            <h4 class="text-sm font-bold text-gray-700 mb-4 border-b pb-2"><i class="fa-solid fa-chart-pie text-blue-500 ml-1"></i> التوزيع النسبي للسجلات المصفاة بالأقسام</h4>
            <div class="flex-1 relative flex items-center justify-center">
                <?php if (count($chart_counts) === 0): ?>
                    <p class="text-xs text-gray-400 font-semibold">لا توجد بيانات مطابقة للرسم حالياً.</p>
                <?php else: ?>
                    <canvas id="pieChart" class="max-h-64"></canvas>
                <?php endif; ?>
            </div>
        </div>
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 flex flex-col justify-between h-96">
            <h4 class="text-sm font-bold text-gray-700 mb-4 border-b pb-2"><i class="fa-solid fa-chart-line text-emerald-500 ml-1"></i> معدل التوثيق الشهري للمرشحات الحالية</h4>
            <div class="flex-1 relative flex items-center justify-center">
                <?php if (count($line_counts) === 0): ?>
                    <p class="text-xs text-gray-400 font-semibold">لا توجد سجلات مطابقة للرسم الخطي حالياً.</p>
                <?php else: ?>
                    <canvas id="lineChart" class="max-h-64"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- رابعاً: [باني ومصمم الكروت والمؤشرات المخصصة المدمج بالكامل بصيغة مفرزة عصرية] -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
        <div class="flex justify-between items-center border-b pb-3 border-gray-100">
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="p-2 bg-indigo-100 text-indigo-600 rounded-lg">
                    <i class="fa-solid fa-chart-simple text-xl"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">باني ومصمم الكروت والمؤشرات المخصصة (KPIs)</h3>
                    <p class="text-[10px] text-gray-400">اختر القسم وحدد الحقول ليقوم النظام ببناء كروت جرد ومؤشرات مستقلة فوراً.</p>
                </div>
            </div>
            
            <?php if ($widget_type_id > 0 && count($widget_results) > 0): ?>
                <button onclick="printCustomWidgets()" class="bg-slate-850 hover:bg-slate-850 text-gray-600 border hover:text-blue-600 py-1.5 px-3 rounded-lg text-xs font-bold transition flex items-center space-x-1 space-x-reverse shadow-sm">
                    <i class="fa-solid fa-print"></i>
                    <span>طباعة تقرير المؤشرات</span>
                </button>
            <?php endif; ?>
        </div>

        <form method="GET" action="index.php" class="space-y-4 text-xs">
            <input type="hidden" name="page" value="dashboard-view">
            
            <!-- لتوريث قيم تصفية الداش بورد السابقة -->
            <input type="hidden" name="filter_type" value="<?php echo $filter_type; ?>">
            <input type="hidden" name="filter_user" value="<?php echo $filter_user; ?>">
            <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                <div>
                    <label class="block text-gray-600 text-xs font-bold mb-1.5">1. حدد القسم الميداني المطلوب حصره:</label>
                    <select name="widget_type_id" id="widget_type_selector" required onchange="filterWidgetFields(this.value)" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans">
                        <option value="">-- اختر القسم الميداني --</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo $widget_type_id === intval($type['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="md:col-span-2 space-y-1.5">
                    <label class="block text-gray-600 text-xs font-bold"><i class="fa-solid fa-list-check text-indigo-500"></i> 2. حدد الحقول الفنية المُراد إبراز كروت وإحصائيات تكرارها:</label>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 bg-gray-50 p-3 rounded-xl border max-h-32 overflow-y-auto">
                        <?php foreach ($fields_list as $f): 
                            // معالجة الفراغات والمسافات (Spaces) من الـ IDs بقاعدة البيانات لتفادي المشاكل
                            $cleaned_ids = implode(',', array_map('trim', explode(',', $f['record_type_id'])));
                        ?>
                            <label data-widget-field-type="<?php echo $cleaned_ids; ?>" class="widget-field-cb-wrapper hidden flex items-center space-x-2 space-x-reverse text-[10px] text-gray-700 cursor-pointer select-none">
                                <input type="checkbox" name="widget_fields[]" value="<?php echo $f['field_name']; ?>" <?php echo in_array($f['field_name'], $widget_fields) ? 'checked' : ''; ?> class="w-4 h-4 text-indigo-600 border-gray-300 rounded">
                                <span><?php echo htmlspecialchars($f['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2 border-t">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-xl text-xs transition shadow-sm flex items-center space-x-1.5 space-x-reverse">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>توليد وتشييد الكروت الإحصائية المخصصة</span>
                </button>
            </div>
        </form>

        <!-- مخرجات كروت التجميع الموحدة المصلحة (كارت مستقل لكل حقل مفرز بالعدد فقط) -->
        <?php if ($widget_type_id > 0 && count($widget_results) > 0): ?>
            <div class="space-y-6 pt-4" id="generated-widgets-area">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6" id="kpi-cards-grid">
                    
                    <!-- الكرت الكلي للقسم -->
                    <div class="kpi-print-card bg-gradient-to-br from-slate-950 to-slate-850 text-white p-5 rounded-2xl shadow-sm border border-slate-800 flex flex-col justify-between hover:shadow-md transition min-h-[160px]">
                        <div class="flex justify-between items-start">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">إجمالي السجلات بالقسم</span>
                            <span class="text-slate-400 text-xs"><i class="fa-solid fa-folder-closed"></i></span>
                        </div>
                        <div class="flex items-baseline space-x-1 space-x-reverse mt-4">
                            <span class="text-3xl font-black font-sans"><?php echo $widget_total_count; ?></span>
                            <span class="text-[10px] text-slate-400 font-bold">معاينة</span>
                        </div>
                    </div>

                    <?php 
                    $colors_palette = [
                        ['bg' => 'bg-indigo-50/50', 'border' => 'border-indigo-100', 'text' => 'text-indigo-600', 'count' => 'text-indigo-900', 'dot' => 'bg-indigo-500', 'icon' => 'fa-bookmark'],
                        ['bg' => 'bg-emerald-50/50', 'border' => 'border-emerald-100', 'text' => 'text-emerald-600', 'count' => 'text-emerald-900', 'dot' => 'bg-emerald-500', 'icon' => 'fa-circle-check'],
                        ['bg' => 'bg-amber-50/50', 'border' => 'border-amber-100', 'text' => 'text-amber-600', 'count' => 'text-amber-900', 'dot' => 'bg-amber-500', 'icon' => 'fa-star'],
                        ['bg' => 'bg-rose-50/50', 'border' => 'border-rose-100', 'text' => 'text-rose-600', 'count' => 'text-rose-900', 'dot' => 'bg-rose-500', 'icon' => 'fa-circle-exclamation'],
                        ['bg' => 'bg-cyan-50/50', 'border' => 'border-cyan-100', 'text' => 'text-cyan-600', 'count' => 'text-cyan-900', 'dot' => 'bg-cyan-500', 'icon' => 'fa-circle-info'],
                        ['bg' => 'bg-violet-50/50', 'border' => 'border-violet-100', 'text' => 'text-violet-600', 'count' => 'text-violet-900', 'dot' => 'bg-violet-500', 'icon' => 'fa-tag'],
                    ];
                    $color_idx = 0;

                    foreach ($widget_results as $res): 
                        $palette = $colors_palette[$color_idx % count($colors_palette)];
                        $color_idx++;

                        // حساب مجموع الإدخالات المعبأة فعلياً لهذا الحقل
                        $total_field_filled = 0;
                        foreach ($res['data'] as $row) { $total_field_filled += $row['cnt']; }
                    ?>
                        <div class="kpi-print-card <?php echo $palette['bg']; ?> <?php echo $palette['border']; ?> p-5 rounded-2xl shadow-sm border flex flex-col justify-between hover:shadow-md transition page-break-inside-avoid min-h-[160px]">
                            <div class="flex justify-between items-start border-b pb-2 mb-3 border-gray-100">
                                <span class="text-xs font-bold text-gray-700">
                                    <i class="fa-solid <?php echo $palette['icon']; ?> <?php echo $palette['text']; ?> ml-1"></i>
                                    <?php echo htmlspecialchars($res['label']); ?>
                                </span>
                                <span class="text-[10px] text-gray-400 font-bold">إحصائية الحقل</span>
                            </div>

                            <div class="flex-1 flex flex-col justify-center">
                                <?php if ($res['type'] === 'select'): ?>
                                    <!-- إذا كان الحقل قائمة منسدلة: نعرض فقط الخيارات وأعدادها بشكل مختصر جداً بدون قوائم فرعية معقدة -->
                                    <div class="space-y-1.5 text-xs text-gray-600">
                                        <?php if (count($res['data']) === 0): ?>
                                            <span class="text-gray-400 text-center block text-[10px]">لا توجد خيارات مسجلة</span>
                                        <?php else: ?>
                                            <?php foreach ($res['data'] as $row): ?>
                                                <div class="flex justify-between items-center bg-white/60 px-2 py-1 rounded-md border border-gray-50">
                                                    <span class="font-bold text-gray-700 text-[10px]"><?php echo htmlspecialchars($row['val']); ?>:</span>
                                                    <span class="font-mono font-black <?php echo $palette['text']; ?>"><?php echo $row['cnt']; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <!-- إذا كان الحقل نصي أو رقمي عادي: نعرض فقط إجمالي السجلات التي تم توثيقها بداخل هذا الحقل -->
                                    <div class="text-center py-2">
                                        <span class="text-3xl font-black <?php echo $palette['count']; ?> font-sans block"><?php echo $total_field_filled; ?></span>
                                        <span class="text-[9px] text-gray-400 font-bold block mt-1">حالة موثقة مسجلة</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- خامساً: لوحة التحليل التفصيلي الإحصائي للحقول المخصصة ومخرجاتها -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-6">
        <div class="flex justify-between items-center border-b pb-3">
            <div class="flex items-center space-x-3 space-x-reverse">
                <div class="p-2 bg-indigo-50 text-indigo-600 rounded-lg">
                    <i class="fa-solid fa-chart-simple text-xl"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">التحليل الإحصائي التلقائي للحقول الفنية والمخصصة</h3>
                    <p class="text-[10px] text-gray-400">اختر الحقل والموظف والمدة، وسيقوم النظام بتجميع وعد وتحليل القيم فورياً بصرياً وورقياً.</p>
                </div>
            </div>
            
            <?php if (!empty($rf_field) && count($field_analysis_data) > 0): ?>
                <button onclick="printFieldAnalysis()" class="bg-slate-850 hover:bg-slate-850 text-gray-600 border hover:text-blue-600 py-1.5 px-3 rounded-lg text-xs font-bold transition flex items-center space-x-1 space-x-reverse shadow-sm">
                    <i class="fa-solid fa-print"></i>
                    <span>طباعة هذا التحليل الفني</span>
                </button>
            <?php endif; ?>
        </div>

        <!-- فورم الاستعلام والفلترة الخاص بالتحليل الفني للحقل -->
        <form method="GET" action="index.php" class="grid grid-cols-1 md:grid-cols-4 gap-4 bg-gray-50/50 p-4 rounded-xl border border-gray-100 items-end">
            <input type="hidden" name="page" value="dashboard-view">
            
            <!-- لتوريث فلاتر الداش بورد السابقة لعدم تصفيرها -->
            <input type="hidden" name="filter_type" value="<?php echo $filter_type; ?>">
            <input type="hidden" name="filter_user" value="<?php echo $filter_user; ?>">
            <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">

            <!-- 1. اختيار الحقل المستهدف للتحليل -->
            <div>
                <label class="block text-gray-600 text-xs font-bold mb-1">اختر الحقل المراد تحليله إحصائياً:</label>
                <select name="report_field" id="report_field_selector" required class="w-full px-3 py-2 border rounded-lg text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans">
                    <option value="">-- اختر الحقل الفني --</option>
                    <?php foreach ($fields_list as $f): ?>
                        <option value="<?php echo $f['field_name']; ?>" <?php echo $rf_field === $f['field_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. تحديد الموظف للتحليل -->
            <div>
                <label class="block text-gray-600 text-xs font-bold mb-1">فلترة بمسؤول التوثيق:</label>
                <select name="report_user" class="w-full px-3 py-2 border rounded-lg text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans">
                    <option value="">-- كل الموظفين --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $rf_user === intval($u['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 3. المدى الزمني للتحليل -->
            <div>
                <label class="block text-gray-600 text-xs font-bold mb-1">من تاريخ:</label>
                <input type="date" name="report_date_from" value="<?php echo htmlspecialchars($rf_from); ?>" class="w-full px-3 py-1.5 border rounded-lg text-xs text-gray-700 focus:outline-none bg-white">
            </div>
            <div>
                <label class="block text-gray-600 text-xs font-bold mb-1">إلى تاريخ:</label>
                <div class="grid grid-cols-3 gap-2">
                    <input type="date" name="report_date_to" value="<?php echo htmlspecialchars($rf_to); ?>" class="col-span-2 px-3 py-1.5 border rounded-lg text-xs text-gray-700 focus:outline-none bg-white">
                    <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg text-xs flex items-center justify-center shadow" title="بدء التحليل الفني">
                        <i class="fa-solid fa-chart-line text-sm"></i>
                    </button>
                </div>
            </div>
        </form>

        <!-- مخرجات الاستعلام والتحليل -->
        <?php if (!empty($rf_field)): ?>
            <?php if (count($field_analysis_data) === 0): ?>
                <div class="text-center py-8 text-gray-400 font-bold">عذراً، لا توجد بيانات مسجلة حالياً لهذا الحقل الفني مع خيارات التصفية الحالية.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 pt-4 items-center">
                    
                    <!-- أ. الرسم البياني العمودي -->
                    <div class="flex flex-col h-80 justify-between p-4 bg-gray-50/50 rounded-xl border border-gray-100">
                        <h4 class="text-xs font-bold text-gray-600 border-b pb-1.5 text-center"><i class="fa-solid fa-chart-simple text-indigo-500 ml-1"></i> رسم بياني لتوزيع تكرار القيم</h4>
                        <div class="flex-1 relative flex items-center justify-center">
                            <canvas id="fieldAnalysisChart" class="max-h-64"></canvas>
                        </div>
                    </div>

                    <!-- ب. جدول التوزيع الإحصائي والنسب المئوية الدقيقة للتصدير والطباعة -->
                    <div class="space-y-3" id="analysis-table-container">
                        <span class="text-xs font-bold text-gray-700 block"><i class="fa-solid fa-list-check text-indigo-500 ml-1"></i> جدول حصر التوزيع المئوي للقيم:</span>
                        <div class="overflow-x-auto rounded-xl border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                                <thead class="bg-gray-100 text-gray-700 font-bold uppercase">
                                    <tr>
                                        <th class="px-4 py-2.5">القيمة المدونة بالحقل</th>
                                        <th class="px-4 py-2.5">عدد مرات التكرار</th>
                                        <th class="px-4 py-2.5">النسبة المئوية من الحصر</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100 text-gray-600 font-semibold">
                                    <?php foreach ($field_analysis_data as $row): 
                                        $percentage = ($row['cnt'] / $total_rf_records) * 100;
                                    ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-2 text-slate-800 font-bold"><?php echo htmlspecialchars($row['val']); ?></td>
                                            <td class="px-4 py-2 font-mono text-slate-600"><?php echo $row['cnt']; ?> سجل</td>
                                            <td class="px-4 py-2 font-mono text-emerald-600 font-bold"><?php echo number_format($percentage, 1); ?> %</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="bg-slate-50 font-extrabold text-slate-800">
                                        <td class="px-4 py-2 border-t">إجمالي العينات والمدخلات الفنية:</td>
                                        <td class="px-4 py-2 border-t font-mono" colspan="2"><?php echo $total_rf_records; ?> سجل موثق</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>

</div>

<!-- معالجات الـ JS والرسوم البيانية المترابطة بالكامل -->
<script>
    document.addEventListener("DOMContentLoaded", function() {
        
        // 1. مزامنة بقاء صندوق الفلاتر المتقدمة مفتوحاً إذا كان مفعلاً
        const urlParams = new URLSearchParams(window.location.search);
        let hasAdvFilter = false;
        for (const [key, value] of urlParams.entries()) {
            if (key.startsWith('col_') && value !== '') { hasAdvFilter = true; break; }
        }
        if (hasAdvFilter) {
            document.getElementById('advanced-filters-container').classList.remove('hidden');
            document.getElementById('filters-arrow').classList.add('rotate-180');
        }

        // 2. مزامنة وتصفية حقول باني الكروت المخصصة فورياً بناء على القسم المختار قديماً
        const activeWidgetType = document.getElementById('widget_type_selector').value;
        if (activeWidgetType) {
            filterWidgetFields(activeWidgetType);
        }

        // أ. الرسم الدائري للأقسام
        <?php if (count($chart_counts) > 0): ?>
        const ctxPie = document.getElementById('pieChart').getContext('2d');
        new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($chart_counts); ?>,
                    backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#6b7280'],
                    borderWidth: 1
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { family: 'Cairo', size: 10 } } } } }
        });
        <?php endif; ?>

        // ب. الرسم الخطي للنمو الشهري
        <?php if (count($line_counts) > 0): ?>
        const ctxLine = document.getElementById('lineChart').getContext('2d');
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($line_labels); ?>,
                datasets: [{
                    label: 'السجلات الموثقة',
                    data: <?php echo json_encode($line_counts); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.08)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2
                }]
            },
            options: { 
                responsive: true, 
                plugins: { legend: { display: false } }, 
                scales: { 
                    y: { beginAtZero: true, ticks: { font: { family: 'Cairo', size: 9 } } },
                    x: { ticks: { font: { family: 'Cairo', size: 9 } } }
                } 
            }
        });
        <?php endif; ?>

        // جـ. تفعيل الرسم البياني العمودي للتحليل الإحصائي
        <?php if (!empty($rf_field) && count($field_analysis_data) > 0): ?>
        const ctxRF = document.getElementById('fieldAnalysisChart').getContext('2d');
        new Chart(ctxRF, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($field_labels_js, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($field_counts_js); ?>,
                    backgroundColor: 'rgba(99, 102, 241, 0.85)',
                    borderColor: '#6366f1',
                    borderWidth: 1.5,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { font: { family: 'Cairo', size: 9 } } },
                    x: { ticks: { font: { family: 'Cairo', size: 9 } } }
                }
            }
        });
        <?php endif; ?>
    });

    function toggleAdvancedFilters() {
        document.getElementById('advanced-filters-container').classList.toggle('hidden');
        document.getElementById('filters-arrow').classList.toggle('rotate-180');
    }

    // [تحديث تفاعلي]: دالة تصفية الحقول المخصصة لباني الكروت بناء على القسم المختار لتلافي التكرار والازدواجية
    function filterWidgetFields(recordTypeId) {
        document.querySelectorAll('.widget-field-cb-wrapper').forEach(wrapper => {
            const fieldTypeIdStr = wrapper.getAttribute('data-widget-field-type');
            const typeIds = fieldTypeIdStr.split(',').map(s => s.trim()); // تنظيف المسافات والفراغات تلقائياً لعدم الحجب
            if (typeIds.includes(String(recordTypeId))) {
                wrapper.classList.remove('hidden');
            } else {
                wrapper.classList.add('hidden');
                wrapper.querySelector('input[type="checkbox"]').checked = false; 
            }
        });
    }

    // دالة طباعة وتوليد مستند الـ PDF للبوكسات المفرزة المجمعة بالتنسيق الموحد العصري الجديد
    function printCustomWidgets() {
        const printWindow = window.open('', '_blank');
        const widgetsHTML = document.getElementById('generated-widgets-area').innerHTML;
        const selector = document.getElementById('widget_type_selector');
        const typeLabel = selector.options[selector.selectedIndex].text;

        let htmlContent = '<html lang="ar" dir="rtl"><head><title>تقرير المؤشرات المخصصة</title>';
        htmlContent += '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">';
        htmlContent += '<script src="https://cdn.tailwindcss.com"><' + '/script>';
        htmlContent += '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
        htmlContent += '<style>body { font-family: "Cairo", sans-serif; padding: 40px; background: white; color: black; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;}';
        htmlContent += '.no-print { display: none !important; }';
        
        // تنسيق شبكة الكروت في الطباعة لتكون رقيقة وعصرية تماماً كما بالمتصفح
        htmlContent += '.widgets-print-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 15px; }';
        htmlContent += '.kpi-print-card { border: 1px solid #e2e8f0; padding: 16px; border-radius: 12px; background: #f8fafc; display: flex; flex-direction: column; justify-content: space-between; }';
        htmlContent += '.kpi-print-card span { font-weight: 700; color: #1e293b; }';
        
        htmlContent += '</style></head><body>';
        htmlContent += '<div class="text-center mb-8 border-b pb-4"><h1 class="text-lg font-bold text-gray-800">تقرير المؤشرات والتحليلات الميدانية المخصصة</h1>';
        htmlContent += '<h2 class="text-xs text-indigo-600 font-bold mt-1">القسم الميداني الموثق: ( ' + typeLabel + ' )</h2>';
        htmlContent += '<span class="text-[10px] text-gray-400 block mt-1">تاريخ استخراج التقرير: ' + new Date().toISOString().substring(0, 10) + '</span></div>';
        
        let cleanWidgetsHTML = widgetsHTML.replace(/grid-cols-1 md:grid-cols-3/g, 'widgets-print-grid');
        
        htmlContent += cleanWidgetsHTML;
        htmlContent += '<' + 'script' + '>';
        htmlContent += 'window.addEventListener("DOMContentLoaded", () => { setTimeout(() => { window.print(); window.close(); }, 500); });';
        htmlContent += '<' + '/script' + '></body></html>';

        printWindow.document.write(htmlContent);
        printWindow.document.close();
    }

    // دالة طباعة وتوليد مستند الـ PDF للتحليل الإحصائي للحقل
    function printFieldAnalysis() {
        const printWindow = window.open('', '_blank');
        const chartImg = document.getElementById('fieldAnalysisChart').toDataURL('image/png');
        const tableHTML = document.getElementById('analysis-table-container').innerHTML;
        const fieldLabel = document.getElementById('report_field_selector').options[document.getElementById('report_field_selector').selectedIndex].text;
        
        printWindow.document.write(`
            <html lang="ar" dir="rtl">
            <head>
                <title>تقرير تحليل حقل: ${fieldLabel}</title>
                <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
                <script src="https://cdn.tailwindcss.com"><` + `/script>
                <style>
                    body { font-family: 'Cairo', sans-serif; padding: 45px; background: white; color: black; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 11px; }
                    th, td { border: 1px solid #ccc; padding: 10px; text-align: right; }
                    th { background-color: #f3f4f6; }
                </style>
            </head>
            <body>
                <div class="text-center mb-8 border-b pb-4">
                    <h1 class="text-xl font-bold text-gray-800">تقرير إحصائي جيو-تحليلي مفصل</h1>
                    <h2 class="text-sm text-indigo-600 font-bold mt-1">حصر وتوزيع تكرار قيم حقل: ( ${fieldLabel} )</h2>
                    <span class="text-[10px] text-gray-400 block mt-1">تاريخ استخراج المستند: ${new Date().toISOString().substring(0, 10)}</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-center mt-6">
                    <div class="flex justify-center border p-4 rounded-xl bg-gray-50/20">
                        <img src="${chartImg}" class="max-h-64 object-contain">
                    </div>
                    <div>
                        <span class="text-xs font-bold text-gray-700 block mb-2">جدول الحصر والنسب المئوية للتكرار:</span>
                        ${tableHTML}
                    </div>
                </div>
                <script>
                    window.addEventListener('DOMContentLoaded', () => {
                        setTimeout(() => { window.print(); window.close(); }, 500);
                    });
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
</script>