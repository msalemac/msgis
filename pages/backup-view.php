<?php
// pages/backup-view.php - مركز النسخ الاحتياطي وصيانة وتطهير النظام (النسخة النهائية الرشيقة والمؤمنة بالكامل لبيئات PHP 8.4)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

$backups_dir = 'uploads/backups/';
$temp_dir = 'uploads/temp/';

if (!is_dir($backups_dir)) { mkdir($backups_dir, 0755, true); }
if (!is_dir($temp_dir)) { mkdir($temp_dir, 0755, true); }

if (isset($_SESSION['backup_success_msg'])) { $message = $_SESSION['backup_success_msg']; unset($_SESSION['backup_success_msg']); }
if (isset($_SESSION['backup_error_msg'])) { $error = $_SESSION['backup_error_msg']; unset($_SESSION['backup_error_msg']); }

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

// ----------------- [1. الدوال البرمجية الذكية] -----------------

function generateDatabaseSqlDump($pdo, $structure_only = false) {
    $out = "-- SQL Database Backup Generated Dynamically by GIS Manager\n";
    $out .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $out .= "DROP TABLE IF EXISTS `$table`;\n";
        $stmt_create = $pdo->query("SHOW CREATE TABLE `$table`");
        $row_create = $stmt_create->fetch(PDO::FETCH_NUM);
        $out .= $row_create[1] . ";\n\n";

        if (!$structure_only) {
            $stmt_data = $pdo->query("SELECT * FROM `$table`");
            while ($row_data = $stmt_data->fetch(PDO::FETCH_ASSOC)) {
                $keys = array_keys($row_data);
                $escaped_keys = array_map(function($k) { return "`$k`"; }, $keys);
                
                $vals = array_values($row_data);
                $escaped_vals = array_map(function($v) use ($pdo) {
                    if ($v === null) return "NULL";
                    return $pdo->quote($v);
                }, $vals);
                
                $out .= "INSERT INTO `$table` (" . implode(', ', $escaped_keys) . ") VALUES (" . implode(', ', $escaped_vals) . ");\n";
            }
            $out .= "\n";
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

function generateZipBackup($source_dir, $destination_zip) {
    if (!extension_loaded('zip') || !file_exists($source_dir)) { return false; }
    
    $zip = new ZipArchive();
    if (!$zip->open($destination_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE)) { return false; }

    $source_dir = realpath($source_dir);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($source_dir) + 1);

            if (strpos($relative_path, 'uploads/backups') !== false || strpos($relative_path, 'uploads/temp') !== false) {
                continue;
            }

            $zip->addFile($file_path, $relative_path);
        }
    }
    return $zip->close();
}

// ----------------- [2. معالجة عمليات التحميل المباشرة للجلسة الحالية] -----------------

if (isset($_GET['action']) && $_GET['action'] === 'download_sql') {
    $mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'full';
    $structure_only = ($mode === 'structure');
    
    $sql_dump = generateDatabaseSqlDump($pdo, $structure_only);
    $filename = 'db_backup_' . $mode . '_' . date('Y-m-d_H-i') . '.sql';

    logActivity($pdo, "تنزيل قاعدة بيانات", "قام الموظف بسحب وتحميل ملف SQL لقاعدة البيانات بالصيغة: " . $mode);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo $sql_dump;
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'download_zip') {
    $zip_filename = 'files_backup_' . date('Y-m-d_H-i') . '.zip';
    $target_zip = $temp_dir . $zip_filename;

    if (generateZipBackup('.', $target_zip)) {
        logActivity($pdo, "تنزيل ملفات النظام", "قام المستخدم بضغط وتحميل أكواد ومرفقات المعاينات بالكامل بصيغة ZIP.");

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename=' . $zip_filename);
        header('Content-Length: ' . filesize($target_zip));
        readfile($target_zip);
        unlink($target_zip); 
        exit;
    } else {
        $_SESSION['backup_error_msg'] = "عذراً، فشل توليد ملف الـ ZIP الاحتياطي للأنظمة.";
        safeRedirect("index.php?page=backup-view");
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'download_local') {
    $file = isset($_GET['file']) ? basename(trim((string)$_GET['file'])) : '';
    $filepath = $backups_dir . $file;
    if (!empty($file) && file_exists($filepath)) {
        logActivity($pdo, "تحميل نسخة محلية", "قام المستخدم بتحميل ملف النسخة الاحتياطية المحفوظة محلياً باسم: " . $file);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . $file);
        readfile($filepath);
        exit;
    }
}

// ----------------- [3. معالجة طلبات الإرسال الـ POST تحت حماية CSRF المزدوجة] -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الإجباري لتوكن الجلسة CSRF لحماية تطهير وصيانة السيرفر
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'create_local_backup') {
        $type = trim((string)($_POST['backup_type'] ?? '')); 

        if ($type === 'sql_full' || $type === 'sql_structure') {
            $structure_only = ($type === 'sql_structure');
            $sql_dump = generateDatabaseSqlDump($pdo, $structure_only);
            $filename = 'local_db_' . ($structure_only ? 'structure' : 'full') . '_' . date('Y-m-d_H-i') . '.sql';
            
            if (file_put_contents($backups_dir . $filename, $sql_dump) !== false) {
                logActivity($pdo, "توليد نسخة سحابية", "تم توليد وحفظ نسخة SQL على السيرفر بنجاح باسم: " . $filename);
                $_SESSION['backup_success_msg'] = "تم حفظ نسخة احتياطية من قاعدة البيانات على السيرفر بنجاح باسم: {$filename}";
            }
        } elseif ($type === 'zip_files') {
            $filename = 'local_files_' . date('Y-m-d_H-i') . '.zip';
            if (generateZipBackup('.', $backups_dir . $filename)) {
                logActivity($pdo, "توليد نسخة سحابية لملفات النظام", "تم ضغط وحفظ ملفات النظام ومرفقات الـ PDF والصور على السيرفر بنجاح باسم: " . $filename);
                $_SESSION['backup_success_msg'] = "تم ضغط وحفظ ملفات ومرفقات النظام بالكامل على السيرفر بنجاح باسم: {$filename}";
            } else { $_SESSION['backup_error_msg'] = "فشل توليد ضغط الملفات."; }
        }
        safeRedirect("index.php?page=backup-view");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'restore_db') {
        if (isset($_FILES['sql_restore_file']) && $_FILES['sql_restore_file']['error'] === UPLOAD_ERR_OK) {
            $file_ext = strtolower(pathinfo($_FILES['sql_restore_file']['name'], PATHINFO_EXTENSION));
            if ($file_ext === 'sql') {
                $sql_content = file_get_contents($_FILES['sql_restore_file']['tmp_name']);
                if ($sql_content !== false) {
                    try {
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                        $pdo->exec($sql_content);
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                        
                        logActivity($pdo, "استرجاع النظام الكلي", "قام المستخدم برفع واسترجاع قاعدة البيانات الكلية من ملف خارجي بنجاح.");
                        $_SESSION['backup_success_msg'] = "تم استرجاع وإعادة بناء قاعدة البيانات بالكامل بنجاح من الملف المرفوع.";
                    } catch (PDOException $e) {
                        $_SESSION['backup_error_msg'] = "عذراً، حدث خطأ أثناء تنفيذ استعلامات الـ SQL بالملف المرفوع.";
                    }
                }
            } else { $_SESSION['backup_error_msg'] = "يرجى رفع ملفات قواعد بيانات بصيغة .sql فقط."; }
        }
        safeRedirect("index.php?page=backup-view");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_local') {
        $file = basename(trim((string)($_POST['filename'] ?? '')));
        $filepath = $backups_dir . $file;
        if (file_exists($filepath)) {
            unlink($filepath);
            logActivity($pdo, "حذف نسخة محلية", "تم حذف ملف النسخة الاحتياطية المحددة من السيرفر باسم: " . $file);
            $_SESSION['backup_success_msg'] = "تم حذف ملف النسخة الاحتياطية المحددة من السيرفر.";
        }
        safeRedirect("index.php?page=backup-view");
    }

    // ----------------- [4. أدوات التصفية والتطهير المخصصة للسجلات] -----------------
    if (isset($_POST['action']) && $_POST['action'] === 'delete_records_custom') {
        $cleanup_type = trim((string)($_POST['cleanup_type'] ?? ''));
        $confirm_text = trim((string)($_POST['confirm_text'] ?? ''));

        // التحقق الثنائي للأمان من كتابة المشرف للكلمة التأكيدية قبل المعالجة
        if ($confirm_text !== 'DELETE' && $confirm_text !== 'حذف') {
            $_SESSION['backup_error_msg'] = "عذراً، لم تكتب كلمة التأكيد بشكل صحيح.";
            safeRedirect("index.php?page=backup-view");
        }

        try {
            if ($cleanup_type === 'date_range') {
                $start_date = trim((string)($_POST['start_date'] ?? '')) . ' 00:00:00';
                $end_date = trim((string)($_POST['end_date'] ?? '')) . ' 23:59:59';
                
                $stmt = $pdo->prepare("DELETE FROM records WHERE created_at BETWEEN ? AND ?");
                $stmt->execute([$start_date, $end_date]);
                $deleted_count = $stmt->rowCount();

                logActivity($pdo, "تطهير السجلات (تاريخي)", "قام المشرف بحذف السجلات للفترة من " . $_POST['start_date'] . " إلى " . $_POST['end_date'] . ". العدد المحذوف: " . $deleted_count);
                $_SESSION['backup_success_msg'] = "تمت إزالة ({$deleted_count}) سجلات خلال الفترة المحددة بنجاح.";

            } elseif ($cleanup_type === 'record_type') {
                $target_type_id = intval($_POST['target_record_type_id'] ?? 0);
                $type_label = $pdo->query("SELECT label FROM record_types WHERE id = " . $target_type_id)->fetchColumn() ?: "مجهول";

                $stmt = $pdo->prepare("DELETE FROM records WHERE record_type_id = ?");
                $stmt->execute([$target_type_id]);
                $deleted_count = $stmt->rowCount();

                logActivity($pdo, "تطهير السجلات (حسب القسم)", "قام المشرف بحذف جميع السجلات للقسم الفني: " . $type_label . ". العدد المحذوف: " . $deleted_count);
                $_SESSION['backup_success_msg'] = "تم بنجاح حذف ({$deleted_count}) سجلات مخصصة لقسم: {$type_label}.";

            } elseif ($cleanup_type === 'truncate_all') {
                // إفراغ الجدول بالكامل مع إغلاق القيود مؤقتاً لتجنب أخطاء المفاتيح الأجنبية
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $pdo->exec("TRUNCATE TABLE records;");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                logActivity($pdo, "إفراغ كامل السجلات", "قام المشرف بعمل تصفير وإفراغ كامل لجدول السجلات (TRUNCATE).");
                $_SESSION['backup_success_msg'] = "تم تصفير وإفراغ جدول السجلات الميدانية بالكامل بنجاح من المنصة.";
            }

        } catch (PDOException $e) {
            $_SESSION['backup_error_msg'] = "فشلت عملية التطهير: " . $e->getMessage();
        }
        
        safeRedirect("index.php?page=backup-view");
    }

    // معالجة أداة الصيانة بنظام حماية داخلي مستقل لعدم الانهيار عند قفل تصاريح السيرفر
    if (isset($_POST['action']) && $_POST['action'] === 'run_system_maintenance') {
        try {
            // أ. تحسين وفهرسة الجداول المتاحة
            try {
                $tables_stmt = $pdo->query("SHOW TABLES");
                while ($row = $tables_stmt->fetch(PDO::FETCH_NUM)) {
                    try {
                        $pdo->exec("OPTIMIZE TABLE `{$row[0]}`");
                    } catch (PDOException $opt_err) { }
                }
            } catch (PDOException $tbl_err) { }

            // ب. جرد وحذف الملفات والصور اليتيمة والميتة على السيرفر لإفراغ مساحة الهارد ديسك
            $stmtPhotosInDb = $pdo->query("SELECT photo_path FROM records WHERE photo_path IS NOT NULL");
            $db_photos = $stmtPhotosInDb->fetchAll(PDO::FETCH_COLUMN);

            $stmtPdfsInDb = $pdo->query("SELECT pdf_path FROM records WHERE pdf_path IS NOT NULL");
            $db_pdfs = $stmtPdfsInDb->fetchAll(PDO::FETCH_COLUMN);

            // دمج الشعار من الإعدادات لعدم حذفه بالخطأ
            $stmtLogoInDb = $pdo->query("SELECT logo_path FROM print_templates WHERE logo_path IS NOT NULL");
            $db_logos = $stmtLogoInDb->fetchAll(PDO::FETCH_COLUMN);

            $all_db_files = array_merge($db_photos, $db_pdfs, $db_logos);
            $deleted_files_count = 0;
            $freed_space = 0;

            // جرد المجلدات الفعلية وحذف الملفات غير المسجلة بقاعدة البيانات
            $upload_folders = ['uploads/photos', 'uploads/pdfs', 'uploads/logos'];
            foreach ($upload_folders as $folder) {
                if (is_dir($folder)) {
                    $files = scandir($folder);
                    foreach ($files as $file) {
                        if ($file !== '.' && $file !== '..' && $file !== '.htaccess') {
                            $file_path = $folder . '/' . $file;
                            if (file_exists($file_path)) {
                                if (!in_array($file_path, $all_db_files)) {
                                    $freed_space += filesize($file_path);
                                    unlink($file_path);
                                    $deleted_files_count++;
                                }
                            }
                        }
                    }
                }
            }

            $freed_mb = round($freed_space / (1024 * 1024), 2);
            logActivity($pdo, "صيانة وتطهير النظام", "قام المستخدم بتشغيل أداة الصيانة؛ تم فحص قاعدة البيانات، وحذف {$deleted_files_count} ملف يتيم وتوفير {$freed_mb} ميجابايت.");
            $_SESSION['backup_success_msg'] = "تمت عملية الصيانة الشاملة وتطهير النظام بنجاح! تم تحسين الجداول المتاحة، مسح {$deleted_files_count} ملف يتيم وتوفير مساحة تقريبية: {$freed_mb} ميجابايت على السيرفر.";
        } catch (PDOException $e) { $_SESSION['backup_error_msg'] = "عذراً، فشل إجراء الصيانة الشاملة."; }
        safeRedirect("index.php?page=backup-view");
    }
}

// جلب ملفات السيرفر
$local_backups = [];
if (is_dir($backups_dir)) {
    $files = scandir($backups_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && $file !== '.htaccess') {
            $local_backups[] = [
                'name' => $file,
                'size' => round(filesize($backups_dir . $file) / (1024 * 1024), 2), 
                'date' => date('Y-m-d H:i', filemtime($backups_dir . $file)),
                'type' => pathinfo($file, PATHINFO_EXTENSION)
            ];
        }
    }
}
?>

<!-- التنبيهات بـ SweetAlert -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ في العملية', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<div class="space-y-6 animate-fade text-right" dir="rtl">

    <!-- باني ومحرك الاستبدال والإصلاح الفوري للبيانات -->
    <div class="bg-gradient-to-br from-indigo-900 to-indigo-950 text-white p-6 rounded-2xl shadow-xl border border-indigo-950 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center space-x-4 space-x-reverse">
            <div class="p-3 bg-white/10 text-indigo-300 rounded-xl shadow-inner border border-white/5">
                <i class="fa-solid fa-wand-magic-sparkles text-3xl animate-pulse"></i>
            </div>
            <div>
                <h3 class="text-sm font-black text-white">تشغيل محرك إصلاح وتطهير البيانات الفوري (Data Consolidation)</h3>
                <p class="text-[10px] text-indigo-200 mt-1 max-w-xl leading-relaxed font-semibold">أداة تفاعلية متطورة صُممت خصيصاً لمساعدتك على استكشاف وتعديل وتصحيح الكلمات أو الحروف المدخلة بشكل خاطئ أثناء استيراد البيانات (مثل توحيد دمج "خارج 1ك" و "خارج 1 ك") داخل سجلات النظام بضغطة زر واحدة.</p>
            </div>
        </div>
        <a href="index.php?page=data-repair" class="w-full md:w-auto bg-white hover:bg-slate-100 text-indigo-950 font-black py-2.5 px-6 rounded-xl text-xs transition text-center shadow-md">
            تشغيل محرك الإصلاح والدمج
        </a>
    </div>

    <!-- الصف الأول: كروت النسخ الاحتياطي الفوري (تنزيل مباشر) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- كرت 1: سحب قاعدة البيانات كاملة -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-150 flex flex-col justify-between hover:shadow-lg transition duration-300">
            <div class="space-y-2">
                <div class="flex items-center space-x-2 space-x-reverse text-blue-600 font-black">
                    <i class="fa-solid fa-database text-lg"></i>
                    <h4 class="text-xs">نسخ قاعدة البيانات بالكامل</h4>
                </div>
                <p class="text-[10px] text-gray-400 font-bold leading-normal">يقوم بسحب نسخة كاملة تحتوى على الهيكل الهندسي، الحقول الديناميكية، السجلات الميدانية، والموظفين وتنزيلها بصيغة .sql فوراً.</p>
            </div>
            <a href="index.php?action=download_sql&mode=full" class="mt-4 bg-blue-600 hover:bg-blue-700 text-white font-black py-2.5 px-4 rounded-xl text-[10px] text-center transition shadow-sm">
                <span class="text-white">تحميل قاعدة البيانات (.sql)</span>
            </a>
        </div>

        <!-- كرت 2: نسخ الهيكل الهندسي فقط -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-150 flex flex-col justify-between hover:shadow-lg transition duration-300">
            <div class="space-y-2">
                <div class="flex items-center space-x-2 space-x-reverse text-purple-600 font-black">
                    <i class="fa-solid fa-diagram-project text-lg"></i>
                    <h4 class="text-xs">نسخ الهيكل الهندسي فقط</h4>
                </div>
                <p class="text-[10px] text-gray-400 font-bold leading-normal">يقوم بسحب بنية الجداول وهيكل الحقول الديناميكية فقط دون أي بيانات أو معاينات، مخصص لتركيب النظام من الصفر على سيرفر جديد.</p>
            </div>
            <a href="index.php?action=download_sql&mode=structure" class="mt-4 bg-purple-600 hover:bg-purple-700 text-white font-black py-2.5 px-4 rounded-xl text-[10px] text-center transition shadow-sm">
                <span class="text-white">تحميل الهيكل فقط (.sql)</span>
            </a>
        </div>

        <!-- كرت 3: ضغط وتحميل ملفات النظام -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-150 flex flex-col justify-between hover:shadow-lg transition duration-300">
            <div class="space-y-2">
                <div class="flex items-center space-x-2 space-x-reverse text-amber-600 font-black">
                    <i class="fa-solid fa-file-zipper text-lg"></i>
                    <h4 class="text-xs">ضغط وتحميل ملفات ومرفقات النظام</h4>
                </div>
                <p class="text-[10px] text-gray-400 font-bold leading-normal">يقوم بضغط كافة ملفات البرمجة والصور الميدانية وملفات الـ PDF المرفوعة، وتنزيلها كملف .zip (قد يستغرق بعض الوقت حسب حجم الصور).</p>
            </div>
            <a href="index.php?action=download_zip" class="mt-4 bg-amber-600 hover:bg-amber-700 text-white font-black py-2.5 px-4 rounded-xl text-[10px] text-center transition shadow-sm">
                <span class="text-white">تحميل ملفات النظام (.zip)</span>
            </a>
        </div>
    </div>

    <!-- الصف الثاني: حفظ واسترجاع وصيانة -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        
        <!-- بوكس 1: حفظ نسخة على السيرفر نفسه -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-150 space-y-4 lg:col-span-1">
            <div class="flex items-center space-x-2 space-x-reverse mb-2 border-b pb-2 border-gray-100">
                <i class="fa-solid fa-floppy-disk text-emerald-600 text-lg"></i>
                <h3 class="font-black text-slate-900 text-xs">توليد وحفظ نسخة على السيرفر</h3>
            </div>
            <form action="index.php?page=backup-view" method="POST" class="space-y-4">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="create_local_backup">
                
                <div>
                    <label class="block text-slate-500 text-xs font-bold mb-1.5">اختر نوع النسخة الاحتياطية:</label>
                    <select name="backup_type" required class="w-full px-3 py-2 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans">
                        <option value="sql_full">قاعدة البيانات بالكامل (هيكل + داتا)</option>
                        <option value="sql_structure">الهيكل الهندسي فقط لجداول الـ SQL</option>
                        <option value="zip_files">ملفات وأكواد ومرفقات الموقع (.zip)</option>
                    </select>
                </div>
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-2.5 px-4 rounded-xl text-xs transition shadow-sm">
                    حفظ نسخة احتياطية محلية
                </button>
            </form>
        </div>

        <!-- بوكس 2: استرجاع قاعدة البيانات -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-150 space-y-4 lg:col-span-1">
            <div class="flex items-center space-x-2 space-x-reverse mb-2 border-b pb-2 border-gray-100">
                <i class="fa-solid fa-file-shield text-red-600 text-lg"></i>
                <h3 class="font-black text-red-800 text-xs">استرجاع قاعدة البيانات (Restore)</h3>
            </div>
            <div class="bg-red-50 border border-red-100 text-red-700 p-2.5 rounded-lg text-[9px] font-bold leading-relaxed">
                استرجاع ملف خارجي سيقوم بحذف كافة السجلات والأنشطة والمعاينات الحالية بالكامل! يُرجى رفع ملفات .sql معتمدة فقط.
            </div>
            <form action="index.php?page=backup-view" method="POST" enctype="multipart/form-data" onsubmit="return confirmRestore()" class="space-y-3">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="restore_db">
                
                <input type="file" name="sql_restore_file" accept=".sql" required class="w-full text-xs text-gray-500 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:bg-red-50 file:text-red-700 cursor-pointer font-bold">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-2.5 px-4 rounded-xl text-xs transition shadow-sm">
                    بدء الاسترجاع الفوري
                </button>
            </form>
        </div>

        <!-- بوكس 3: أداة صيانة وتطهير السيرفر الشاملة -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-150 space-y-4 lg:col-span-1">
            <div class="flex items-center space-x-2 space-x-reverse mb-2 border-b pb-2 border-gray-100">
                <i class="fa-solid fa-screwdriver-wrench text-indigo-600 text-lg"></i>
                <h3 class="font-black text-slate-900 text-xs">أداة صيانة وتطهير السيرفر</h3>
            </div>
            <p class="text-[10px] text-gray-400 font-bold leading-relaxed">أداة ذكية تقوم بـ (تحسين جداول قاعدة البيانات المتاحة، ومسح كافة ملفات الـ PDF والصور "اليتيمة" المرفوعة والغير مربوطة بأي سجل ميداني لتوفير مساحة السيرفر فورياً).</p>
            
            <form id="maintenance-form" action="index.php?page=backup-view" method="POST">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="run_system_maintenance">
                
                <button type="button" onclick="runSystemMaintenanceConfirm(event)" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-black py-2.5 px-4 rounded-xl text-xs transition shadow-sm flex items-center justify-center space-x-2 space-x-reverse">
                    <i class="fa-solid fa-wand-magic-sparkles text-white animate-pulse"></i>
                    <span class="text-white">تشغيل الصيانة الآن</span>
                </button>
            </form>
        </div>

    </div>

    <!-- صندوق تصفية وتطهير السجلات الميدانية المتقدم والمؤمن -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-red-200 space-y-4">
        <div class="flex items-center space-x-2 space-x-reverse mb-2 border-b pb-2 border-red-100">
            <i class="fa-solid fa-trash-can-arrow-up text-red-600 text-lg"></i>
            <h3 class="font-black text-red-800 text-xs">أدوات تصفية وتطهير السجلات الميدانية المخصصة</h3>
        </div>
        <div class="bg-red-50 border border-red-100 text-red-700 p-2.5 rounded-lg text-[9px] font-bold leading-relaxed">
            تنبيه أمني هام جداً: الإجراءات التالية تؤدي إلى حذف نهائي ومحو كامل للسجلات المحددة من قاعدة البيانات. يرجى أخذ نسخة احتياطية أولاً قبل اتخاذ أي قرار.
        </div>
        
        <form id="purge-form" action="index.php?page=backup-view" method="POST" class="space-y-4 text-xs">
            
            <!-- حقل الأمان CSRF -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="action" value="delete_records_custom">
            <input type="hidden" name="confirm_text" id="purge_confirm_text">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 font-bold text-slate-800">
                <!-- اختيار طريقة التطهير -->
                <div>
                    <label class="block text-slate-500 text-[10px] font-bold mb-1.5">اختر نوع التطهير المطلوب:</label>
                    <select name="cleanup_type" id="cleanup_type" onchange="togglePurgeOptions(this.value)" class="w-full px-3 py-2 border rounded-xl text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans">
                        <option value="">-- اختر الإجراء المطلوب --</option>
                        <option value="date_range">حذف سجلات فترة زمنية محددة</option>
                        <option value="record_type">حذف سجلات قسم ميداني محدد</option>
                        <option value="truncate_all">إفراغ ومحو كافة السجلات بالكامل (تصفير)</option>
                    </select>
                </div>

                <!-- خيارات الفترة الزمنية -->
                <div id="purge-date-box" class="hidden md:col-span-2 grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-slate-500 text-[10px] font-bold mb-1.5">تاريخ البدء (من)</label>
                        <input type="date" name="start_date" id="purge_start_date" class="w-full px-3 py-1.5 border rounded-xl text-xs font-bold focus:outline-none text-slate-900 bg-white">
                    </div>
                    <div>
                        <label class="block text-slate-500 text-[10px] font-bold mb-1.5">تاريخ الانتهاء (إلى)</label>
                        <input type="date" name="end_date" id="purge_end_date" class="w-full px-3 py-1.5 border rounded-xl text-xs font-bold focus:outline-none text-slate-900 bg-white">
                    </div>
                </div>

                <!-- خيارات القسم الميداني -->
                <div id="purge-type-box" class="hidden">
                    <label class="block text-slate-500 text-[10px] font-bold mb-1.5">اختر القسم المستهدف بالتطهير:</label>
                    <select name="target_record_type_id" id="target_record_type_id" class="w-full px-3 py-2 border rounded-xl text-xs font-bold text-gray-700 focus:outline-none bg-white font-sans">
                        <?php foreach ($pdo->query("SELECT id, label FROM record_types ORDER BY id DESC")->fetchAll() as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="button" onclick="runPurgeConfirm(event)" class="bg-red-600 hover:bg-red-700 text-white font-black py-2.5 px-6 rounded-xl text-xs transition shadow-sm flex items-center space-x-1.5 space-x-reverse">
                    <i class="fa-solid fa-trash-can text-white"></i>
                    <span class="text-white">تنفيذ حذف وتطهير البيانات المحددة</span>
                </button>
            </div>
        </form>
    </div>

    <!-- الصف الثالث: جدول استعراض وتحميل وحذف النسخ الاحتياطية المحفوظة محلياً على السيرفر -->
    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-150">
        <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3 border-gray-100">
            <div class="p-2 bg-slate-100 text-slate-600 rounded-lg"><i class="fa-solid fa-layer-group text-xl"></i></div>
            <h3 class="text-sm font-black text-slate-950">قائمة ملفات النسخ الاحتياطية المحفوظة محلياً على السيرفر</h3>
        </div>
        <div class="overflow-x-auto rounded-xl border border-gray-150">
            <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                <thead class="bg-slate-100 text-slate-900 font-black uppercase">
                    <tr>
                        <th class="px-6 py-3">اسم ملف النسخة</th>
                        <th class="px-6 py-3">نوع الملف</th>
                        <th class="px-6 py-3">الحجم التقريبي</th>
                        <th class="px-6 py-3">تاريخ ووقت التوليد</th>
                        <th class="px-6 py-3 text-center">العمليات والإجراءات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 text-slate-900 font-black">
                    <?php if (count($local_backups) === 0): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400 font-bold">لا توجد ملفات نسخ احتياطية محفوظة على السيرفر حالياً.</td></tr>
                    <?php else: ?>
                        <?php foreach ($local_backups as $b): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-mono text-slate-900"><?php echo htmlspecialchars($b['name']); ?></td>
                                <td class="px-6 py-3 font-black">
                                    <?php if ($b['type'] === 'sql'): ?>
                                        <span class="bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded-full"><i class="fa-solid fa-database font-mono"></i> SQL</span>
                                    <?php else: ?>
                                        <span class="bg-amber-100 text-amber-800 text-[10px] font-bold px-2 py-0.5 rounded-full"><i class="fa-solid fa-file-zipper font-mono"></i> ZIP</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-3 font-mono"><?php echo $b['size']; ?> ميجابايت</td>
                                <td class="px-6 py-3 text-slate-400 font-bold"><?php echo $b['date']; ?></td>
                                <td class="px-6 py-3 text-center space-x-2 space-x-reverse font-bold">
                                    <a href="index.php?action=download_local&file=<?php echo urlencode($b['name']); ?>" class="text-blue-500 hover:text-blue-700 font-black"><i class="fa-solid fa-download"></i> تحميل</a>
                                    <form action="index.php?page=backup-view" method="POST" onsubmit="return confirm('حذف هذا الملف؟');" class="inline">
                                        
                                        <!-- حقل الأمان CSRF -->
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                        <input type="hidden" name="action" value="delete_local">
                                        <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['name']); ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 font-black"><i class="fa-solid fa-trash-can"></i> حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function confirmRestore() {
        return confirm("تنبيه أمني هام جداً:\n\nهل أنت متأكد بنسبة 100% من رغبتك في استرجاع قاعدة البيانات؟\n\nتأكيد هذه الخطوة سيقوم بحذف واستبدال كافة سجلات وبيانات الموظفين والمعاينات الحالية بشكل فوري ولا يمكن التراجع عنها!");
    }

    function runSystemMaintenanceConfirm(event) {
        event.preventDefault();
        
        Swal.fire({
            title: 'هل تريد تشغيل صيانة وتطهير النظام؟',
            html: `<div class="text-right text-xs leading-relaxed space-y-2 text-gray-600 font-bold" dir="rtl">
                    <p class="font-black text-slate-800 mb-2">ستقوم هذه الأداة بالإجراءات الإستراتيجية التالية:</p>
                    <div>1. <span class="font-black text-indigo-600">تحسين وفهرسة جداول قاعدة البيانات المتاحة</span> لزيادة سرعة التصفح والاستعلام.</div>
                    <div>2. <span class="font-black text-indigo-600">جرد ومطابقة مجلدات المرفقات</span> (الصور والـ PDFs واللوجو المعتمد).</div>
                    <div>3. <span class="font-black text-red-600">تطهير وحذف الملفات الميتة واليتيمة</span> (المرفقات المرفوعة والغير مربوطة حالياً بأي سجل ميداني بقاعدة البيانات) لتهيئة وتوفير مساحة السيرفر.</div>
                   </div>`,
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#4f46e5', // Indigo
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'تأكيد وبدء الصيانة الشاملة',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('maintenance-form').submit();
            }
        });
    }

    function togglePurgeOptions(type) {
        document.getElementById('purge-date-box').classList.add('hidden');
        document.getElementById('purge-type-box').classList.add('hidden');

        if (type === 'date_range') {
            document.getElementById('purge-date-box').classList.remove('hidden');
        } else if (type === 'record_type') {
            document.getElementById('purge-type-box').classList.remove('hidden');
        }
    }

    function runPurgeConfirm(event) {
        event.preventDefault();
        
        const cleanupType = document.getElementById('cleanup_type').value;
        if (!cleanupType) {
            Swal.fire({ icon: 'warning', title: 'تنبيه مطلوب', text: 'يرجى اختيار نوع التطهير المطلوب أولاً من القائمة.' });
            return;
        }

        if (cleanupType === 'date_range') {
            const start = document.getElementById('purge_start_date').value;
            const end = document.getElementById('purge_end_date').value;
            if (!start || !end) {
                Swal.fire({ icon: 'warning', title: 'حقول مطلوبة', text: 'يرجى تحديد تاريخ البدء وتاريخ الانتهاء لفترة التطهير الزمنية.' });
                return;
            }
        }

        let warningText = "سيؤدي هذا الإجراء لحذف ومحو البيانات المحددة نهائياً من قاعدة بيانات المنصة الكلية.";
        if (cleanupType === 'truncate_all') {
            warningText = "تحذير حرج للغاية: سيتم مسح وإفراغ جدول السجلات بالكامل (تصفير تام) لجميع الأقسام الميدانية دون استثناء!";
        }

        Swal.fire({
            title: 'هل أنت متأكد من الحذف النهائي؟',
            text: warningText,
            icon: 'warning',
            input: 'text',
            inputPlaceholder: 'اكتب كلمة (حذف) أو (DELETE) لتأكيد العملية...',
            showCancelButton: true,
            confirmButtonColor: '#dc2626', // Red
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'تأكيد الحذف والمحو النهائي للبيانات',
            cancelButtonText: 'إلغاء العملية',
            inputValidator: (value) => {
                if (!value || (value.trim() !== 'حذف' && value.trim() !== 'DELETE')) {
                    return 'يرجى كتابة كلمة التأكيد بشكل صحيح لتمكين الإجراء!';
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('purge_confirm_text').value = result.value.trim();
                document.getElementById('purge-form').submit();
            }
        });
    }
</script>