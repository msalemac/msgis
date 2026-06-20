<?php
// pages/reports-view.php - منشئ ومستخرج التقارير التفصيلية وجداول الحصر (النسخة النهائية لبيئات PHP 8.4)
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$role = isset($role) ? $role : ($_SESSION['role'] ?? 'user');
$user_allowed_pages = !empty($_SESSION['allowed_pages']) ? explode(',', (string)$_SESSION['allowed_pages']) : [];

// [صمام أمان حاسم]: حظر وطرد أي مستخدم عادي يحاول قراءة التقارير الحساسة دون تصريح صريح
if ($role !== 'admin' && !in_array('reports-view', $user_allowed_pages)) {
    die("عذراً، ليس لديك صلاحية الوصول إلى موديول منشئ التقارير.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية لثبات واستقرار التنبيهات الرسومية
if (isset($_SESSION['reports_success_msg'])) { $message = $_SESSION['reports_success_msg']; unset($_SESSION['reports_success_msg']); }
if (isset($_SESSION['reports_error_msg'])) { $error = $_SESSION['reports_error_msg']; unset($_SESSION['reports_error_msg']); }

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

// ----------------- [1. الدوال الجغرافية الشاملة للفرز بحدود KML (Point-in-Polygon)] -----------------

if (!function_exists('parseKmlToPolygonPoints')) {
    function parseKmlToPolygonPoints($kml_data) {
        $polygon = [];
        try {
            $xml = simplexml_load_string($kml_data);
            if ($xml) {
                $xml->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');
                $result = $xml->xpath('//kml:coordinates');
                if (empty($result)) { $result = $xml->xpath('//coordinates'); }
                
                if (!empty($result)) {
                    $coordsText = trim((string)$result[0]);
                    $coordsArr = preg_split('/\s+/', $coordsText);
                    foreach ($coordsArr as $pointStr) {
                        $parts = explode(',', $pointStr);
                        if (count($parts) >= 2) {
                            $lng = floatval($parts[0]); $lat = floatval($parts[1]);
                            $polygon[] = ['lat' => $lat, 'lng' => $lng];
                        }
                    }
                }
            }
        } catch (Exception $e) {}
        return $polygon;
    }
}

if (!function_exists('isPointInPolygon')) {
    function isPointInPolygon($lat, $lng, $polygon) {
        $inside = false;
        $numPoints = count($polygon);
        if ($numPoints < 3) return false; 
        
        for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
            $xi = $polygon[$i]['lat']; $yi = $polygon[$i]['lng'];
            $xj = $polygon[$j]['lat']; $yj = $polygon[$j]['lng'];
            
            $intersect = (($yi > $lng) != ($yj > $lng)) && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);
            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }
}

// ----------------- [2. معالجة عمليات الـ POST الخاصة بالتقارير المفضلة وحفظ القوالب أمنياً] -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الإجباري من توكن حماية CSRF لمنع هجمات التزوير
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_favorite_report') {
        $report_name = trim((string)($_POST['report_name'] ?? ''));
        $columns_config = trim((string)($_POST['columns_config'] ?? ''));
        $filters_config = trim((string)($_POST['filters_config'] ?? ''));

        if (!empty($report_name)) {
            try {
                $stmtInsFav = $pdo->prepare("INSERT INTO favorite_reports (report_name, columns_config, filters_config) VALUES (?, ?, ?)");
                $stmtInsFav->execute([$report_name, $columns_config, $filters_config]);
                
                logActivity($pdo, "حفظ تقرير مفضل", "قام المستخدم بحفظ قالب تقرير مفضل جديد باسم: " . $report_name);
                $_SESSION['reports_success_msg'] = "تم حفظ وتثبيت التقرير المفضل بنجاح باسم: " . $report_name;
            } catch (PDOException $e) { $_SESSION['reports_error_msg'] = "حدث خطأ أثناء حفظ التقرير المفضل."; }
        }
        safeRedirect("index.php?page=reports-view");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_favorite_report') {
        $fav_id = intval($_POST['fav_id'] ?? 0);
        try {
            $stmtDelFav = $pdo->prepare("DELETE FROM favorite_reports WHERE id = ?");
            $stmtDelFav->execute([$fav_id]);
            $_SESSION['reports_success_msg'] = "تم حذف قالب التقرير المفضل بنجاح من المنصة الكلية.";
        } catch (PDOException $e) { $_SESSION['reports_error_msg'] = "فشل حذف التقرير المفضل."; }
        safeRedirect("index.php?page=reports-view");
    }
}

// ----------------- [3. استدعاء وجلب السجلات المفلترة والحدود الجغرافية والتقارير المفضلة] -----------------

// جلب الحدود الإدارية لتشغيل الفرز الجغرافي KML
$boundaries = $pdo->query("SELECT id, name, color, kml_data FROM boundaries ORDER BY id DESC")->fetchAll();
$filter_boundary_id = isset($_GET['filter_boundary_id']) ? intval($_GET['filter_boundary_id']) : 0;

// جلب السجلات المصرح للموظف برؤيتها فقط
try {
    if ($role === 'admin') {
        $records_query = "
            SELECT r.*, rt.label AS type_label, rt.color, u.username 
            FROM records r
            JOIN record_types rt ON r.record_type_id = rt.id
            JOIN users u ON r.user_id = u.id
            ORDER BY r.id DESC
        ";
        $stmt = $pdo->query($records_query);
    } else {
        $records_query = "
            SELECT r.*, rt.label AS type_label, rt.color, u.username 
            FROM records r
            JOIN record_types rt ON r.record_type_id = rt.id
            JOIN users u ON r.user_id = u.id
            WHERE FIND_IN_SET(r.record_type_id, ?)
            ORDER BY r.id DESC
        ";
        $stmt = $pdo->prepare($records_query);
        $stmt->execute([$allowed_types]);
    }
    $raw_records = $stmt->fetchAll();
} catch (PDOException $e) { die("خطأ في جلب السجلات: " . $e->getMessage()); }

// [تنشيط الفرز الجغرافي]: تصفية السجلات التي تقع داخل مضلع الـ KML المختار حالياً
$records = [];
if ($filter_boundary_id > 0) {
    // جلب الـ KML الخاص بالحي المختار باستخدام الاستعلام الآمن المجهز
    $stmtBoundKml = $pdo->prepare("SELECT kml_data FROM boundaries WHERE id = ?");
    $stmtBoundKml->execute([$filter_boundary_id]);
    $selected_boundary_kml = $stmtBoundKml->fetchColumn();
    
    if ($selected_boundary_kml) {
        $polygon = parseKmlToPolygonPoints($selected_boundary_kml);
        foreach ($raw_records as $rec) {
            if ($rec['latitude'] && $rec['longitude']) {
                if (isPointInPolygon(floatval($rec['latitude']), floatval($rec['longitude']), $polygon)) {
                    $records[] = $rec; 
                }
            }
        }
    }
} else {
    $records = $raw_records; 
}

// تقسيم السجلات جغرافياً للصفحة الحالية (Array Pagination)
$total_records = count($records);
$limit = 20; 
$total_pages = ceil($total_records / $limit);
$current_page = isset($_GET['p_num']) ? max(1, intval($_GET['p_num'])) : 1;
if ($current_page > $total_pages && $total_pages > 0) { $current_page = $total_pages; }
$offset = ($current_page - 1) * $limit;

// اقتطاع شريحة السجلات المعروضة للصفحة الحالية
$paginated_records = array_slice($records, $offset, $limit);

// جلب الأقسام والحقول المخصصة المتاحة للموظف لبناء فلاتر الجدول المعتمدة
if ($role === 'admin') {
    $types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
    $all_fields_for_columns = $pdo->query("SELECT field_name, label, type, record_type_id FROM fields WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
} else {
    $stmtT = $pdo->prepare("SELECT * FROM record_types WHERE FIND_IN_SET(id, ?) ORDER BY id DESC");
    $stmtT->execute([$allowed_types]);
    $types = $stmtT->fetchAll();

    $stmtF = $pdo->prepare("SELECT field_name, label, type, record_type_id FROM fields WHERE FIND_IN_SET(record_type_id, ?) AND is_active = 1 ORDER BY id ASC");
    $stmtF->execute([$allowed_types]);
    $all_fields_for_columns = $stmtF->fetchAll();
}

$favorite_reports = $pdo->query("SELECT * FROM favorite_reports ORDER BY id DESC")->fetchAll();
?>

<div class="space-y-6 text-right animate-fade" dir="rtl">

    <!-- كارت الأدوات وفلترة مضلعات KML والتقارير المفضلة والتصدير -->
    <div class="bg-white p-4 rounded-xl shadow-md border border-gray-100 flex flex-wrap items-center justify-between gap-3 bg-gray-50/50">
        
        <div class="flex flex-wrap items-center gap-3">
            <!-- أ. الفرز الجغرافي (KML): تصفية السجلات الجغرافية داخل مضلع KML معين -->
            <div class="flex items-center space-x-2 space-x-reverse">
                <label class="text-[10px] text-gray-500 font-bold">الالتقاء والفرز الجغرافي (KML):</label>
                <select id="filter_boundary_selector" onchange="filterTableByBoundary(this.value)" class="px-2 py-1.5 border border-gray-200 rounded-lg text-[10px] font-bold text-gray-700 bg-white focus:outline-none">
                    <option value="">كل الحدود والمضلعات</option>
                    <?php foreach ($boundaries as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $filter_boundary_id === intval($b['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ب. اختيار تقرير مفضل محفوظ مسبقاً لتطبيقه فوراً -->
            <div class="flex items-center space-x-2 space-x-reverse border-r pr-3">
                <label class="text-[10px] text-purple-600 font-bold">التقارير المفضلة المعتمدة:</label>
                <select id="favorite_report_selector" onchange="loadFavoriteReport(this.value)" class="px-2 py-1.5 border border-purple-200 rounded-lg text-[10px] font-bold text-purple-700 bg-white focus:outline-none">
                    <option value="">-- اختر تقريراً مفضلاً --</option>
                    <?php foreach ($favorite_reports as $fav): ?>
                        <option value="<?php echo $fav['id']; ?>"><?php echo htmlspecialchars($fav['report_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <!-- حفظ التنسيق الحالي كقالب مفضل -->
            <button onclick="saveCurrentLayoutAsFavorite()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-3 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm" title="حفظ شكل الفلاتر">
                <i class="fa-solid fa-bookmark text-white"></i>
                <span class="text-white">حفظ الاستعلام الحالي</span>
            </button>

            <!-- تخصيص الأعمدة المنسدلة -->
            <div class="relative inline-block text-right">
                <button onclick="toggleColSelector()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm">
                    <i class="fa-solid fa-table-columns text-white"></i>
                    <span class="text-white">تخصيص الأعمدة المعروضة</span>
                </button>
                <div id="col-selector-dropdown" class="absolute left-0 mt-2 w-64 rounded-xl shadow-2xl bg-white border border-gray-100 z-20 p-4 hidden text-gray-800 text-right">
                    <span class="block text-xs font-bold text-gray-700 border-b pb-1 mb-2">تحديد الأعمدة الظاهرة</span>
                    <div class="space-y-2 max-h-60 overflow-y-auto text-xs">
                        <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-id" onchange="toggleTableCol('col-id', this.checked)" checked class="w-4 h-4 rounded"><span>رقم المعاينة</span></label>
                        <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-type" onchange="toggleTableCol('col-type', this.checked)" checked class="w-4 h-4 rounded"><span>القسم الميداني</span></label>
                        <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-user" onchange="toggleTableCol('col-user', this.checked)" checked class="w-4 h-4 rounded"><span>الموظف المسؤول</span></label>
                        <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-coords" onchange="toggleTableCol('col-coords', this.checked)" checked class="w-4 h-4 rounded"><span>الموقع الجغرافي</span></label>
                        <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-attachments" onchange="toggleTableCol('col-attachments', this.checked)" checked class="w-4 h-4 rounded"><span>المرفقات</span></label>
                        <label class="flex items-center space-x-2 space-x-reverse cursor-pointer"><input type="checkbox" id="cb-col-date" onchange="toggleTableCol('col-date', this.checked)" checked class="w-4 h-4 rounded"><span>تاريخ التوثيق</span></label>
                        <hr class="my-2">
                        <?php foreach ($all_fields_for_columns as $f): ?>
                            <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                                <input type="checkbox" id="cb-col-<?php echo $f['field_name']; ?>" onchange="toggleTableCol('col-<?php echo $f['field_name']; ?>', this.checked)" class="w-4 h-4 rounded text-purple-600">
                                <span><?php echo htmlspecialchars($f['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <button onclick="exportExcelReport()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm">
                <i class="fa-solid fa-file-excel text-sm text-white"></i>
                <span class="text-white">تصدير تقرير XLS</span>
            </button>
            <button onclick="printFilteredPDF()" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg text-xs transition flex items-center space-x-1.5 space-x-reverse shadow-sm">
                <i class="fa-solid fa-file-pdf text-sm text-white"></i>
                <span class="text-white">تصدير تقرير PDF</span>
            </button>
        </div>
    </div>

    <!-- جدول التقرير الديناميكي المطور بالربط المعرفي الحصري -->
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table id="main-records-table" class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-gray-50 text-gray-700 font-bold uppercase">
                    <tr>
                        <th data-column="col-id" class="px-6 py-3">رقم المعاينة</th>
                        <th data-column="col-type" class="px-6 py-3">القسم الميداني</th>
                        <th data-column="col-user" class="px-6 py-3">الموظف المسؤول</th>
                        <th data-column="col-coords" class="px-6 py-3">الموقع الجغرافي</th>
                        <th data-column="col-attachments" class="px-6 py-3">المستندات والصور</th>
                        <th data-column="col-date" class="px-6 py-3">تاريخ التوثيق</th>
                        
                        <?php foreach ($all_fields_for_columns as $f): ?>
                            <th data-column="col-<?php echo $f['field_name']; ?>" class="px-6 py-3 hidden text-purple-600 font-bold"><?php echo htmlspecialchars($f['label']); ?></th>
                        <?php endforeach; ?>
                        <th class="px-6 py-3 text-center no-print w-28 font-bold">الإجراءات</th>
                    </tr>

                    <!-- صف البحث والفلترة لكل عمود بشكل مستقل تماماً بالربط المعرفي -->
                    <tr class="bg-slate-100/50 no-print border-b">
                        <td data-column="col-id" class="p-2"><input type="text" data-filter-for="col-id" onkeyup="filterTable()" placeholder="رقم..." class="col-search-input w-full px-2 py-1 border rounded text-[10px] text-center focus:outline-none"></td>
                        <td data-column="col-type" class="p-2"><input type="text" data-filter-for="col-type" onkeyup="filterTable()" placeholder="القسم..." class="col-search-input w-full px-2 py-1 border rounded text-[10px] text-right focus:outline-none"></td>
                        <td data-column="col-user" class="p-2"><input type="text" data-filter-for="col-user" onkeyup="filterTable()" placeholder="المسؤول..." class="col-search-input w-full px-2 py-1 border rounded text-[10px] text-right focus:outline-none"></td>
                        <td data-column="col-coords" class="p-2"></td>
                        <td data-column="col-attachments" class="p-2"></td>
                        <td data-column="col-date" class="p-2">
                            <div class="flex items-center space-x-1 space-x-reverse">
                                <select data-operator-for="col-date" onchange="filterTable()" class="operator-select px-1 py-1 border rounded text-[9px] focus:outline-none bg-white font-sans">
                                    <option value="contains">يحتوي</option>
                                    <option value="equals">=</option>
                                    <option value="gt">بعد</option>
                                    <option value="lt">قبل</option>
                                </select>
                                <input type="text" data-filter-for="col-date" onkeyup="filterTable()" placeholder="تاريخ..." class="col-search-input flex-1 px-1.5 py-1 border rounded text-[10px] focus:outline-none bg-white">
                            </div>
                        </td>
                        
                        <?php foreach ($all_fields_for_columns as $f): ?>
                            <td data-column="col-<?php echo $f['field_name']; ?>" class="p-2 hidden">
                                <div class="flex items-center space-x-1 space-x-reverse">
                                    <select data-operator-for="col-<?php echo $f['field_name']; ?>" onchange="filterTable()" class="operator-select px-1 py-1 border rounded text-[9px] focus:outline-none bg-white font-sans">
                                        <option value="contains">يحتوي</option>
                                        <option value="equals">=</option>
                                        <?php if ($f['type'] === 'number'): ?>
                                            <option value="gt">&gt;</option>
                                            <option value="lt">&lt;</option>
                                        <?php endif; ?>
                                    </select>
                                    <input type="text" data-filter-for="col-<?php echo $f['field_name']; ?>" onkeyup="filterTable()" data-type="<?php echo $f['type']; ?>" placeholder="بحث..." class="col-search-input flex-1 px-1.5 py-1 border rounded text-[10px] focus:outline-none bg-white">
                                </div>
                            </td>
                        <?php endforeach; ?>
                        <td class="p-2 no-print"></td>
                    </tr>
                </thead>
                <tbody id="table-body" class="bg-white divide-y divide-gray-100 text-[11px] text-slate-900 font-bold">
                    <?php foreach ($paginated_records as $rec): ?>
                        <tr class="record-row hover:bg-gray-50/50 transition">
                            <td data-column="col-id" class="px-6 py-4 font-mono font-bold text-gray-500">#<?php echo $rec['id']; ?></td>
                            <td data-column="col-type" class="px-6 py-4 font-black text-slate-800">
                                <span class="inline-block w-2.5 h-2.5 rounded-full ml-1.5" style="background-color: <?php echo $rec['color'] ?: '#3085d6'; ?>;"></span>
                                <?php echo htmlspecialchars($rec['type_label']); ?>
                            </td>
                            <td data-column="col-user" class="px-6 py-4 font-extrabold text-slate-800"><?php echo htmlspecialchars($rec['username']); ?></td>
                            <td data-column="col-coords" class="px-6 py-4 font-mono text-[10px] text-slate-700">
                                <?php if ($rec['latitude'] && $rec['longitude']): ?>
                                    <span>Lat: <?php echo round($rec['latitude'], 5); ?>, Lng: <?php echo round($rec['longitude'], 5); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400">غير محدد</span>
                                <?php endif; ?>
                            </td>
                            <td data-column="col-attachments" class="px-6 py-4 space-x-1 space-x-reverse no-print">
                                <?php if ($rec['photo_path']): ?>
                                    <span class="bg-amber-100 text-amber-800 text-[10px] font-bold px-1.5 py-0.5 rounded-full">صورة</span>
                                <?php endif; ?>
                                <?php if ($rec['pdf_path']): ?>
                                    <span class="bg-red-100 text-red-800 text-[10px] font-bold px-1.5 py-0.5 rounded-full">PDF</span>
                                <?php endif; ?>
                            </td>
                            <td data-column="col-date" class="px-6 py-4 text-slate-500 font-bold"><?php echo date('Y-m-d H:i', strtotime($rec['created_at'])); ?></td>
                            
                            <?php 
                            $dyn_vals = json_decode($rec['dynamic_values'], true) ?: [];
                            foreach ($all_fields_for_columns as $f): 
                                // حماية تفريغ البيانات من الانهيار عند التحويل لـ string ببيئات PHP 8.4
                                $val = isset($dyn_vals[$f['field_name']]) ? (string)$dyn_vals[$f['field_name']] : '-';
                            ?>
                                <td data-column="col-<?php echo $f['field_name']; ?>" class="px-6 py-4 hidden font-black text-slate-900" data-value="<?php echo htmlspecialchars($val); ?>">
                                    <?php if ($val === 'نعم'): ?>
                                        <span class="text-emerald-700"><i class="fa-solid fa-circle-check ml-1 text-emerald-600"></i> نعم</span>
                                    <?php elseif ($val === 'لا'): ?>
                                        <span class="text-red-700"><i class="fa-solid fa-circle-xmark ml-1 text-red-500"></i> لا</span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($val); ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <td class="px-6 py-4 text-center space-x-1.5 space-x-reverse font-sans no-print w-28">
                                <a href="index.php?page=view-record&id=<?php echo $rec['id']; ?>" class="inline-flex items-center p-2 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg transition" title="عرض السجل">
                                    <i class="fa-solid fa-eye text-xs text-blue-600"></i>
                                </a>
                                <?php if ($rec['latitude'] && $rec['longitude']): ?>
                                    <a href="index.php?page=map-view&highlight=<?php echo $rec['id']; ?>" class="inline-flex items-center p-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-600 rounded-lg transition" title="تحديد بالخريطة">
                                        <i class="fa-solid fa-map-location-dot text-xs text-emerald-600"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="inline-flex items-center p-2 bg-gray-50 text-gray-300 rounded-lg cursor-not-allowed" title="لا توجد إحداثيات">
                                        <i class="fa-solid fa-map-location-dot text-xs text-gray-300"></i>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
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
                        <a href="index.php?page=reports-view&p_num=<?php echo $current_page - 1; ?>&filter_boundary_id=<?php echo $filter_boundary_id; ?>" class="px-3 py-1.5 border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 rounded-lg transition">&laquo; السابق</a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 border border-gray-150 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed">&laquo; السابق</span>
                    <?php endif; ?>

                    <?php 
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <a href="index.php?page=reports-view&p_num=<?php echo $i; ?>&filter_boundary_id=<?php echo $filter_boundary_id; ?>" class="px-3 py-1.5 border rounded-lg transition <?php echo $i === $current_page ? 'bg-blue-600 border-blue-600 text-white shadow-sm' : 'border-gray-200 bg-white hover:bg-gray-50 text-gray-700'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="index.php?page=reports-view&p_num=<?php echo $current_page + 1; ?>&filter_boundary_id=<?php echo $filter_boundary_id; ?>" class="px-3 py-1.5 border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 rounded-lg transition">التالي &raquo;</a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 border border-gray-150 bg-gray-100 text-gray-400 rounded-lg cursor-not-allowed">التالي &raquo;</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-slate-900 text-slate-300 px-6 py-3 flex flex-wrap justify-between items-center text-xs font-semibold select-none border-t border-slate-800">
            <div><i class="fa-solid fa-list-ol text-blue-400 ml-1"></i> السجلات المعروضة المطابقة للبحث بالصفحة: <span id="status-count" class="text-white font-bold text-sm">0</span></div>
            <div id="status-calculations" class="flex flex-wrap items-center gap-4 text-[11px]"></div>
        </div>
    </div>
</div>

<!-- نموذج إرسال لحفظ التقرير المفضل POST (محدث بالـ CSRF Token) -->
<form id="save-favorite-form" method="POST" action="index.php?page=reports-view" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="action" value="save_favorite_report">
    <input type="hidden" name="report_name" id="fav_report_name">
    <input type="hidden" name="columns_config" id="fav_columns_config">
    <input type="hidden" name="filters_config" id="fav_filters_config">
</form>

<!-- معالجات ومزامنة جافا سكريبت للتبويبات والأعمدة والطباعة والتقارير المفضلة -->
<script>
    const dynamicColumns = <?php echo json_encode($all_fields_for_columns, JSON_UNESCAPED_UNICODE); ?>;
    const printTemplates = <?php echo json_encode($pdo->query("SELECT id, template_name FROM print_templates ORDER BY id DESC")->fetchAll(), JSON_UNESCAPED_UNICODE); ?>;
    const favoriteReports = <?php echo json_encode($favorite_reports, JSON_UNESCAPED_UNICODE); ?>;

    document.addEventListener("DOMContentLoaded", function() {
        loadCustomColumns();
        
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab === 'widgets' || urlParams.get('widget_type_id')) {
            switchReportTab('widgets-tab', document.getElementById('btn-widgets-tab'));
            const typeId = document.getElementById('widget_type_selector').value;
            if (typeId) filterWidgetFields(typeId);
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.relative')) {
                document.getElementById('col-selector-dropdown').classList.add('hidden');
            }
        });
    });

    function saveCurrentLayoutAsFavorite() {
        const table = document.getElementById('main-records-table');
        const headers = Array.from(table.querySelectorAll('thead tr:first-child th'));
        
        let visibleCols = [];
        let activeFilters = {};

        headers.forEach(th => {
            const colName = th.getAttribute('data-column');
            const isColHidden = th.classList.contains('hidden');
            
            if (!isColHidden) {
                visibleCols.push(colName);
                
                const searchInput = table.querySelector(`.col-search-input[data-filter-for="${colName}"]`);
                if (searchInput && searchInput.value.trim() !== '') {
                    const opSelect = table.querySelector(`.operator-select[data-operator-for="${colName}"]`);
                    activeFilters[colName] = {
                        val: searchInput.value.trim(),
                        op: opSelect ? opSelect.value : 'contains'
                    };
                }
            }
        });

        if (visibleCols.length === 0) return;

        Swal.fire({
            title: 'حفظ هذا الاستعلام كتقرير مفضل',
            input: 'text',
            inputPlaceholder: 'اكتب اسماً منسقاً للتقرير (مثال: مخالفات حي ثان)...',
            showCancelButton: true,
            confirmButtonText: 'حسناً',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#4f46e5',
            inputValidator: (value) => {
                if (!value || value.trim() === '') {
                    return 'يرجى كتابة اسم التقرير أولاً لحفظه برمجياً!';
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const reportName = result.value.trim();
                document.getElementById('fav_report_name').value = reportName;
                document.getElementById('fav_columns_config').value = visibleCols.join(',');
                document.getElementById('fav_filters_config').value = JSON.stringify(activeFilters);
                document.getElementById('save-favorite-form').submit();
            }
        });
    }

    function loadFavoriteReport(favId) {
        if (!favId) return;
        
        const fav = favoriteReports.find(f => f.id == favId);
        if (!fav) return;

        const allPossibleCols = ['col-id', 'col-type', 'col-user', 'col-coords', 'col-attachments', 'col-date'];
        dynamicColumns.forEach(f => { allPossibleCols.push('col-' + f.field_name); });

        allPossibleCols.forEach(col => {
            document.querySelectorAll(`[data-column="${col}"]`).forEach(el => el.classList.add('hidden'));
            const cb = document.getElementById('cb-' + col);
            if (cb) cb.checked = false;
            
            const searchInput = document.querySelector(`.col-search-input[data-filter-for="${col}"]`);
            if (searchInput) searchInput.value = '';
        });

        const visibleCols = fav.columns_config.split(',');
        visibleCols.forEach(col => {
            document.querySelectorAll(`[data-column="${col}"]`).forEach(el => el.classList.remove('hidden'));
            const cb = document.getElementById('cb-' + col);
            if (cb) cb.checked = true;
            localStorage.setItem('hide-' + col, 'false'); 
        });

        if (fav.filters_config) {
            try {
                const filters = JSON.parse(fav.filters_config);
                for (const [colName, cond] of Object.entries(filters)) {
                    const searchInput = document.querySelector(`.col-search-input[data-filter-for="${colName}"]`);
                    const opSelect = document.querySelector(`.operator-select[data-operator-for="${colName}"]`);
                    if (searchInput) searchInput.value = cond.val;
                    if (opSelect) opSelect.value = cond.op;
                }
            } catch(e) {}
        }

        filterTable();

        document.getElementById('favorite_report_selector').value = '';
        Swal.fire({ icon: 'success', title: 'تم الفتح والمزامنة', text: 'تم تطبيق قالب التقرير المفضل بنجاح وتصفية النتائج.', timer: 1500, showConfirmButton: false });
    }

    function filterTableByBoundary(boundaryId) {
        window.location.href = 'index.php?page=reports-view&filter_boundary_id=' + boundaryId;
    }

    function filterWidgetFields(recordTypeId) {
        document.querySelectorAll('.widget-field-cb-wrapper').forEach(wrapper => {
            const fieldTypeIdStr = wrapper.getAttribute('data-widget-field-type');
            const typeIds = fieldTypeIdStr.split(',');
            if (typeIds.includes(String(recordTypeId))) {
                wrapper.classList.remove('hidden');
            } else {
                wrapper.classList.add('hidden');
                wrapper.querySelector('input[type="checkbox"]').checked = false; 
            }
        });
    }

    function switchReportTab(tabId, btn) {
        document.querySelectorAll('.report-tab-content').forEach(content => content.classList.add('hidden'));
        document.getElementById(tabId).classList.remove('hidden');

        document.querySelectorAll('.report-tab-btn').forEach(b => {
            b.classList.remove('text-blue-600', 'bg-blue-50');
            b.classList.add('text-gray-500', 'hover:text-gray-700', 'hover:bg-gray-50');
        });
        btn.classList.remove('text-gray-500', 'hover:text-gray-700', 'hover:bg-gray-50');
        btn.classList.add('text-blue-600', 'bg-blue-50');
    }

    function filterTable() {
        const table = document.getElementById('main-records-table');
        const rows = table.getElementsByClassName('record-row');
        const headers = Array.from(table.querySelectorAll('thead tr:first-child th'));

        for (let i = 0; i < rows.length; i++) {
            let rowShow = true;
            
            for (let j = 0; j < headers.length; j++) {
                const colName = headers[j].getAttribute('data-column');
                if (!colName) continue; 
                
                const isColHidden = headers[j].classList.contains('hidden');
                if (isColHidden) continue;

                const input = table.querySelector(`.col-search-input[data-filter-for="${colName}"]`);
                const opSelect = table.querySelector(`.operator-select[data-operator-for="${colName}"]`);
                const operator = opSelect ? opSelect.value : 'contains';

                if (input && input.value.trim() !== '') {
                    const filterText = input.value.trim().toLowerCase();
                    const cell = rows[i].querySelector(`[data-column="${colName}"]`);
                    
                    if (cell) {
                        const cellText = cell.textContent.trim().toLowerCase();
                        
                        if (operator === 'equals') {
                            if (cellText !== filterText) { rowShow = false; break; }
                        } else if (operator === 'gt') {
                            const valNum = parseFloat(cellText);
                            const filterNum = parseFloat(filterText);
                            if (!isNaN(valNum) && !isNaN(filterNum)) {
                                if (valNum <= filterNum) { rowShow = false; break; }
                            } else {
                                if (cellText <= filterText) { rowShow = false; break; }
                            }
                        } else if (operator === 'lt') {
                            const valNum = parseFloat(cellText);
                            const filterNum = parseFloat(filterText);
                            if (!isNaN(valNum) && !isNaN(filterNum)) {
                                if (valNum >= filterNum) { rowShow = false; break; }
                            } else {
                                if (cellText >= filterText) { rowShow = false; break; }
                            }
                        } else if (operator === 'contains') {
                            if (!cellText.includes(filterText)) { rowShow = false; break; }
                        }
                    }
                }
            }
            
            if (rowShow) {
                rows[i].classList.remove('hidden');
            } else {
                rows[i].classList.add('hidden');
            }
        }
        updateStatusBar();
    }

    function toggleColSelector() { document.getElementById('col-selector-dropdown').classList.toggle('hidden'); }

    function toggleTableCol(colName, isVisible) {
        document.querySelectorAll(`[data-column="${colName}"]`).forEach(el => {
            if (isVisible) { el.classList.remove('hidden'); }
            else { el.classList.add('hidden'); }
        });
        localStorage.setItem('hide-' + colName, isVisible ? 'false' : 'true');
        filterTable(); 
    }

    function loadCustomColumns() {
        const columns = ['col-id', 'col-type', 'col-user', 'col-coords', 'col-attachments', 'col-date'];
        dynamicColumns.forEach(f => { columns.push('col-' + f.field_name); });

        columns.forEach(col => {
            const cb = document.getElementById('cb-' + col);
            let isVisible = true;
            
            if (col.startsWith('col-') && !['col-id', 'col-type', 'col-user', 'col-coords', 'col-attachments', 'col-date'].includes(col)) {
                isVisible = localStorage.getItem('hide-' + col) === 'false';
            } else {
                isVisible = localStorage.getItem('hide-' + col) !== 'true';
            }

            document.querySelectorAll(`[data-column="${col}"]`).forEach(el => {
                if (isVisible) { el.classList.remove('hidden'); }
                else { el.classList.add('hidden'); }
            });

            if (cb) { cb.checked = isVisible; }
        });
    }

    function printFilteredPDF() {
        const printWindow = window.open('', '_blank');
        const tableHTML = document.getElementById('main-records-table').outerHTML;
        
        let htmlContent = '<html lang="ar" dir="rtl"><head><title>تقرير السجلات الجغرافية المفلتر</title>';
        htmlContent += '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">';
        htmlContent += '<script src="https://cdn.tailwindcss.com"><' + '/script>';
        htmlContent += '<style>body { font-family: "Cairo", sans-serif; padding: 30px; background: white; color: black; }';
        htmlContent += '.no-print, .col-search-input, .operator-select, .hidden { display: none !important; }';
        htmlContent += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }';
        htmlContent += 'th, td { border: 1px solid #ccc; padding: 8px; text-align: right; }';
        htmlContent += 'th { background-color: #f3f4f6; }</style></head><body>';
        htmlContent += '<div class="text-center mb-6"><h1 class="text-lg font-bold">تقرير تفصيلي بمخرجات المعاينات والتوثيقات الميدانية</h1>';
        htmlContent += '<span class="text-[10px] text-gray-400 block mt-1">تاريخ استخراج التقرير: ' + new Date().toISOString().substring(0, 10) + '</span></div>';
        htmlContent += tableHTML;
        htmlContent += '<' + 'script' + '>';
        htmlContent += 'window.addEventListener("DOMContentLoaded", () => { setTimeout(() => { window.print(); window.close(); }, 500); });';
        htmlContent += '<' + '/script' + '></body></html>';
        
        printWindow.document.write(htmlContent);
        printWindow.document.close();
    }

    function exportExcelReport() {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('action', 'export_csv');
        
        const table = document.getElementById('main-records-table');
        const headers = Array.from(table.querySelectorAll('thead tr:first-child th'));

        headers.forEach((th) => {
            const colName = th.getAttribute('data-column');
            if (!colName) return; 
            const isColHidden = th.classList.contains('hidden');

            if (isColHidden) return;

            const input = table.querySelector(`.col-search-input[data-filter-for="${colName}"]`);
            const opSelect = table.querySelector(`.operator-select[data-operator-for="${colName}"]`);
            const op = opSelect ? opSelect.value : 'contains';

            if (input && input.value.trim() !== '') {
                urlParams.set('f_' + colName, input.value.trim());
                urlParams.set('op_' + colName, op);
            }
        });

        window.location.href = 'index.php?' + urlParams.toString();
    }

    function updateStatusBar() {
        const table = document.getElementById('main-records-table');
        const rows = table.getElementsByClassName('record-row');
        let visibleCount = 0;
        let totalArea = 0;

        const areaColHeader = table.querySelector('thead th[data-column="col-area_size"]');
        const isAreaVisible = areaColHeader ? !areaColHeader.classList.contains('hidden') : false;

        for (let i = 0; i < rows.length; i++) {
            if (!rows[i].classList.contains('hidden')) {
                visibleCount++;
                
                if (isAreaVisible) {
                    const areaCell = rows[i].querySelector('td[data-column="col-area_size"]');
                    if (areaCell) {
                        const areaVal = parseFloat(areaCell.getAttribute('data-value') || areaCell.textContent);
                        if (!isNaN(areaVal)) {
                            totalArea += areaVal;
                        }
                    }
                }
            }
        }

        document.getElementById('status-count').innerText = visibleCount;

        const calcBox = document.getElementById('status-calculations');
        if (isAreaVisible && visibleCount > 0) {
            calcBox.innerHTML = `<div><i class="fa-solid fa-chart-area text-emerald-400 ml-1"></i> إجمالي المساحة الظاهرة بالصفحة: <span class="text-white font-bold font-sans">${totalArea.toLocaleString()}</span> م²</div>`;
        } else {
            calcBox.innerHTML = '';
        }
    }
</script>