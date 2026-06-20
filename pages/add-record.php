<?php
// pages/add-record.php - نموذج التوثيق الميداني والالتقاء الجغرافي (النسخة النهائية الفائقة الأمان والتنقل)

if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

// تعريف الدوال الجغرافية المساعدة (داخل شرط عدم التكرار لتفادي الأخطاء البرمجية)
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
                            $polygon[] = ['lat' => floatval($parts[1]), 'lng' => floatval($parts[0])];
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

/**
 * دالة إعادة التوجيه الفائقة والمقاومة للتعليق وتكرار النماذج
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

// ----------------- [بوابة فحص جيو-مكانية خلفية فورية للـ GPS والـ KML] -----------------
if (isset($_GET['action']) && $_GET['action'] === 'check_geofence') {
    header('Content-Type: application/json');
    $lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
    $lng = isset($_GET['lng']) ? floatval($_GET['lng']) : 0;
    
    $detected_boundary_name = '';
    $detected_boundary_id = 0;
    
    if ($lat && $lng) {
        $boundaries = $pdo->query("SELECT id, name, kml_data FROM boundaries")->fetchAll();
        foreach ($boundaries as $b) {
            $polygon = parseKmlToPolygonPoints($b['kml_data']);
            if (isPointInPolygon($lat, $lng, $polygon)) {
                $detected_boundary_name = $b['name'];
                $detected_boundary_id = $b['id'];
                break;
            }
        }
    }
    
    echo json_encode([
        'success'       => !empty($detected_boundary_name),
        'boundary_name' => $detected_boundary_name,
        'boundary_id'   => $detected_boundary_id
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$get_lat = isset($_GET['lat']) ? floatval($_GET['lat']) : '';
$get_lng = isset($_GET['lng']) ? floatval($_GET['lng']) : '';

// تعيين متغيرات الدور والأقسام بالاعتماد على الجلسة
$role = isset($role) ? $role : $_SESSION['role'];
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';

$message = '';
$error = '';

// معالجة وحفظ مدخلات السجل الميداني أمنياً
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الموحد لتوكن حماية CSRF لمنع طلبات التزوير
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_record') {
        $record_type_id = intval($_POST['record_type_id'] ?? 0);
        $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
        $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
        $user_id = $_SESSION['user_id'];

        if ($role !== 'admin' && !in_array($record_type_id, explode(',', $allowed_types))) {
            die("محاولة تلاعب أمنية محظورة.");
        }

        $stmtFields = $pdo->prepare("SELECT * FROM fields WHERE FIND_IN_SET(?, record_type_id) AND is_active = 1");
        $stmtFields->execute([$record_type_id]);
        $typeFields = $stmtFields->fetchAll();

        $dynamic_values = [];
        $validation_ok = true;

        foreach ($typeFields as $field) {
            $f_name = $field['field_name'];
            if ($field['type'] === 'checkbox') {
                $dynamic_values[$f_name] = isset($_POST[$f_name]) ? 'نعم' : 'لا';
            } else {
                $val = isset($_POST[$f_name]) ? trim((string)$_POST[$f_name]) : '';
                if ($field['is_required'] && empty($val)) {
                    $validation_ok = false;
                    $error = "الحقل ({$field['label']}) إجباري لتأسيس السجل الميداني.";
                    break;
                }
                $dynamic_values[$f_name] = $val;
            }
        }

        // منع التداخل والتكرار الجغرافي للمخالفات في نطاق 30 متراً
        if ($validation_ok && $latitude && $longitude) {
            try {
                $stmt_prox = $pdo->prepare("
                    SELECT id, 
                           ST_Distance_Sphere(geom, POINT(?, ?)) AS distance 
                    FROM records 
                    WHERE record_type_id = ?
                    HAVING distance < 30 
                    LIMIT 1
                ");
                $stmt_prox->execute([$longitude, $latitude, $record_type_id]);
                $prox_record = $stmt_prox->fetch();
                
                if ($prox_record) {
                    $validation_ok = false;
                    $error = "تحذير جيو-ميداني: يوجد سجل معاينة موثق مسبقاً في هذا الموقع الجغرافي الفعلي على مسافة أقل من 30 متراً (رقم السجل: #" . $prox_record['id'] . ")، يرجى مراجعة السجلات لمنع الازدواجية.";
                }
            } catch (PDOException $e) {
                // تجاوز الخطأ واستكمال الحفظ الميداني العادي
            }
        }

        // رفع الصورة
        $photo_path = null;
        if ($validation_ok && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/photos/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
            $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'img_' . uniqid() . '_' . time() . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) { $photo_path = $target_file; }
        }

        // رفع الـ PDF
        $pdf_path = null;
        if ($validation_ok && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir_pdf = 'uploads/pdfs/';
            if (!is_dir($upload_dir_pdf)) { mkdir($upload_dir_pdf, 0755, true); }
            $file_extension = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
            if ($file_extension === 'pdf') {
                $new_filename_pdf = 'doc_' . uniqid() . '_' . time() . '.pdf';
                $target_file_pdf = $upload_dir_pdf . $new_filename_pdf;
                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $target_file_pdf)) { $pdf_path = $target_file_pdf; }
            }
        }

        if ($validation_ok) {
            try {
                $json_data = json_encode($dynamic_values, JSON_UNESCAPED_UNICODE);
                $insert = $pdo->prepare("INSERT INTO records (record_type_id, user_id, latitude, longitude, photo_path, pdf_path, dynamic_values) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insert->execute([$record_type_id, $user_id, $latitude, $longitude, $photo_path, $pdf_path, $json_data]);
                $new_record_id = $pdo->lastInsertId();

                logActivity($pdo, "إنشاء سجل ميداني", "قام المستخدم بإنشاء سجل معاينة جديد برقم #" . $new_record_id . " بالإحداثيات: " . $latitude . "," . $longitude);

                // إرسال الإشعار التلقائي للتليجرام
                $type_label_txt = $pdo->query("SELECT label FROM record_types WHERE id = " . $record_type_id)->fetchColumn();
                $telegram_msg = "🚨 <b>توثيق معاينة ميدانية جديدة</b> 🚨\n\n";
                $telegram_msg .= "<b>رقم السجل:</b> #" . $new_record_id . "\n";
                $telegram_msg .= "<b>القسم الفني:</b> " . $type_label_txt . "\n";
                $telegram_msg .= "<b>الموظف المسؤول:</b> " . $_SESSION['username'] . "\n";
                if ($latitude && $longitude) {
                    $telegram_msg .= "<b>موقع السجل (Google Maps):</b> <a href='https://www.google.com/maps/search/?api=1&query=" . $latitude . "," . $longitude . "'>اضغط للذهاب للموقع بالسيارة</a>\n";
                }
                $telegram_msg .= "\n<i>تم الإرسال آلياً بواسطة GIS MANAGER SECURITY BOT</i>";
                
                sendTelegramNotification($telegram_msg, $photo_path);

                // تحويل الموظف الميداني لطلب GET آمن لمنع تكرار الحفاظ عند تحديث الصفحة F5 مع تفعيل خيارات الطباعة الفورية
                $_SESSION['manage_success_msg'] = "تم حفظ وتوثيق السجل الجديد بنجاح في النظام.";
                safeRedirect("index.php?page=add-record&success=1&last_id=" . $new_record_id);
            } catch (PDOException $e) { $error = "خطأ بقاعدة البيانات: " . $e->getMessage(); }
        }
    }
}

// جلب الأقسام المصرح بها
if ($role === 'admin') {
    $types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
} else {
    $stmtT = $pdo->prepare("SELECT * FROM record_types WHERE FIND_IN_SET(id, ?) ORDER BY id DESC");
    $stmtT->execute([$allowed_types]);
    $types = $stmtT->fetchAll();
}

// جلب الحقول المشتركة والنشطة
$allFields = $pdo->query("SELECT * FROM fields WHERE is_active = 1 ORDER BY group_name, field_order ASC")->fetchAll();
$grouped_fields = [];
foreach ($allFields as $f) {
    $type_ids = explode(',', $f['record_type_id']);
    foreach ($type_ids as $tid) {
        $grouped_fields[$tid][] = $f;
    }
}

// قراءة متغيرات النجاح بعد إعادة التوجيه الآمنة للـ PRG
$success_saved = isset($_GET['success']) && $_GET['success'] == 1;
$last_record_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
?>

<!-- التنبيهات الجمالية لـ SweetAlert (معدلة لتعمل آلياً بعد طلب GET الآمن لتفادي ازدواجية حفظ المستندات) -->
<?php if ($success_saved && $last_record_id > 0): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                icon: 'success',
                title: 'تم حفظ وتوثيق السجل بنجاح',
                text: 'هل تود طباعة المحضر الرسمي لهذا السجل الآن؟',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'نعم، طباعة التقرير فوراً',
                cancelButtonText: 'موافق وإغلاق'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.open('print.php?id=<?php echo $last_record_id; ?>', '_blank');
                }
                // تطهير شريط الرابط البرمجي للمتصفح لمنع تكرار ظهور النافذة عند التحديث الميداني
                window.history.replaceState({}, document.title, "index.php?page=add-record");
            });
        });
    </script>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ في حفظ السجل', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<div class="max-w-6xl mx-auto text-right" dir="rtl">
    <form id="record-form" action="index.php?page=add-record" method="POST" enctype="multipart/form-data" class="space-y-6">
        
        <!-- حقل الأمان CSRF -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="action" value="save_record">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            
            <!-- العمود الأيمن (تعبئة الاستبيان الميداني) -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-105">
                    <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                        <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i class="fa-solid fa-list-check text-md"></i></div>
                        <h3 class="text-sm font-bold text-gray-800">بيانات التأسيس واختيار القسم الميداني</h3>
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-semibold mb-1">اختر نوع السجل الميداني المراد تعبئته</label>
                        <select name="record_type_id" id="record_type_id" required onchange="renderDynamicFields()" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none text-xs font-bold text-gray-700 bg-white">
                            <option value="">-- اختر القسم الميداني --</option>
                            <?php foreach ($types as $type): ?>
                                <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- حاوية الأكورديونات للجروبات -->
                <div id="dynamic-fields-box" class="hidden space-y-4"></div>
            </div>

            <!-- العمود الأيسر (الـ GPS والمرفقات المادية) -->
            <div class="lg:col-span-1 space-y-6 lg:sticky lg:top-4">
                <!-- الـ GPS والالتقاط الجغرافي -->
                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                    <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                        <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-location-crosshairs text-md"></i></div>
                        <h3 class="text-sm font-bold text-gray-800">تحديد الموقع الجغرافي</h3>
                    </div>
                    <button type="button" onclick="getLocation()" class="w-full flex items-center justify-center space-x-2 space-x-reverse bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold py-2 px-3 rounded-lg text-xs transition">
                        <i class="fa-solid fa-satellite-dish"></i>
                        <span id="gps-btn-text" class="text-white">التقاط الإحداثي تلقائياً</span>
                    </button>
                    <div class="space-y-3 mt-4">
                        <div>
                            <span class="text-[10px] text-gray-400 font-bold block mb-1">خط العرض (Latitude)</span>
                            <input type="number" step="any" name="latitude" id="latitude" value="<?php echo $get_lat; ?>" required placeholder="مثال: 30.0444" class="w-full bg-gray-50 px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold text-left focus:outline-none" dir="ltr">
                        </div>
                        <div>
                            <span class="text-[10px] text-gray-400 font-bold block mb-1">خط الطول (Longitude)</span>
                            <input type="number" step="any" name="longitude" id="longitude" value="<?php echo $get_lng; ?>" required placeholder="مثال: 31.2357" class="w-full bg-gray-50 px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold text-left focus:outline-none" dir="ltr">
                        </div>
                    </div>
                </div>

                <!-- المرفقات والملفات الفنية -->
                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                    <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                        <div class="p-2 bg-amber-100 text-amber-600 rounded-lg"><i class="fa-solid fa-paperclip text-md"></i></div>
                        <h3 class="text-sm font-bold text-gray-800">مرفقات ووثائق المعاينة</h3>
                    </div>
                    <div class="space-y-2">
                        <span class="text-xs text-gray-500 block font-bold">1. الصورة الميدانية للمعاينة</span>
                        <input type="file" id="photo-input" accept="image/*" class="hidden" onchange="processAndPreviewImage(event)">
                        <input type="file" name="photo" id="final-photo" class="hidden">
                        <button type="button" onclick="document.getElementById('photo-input').click()" class="w-full flex items-center justify-center space-x-2 bg-gray-50 hover:bg-gray-100 text-gray-700 py-2.5 px-4 rounded-lg border-2 border-dashed border-gray-200 text-xs font-bold">
                            <i class="fa-solid fa-camera"></i>
                            <span>التقاط وصيانة الصورة</span>
                        </button>
                        <div class="flex justify-center mt-1">
                            <div class="w-20 h-20 border border-gray-200 rounded-lg flex items-center justify-center overflow-hidden bg-gray-50" id="preview-box">
                                <i class="fa-regular fa-image text-gray-300 text-lg" id="placeholder-icon"></i>
                                <img id="image-preview" class="hidden w-full h-full object-cover">
                            </div>
                        </div>
                    </div>
                    <hr class="border-gray-100 my-4">
                    <div class="space-y-2">
                        <span class="text-xs text-gray-500 block font-bold">2. مستند PDF رسمي معتمد</span>
                        <input type="file" name="pdf_file" id="pdf_file" accept=".pdf" class="hidden" onchange="updatePdfStatus(event)">
                        <button type="button" onclick="document.getElementById('pdf_file').click()" class="w-full flex items-center justify-center space-x-2 bg-gray-50 hover:bg-gray-100 text-gray-700 py-2.5 px-4 rounded-lg border-2 border-dashed border-gray-200 text-xs font-bold">
                            <i class="fa-solid fa-file-pdf text-red-500"></i>
                            <span>إرفاق ملف PDF المعتمد</span>
                        </button>
                        <div id="pdf-status" class="hidden bg-emerald-50 text-emerald-800 border border-emerald-100 p-2.5 rounded-lg text-center text-[10px] font-bold mt-2">
                            <i class="fa-solid fa-circle-check text-emerald-600"></i> <span id="pdf-filename"></span>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold py-3 px-4 rounded-xl transition shadow-md flex items-center justify-center space-x-2 space-x-reverse text-md">
                    <i class="fa-solid fa-floppy-disk text-white"></i>
                    <span class="text-white">حفظ السجل ميدانياً</span>
                </button>
            </div>

        </div>
    </form>
</div>

<script>
    const groupedFields = <?php echo json_encode($grouped_fields, JSON_UNESCAPED_UNICODE); ?>;
    const isAdmin = <?php echo ($role === 'admin') ? 'true' : 'false'; ?>;

    function renderDynamicFields() {
        const typeId = document.getElementById('record_type_id').value;
        const mainBox = document.getElementById('dynamic-fields-box');

        mainBox.innerHTML = '';

        if (!typeId || !groupedFields[typeId]) {
            mainBox.classList.add('hidden');
            return;
        }

        mainBox.classList.remove('hidden');

        const groups = {};
        groupedFields[typeId].forEach(field => {
            const gName = field.group_name || 'بيانات عامة';
            if (!groups[gName]) { groups[gName] = []; }
            groups[gName].push(field);
        });

        let gIndex = 0;
        for (const [groupTitle, fields] of Object.entries(groups)) {
            gIndex++;
            
            const accordionCard = document.createElement('div');
            accordionCard.className = 'bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden';

            const header = document.createElement('div');
            header.className = 'flex justify-between items-center p-4 bg-gray-50/70 hover:bg-gray-50 cursor-pointer select-none border-b';
            
            const currentGIndex = gIndex;
            header.onclick = function() { toggleAccordion('acc-' + currentGIndex, 'arrow-' + currentGIndex); };

            const titleDiv = document.createElement('div');
            titleDiv.className = 'flex items-center space-x-3 space-x-reverse';
            titleDiv.innerHTML = `
                <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xs font-bold">${gIndex}</span>
                <span class="font-bold text-gray-800 text-sm">${groupTitle}</span>
            `;

            const arrowIcon = document.createElement('i');
            arrowIcon.id = 'arrow-' + gIndex;
            arrowIcon.className = 'fa-solid fa-chevron-up text-xs text-gray-400 transition-transform duration-300';

            header.appendChild(titleDiv);
            header.appendChild(arrowIcon);

            const contentDiv = document.createElement('div');
            contentDiv.id = 'acc-' + gIndex;
            contentDiv.className = 'p-5 grid grid-cols-1 md:grid-cols-2 gap-6';

            fields.forEach(field => {
                const fieldWrapper = document.createElement('div');

                if (field.type === 'checkbox') {
                    fieldWrapper.className = 'flex items-center space-x-3 space-x-reverse p-3.5 bg-gray-50 rounded-lg border border-gray-100 md:col-span-2';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = field.field_name;
                    checkbox.id = 'field_' + field.field_name;
                    checkbox.className = 'w-5 h-5 text-emerald-600 border-gray-300 rounded cursor-pointer dynamic-input';

                    const label = document.createElement('label');
                    label.htmlFor = 'field_' + field.field_name;
                    label.className = 'text-xs text-gray-700 font-bold cursor-pointer select-none';
                    label.innerHTML = field.label + (field.is_required == 1 ? ' <span class="text-red-500">*</span>' : '');

                    fieldWrapper.appendChild(checkbox);
                    fieldWrapper.appendChild(label);
                } else {
                    fieldWrapper.className = 'space-y-1';
                    const label = document.createElement('label');
                    label.className = 'block text-gray-600 text-xs font-semibold';
                    label.innerHTML = field.label + (field.is_required == 1 ? ' <span class="text-red-500">*</span>' : '');
                    fieldWrapper.appendChild(label);

                    let inputElement;
                    if (field.type === 'select') {
                        inputElement = document.createElement('select');
                        inputElement.className = 'w-full px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold text-gray-750 focus:ring-1 focus:ring-emerald-500 focus:outline-none dynamic-input bg-white';
                        const defOpt = document.createElement('option');
                        defOpt.value = '';
                        defOpt.innerText = '-- اختر --';
                        inputElement.appendChild(defOpt);

                        if (field.options) {
                            const opts = field.options.split(',');
                            opts.forEach(opt => {
                                const o = document.createElement('option');
                                o.value = opt.trim();
                                o.innerText = opt.trim();
                                inputElement.appendChild(o);
                            });
                        }
                    } else if (field.type === 'textarea') {
                        inputElement = document.createElement('textarea');
                        inputElement.rows = 2;
                        inputElement.className = 'w-full px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold focus:ring-1 focus:ring-emerald-500 focus:outline-none dynamic-input';
                        fieldWrapper.className = 'space-y-1 md:col-span-2'; 
                    } else {
                        inputElement = document.createElement('input');
                        inputElement.type = field.type;
                        inputElement.className = 'w-full px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold focus:ring-1 focus:ring-emerald-500 focus:outline-none dynamic-input';
                    }

                    inputElement.name = field.field_name;
                    if (field.is_required == 1) { inputElement.required = true; }
                    fieldWrapper.appendChild(inputElement);
                }

                contentDiv.appendChild(fieldWrapper);
            });

            accordionCard.appendChild(header);
            accordionCard.appendChild(contentDiv);
            mainBox.appendChild(accordionCard);
        }
    }

    function toggleAccordion(id, arrowId) {
        document.getElementById(id).classList.toggle('hidden');
        document.getElementById(arrowId).classList.toggle('rotate-180');
    }

    function getLocation() {
        const btnText = document.getElementById('gps-btn-text');
        if (navigator.geolocation) {
            btnText.innerText = "جاري جلب الإحداثيات...";
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude.toFixed(8);
                    const lng = position.coords.longitude.toFixed(8);
                    document.getElementById('latitude').value = lat;
                    document.getElementById('longitude').value = lng;
                    btnText.innerText = "تم الالتقاط بنجاح";
                    Swal.fire({ icon: 'success', title: 'تم التحديد', text: 'تم التقاط الموقع بنجاح.', timer: 1200, showConfirmButton: false });
                    
                    checkGeofenceBoundary(lat, lng);
                },
                function() {
                    btnText.innerText = "فشل الالتقاط";
                    Swal.fire({ icon: 'error', title: 'خطأ', text: 'يرجى تفعيل الـ GPS.' });
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }
    }

    document.getElementById('latitude').addEventListener('change', triggerManualGeofenceCheck);
    document.getElementById('longitude').addEventListener('change', triggerManualGeofenceCheck);

    function triggerManualGeofenceCheck() {
        const lat = document.getElementById('latitude').value;
        const lng = document.getElementById('longitude').value;
        if (lat && lng) {
            checkGeofenceBoundary(lat, lng);
        }
    }

    function checkGeofenceBoundary(lat, lng) {
        fetch(`index.php?page=add-record&action=check_geofence&lat=${lat}&lng=${lng}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const detectedArea = data.boundary_name;
                    const sheikhaSelect = document.querySelector('select[name="sheikha"]');
                    
                    if (sheikhaSelect) {
                        if (isAdmin) {
                            Swal.fire({
                                title: 'رصد حدود جغرافي تلقائي',
                                text: `تم رصد إحداثيات المعاينة الميدانية داخل حدود: (${detectedArea}). هل تود اعتماد هذا الحي وتعبئته تلقائياً بالاستمارة؟`,
                                icon: 'info',
                                showCancelButton: true,
                                confirmButtonColor: '#10b981',
                                cancelButtonColor: '#6b7280',
                                confirmButtonText: 'نعم، اعتماد وتعبئة',
                                cancelButtonText: 'لا، سأختار يدوياً'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    selectOptionByText(sheikhaSelect, detectedArea);
                                }
                            });
                        } else {
                            selectOptionByText(sheikhaSelect, detectedArea);
                            sheikhaSelect.style.pointerEvents = 'none'; 
                            sheikhaSelect.style.backgroundColor = '#f3f4f6'; 
                        }
                    }
                }
            })
            .catch(err => console.error("Geofence Check Error:", err));
    }

    function selectOptionByText(selectElement, text) {
        for (let i = 0; i < selectElement.options.length; i++) {
            const optText = selectElement.options[i].text.trim();
            if (optText.includes(text) || text.includes(optText)) {
                selectElement.selectedIndex = i;
                selectElement.dispatchEvent(new Event('change')); 
                break;
            }
        }
    }

    function processAndPreviewImage(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = function(event) {
            const img = new Image();
            img.src = event.target.result;
            img.onload = function() {
                const max_width = 1200; const max_height = 1200;
                let width = img.width; let height = img.height;
                if (width > height) { if (width > max_width) { height *= max_width / width; width = max_width; } }
                else { if (height > max_height) { width *= max_height / height; height = max_height; } }

                const canvas = document.createElement('canvas');
                canvas.width = width; canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                canvas.toBlob(function(blob) {
                    const compressedFile = new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() });
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(compressedFile);
                    document.getElementById('final-photo').files = dataTransfer.files;

                    const previewImg = document.getElementById('image-preview');
                    const placeholderIcon = document.getElementById('placeholder-icon');
                    previewImg.src = URL.createObjectURL(compressedFile);
                    previewImg.classList.remove('hidden');
                    placeholderIcon.classList.add('hidden');
                }, 'image/jpeg', 0.8);
            };
        };
    }

    function updatePdfStatus(event) {
        const file = event.target.files[0];
        const statusBox = document.getElementById('pdf-status');
        const filenameSpan = document.getElementById('pdf-filename');
        if (file && file.type === "application/pdf") {
            filenameSpan.innerText = file.name;
            statusBox.classList.remove('hidden');
        } else {
            Swal.fire({ icon: 'error', title: 'خطأ', text: 'يرجى رفع ملف بصيغة PDF فقط.' });
            event.target.value = '';
            statusBox.classList.add('hidden');
        }
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function(registrations) {
            for (let registration of registrations) {
                registration.unregister().then(function(boolean) {
                    if (boolean) {
                        console.log('Successfully unregistered old offline Service Worker.');
                    }
                });
            }
        }).catch(function(err) {
            console.warn('Service Worker unregistration failed: ', err);
        });
    }
</script>