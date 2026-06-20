<?php
// pages/edit-record.php - تعديل السجل الميداني (النسخة النهائية الرشيقة والمؤمنة بالكامل لبيئات PHP 8.4)
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$record_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$role = $_SESSION['role'];
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';

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

if ($record_id <= 0) {
    echo "<div class='bg-red-100 p-4 rounded-xl text-red-700 text-center font-bold'>عذراً، رقم السجل غير صحيح.</div>";
    return;
}

try {
    // 1. جلب السجل للتعديل
    $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ?");
    $stmt->execute([$record_id]);
    $record = $stmt->fetch();

    if (!$record) {
        echo "<div class='bg-red-100 p-4 rounded-xl text-red-700 text-center font-bold'>عذراً، السجل المطلوب غير موجود.</div>";
        return;
    }

    // [صمام الأمان والتحقق الحصري]: حظر وطرد أي مستخدم عادي يحاول تعديل سجل ينتمي لقسم محظور عنه أمنياً
    if ($role !== 'admin') {
        $allowed_ids = explode(',', $allowed_types);
        if (!in_array($record['record_type_id'], $allowed_ids)) {
            echo "<div class='bg-red-100 p-6 rounded-2xl text-red-700 text-center font-bold flex flex-col items-center justify-center space-y-2 max-w-md mx-auto my-12 border border-red-200 shadow-sm'>
                    <i class='fa-solid fa-shield-halved text-4xl text-red-600 animate-pulse mb-2'></i>
                    <span class='text-sm'>خطأ أمني: عذراً، حسابك الشخصي غير مصرح له بتعديل هذا السجل الميداني الحساس.</span>
                  </div>";
            return; 
        }
    }

    // 2. جلب الحقول المشتركة والنشطة فقط المربوطة بالقسم للتعديل (مع تطبيق FIND_IN_SET)
    $stmtFields = $pdo->prepare("
        SELECT * 
        FROM fields 
        WHERE FIND_IN_SET(?, record_type_id) AND is_active = 1 
        ORDER BY group_name, field_order ASC
    ");
    $stmtFields->execute([$record['record_type_id']]);
    $typeFields = $stmtFields->fetchAll();

    $dynamic_values = json_decode($record['dynamic_values'], true) ?: [];

} catch (PDOException $e) { 
    die("خطأ قاعدة البيانات: " . $e->getMessage()); 
}

// 3. معالجة تحديث السجل وحفظ التعديلات الجديدة تحت حماية CSRF المزدوجة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الإجباري من توكن حماية CSRF لحماية تعديل المعاينات
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_record') {
        $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
        $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;

        $updated_dynamic_values = [];
        $validation_ok = true;

        foreach ($typeFields as $field) {
            $f_name = $field['field_name'];
            if ($field['type'] === 'checkbox') {
                $updated_dynamic_values[$f_name] = isset($_POST[$f_name]) ? 'نعم' : 'لا';
            } else {
                $val = isset($_POST[$f_name]) ? trim((string)$_POST[$f_name]) : '';
                if ($field['is_required'] && empty($val)) {
                    $validation_ok = false;
                    $error = "الحقل ({$field['label']}) إجباري الإدخال لاستكمال التعديل الميداني.";
                    break;
                }
                $updated_dynamic_values[$f_name] = $val;
            }
        }

        // الصورة الميدانية
        $photo_path = $record['photo_path'];
        if ($validation_ok && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/photos/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
            if ($photo_path && file_exists($photo_path)) { unlink($photo_path); }

            $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'img_' . uniqid() . '_' . time() . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) { $photo_path = $target_file; }
        }

        // PDF المعتمد
        $pdf_path = $record['pdf_path'];
        if ($validation_ok && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir_pdf = 'uploads/pdfs/';
            if (!is_dir($upload_dir_pdf)) { mkdir($upload_dir_pdf, 0755, true); }
            if ($pdf_path && file_exists($pdf_path)) { unlink($pdf_path); }

            $file_extension = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
            if ($file_extension === 'pdf') {
                $new_filename_pdf = 'doc_' . uniqid() . '_' . time() . '.pdf';
                $target_file_pdf = $upload_dir_pdf . $new_filename_pdf;
                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $target_file_pdf)) { $pdf_path = $target_file_pdf; }
            }
        }

        if ($validation_ok) {
            try {
                $json_data = json_encode($updated_dynamic_values, JSON_UNESCAPED_UNICODE);
                $update = $pdo->prepare("UPDATE records SET latitude = ?, longitude = ?, photo_path = ?, pdf_path = ?, dynamic_values = ? WHERE id = ?");
                $update->execute([$latitude, $longitude, $photo_path, $pdf_path, $json_data, $record_id]);
                
                // تسجيل التعديل في سجل الرقابة والأنشطة
                logActivity($pdo, "تعديل سجل ميداني", "قام المستخدم بتعديل بيانات السجل رقم #" . $record_id);

                $_SESSION['edit_record_success_msg'] = "تم حفظ وتعديل بيانات المعاينة بنجاح في النظام الميداني.";
            } catch (PDOException $e) { 
                $_SESSION['edit_record_error_msg'] = "حدث خطأ أثناء حفظ وتعديل المعاينة."; 
            }
        }
        
        // التحويل الفوري الآمن بعد الـ POST لمنع سقوط التنسيقات عند الحفظ والتحديث
        safeRedirect("index.php?page=edit-record&id=" . $record_id);
    }
}

// قراءة رسائل الجلسة الأمنية لصفحة التعديل
if (isset($_SESSION['edit_record_success_msg'])) { $message = $_SESSION['edit_record_success_msg']; unset($_SESSION['edit_record_success_msg']); }
if (isset($_SESSION['edit_record_error_msg'])) { $error = $_SESSION['edit_record_error_msg']; unset($_SESSION['edit_record_error_msg']); }

// تجميع الحقول للمجموعات بالأكورديونات
$grouped_fields = [];
foreach ($typeFields as $f) {
    $grouped_fields[$f['group_name']][] = $f;
}
?>

<!-- التنبيهات بـ SweetAlert -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تم التعديل', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'رائع' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ في التعديل', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<div class="max-w-5xl mx-auto space-y-6 animate-fade text-right" dir="rtl">
    <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm border border-gray-100">
        <a href="index.php?page=records-manage" class="flex items-center space-x-2 space-x-reverse text-gray-600 hover:text-blue-600 font-semibold transition">
            <i class="fa-solid fa-arrow-right"></i>
            <span>العودة لجداول الإدارة الميدانية</span>
        </a>
        <h3 class="font-bold text-gray-800 text-sm">تعديل وتحديث بيانات المعاينة رقم #<?php echo $record_id; ?></h3>
    </div>

    <form id="record-form" action="index.php?page=edit-record&id=<?php echo $record_id; ?>" method="POST" enctype="multipart/form-data" class="space-y-6">
        
        <!-- حقل الأمان CSRF -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="action" value="update_record">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            
            <!-- اليمين (الحقول الفنية بالأكورديونات) -->
            <div class="lg:col-span-2 space-y-4">
                <?php if (count($grouped_fields) === 0): ?>
                    <div class="bg-white p-6 rounded-xl text-center text-gray-400 font-bold">لا توجد حقول فنية مخصصة لهذا السجل لتعديلها.</div>
                <?php else: ?>
                    <?php 
                    $gIndex = 0;
                    foreach ($grouped_fields as $groupTitle => $fields): 
                        $gIndex++;
                    ?>
                        <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
                            <div class="flex justify-between items-center p-4 bg-gray-50/70 hover:bg-gray-50 cursor-pointer border-b" onclick="toggleAcc('acc-<?php echo $gIndex; ?>', 'arrow-<?php echo $gIndex; ?>')">
                                <div class="flex items-center space-x-3 space-x-reverse">
                                    <span class="w-6 h-6 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center text-xs font-bold"><?php echo $gIndex; ?></span>
                                    <span class="font-bold text-gray-800 text-sm"><?php echo htmlspecialchars($groupTitle); ?></span>
                                </div>
                                <i id="arrow-<?php echo $gIndex; ?>" class="fa-solid fa-chevron-up text-xs text-gray-400 transition-transform duration-300"></i>
                            </div>
                            
                            <div id="acc-<?php echo $gIndex; ?>" class="p-5 grid grid-cols-1 md:grid-cols-2 gap-6">
                                <?php foreach ($fields as $field): 
                                    $old_value = isset($dynamic_values[$field['field_name']]) ? $dynamic_values[$field['field_name']] : '';
                                ?>
                                    <div class="<?php echo $field['type'] === 'textarea' || $field['type'] === 'checkbox' ? 'md:col-span-2' : ''; ?> space-y-1">
                                        <?php if ($field['type'] === 'checkbox'): ?>
                                            <div class="flex items-center space-x-3 space-x-reverse p-3 bg-gray-50 rounded-lg border cursor-pointer select-none">
                                                <input type="checkbox" name="<?php echo $field['field_name']; ?>" id="f_<?php echo $field['field_name']; ?>" value="1" <?php echo $old_value === 'نعم' ? 'checked' : ''; ?> class="w-5 h-5 text-emerald-600 border-gray-300 rounded cursor-pointer">
                                                <label for="f_<?php echo $field['field_name']; ?>" class="text-xs text-gray-700 font-bold cursor-pointer"><?php echo htmlspecialchars($field['label']); ?></label>
                                            </div>
                                        <?php else: ?>
                                            <label class="block text-gray-600 text-xs font-semibold"><?php echo htmlspecialchars($field['label']); ?> <?php echo $field['is_required'] ? '<span class="text-red-500">*</span>' : ''; ?></label>
                                            
                                            <?php if ($field['type'] === 'select'): ?>
                                                <select name="<?php echo $field['field_name']; ?>" <?php echo $field['is_required'] ? 'required' : ''; ?> class="w-full px-3 py-1.5 border rounded-lg text-xs font-bold text-gray-700 bg-white">
                                                    <option value="">-- اختر --</option>
                                                    <?php 
                                                    $opts = explode(',', $field['options']);
                                                    foreach ($opts as $opt): 
                                                        $opt = trim($opt);
                                                    ?>
                                                        <option value="<?php echo $opt; ?>" <?php echo $old_value === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php elseif ($field['type'] === 'textarea'): ?>
                                                <textarea name="<?php echo $field['field_name']; ?>" rows="2" <?php echo $field['is_required'] ? 'required' : ''; ?> class="w-full px-3 py-1.5 border rounded-lg text-xs font-bold"><?php echo htmlspecialchars($old_value); ?></textarea>
                                            <?php else: ?>
                                                <input type="<?php echo $field['type']; ?>" name="<?php echo $field['field_name']; ?>" value="<?php echo htmlspecialchars($old_value); ?>" <?php echo $field['is_required'] ? 'required' : ''; ?> class="w-full px-3 py-1.5 border rounded-lg text-xs font-bold text-slate-800">
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- اليسار (تعديل الموقع الجغرافي والمرفقات المادية) -->
            <div class="lg:col-span-1 space-y-6">
                <!-- تعديل الإحداثيات الجغرافية -->
                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                    <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                        <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-location-crosshairs text-md"></i></div>
                        <h3 class="text-sm font-bold text-gray-800">تعديل الإحداثيات الجغرافية</h3>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <span class="text-[10px] text-gray-400 font-bold block mb-1">خط العرض (Latitude)</span>
                            <input type="number" step="any" name="latitude" id="latitude" value="<?php echo $record['latitude']; ?>" required class="w-full bg-gray-50 px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold text-left focus:outline-none" dir="ltr">
                        </div>
                        <div>
                            <span class="text-[10px] text-gray-400 font-bold block mb-1">خط الطول (Longitude)</span>
                            <input type="number" step="any" name="longitude" id="longitude" value="<?php echo $record['longitude']; ?>" required class="w-full bg-gray-50 px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold text-left focus:outline-none" dir="ltr">
                        </div>
                    </div>
                </div>

                <!-- تحديث ومراجعة المرفقات والملفات الفنية -->
                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                    <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                        <div class="p-2 bg-amber-100 text-amber-600 rounded-lg"><i class="fa-solid fa-paperclip text-md"></i></div>
                        <h3 class="text-sm font-bold text-gray-800">تحديث المرفقات</h3>
                    </div>

                    <div class="space-y-2">
                        <span class="text-xs text-gray-500 block font-bold">1. الصورة الميدانية للمعاينة</span>
                        <input type="file" id="photo-input" accept="image/*" class="hidden" onchange="processAndPreviewImage(event)">
                        <input type="file" name="photo" id="final-photo" class="hidden">
                        <button type="button" onclick="document.getElementById('photo-input').click()" class="w-full flex items-center justify-center space-x-2 bg-gray-50 hover:bg-gray-100 text-gray-700 py-2 px-4 rounded-lg border-2 border-dashed border-gray-200 text-xs font-bold">
                            <i class="fa-solid fa-camera"></i>
                            <span>تغيير الصورة الملتقطة</span>
                        </button>
                        <div class="flex justify-center mt-1">
                            <div class="w-20 h-20 border border-gray-200 rounded-lg flex items-center justify-center overflow-hidden bg-gray-50">
                                <?php if ($record['photo_path']): ?>
                                    <img id="image-preview" src="<?php echo $record['photo_path']; ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <i class="fa-regular fa-image text-gray-300 text-lg" id="placeholder-icon"></i>
                                    <img id="image-preview" class="hidden w-full h-full object-cover">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <hr class="border-gray-100 my-4">

                    <div class="space-y-2">
                        <span class="text-xs text-gray-500 block font-bold">2. ملف الـ PDF المعتمد</span>
                        <input type="file" name="pdf_file" id="pdf_file" accept=".pdf" class="hidden" onchange="updatePdfStatus(event)">
                        <button type="button" onclick="document.getElementById('pdf_file').click()" class="w-full flex items-center justify-center space-x-2 bg-gray-50 hover:bg-gray-100 text-gray-700 py-2 px-4 rounded-lg border-2 border-dashed border-gray-200 text-xs font-bold">
                            <i class="fa-solid fa-file-pdf text-red-500"></i>
                            <span>تغيير أو إرفاق ملف PDF جديد</span>
                        </button>
                        <div id="pdf-status" class="<?php echo $record['pdf_path'] ? '' : 'hidden'; ?> bg-emerald-50 text-emerald-800 border p-2 rounded-lg text-center text-[10px] font-semibold mt-2">
                            <i class="fa-solid fa-circle-check text-emerald-600"></i> 
                            <span id="pdf-filename"><?php echo $record['pdf_path'] ? 'ملف الـ PDF مرفق ومثبت' : ''; ?></span>
                        </div>
                    </div>
                </div>

                <!-- حفظ وتثبيت التعديلات -->
                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded-xl transition duration-200 shadow-md flex items-center justify-center space-x-2 space-x-reverse text-md">
                    <i class="fa-solid fa-floppy-disk text-white"></i>
                    <span class="text-white">حفظ وتحديث التغييرات</span>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    function toggleAcc(id, arrowId) {
        document.getElementById(id).classList.toggle('hidden');
        document.getElementById(arrowId).classList.toggle('rotate-180');
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
                    previewImg.src = URL.createObjectURL(compressedFile);
                    previewImg.classList.remove('hidden');
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
</script>