<?php
// pages/export-view.php - مركز تصدير خرائط KML وجداول المعاينات الجغرافية (النسخة النهائية لبيئات PHP 8.4)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية لثبات واستقرار التنبيهات الرسومية
if (isset($_SESSION['export_success_msg'])) { $message = $_SESSION['export_success_msg']; unset($_SESSION['export_success_msg']); }
if (isset($_SESSION['export_error_msg'])) { $error = $_SESSION['export_error_msg']; unset($_SESSION['export_error_msg']); }

// دالة إعادة التوجيه الفائقة والمقاومة لقيود البفر والـ Headers
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

// ----------------- [1. معالجة وتوليد خرائط الـ KML لجوجل إيرث من السيرفر مباشرة قبل الـ HTML] -----------------
if (isset($_GET['action']) && $_GET['action'] === 'export_kml') {
    $exp_type_id = isset($_GET['type_id']) ? intval($_GET['type_id']) : 0;
    $date_from = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';

    $where_clauses = ["r.latitude IS NOT NULL AND r.longitude IS NOT NULL"];
    $paramsExp = [];

    if ($exp_type_id > 0) { $where_clauses[] = "r.record_type_id = ?"; $paramsExp[] = $exp_type_id; }
    if (!empty($date_from)) { $where_clauses[] = "DATE(r.created_at) >= ?"; $paramsExp[] = $date_from; }
    if (!empty($date_to)) { $where_clauses[] = "DATE(r.created_at) <= ?"; $paramsExp[] = $date_to; }

    $where_sql = "WHERE " . implode(" AND ", $where_clauses);

    try {
        $stmtExp = $pdo->prepare("
            SELECT r.*, rt.label AS type_label, rt.color, u.username 
            FROM records r 
            JOIN record_types rt ON r.record_type_id = rt.id 
            JOIN users u ON r.user_id = u.id
            $where_sql 
            ORDER BY r.id DESC
        ");
        $stmtExp->execute($paramsExp);
        $records_kml = $stmtExp->fetchAll();
    } catch (PDOException $e) { die("خطأ في تصدير KML: " . $e->getMessage()); }

    $fields_stmt = $pdo->query("SELECT field_name, label FROM fields");
    $fields_map = $fields_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // إطلاق ترويسات تحميل ملف الـ KML الجغرافي للمتصفح مباشرة
    header('Content-Type: application/vnd.google-earth.kml+xml');
    header('Content-Disposition: attachment; filename=gis_export_kml_' . date('Y-m-d') . '.kml');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
    echo '  <Document>' . "\n";
    echo '    <name>GIS Manager KML Full Export</name>' . "\n";

    foreach ($records_kml as $rec) {
        $color_clean = str_replace('#', 'ff', $rec['color'] ?: '#3085d6');
        
        $html_desc = "<h3>تفاصيل المعاينة الجغرافية لـ #" . $rec['id'] . "</h3>";
        $html_desc .= "<table border='1' style='border-collapse:collapse; font-family:sans-serif; font-size:11px; width:300px; text-align:right;' dir='rtl'>";
        $html_desc .= "<tr style='background:#f1f5f9;'><th>الحقل الفني</th><th>القيمة المعتمدة</th></tr>";
        $html_desc .= "<tr><td><b>القسم</b></td><td>" . htmlspecialchars((string)$rec['type_label']) . "</td></tr>";
        $html_desc .= "<tr><td><b>المسؤول</b></td><td>" . htmlspecialchars((string)$rec['username']) . "</td></tr>";
        $html_desc .= "<tr><td><b>تاريخ التوثيق</b></td><td>" . $rec['created_at'] . "</td></tr>";
        $html_desc .= "<tr><td><b>الإحداثيات</b></td><td>" . $rec['latitude'] . "," . $rec['longitude'] . "</td></tr>";

        $dyn_vals = json_decode($rec['dynamic_values'], true) ?: [];
        foreach ($dyn_vals as $key => $value) {
            if (!empty($value) && $value !== 'null' && $value !== '-') {
                $displayLabel = isset($fields_map[$key]) ? $fields_map[$key] : $key;
                $html_desc .= "<tr><td><b>" . htmlspecialchars((string)$displayLabel) . "</b></td><td>" . htmlspecialchars((string)$value) . "</td></tr>";
            }
        }
        $html_desc .= "</table>";

        if ($rec['photo_path']) {
            $full_image_url = "https://" . $_SERVER['HTTP_HOST'] . "/" . $rec['photo_path'];
            $html_desc .= "<br><img src='" . $full_image_url . "' style='max-width:280px; max-height:200px; border-radius:8px;'>";
        }

        echo '    <Placemark>' . "\n";
        echo '      <name>سجل #' . $rec['id'] . ' (' . htmlspecialchars((string)$rec['type_label']) . ')</name>' . "\n";
        echo '      <description><![CDATA[' . $html_desc . ']]></description>' . "\n";
        echo '      <Style>' . "\n";
        echo '        <IconStyle>' . "\n";
        echo '          <color>' . $color_clean . '</color>' . "\n";
        echo '        </IconStyle>' . "\n";
        echo '      </Style>' . "\n";
        echo '      <Point>' . "\n";
        echo '        <coordinates>' . $rec['longitude'] . ',' . $rec['latitude'] . ',0</coordinates>' . "\n";
        echo '      </Point>' . "\n";
        echo '    </Placemark>' . "\n";
    }

    echo '  </Document>' . "\n";
    echo '</kml>' . "\n";
    exit;
}

// جلب الأقسام الفنية لرسم قائمة الاختيار بالشاشة
$types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
?>

<!-- التنبيهات باستخدام SweetAlert2 -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية بنجاح', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'تنبيه خطأ', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<div class="space-y-6 max-w-4xl mx-auto animate-fade text-right" dir="rtl">

    <!-- كارت محرك التصدير الجغرافي والذكي المطور -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 space-y-4">
        <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-3">
            <div class="p-2 bg-orange-100 text-orange-600 rounded-lg"><i class="fa-solid fa-file-export text-xl animate-pulse"></i></div>
            <h3 class="text-sm font-bold text-gray-800">مركز تصدير واستخراج البيانات الجغرافية والتقارير</h3>
        </div>
        
        <p class="text-xs text-gray-400 leading-relaxed font-semibold">بإمكانك تصفية وجرد البيانات جغرافياً وتاريخياً لتصدير خرائط مضلعات جوجل إيرث KML أو تقارير وجداول ميكروسوفت إكسل الشاملة بصيغة منسقة وآمنة ومقاومة للأخطاء الإملائية.</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 bg-gray-50/50 p-4 rounded-xl border border-gray-100 items-end">
            <div>
                <label class="block text-gray-600 text-xs font-semibold mb-1">تحديد القسم لتصديره:</label>
                <select id="export_type_selector" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none bg-white font-bold text-gray-700">
                    <option value="0">تصدير كافة الأقسام جغرافياً</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['label']); ?></option>
                    <?php endforeach; ?></select>
            </div>
            <div>
                <label class="block text-gray-600 text-xs font-semibold mb-1">من تاريخ التوثيق:</label>
                <input type="date" id="export_date_from" class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-xs text-gray-700 focus:outline-none bg-white font-semibold">
            </div>
            <div>
                <label class="block text-gray-600 text-xs font-semibold mb-1">إلى تاريخ التوثيق:</label>
                <input type="date" id="export_date_to" class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-xs text-gray-700 focus:outline-none bg-white font-semibold">
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
            <button onclick="triggerXlsFilteredExport()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-4 rounded-xl transition shadow flex items-center justify-center space-x-2 space-x-reverse text-sm">
                <i class="fa-solid fa-file-excel text-lg text-white"></i>
                <span class="text-white">تحميل تقرير الجداول الشامل (Excel / XLS)</span>
            </button>
            <button onclick="triggerKmlFilteredExport()" class="bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded-xl transition shadow flex items-center justify-center space-x-2 space-x-reverse text-sm">
                <i class="fa-solid fa-earth-africa text-lg text-white"></i>
                <span class="text-white">تحميل ملف الخرائط الجغرافية (KML / GIS Map)</span>
            </button>
        </div>
    </div>

</div>

<!-- معالجات الـ JS المتقدمة لتصفية وتنزيل الملفات -->
<script>
    function triggerKmlFilteredExport() {
        const typeId = document.getElementById('export_type_selector').value;
        const dateFrom = document.getElementById('export_date_from').value;
        const dateTo = document.getElementById('export_date_to').value;

        // التوجيه لـ index لتنفيذ الاعتراض وتحميل الملف مباشرة قبل الـ HTML
        let exportUrl = 'index.php?page=export-view&action=export_kml&type_id=' + typeId;
        if (dateFrom !== '') exportUrl += '&date_from=' + dateFrom;
        if (dateTo !== '') exportUrl += '&date_to=' + dateTo;

        window.location.href = exportUrl;
    }

    function triggerXlsFilteredExport() {
        const typeId = document.getElementById('export_type_selector').value;
        const dateFrom = document.getElementById('export_date_from').value;
        const dateTo = document.getElementById('export_date_to').value;

        let exportUrl = 'index.php?action=export_csv';
        if (typeId > 0) exportUrl += '&filter_type=' + typeId;
        if (dateFrom !== '') exportUrl += '&date_from=' + dateFrom;
        if (dateTo !== '') exportUrl += '&date_to=' + dateTo;

        window.location.href = exportUrl;
    }
</script>