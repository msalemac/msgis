<?php
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

// 1. الفلاتر العامة الأساسية
$where_clauses = [];
$params = [];

$filter_type = isset($_GET['filter_type']) ? intval($_GET['filter_type']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($filter_type > 0) { $where_clauses[] = "r.record_type_id = ?"; $params[] = $filter_type; }
if (!empty($date_from)) { $where_clauses[] = "DATE(r.created_at) >= ?"; $params[] = $date_from; }
if (!empty($date_to)) { $where_clauses[] = "DATE(r.created_at) <= ?"; $params[] = $date_to; }
if (!empty($search_query)) {
    $where_clauses[] = "(r.dynamic_values LIKE ? OR u.username LIKE ? OR r.id = ?)";
    $params[] = '%' . $search_query . '%'; $params[] = '%' . $search_query . '%'; $params[] = intval($search_query);
}

// 2. قراءة فلاتر الأعمدة المتقدمة (Excel-Style Filters) وتطبيقها في الاستعلام
$core_cols_map = [
    'col-id'   => 'r.id',
    'col-type' => 'rt.label',
    'col-user' => 'u.username',
    'col-date' => 'r.created_at'
];

foreach ($core_cols_map as $paramName => $dbCol) {
    $getParam = 'f_' . $paramName;
    $getOp = 'op_' . $paramName;
    if (isset($_GET[$getParam]) && $_GET[$getParam] !== '') {
        $val = trim($_GET[$getParam]);
        $op = isset($_GET[$getOp]) ? $_GET[$getOp] : 'contains';
        
        if ($op === 'equals') {
            $where_clauses[] = "$dbCol = ?"; $params[] = $val;
        } elseif ($op === 'gt') {
            $where_clauses[] = "$dbCol > ?"; $params[] = $val;
        } elseif ($op === 'lt') {
            $where_clauses[] = "$dbCol < ?"; $params[] = $val;
        } else {
            $where_clauses[] = "$dbCol LIKE ?"; $params[] = '%' . $val . '%';
        }
    }
}

// ب. فلاتر الحقول الديناميكية بالـ JSON
$fields_stmt = $pdo->query("SELECT field_name, label, type FROM fields");
$fields_list = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($fields_list as $field) {
    $f_name = $field['field_name'];
    $paramName = 'col-' . $f_name;
    $getParam = 'f_' . $paramName;
    $getOp = 'op_' . $paramName;

    if (isset($_GET[$getParam]) && $_GET[$getParam] !== '') {
        $val = trim($_GET[$getParam]);
        $op = isset($_GET[$getOp]) ? $_GET[$getOp] : 'contains';
        $dbCol = "JSON_UNQUOTE(JSON_EXTRACT(r.dynamic_values, '$.$f_name'))";

        if ($op === 'equals') {
            $where_clauses[] = "$dbCol = ?"; $params[] = $val;
        } elseif ($op === 'gt') {
            if ($field['type'] === 'number') {
                $where_clauses[] = "CAST($dbCol AS DECIMAL(10,2)) > ?"; $params[] = floatval($val);
            } else { $where_clauses[] = "$dbCol > ?"; $params[] = $val; }
        } elseif ($op === 'lt') {
            if ($field['type'] === 'number') {
                $where_clauses[] = "CAST($dbCol AS DECIMAL(10,2)) < ?"; $params[] = floatval($val);
            } else { $where_clauses[] = "$dbCol < ?"; $params[] = $val; }
        } else {
            $where_clauses[] = "$dbCol LIKE ?"; $params[] = '%' . $val . '%';
        }
    }
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// جلب البيانات المفلترة بالكامل
$records_query = "
    SELECT r.*, rt.label AS type_label, u.username 
    FROM records r
    JOIN record_types rt ON r.record_type_id = rt.id
    JOIN users u ON r.user_id = u.id
    $where_sql
    ORDER BY r.id DESC
";
$stmt = $pdo->prepare($records_query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// [تم حل المشكلة هنا]: تم إزالة ob_end_clean المسببة للتنبيه والتعطل لضمان التحميل المباشر الفوري للملف
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=gis_filtered_export_' . date('Y-m-d_H-i') . '.xls');

// طباعة كود تنسيق الملف الرياضي والأعمدة الملونة بالكامل لـ Excel
echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel" lang="ar" dir="rtl">' . "\n";
echo '<head>' . "\n";
echo '  <meta http-equiv="content-type" content="text/html; charset=utf-8">' . "\n";
echo '  <style>' . "\n";
echo '    td { font-family: "Cairo", sans-serif; font-size: 11px; text-align: center; border: 1px solid #cbd5e1; padding: 5px; }' . "\n";
echo '    .header-td { font-weight: bold; background-color: #cbd5e1; border: 1px solid #94a3b8; }' . "\n";
echo '    .record-id-td { font-weight: bold; color: #475569; font-family: "Courier New", monospace; }' . "\n";
echo '    .type-label-td { font-weight: bold; color: #1e3a8a; }' . "\n";
echo '  </style>' . "\n";
echo '</head>' . "\n";
echo '<body>' . "\n";
echo '  <table>' . "\n";
echo '    <tr>' . "\n";
echo '      <td class="header-td">رقم السجل</td>' . "\n";
echo '      <td class="header-td">القسم الميداني</td>' . "\n";
echo '      <td class="header-td">المسؤول</td>' . "\n";
echo '      <td class="header-td">خط العرض (Lat)</td>' . "\n";
echo '      <td class="header-td">خط الطول (Lng)</td>' . "\n";
echo '      <td class="header-td">تاريخ التوثيق</td>' . "\n";
foreach ($fields_list as $f) {
    echo '      <td class="header-td">' . htmlspecialchars(str_replace(',', '-', $f['label'])) . '</td>' . "\n";
}
echo '    </tr>' . "\n";

// كتابة السجلات الميدانية
foreach ($records as $rec) {
    echo '    <tr>' . "\n";
    echo '      <td class="record-id-td">#' . $rec['id'] . '</td>' . "\n";
    echo '      <td class="type-label-td">' . htmlspecialchars($rec['type_label']) . '</td>' . "\n";
    echo '      <td>' . htmlspecialchars($rec['username']) . '</td>' . "\n";
    echo '      <td>' . $rec['latitude'] . '</td>' . "\n";
    echo '      <td>' . $rec['longitude'] . '</td>' . "\n";
    echo '      <td>' . $rec['created_at'] . '</td>' . "\n";
    
    $dyn_vals = json_decode($rec['dynamic_values'], true) ?: [];
    foreach ($fields_list as $f) {
        $f_name = $f['field_name'];
        $val = isset($dyn_vals[$f_name]) ? $dyn_vals[$f_name] : '-';
        echo '      <td>' . htmlspecialchars($val) . '</td>' . "\n";
    }
    echo '    </tr>' . "\n";
}

echo '  </table>' . "\n";
echo '</body>' . "\n";
echo '</html>' . "\n";
exit;