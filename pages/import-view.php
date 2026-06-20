<?php
// pages/import-view.php - معالج استيراد وقراءة ملفات السجلات (النسخة النهائية الفائقة الأمان والاستقرار لبيئات PHP 8.4)
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$role = isset($role) ? $role : ($_SESSION['role'] ?? 'user');
if ($role !== 'admin') {
    die("عذراً، ليس لديك صلاحية الوصول إلى موديول الاستيراد.");
}

$message = '';
$error = '';

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

// ----------------- [1. تعريف جميع الدوال المساعدة والإجرائية في أعلى الملف لاستقرار المعالجة] -----------------

// أ. دالة تحويل التاريخ من النظام الرقمي لإكسل (Serial Date) إلى صيغة المقروءة YYYY-MM-DD
if (!function_exists('convertExcelDateToYmd')) {
    function convertExcelDateToYmd($excel_date) {
        if (is_numeric($excel_date) && $excel_date > 25569) {
            $unix_time = ($excel_date - 25569) * 86400;
            return date('Y-m-d', $unix_time);
        }
        return $excel_date;
    }
}

// ب. دالة قراءة وتفتيت وتفكيك قوالب الاستيراد المصدرة بامتداد .xls القائمة على الـ HTML بنجاح
if (!function_exists('parseHtmlXlsFile')) {
    function parseHtmlXlsFile($filePath) {
        $content = file_get_contents($filePath);
        if (!$content) return false;

        $rows = [];
        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $tr_matches)) {
            foreach ($tr_matches[1] as $tr_content) {
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr_content, $td_matches)) {
                    $row_data = array_map(function($val) {
                        return convertToUtf8IfNeeded(trim(strip_tags($val)));
                    }, $td_matches[1]);
                    
                    // [صمام أمان]: تجاهل وحذف الصفوف الفارغة بالكامل لعدم حجب مواءمة البيانات
                    $clean_filtered = array_filter($row_data, function($v) {
                        return $v !== null && trim((string)$v) !== '';
                    });

                    if (count($clean_filtered) > 0) {
                        $rows[] = $row_data;
                    }
                }
            }
        }

        // [تعديل استراتيجي]: جرد وضبط محاذاة خلايا وأعمدة جميع الصفوف بناء على الصف الأول (العناوين) لمنع التداخل والإزاحة
        if (!empty($rows)) {
            $max_header_idx = max(array_keys($rows[0]));
            foreach ($rows as &$r) {
                for ($i = 0; $i <= $max_header_idx; $i++) {
                    if (!isset($r[$i])) {
                        $r[$i] = ''; // تعبئة الخلايا المفقودة بنص فارغ
                    }
                }
                ksort($r); // فرز وتصاعدية فهارس الأعمدة
            }
        }

        return $rows;
    }
}

// دالة استخلاص الفهرس الحقيقي للأعمدة من إحداثيات إكسل (مثل تحويل "C" إلى 2) لضمان المحاذاة ومنع الإزاحة
if (!function_exists('getExcelColumnIndex')) {
    function getExcelColumnIndex($ref) {
        preg_match('/^[A-Z]+/i', $ref, $matches);
        if (empty($matches)) return 0;
        
        $letters = strtoupper($matches[0]);
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1; // إرجاع فهرس يبدأ من 0
    }
}

// جـ. دالة تفكيك ملفات .XLSX الأصلية الثنائية المضغوطة (محدثة بالكامل لحل مشكلة الخلايا والأعمدة والصفوف الفارغة)
if (!function_exists('parseXlsxFile')) {
    function parseXlsxFile($filePath) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return false;

        $sharedStrings = [];
        $stringsEntry = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsEntry) {
            $xml = simplexml_load_string($stringsEntry);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $val) {
                    $sharedStrings[] = (string)($val->t ?: $val->r->t);
                }
            }
        }

        $sheetEntry = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetEntry) { $zip->close(); return false; }

        $xml = simplexml_load_string($sheetEntry);
        $rows = [];
        if ($xml && isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $row) {
                $row_data = [];
                foreach ($row->c as $cell) {
                    $attr = $cell->attributes();
                    $ref = isset($attr['r']) ? (string)$attr['r'] : '';
                    $col_idx = getExcelColumnIndex($ref); // جلب الفهرس الجغرافي الحقيقي والمؤمن للخلية

                    $type = isset($attr['t']) ? (string)$attr['t'] : '';
                    $val = (string)$cell->v;

                    $final_val = '';
                    if ($type === 's') {
                        $final_val = isset($sharedStrings[$val]) ? $sharedStrings[$val] : '';
                    } else { 
                        $final_val = $val; 
                    }
                    
                    // إدراج القيمة في موقعها الهندسي الدقيق داخل المصفوفة
                    $row_data[$col_idx] = $final_val;
                }
                
                // [صمام أمان]: جرد وتصفية الصفوف؛ وتجاهل الصفوف الفارغة بالكامل
                $clean_filtered = array_filter($row_data, function($v) {
                    return $v !== null && trim((string)$v) !== '';
                });

                if (count($clean_filtered) > 0) {
                    $rows[] = $row_data;
                }
            }
        }
        $zip->close();

        // [تعديل استراتيجي حاسم]: جرد وضبط محاذاة خلايا وأعمدة جميع الصفوف بناء على الصف الأول (العناوين) لمنع التداخل
        if (!empty($rows)) {
            $max_header_idx = max(array_keys($rows[0]));
            
            foreach ($rows as &$r) {
                for ($i = 0; $i <= $max_header_idx; $i++) {
                    if (!isset($r[$i])) {
                        $r[$i] = ''; // تعبئة الخلايا المفقودة بنص فارغ لمنع الإزاحة والتداخل
                    }
                }
                ksort($r); // فرز وتصاعدية فهارس الأعمدة
            }
        }

        return $rows;
    }
}

// د. دالة تحويل الترميز لـ UTF-8 لملفات الـ CSV
if (!function_exists('convertToUtf8IfNeeded')) {
    function convertToUtf8IfNeeded($text) {
        if (empty($text)) return $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'Windows-1256');
        }
        return $text;
    }
}

// هـ. دالة تحويل الفهرس الرقمي للأعمدة إلى حروف إكسل (مثل: 3 -> C)
if (!function_exists('getExcelColumnLetter')) {
    function getExcelColumnLetter($index) {
        $letter = '';
        while ($index > 0) {
            $temp = ($index - 1) % 26;
            $letter = chr(65 + $temp) . $letter;
            $index = intval(($index - $temp) / 26);
        }
        return $letter;
    }
}

$backups_dir = 'uploads/backups/';
$temp_dir = 'uploads/temp/';
if (!is_dir($temp_dir)) { mkdir($temp_dir, 0755, true); }

if (isset($_SESSION['import_success_msg'])) { $message = $_SESSION['import_success_msg']; unset($_SESSION['import_success_msg']); }
if (isset($_SESSION['import_error_msg'])) { $error = $_SESSION['import_error_msg']; unset($_SESSION['import_error_msg']); }

// جلب الأقسام ومسميات الحقول الديناميكية لتأسيس خرائط المطابقة
$types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
$all_fields = $pdo->query("SELECT id, field_name, label, type, options, record_type_id FROM fields WHERE is_active = 1")->fetchAll();

$csv_headers = [];
$temp_data_file = isset($_SESSION['import_temp_json']) ? $_SESSION['import_temp_json'] : '';
$detected_delimiter = ",";

// معالجة حركة الـ POST مع التحقق الأمني الموحد لتوكن الجلسة CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الإجباري من توكن حماية CSRF لمنع طلبات التزوير
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    // أ. معالج رفع الملفات الرئيسي (CSV & XLSX & XLS)
    if (isset($_POST['action']) && $_POST['action'] === 'upload_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
            $parsed_rows = [];

            if ($file_ext === 'xlsx') {
                $parsed_rows = parseXlsxFile($_FILES['csv_file']['tmp_name']);
            } elseif ($file_ext === 'xls') {
                $parsed_rows = parseHtmlXlsFile($_FILES['csv_file']['tmp_name']);
            } elseif ($file_ext === 'csv') {
                $tmp_path = $_FILES['csv_file']['tmp_name'];
                
                if (($first_line_handle = fopen($tmp_path, "r")) !== FALSE) {
                    $first_line = fgets($first_line_handle);
                    if ($first_line !== FALSE) {
                        if (strpos($first_line, ';') !== false && strpos($first_line, ',') === false) { $detected_delimiter = ";"; }
                    }
                    fclose($first_line_handle);
                }

                if (($handle = fopen($tmp_path, "r")) !== FALSE) {
                    while (($row_data = fgetcsv($handle, 1000, $detected_delimiter)) !== FALSE) {
                        $utf8_row = array_map('convertToUtf8IfNeeded', $row_data);
                        $parsed_rows[] = $utf8_row;
                    }
                    fclose($handle);
                }
            }

            if (!empty($parsed_rows)) {
                $temp_json_name = 'parsed_' . uniqid() . '.json';
                if (file_put_contents($temp_dir . $temp_json_name, json_encode($parsed_rows, JSON_UNESCAPED_UNICODE)) !== false) {
                    $_SESSION['import_temp_json'] = $temp_dir . $temp_json_name;
                    $_SESSION['import_success_msg'] = "تم رفع وقراءة ملف البيانات بنجاح، يرجى ضبط الإعدادات ومطابقة الأعمدة بالأسفل لبدء الاستيراد.";
                } else { $_SESSION['import_error_msg'] = "فشل معالجة البيانات المؤقتة."; }
            } else { $_SESSION['import_error_msg'] = "فشل في قراءة وتفتيت محتوى الملف المرفوع."; }
        }
        safeRedirect("index.php?page=import-view");
    }

    // ب. إتمام عملية الاستيراد التلقائية السريعة
    if (isset($_POST['action']) && $_POST['action'] === 'process_import_auto') {
        $record_type_id = intval($_POST['import_record_type_id'] ?? 0);
        $dedup_field_1 = isset($_POST['dedup_field_1']) ? trim((string)$_POST['dedup_field_1']) : '';
        $dedup_field_2 = isset($_POST['dedup_field_2']) ? trim((string)$_POST['dedup_field_2']) : '';
        $mappings = isset($_POST['mappings']) ? (array)$_POST['mappings'] : [];
        $skip_first_row = isset($_POST['skip_first_row']) && $_POST['skip_first_row'] == 1;
        $start_row_idx = $skip_first_row ? 1 : 0;

        $temp_content = [];
        if (!empty($temp_data_file) && file_exists($temp_data_file)) {
            $temp_content = json_decode(file_get_contents($temp_data_file), true) ?: [];
        }

        if ($record_type_id > 0 && !empty($temp_content) && !empty($mappings)) {
            $total_rows_count = count($temp_content);

            if ($total_rows_count >= 1) {
                $imported_count = 0;
                $skipped_count = 0;
                
                $stmtF = $pdo->prepare("SELECT field_name, type FROM fields WHERE FIND_IN_SET(?, record_type_id) AND is_active = 1");
                $stmtF->execute([$record_type_id]);
                $active_fields_info = $stmtF->fetchAll(PDO::FETCH_KEY_PAIR); 
                $active_fields = array_keys($active_fields_info);

                for ($row_idx = $start_row_idx; $row_idx < $total_rows_count; $row_idx++) {
                    $row_data = $temp_content[$row_idx];
                    $latitude = null; $longitude = null; $dynamic_values = [];

                    foreach ($mappings as $csv_idx => $sys_field) {
                        $csv_idx = intval($csv_idx);
                        $val = isset($row_data[$csv_idx]) ? trim((string)$row_data[$csv_idx]) : '';

                        if (empty($sys_field)) continue;

                        if ($sys_field === 'latitude') {
                            $latitude = !empty($val) ? floatval($val) : null;
                        } elseif ($sys_field === 'longitude') {
                            $longitude = !empty($val) ? floatval($val) : null;
                        } elseif (in_array($sys_field, $active_fields)) {
                            if (isset($active_fields_info[$sys_field]) && $active_fields_info[$sys_field] === 'date') {
                                $val = convertExcelDateToYmd($val);
                            }
                            $dynamic_values[$sys_field] = $val;
                        }
                    }

                    $is_duplicate = false;

                    if (!empty($dedup_field_1) && isset($dynamic_values[$dedup_field_1])) {
                        $val_1 = trim((string)$dynamic_values[$dedup_field_1]);
                        if ($val_1 !== '') {
                            $stmtCheck1 = $pdo->prepare("SELECT COUNT(*) FROM records WHERE record_type_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(dynamic_values, '$.$dedup_field_1')) = ?");
                            $stmtCheck1->execute([$record_type_id, $val_1]);
                            if ($stmtCheck1->fetchColumn() > 0) { $is_duplicate = true; }
                        }
                    }

                    if (!$is_duplicate && !empty($dedup_field_2) && isset($dynamic_values[$dedup_field_2])) {
                        $val_2 = trim((string)$dynamic_values[$dedup_field_2]);
                        if ($val_2 !== '') {
                            $stmtCheck2 = $pdo->prepare("SELECT COUNT(*) FROM records WHERE record_type_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(dynamic_values, '$.$dedup_field_2')) = ?");
                            $stmtCheck2->execute([$record_type_id, $val_2]);
                            if ($stmtCheck2->fetchColumn() > 0) { $is_duplicate = true; }
                        }
                    }

                    if ($is_duplicate) {
                        $skipped_count++;
                        continue;
                    }

                    try {
                        $json_data = json_encode($dynamic_values, JSON_UNESCAPED_UNICODE);
                        $insert = $pdo->prepare("INSERT INTO records (record_type_id, user_id, latitude, longitude, dynamic_values) VALUES (?, ?, ?, ?, ?)");
                        $insert->execute([$record_type_id, $_SESSION['user_id'], $latitude, $longitude, $json_data]);
                        $imported_count++;
                    } catch (PDOException $e) { }
                }

                if (file_exists($temp_data_file)) { unlink($temp_data_file); }
                unset($_SESSION['import_temp_json']);

                $_SESSION['import_success_msg'] = "تم الاستيراد التلقائي بنجاح! السجلات الجديدة المسجلة: ({$imported_count}) سجل، وتم استبعاد وتخطي ({$skipped_count}) سجلات مكررة مسبقاً بالنظام لعدم الازدواجية.";
            }
        } else { $_SESSION['import_error_msg'] = "يرجى تحديد القسم ومطابقة الحقول."; }
        safeRedirect("index.php?page=import-view");
    }

    // جـ. معالجة طلب الاستيراد والتدقيق اليدوي المساعد المقيد بـ شيت الإكسل
    if (isset($_POST['action']) && $_POST['action'] === 'process_import_manual') {
        $record_type_id = intval($_POST['import_record_type_id'] ?? 0);
        $dedup_field_1 = isset($_POST['dedup_field_1']) ? trim((string)$_POST['dedup_field_1']) : '';
        $dedup_field_2 = isset($_POST['dedup_field_2']) ? trim((string)$_POST['dedup_field_2']) : '';
        $manual_records = isset($_POST['manual_records']) ? (array)$_POST['manual_records'] : [];

        if ($record_type_id > 0 && !empty($manual_records)) {
            $imported_count = 0;
            $skipped_count = 0;

            $stmtF = $pdo->prepare("SELECT field_name, type FROM fields WHERE FIND_IN_SET(?, record_type_id) AND is_active = 1");
            $stmtF->execute([$record_type_id]);
            $active_fields_info = $stmtF->fetchAll(PDO::FETCH_KEY_PAIR); 
            $active_fields = array_keys($active_fields_info);

            foreach ($manual_records as $row_data) {
                $latitude = !empty($row_data['latitude']) ? floatval($row_data['latitude']) : null;
                $longitude = !empty($row_data['longitude']) ? floatval($row_data['longitude']) : null;
                $dynamic_values = [];

                foreach ($active_fields as $field_name) {
                    if (isset($row_data[$field_name])) {
                        $val = trim((string)$row_data[$field_name]);
                        if (isset($active_fields_info[$field_name]) && $active_fields_info[$field_name] === 'date') {
                            $val = convertExcelDateToYmd($val);
                        }
                        $dynamic_values[$field_name] = $val;
                    }
                }

                $is_duplicate = false;

                if (!empty($dedup_field_1) && isset($dynamic_values[$dedup_field_1])) {
                    $val_1 = trim((string)$dynamic_values[$dedup_field_1]);
                    if ($val_1 !== '') {
                        $stmtCheck1 = $pdo->prepare("SELECT COUNT(*) FROM records WHERE record_type_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(dynamic_values, '$.$dedup_field_1')) = ?");
                        $stmtCheck1->execute([$record_type_id, $val_1]);
                        if ($stmtCheck1->fetchColumn() > 0) { $is_duplicate = true; }
                    }
                }

                if (!$is_duplicate && !empty($dedup_field_2) && isset($dynamic_values[$dedup_field_2])) {
                    $val_2 = trim((string)$dynamic_values[$dedup_field_2]);
                    if ($val_2 !== '') {
                        $stmtCheck2 = $pdo->prepare("SELECT COUNT(*) FROM records WHERE record_type_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(dynamic_values, '$.$dedup_field_2')) = ?");
                        $stmtCheck2->execute([$record_type_id, $val_2]);
                        if ($stmtCheck2->fetchColumn() > 0) { $is_duplicate = true; }
                    }
                }

                if ($is_duplicate) {
                    $skipped_count++;
                    continue;
                }

                try {
                    $json_data = json_encode($dynamic_values, JSON_UNESCAPED_UNICODE);
                    $insert = $pdo->prepare("INSERT INTO records (record_type_id, user_id, latitude, longitude, dynamic_values) VALUES (?, ?, ?, ?, ?)");
                    $insert->execute([$record_type_id, $_SESSION['user_id'], $latitude, $longitude, $json_data]);
                    $imported_count++;
                } catch (PDOException $e) { }
            }

            if (!empty($temp_data_file) && file_exists($temp_data_file)) { unlink($temp_data_file); }
            unset($_SESSION['import_temp_json']);

            $_SESSION['import_success_msg'] = "تم الاستيراد والتدقيق اليدوي بنجاح! السجلات الجديدة المسجلة: ({$imported_count}) سجل، وتخطي واستبعاد ({$skipped_count}) سجل مكرر مسبقاً.";
        } else { $_SESSION['import_error_msg'] = "فشل الاستيراد اليدوي لعدم وجود بيانات معدلة."; }
        safeRedirect("index.php?page=import-view");
    }
}

// قراءة عناوين وجسم الملف المرفوع حالياً للـ Preview
$all_rows_data = [];
if (!empty($temp_data_file) && file_exists($temp_data_file)) {
    $json_content = file_get_contents($temp_data_file);
    if ($json_content) {
        $all_rows_data = json_decode($json_content, true) ?: [];
        if (isset($all_rows_data[0])) { $csv_headers = $all_rows_data[0]; }
    }
}
?>

<!-- التنبيهات بـ SweetAlert -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية بنجاح', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'تنبيه خطأ', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<div class="space-y-6 max-w-6xl mx-auto animate-fade text-right" dir="rtl">

    <!-- البوكس الأول الجمالي: معالج رفع وقراءة الملفات (مرحلة أ) -->
    <?php if (empty($csv_headers)): ?>
        <div class="bg-white p-8 rounded-3xl shadow-md border border-gray-150 transition-all duration-300 hover:shadow-lg">
            <div class="flex items-center space-x-3 space-x-reverse mb-6 border-b pb-4">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-xl"><i class="fa-solid fa-cloud-arrow-up text-xl"></i></div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">قراءة ورفع ملفات المعاينات الميدانية</h3>
                    <p class="text-[10px] text-gray-400 font-bold">يدعم النظام قراءة ملفات الإكسل الحديثة والمصممة لتسهيل المواءمة الميدانية.</p>
                </div>
            </div>

            <!-- لوحة الرفع ذات التنسيق المتقطع الجذاب لسهولة السحب والإلقاء -->
            <form action="index.php?page=import-view" method="POST" enctype="multipart/form-data" class="space-y-4 max-w-lg mx-auto text-center p-8 border-2 border-dashed border-blue-200 rounded-3xl bg-blue-50/20 hover:bg-blue-50/40 transition duration-300">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="upload_csv">
                
                <i class="fa-solid fa-file-excel text-5xl text-blue-300 block mb-3 animate-pulse"></i>
                <span class="text-xs text-slate-700 block font-bold">يرجى اختيار ملف بصيغة (Excel .XLSX / .XLS) أو (CSV) لقراءته</span>
                <input type="file" name="csv_file" accept=".csv, .xlsx, .xls" required class="w-full text-xs text-gray-550 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:bg-blue-100 file:text-blue-700 cursor-pointer font-bold">
                <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold py-3 px-4 rounded-xl text-xs transition shadow-md">قراءة أعمدة الملف المرفوع</button>
            </form>
        </div>
    <?php else: ?>
        
        <!-- البوكس الثاني الجمالي: المواءمة وضبط مسار الاستيراد المزدوج (مرحلة ب) -->
        <div class="bg-white p-8 rounded-3xl shadow-md border border-gray-100 space-y-6">
            <div class="flex items-center space-x-3 space-x-reverse mb-2 border-b pb-4">
                <div class="p-2 bg-emerald-100 text-emerald-600 rounded-xl"><i class="fa-solid fa-sliders text-xl"></i></div>
                <div>
                    <h3 class="text-sm font-bold text-gray-800">مواءمة الفلاتر المزدوجة وتحديد اتجاه الحفظ</h3>
                    <p class="text-[10px] text-gray-400 font-bold">حدد الخيارات لتلافي التكرار والازدواجية في قاعدة البيانات تلقائياً.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end bg-emerald-50/20 p-5 rounded-2xl border border-emerald-100/60">
                <div>
                    <label class="block text-slate-800 text-xs font-bold mb-1.5">القسم الميداني المستهدف لحفظ السجلات:</label>
                    <select id="import_record_type_id" required onchange="updateMappingFields(this.value)" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans focus:ring-1 focus:ring-emerald-500">
                        <option value="">-- اختر القسم المستهدف --</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- منع تكرار الحقول المزدوجة -->
                <div>
                    <label class="block text-slate-800 text-xs font-bold mb-1.5"><i class="fa-solid fa-copy text-red-500 ml-1"></i> حقل التحقق الأول (منع التكرار):</label>
                    <select id="import_dedup_field_1" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans focus:ring-1 focus:ring-emerald-500">
                        <option value="">لا يوجد - تخطي التحقق الأول</option>
                    </select>
                </div>

                <div>
                    <label class="block text-slate-800 text-xs font-bold mb-1.5"><i class="fa-solid fa-copy text-orange-500 ml-1"></i> حقل التحقق الثاني (منع التكرار):</label>
                    <select id="import_dedup_field_2" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans focus:ring-1 focus:ring-emerald-500">
                        <option value="">لا يوجد - تخطي التحقق الثاني</option>
                    </select>
                </div>
            </div>

            <!-- لوحة التحكم بالاستيراد وخيارات تخطي الترويسة -->
            <div class="flex flex-wrap items-center justify-between gap-4 p-5 bg-gray-50 border rounded-2xl">
                <div class="flex items-center space-x-2.5 space-x-reverse select-none">
                    <input type="checkbox" id="skip_first_row_visible" checked class="w-5 h-5 text-emerald-600 border-gray-300 rounded cursor-pointer focus:ring-emerald-500">
                    <label for="skip_first_row_visible" class="text-xs text-slate-700 font-bold cursor-pointer select-none">يحتوي الصف الأول على عناوين (تجاوز الصف الأول عند الاستيراد)</label>
                </div>

                <div class="flex space-x-2.5 space-x-reverse">
                    <button type="button" onclick="submitImportForm('auto')" class="bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold py-2.5 px-6 rounded-xl text-xs transition shadow-sm flex items-center space-x-1.5 space-x-reverse">
                        <i class="fa-solid fa-bolt"></i>
                        <span>استيراد تلقائي فوري</span>
                    </button>
                    
                    <button type="button" onclick="submitImportForm('manual')" id="btn-open-manual-grid" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-2.5 px-6 rounded-xl text-xs transition shadow-sm flex items-center space-x-1.5 space-x-reverse">
                        <i class="fa-solid fa-table-list"></i>
                        <span>مراجعة وتعديل يدوي (شيت إكسل)</span>
                    </button>
                </div>
            </div>

            <!-- استمارة الاستيراد التلقائي المخفية (محدثة بالـ CSRF Token) -->
            <form id="auto-import-form" action="index.php?page=import-view" method="POST" class="hidden">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="process_import_auto">
                <input type="hidden" name="import_record_type_id" id="auto_record_type_id">
                <input type="hidden" name="dedup_field_1" id="auto_dedup_field_1">
                <input type="hidden" name="dedup_field_2" id="auto_dedup_field_2">
                <input type="hidden" name="skip_first_row" id="skip_first_row_hidden" value="1">
                <div id="hidden-mappings-container"></div>
            </form>

            <!-- واجهة مطابقة حقول الاستيراد البصرية -->
            <div id="mapping-visual-panel" class="space-y-4">
                <span class="text-xs font-bold text-gray-750 block mb-2"><i class="fa-solid fa-arrows-split-up-and-left text-blue-500 ml-1"></i> مطابقة الأعمدة: تم مسح ملفك برمجياً وتحديد الروابط تلقائياً بالأخضر. يرجى مراجعة المطابقات بالأسفل:</span>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="mapping-cards-container">
                    <?php foreach ($csv_headers as $index => $header): ?>
                        <div class="mapping-card border border-gray-150 p-4 rounded-2xl bg-white shadow-sm flex items-center justify-between gap-4 transition-all duration-300 hover:shadow-md">
                            
                            <div class="flex items-center space-x-3 space-x-reverse min-w-[200px] max-w-xs">
                                <div class="p-2.5 bg-slate-100 text-slate-600 rounded-xl"><i class="fa-solid fa-file-csv text-md"></i></div>
                                <div class="text-right">
                                    <span class="text-[10px] text-gray-400 block mb-0.5">عمود ملفك المرفوع</span>
                                    <span class="text-xs font-bold text-slate-800 font-mono block truncate" title="<?php echo htmlspecialchars($header); ?>"><?php echo htmlspecialchars($header); ?></span>
                                </div>
                            </div>

                            <div class="match-indicator flex items-center justify-center text-gray-300 text-sm">
                                <i class="fa-solid fa-arrow-left-long"></i>
                            </div>

                            <div class="w-48">
                                <span class="text-[9px] text-gray-400 block mb-1">الحقل المقابل بالنظام:</span>
                                <select id="sel_map_<?php echo $index; ?>" class="field-map-select w-full px-3 py-1.5 border border-gray-200 rounded-lg text-[10px] font-bold text-gray-700 bg-white focus:outline-none focus:ring-1 focus:ring-blue-500 font-sans" data-csv-index="<?php echo $index; ?>" data-csv-header="<?php echo htmlspecialchars($header); ?>" onchange="checkManualMatchStatus(this)">
                                    <option value="">-- تخطي واستبعاد --</option>
                                    <option value="latitude">خط العرض (Latitude)</option>
                                    <option value="longitude">خط الطول (Longitude)</option>
                                </select>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center justify-end pt-4 border-t">
                    <a href="index.php?page=import-view&cancel_import=1" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2 px-6 rounded-xl text-xs transition">إلغاء واسترجاع الافتراضي</a>
                </div>
            </div>

            <!-- شيت إكسل يدوي فخم ومطور (محدث بالـ CSRF Token) -->
            <div id="manual-grid-panel" class="hidden space-y-4">
                <div id="grid-validation-summary" class="w-full flex items-center justify-start py-1"></div>
                
                <form id="manual-import-form" action="index.php?page=import-view" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <input type="hidden" name="action" value="process_import_manual">
                    <input type="hidden" name="import_record_type_id" id="manual_record_type_id">
                    <input type="hidden" name="dedup_field_1" id="manual_dedup_field_1">
                    <input type="hidden" name="dedup_field_2" id="manual_dedup_field_2">

                    <div class="overflow-x-auto rounded-xl border border-purple-200 max-h-[500px] shadow-inner">
                        <table class="min-w-full divide-y divide-gray-200 text-right text-xs bg-white">
                            <thead class="bg-purple-100 text-purple-800 font-black uppercase sticky top-0 z-10">
                                <tr id="manual-grid-header-row"></tr>
                            </thead>
                            <tbody id="manual-grid-body" class="divide-y divide-gray-100 text-[10px] text-gray-600 font-bold"></tbody>
                        </table>
                    </div>

                    <div class="flex items-center space-x-2 space-x-reverse justify-end pt-3 border-t">
                        <button type="button" onclick="cancelManualGrid()" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2 px-6 rounded-xl text-xs transition">تراجع وعودة للمطابقة</button>
                        <button type="submit" id="btn-submit-manual-grid" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-6 rounded-xl text-xs transition shadow">تثبيت وحفظ السجلات المدققة يدوياً</button>
                    </div>
                </form>
            </div>

        </div>
    <?php endif; ?>

    <!-- تحميل وتنزيل قوالب الاستيراد المعتمدة -->
    <div class="bg-white p-6 rounded-3xl shadow-md border border-gray-100 hover:shadow-lg transition-all duration-300">
        <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-4">
            <div class="p-2 bg-purple-100 text-purple-600 rounded-xl"><i class="fa-solid fa-file-excel text-xl"></i></div>
            <div>
                <h3 class="text-sm font-bold text-gray-800">ثانياً: تحميل وتنزيل قالب الاستيراد المعتمد (Excel / XLS Template)</h3>
                <p class="text-[10px] text-gray-400 font-bold">تنزيل قوالب ذكية مجهزة بالتحقق وصناديق التنسيق الفورية للعمل الميداني.</p>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
            <p class="text-xs text-gray-400 leading-relaxed font-semibold font-sans">بناءً على الحقول التي قمت بتشيدها لـ "متغيرات" أو "نقط عسكرية"؛ يمكنك تنزيل ملف إكسل فارغ (مبني ديناميكياً بأعمدته العربية الملونة والمنسقة) لتقوم بتعبئته ميدانياً بدقة ثم إعادة رفعه بالمعالج بالأعلى.</p>
            <div class="p-5 bg-purple-50/40 rounded-3xl border border-purple-100 space-y-3 shadow-inner text-right">
                <label class="block text-xs font-bold text-gray-600">اختر القسم الميداني لتوليد قالبه المخصص:</label>
                <select id="template_type_selector" class="w-full px-3 py-2 border rounded-xl text-xs font-bold text-gray-700 bg-white focus:outline-none focus:ring-1 focus:ring-purple-500 font-sans">
                    <option value="">-- اختر القسم الميداني --</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['label']); ?></option>
                    <?php endforeach; ?></select>
                <button onclick="triggerTemplateDownload()" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs transition shadow flex items-center justify-center space-x-2 space-x-reverse">
                    <i class="fa-solid fa-file-excel text-sm text-white"></i>
                    <span class="text-white">تنزيل وتحميل قالب الاستيراد (Excel / XLS)</span>
                </button>
            </div>
        </div>
    </div>

</div>

<!-- معالجات الـ JS المتقدمة -->
<script>
    const allSystemFields = <?php echo json_encode($all_fields, JSON_UNESCAPED_UNICODE); ?>;
    const allUploadedRows = <?php echo json_encode($all_rows_data, JSON_UNESCAPED_UNICODE); ?>;

    let tablesCount = 0;
    let committeeCount = 0;

    function updateMappingFields(recordTypeId) {
        const selects = document.querySelectorAll('.field-map-select');
        const dedupSelect1 = document.getElementById('import_dedup_field_1');
        const dedupSelect2 = document.getElementById('import_dedup_field_2');

        selects.forEach(sel => { 
            while (sel.options.length > 3) { sel.remove(3); } 
            sel.selectedIndex = 0; 
            checkManualMatchStatus(sel); 
        });
        if (dedupSelect1) { while (dedupSelect1.options.length > 1) { dedupSelect1.remove(1); } }
        if (dedupSelect2) { while (dedupSelect2.options.length > 1) { dedupSelect2.remove(1); } }

        if (!recordTypeId) return;

        allSystemFields.forEach(field => {
            const typeIds = String(field.record_type_id).split(',').map(s => s.trim());
            
            if (typeIds.includes(String(recordTypeId))) {
                selects.forEach(sel => {
                    const opt = document.createElement('option');
                    opt.value = field.field_name;
                    opt.innerText = field.label + " (حقل مخصص)";
                    sel.appendChild(opt);
                });

                if (dedupSelect1) {
                    const opt = document.createElement('option');
                    opt.value = field.field_name;
                    opt.innerText = field.label;
                    dedupSelect1.appendChild(opt);
                }

                if (dedupSelect2) {
                    const opt = document.createElement('option');
                    opt.value = field.field_name;
                    opt.innerText = field.label;
                    dedupSelect2.appendChild(opt);
                }
            }
        });

        runSemanticAutoMapping();
    }

    function runSemanticAutoMapping() {
        const selects = document.querySelectorAll('.field-map-select');
        let matchedCount = 0;

        selects.forEach(sel => {
            const headerText = sel.getAttribute('data-csv-header').trim().toLowerCase().replace(/[^a-zA-Z0-9أ-ي]/g, '');
            
            if (headerText === '') return;

            for (let i = 0; i < sel.options.length; i++) {
                const optText = sel.options[i].text.trim().toLowerCase().replace(' (حقل مخصص)', '').replace(/[^a-zA-Z0-9]/g, '');
                const optValue = sel.options[i].value.trim().toLowerCase().replace(/[^a-zA-Z0-9]/g, '');

                if (headerText === optText || headerText === optValue || optText.includes(headerText) || headerText.includes(optText)) {
                    sel.selectedIndex = i;
                    checkManualMatchStatus(sel); 
                    matchedCount++;
                    break;
                }
            }
        });

        if (matchedCount > 0) {
            const toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 });
            toast.fire({ icon: 'success', title: `تم مطابقة (${matchedCount}) أعمدة تلقائياً بنجاح!` });
        }
    }

    function checkManualMatchStatus(sel) {
        const card = sel.closest('.mapping-card');
        if (!card) return;
        
        const indicator = card.querySelector('.match-indicator');
        
        if (sel.value !== '') {
            card.classList.add('border-emerald-200', 'bg-emerald-50/10');
            card.classList.remove('border-gray-150');
            if (indicator) {
                indicator.innerHTML = '<i class="fa-solid fa-circle-check text-emerald-500 animate-pulse text-lg"></i>';
            }
        } else {
            card.className = 'mapping-card border border-gray-150 p-4 rounded-2xl bg-white shadow-sm flex items-center justify-between gap-4 transition-all duration-300 hover:shadow-md';
            if (indicator) {
                indicator.innerHTML = '<i class="fa-solid fa-arrow-left-long"></i>';
            }
        }
    }

    function submitImportForm(mode) {
        const recordTypeId = document.getElementById('import_record_type_id').value;
        const dedupField1 = document.getElementById('import_dedup_field_1').value;
        const dedupField2 = document.getElementById('import_dedup_field_2').value;
        const skipFirstRow = document.getElementById('skip_first_row_visible').checked;

        if (!recordTypeId) {
            Swal.fire({ icon: 'warning', title: 'تنبيه مطلوب', text: 'يرجى تحديد القسم الميداني المستهدف للمطابقة أولاً.' });
            return;
        }

        if (mode === 'auto') {
            const form = document.getElementById('auto-import-form');
            const hiddenContainer = document.getElementById('hidden-mappings-container');
            hiddenContainer.innerHTML = ''; 

            document.getElementById('auto_record_type_id').value = recordTypeId;
            document.getElementById('auto_dedup_field_1').value = dedupField1; 
            document.getElementById('auto_dedup_field_2').value = dedupField2; 
            document.getElementById('skip_first_row_hidden').value = skipFirstRow ? "1" : "0"; 

            const selects = document.querySelectorAll('.field-map-select');
            let matched_at_least_one = false;

            selects.forEach(sel => {
                const csvIdx = sel.getAttribute('data-csv-index');
                const sysField = sel.value;

                if (sysField !== '') {
                    matched_at_least_one = true;
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = `mappings[${csvIdx}]`;
                    hiddenInput.value = sysField;
                    hiddenContainer.appendChild(hiddenInput);
                }
            });

            if (!matched_at_least_one) {
                Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى مطابقة عمود واحد على الأقل مع حقول السيستم قبل الاستيراد.' });
                return;
            }

            form.submit();
        }

        if (mode === 'manual') {
            document.getElementById('manual_record_type_id').value = recordTypeId;
            document.getElementById('manual_dedup_field_1').value = dedupField1; 
            document.getElementById('manual_dedup_field_2').value = dedupField2; 
            buildManualInteractiveGrid(recordTypeId);
        }
    }

    function buildManualInteractiveGrid(recordTypeId) {
        const selects = document.querySelectorAll('.field-map-select');
        let activeMappings = []; 

        selects.forEach(sel => {
            if (sel.value !== '') {
                activeMappings.push({
                    csv_index: parseInt(sel.getAttribute('data-csv-index')),
                    sys_field: sel.value,
                    header_label: sel.options[sel.selectedIndex].text.replace(' (حقل مخصص)', '')
                });
            }
        });

        if (activeMappings.length === 0) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى مطابقة حقل واحد على الأقل أولاً لبناء جدول التحرير اليدوي.' });
            return;
        }

        document.getElementById('mapping-visual-panel').classList.add('hidden');
        document.getElementById('manual-grid-panel').classList.remove('hidden');

        const headerRow = document.getElementById('manual-grid-header-row');
        headerRow.innerHTML = '<th class="px-3 py-2 text-center font-sans">رقم الصف</th>'; 
        activeMappings.forEach(map => {
            headerRow.innerHTML += `<th class="px-3 py-2 font-sans">${map.header_label}</th>`;
        });

        const gridBody = document.getElementById('manual-grid-body');
        gridBody.innerHTML = '';

        const skipFirstRow = document.getElementById('skip_first_row_visible').checked;
        const start_row_idx = skipFirstRow ? 1 : 0;

        for (let row_idx = start_row_idx; row_idx < allUploadedRows.length; row_idx++) {
            const csvRow = allUploadedRows[row_idx];
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-purple-50/50 transition';

            let rowHTML = `<td class="px-3 py-2 font-mono text-gray-400 text-center border-b">#${row_idx}</td>`;

            activeMappings.forEach(map => {
                const csvVal = csvRow[map.csv_index] ? csvRow[map.csv_index].trim() : '';
                const sysField = map.sys_field;

                rowHTML += `<td class="px-2 py-1.5 border-b">`;
                rowHTML += `<div class="grid-cell-container relative border border-purple-200/50 p-1.5 rounded-lg flex items-center justify-between gap-2 bg-white transition duration-200">`;
                rowHTML += `<span class="error-badge hidden absolute -top-2 left-2 bg-red-600 text-white text-[8px] px-1.5 py-0.5 rounded font-bold shadow-md"></span>`;

                if (sysField === 'latitude' || sysField === 'longitude') {
                    rowHTML += `<input type="number" step="any" oninput="validateManualGrid()" data-field-type="${sysField}" name="manual_records[${row_idx}][${sysField}]" value="${csvVal}" required class="grid-cell-input px-2 py-1 border border-purple-100 rounded text-[10px] text-left focus:outline-none w-32 font-mono font-semibold focus:ring-1 focus:ring-purple-500 bg-white" dir="ltr">`;
                } else {
                    const fieldInfo = allSystemFields.find(f => f.field_name === sysField);
                    const isRequiredAttr = (fieldInfo && fieldInfo.is_required == 1) ? 'required' : '';

                    if (fieldInfo && fieldInfo.type === 'select') {
                        rowHTML += `<select name="manual_records[${row_idx}][${sysField}]" onchange="validateManualGrid()" data-field-type="select" ${isRequiredAttr} class="grid-cell-input px-2 py-1 border border-purple-100 rounded text-[10px] focus:outline-none w-44 bg-white font-semibold focus:ring-1 focus:ring-purple-500 font-sans">`;
                        rowHTML += `<option value="">-- اختر --</option>`;
                        
                        if (fieldInfo.options) {
                            const opts = fieldInfo.options.split(',');
                            opts.forEach(opt => {
                                opt = opt.trim();
                                const isSelected = (opt === csvVal) ? 'selected' : '';
                                rowHTML += `<option value="${opt}" ${isSelected}>${opt}</option>`;
                            });
                        }
                        rowHTML += `</select>`;
                    } else if (fieldInfo && fieldInfo.type === 'date') {
                        rowHTML += `<input type="date" name="manual_records[${row_idx}][${sysField}]" onchange="validateManualGrid()" data-field-type="date" ${isRequiredAttr} value="${csvVal}" class="grid-cell-input px-2 py-1 border border-purple-100 rounded text-[10px] focus:outline-none w-32 font-semibold focus:ring-1 focus:ring-purple-500 font-sans bg-white">`;
                    } else {
                        rowHTML += `<input type="text" name="manual_records[${row_idx}][${sysField}]" oninput="validateManualGrid()" data-field-type="text" ${isRequiredAttr} value="${csvVal}" class="grid-cell-input px-2 py-1 border border-purple-100 rounded text-[10px] focus:outline-none w-44 font-semibold focus:ring-1 focus:ring-purple-500 bg-white">`;
                    }
                }

                rowHTML += `</div></td>`;
            });

            tr.innerHTML = rowHTML;
            gridBody.appendChild(tr);
        }

        validateManualGrid();
    }

    function validateManualGrid() {
        let errorCount = 0;
        const inputs = document.querySelectorAll('.grid-cell-input');

        inputs.forEach(input => {
            const isRequired = input.hasAttribute('required');
            const val = input.value.trim();
            const type = input.getAttribute('data-field-type');
            
            let isInvalid = false;
            let errorMsg = "";

            if (isRequired && val === '') {
                isInvalid = true;
                errorMsg = "مطلوب!";
            } else if ((type === 'latitude' || type === 'longitude') && val !== '') {
                const num = parseFloat(val);
                if (isNaN(num) || num < -180 || num > 180 || num === 0) {
                    isInvalid = true;
                    errorMsg = "غير صالح!";
                }
            }

            const cellContainer = input.closest('.grid-cell-container');
            if (cellContainer) {
                const badge = cellContainer.querySelector('.error-badge');
                if (isInvalid) {
                    errorCount++;
                    cellContainer.classList.add('border-red-300', 'bg-red-50/30');
                    cellContainer.classList.remove('border-purple-200/50');
                    if (badge) {
                        badge.innerText = errorMsg;
                        badge.classList.remove('hidden');
                    }
                } else {
                    cellContainer.classList.remove('border-red-300', 'bg-red-50/30');
                    cellContainer.classList.add('border-purple-200/50');
                    if (badge) {
                        badge.innerText = '';
                        badge.classList.add('hidden');
                    }
                }
            }
        });

        const summary = document.getElementById('grid-validation-summary');
        const submitBtn = document.getElementById('btn-submit-manual-grid');

        if (summary) {
            if (errorCount > 0) {
                summary.innerHTML = `<div class="w-full bg-red-50 text-red-800 border border-red-150 p-2.5 rounded-lg text-[10px] font-bold flex items-center space-x-1.5 space-x-reverse"><i class="fa-solid fa-circle-exclamation text-red-600 animate-pulse text-sm"></i> <span>تنبيه: تم رصد عدد ( ${errorCount} ) خلايا فارغة إجبارية أو إحداثيات خاطئة بالشيت، يرجى مراجعتها وتعديلها بالخلايا الحمراء قبل حفظ البيانات.</span></div>`;
                if(submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            } else {
                summary.innerHTML = `<div class="w-full bg-emerald-50 text-emerald-800 border border-emerald-150 p-2.5 rounded-lg text-[10px] font-bold flex items-center space-x-1.5 space-x-reverse"><i class="fa-solid fa-circle-check text-emerald-600 text-sm"></i> <span>تهانينا! جميع الخلايا والبيانات والتحققات الهندسية بالشيت سليمة ومطابقة وجاهزة للحفظ الآمن 100%.</span></div>`;
                if(submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }
        }
    }

    function cancelManualGrid() {
        document.getElementById('mapping-visual-panel').classList.remove('hidden');
        document.getElementById('manual-grid-panel').classList.add('hidden');
    }

    function triggerTemplateDownload() {
        const typeId = document.getElementById('template_type_selector').value;
        if (!typeId) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار القسم الميداني أولاً لتوليد قالبه المخصص.' });
            return;
        }
        window.location.href = 'index.php?action=download_import_template&type_id=' + typeId;
    }

    document.getElementById('manual-import-form').addEventListener('submit', function() {
        document.getElementById('manual_record_type_id').value = document.getElementById('import_record_type_id').value;
        document.getElementById('manual_dedup_field_1').value = document.getElementById('import_dedup_field_1').value;
        document.getElementById('manual_dedup_field_2').value = document.getElementById('import_dedup_field_2').value;
    });
</script>