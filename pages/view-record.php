<?php
// حماية الملف من الوصول المباشر
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$record_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$role = $_SESSION['role'];
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';

if ($record_id <= 0) {
    echo "<div class='bg-red-100 p-4 rounded-xl text-red-700 text-center font-bold'>عذراً، رقم السجل المدخل غير صحيح.</div>";
    return;
}

try {
    // 1. جلب السجل مع بيانات القسم والمستخدم
    $stmt = $pdo->prepare("
        SELECT r.*, rt.label AS type_label, rt.id AS type_id, u.username 
        FROM records r
        JOIN record_types rt ON r.record_type_id = rt.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$record_id]);
    $record = $stmt->fetch();

    if (!$record) {
        echo "<div class='bg-red-100 p-4 rounded-xl text-red-700 text-center font-bold'>عذراً، السجل المطلوب غير موجود.</div>";
        return;
    }

    // [صمام الأمان والتحقق الحصري]: حظر وطرد أي مستخدم عادي يحاول قراءة سجل ينتمي لقسم محظور عنه أمنياً
    if ($role !== 'admin') {
        $allowed_ids = explode(',', $allowed_types);
        if (!in_array($record['type_id'], $allowed_ids)) {
            echo "<div class='bg-red-100 p-6 rounded-2xl text-red-700 text-center font-bold flex flex-col items-center justify-center space-y-2 max-w-md mx-auto my-12 border border-red-200 shadow-sm'>
                    <i class='fa-solid fa-shield-halved text-4xl text-red-600 animate-pulse mb-2'></i>
                    <span class='text-sm'>خطأ أمني: عذراً، حسابك الشخصي غير مصرح له بالوصول أو قراءة هذا السجل الميداني الحساس.</span>
                  </div>";
            return; // إيقاف التحميل فوراً
        }
    }

    // 2. جلب مسميات الحقول المشتركة والنشطة المربوطة بالقسم الحالي
    $stmtFields = $pdo->prepare("
        SELECT field_name, label 
        FROM fields 
        WHERE FIND_IN_SET(?, record_type_id) AND is_active = 1
    ");
    $stmtFields->execute([$record['type_id']]);
    $fieldsList = $stmtFields->fetchAll(PDO::FETCH_KEY_PAIR); // [field_name => label]

    $dynamic_values = json_decode($record['dynamic_values'], true) ?: [];

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}
?>

<div class="max-w-5xl mx-auto space-y-6 animate-fade">
    <!-- شريط الإجراءات العلوي -->
    <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm border border-gray-100">
        <a href="index.php?page=records-manage" class="flex items-center space-x-2 space-x-reverse text-gray-600 hover:text-blue-600 font-semibold transition">
            <i class="fa-solid fa-arrow-right"></i>
            <span>العودة لإدارة السجلات الميدانية</span>
        </a>
        <button type="button" onclick="openPrintWizardDirect(<?php echo $record_id; ?>)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold flex items-center space-x-2 space-x-reverse transition shadow-sm">
            <i class="fa-solid fa-print"></i>
            <span>طباعة هذا السجل</span>
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- العمود الأيمن (معلومات السجل الجغرافي والمرفقات) -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                <div class="flex items-center space-x-2 space-x-reverse mb-4 border-b pb-2">
                    <i class="fa-solid fa-map-pin text-blue-600 text-lg"></i>
                    <h3 class="font-bold text-gray-800">الموقع الجغرافي</h3>
                </div>

                <?php if ($record['latitude'] && $record['longitude']): ?>
                    <div id="mini-map" class="h-48 rounded-lg border border-gray-100 mb-4"></div>
                    <div class="text-xs space-y-1 bg-gray-50 p-3 rounded-lg mb-4 text-left font-mono" dir="ltr">
                        <div>Lat: <?php echo htmlspecialchars($record['latitude']); ?></div>
                        <div>Lng: <?php echo htmlspecialchars($record['longitude']); ?></div>
                    </div>
                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $record['latitude']; ?>,<?php echo $record['longitude']; ?>" target="_blank" class="w-full flex items-center justify-center space-x-2 space-x-reverse bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-lg transition text-sm shadow-sm">
                        <i class="fa-solid fa-diamond-turn-right"></i>
                        <span>خرائط Google Maps</span>
                    </a>
                <?php else: ?>
                    <p class="text-gray-400 text-sm text-center py-4">لم يتم التقاط موقع جغرافي لهذا السجل.</p>
                <?php endif; ?>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                <div class="flex items-center space-x-2 space-x-reverse mb-4 border-b pb-2">
                    <i class="fa-solid fa-folder-open text-amber-600 text-lg"></i>
                    <h3 class="font-bold text-gray-800">الملفات المرفقة</h3>
                </div>

                <div class="space-y-4">
                    <?php if ($record['photo_path']): ?>
                        <div class="border rounded-lg overflow-hidden bg-gray-50 group relative">
                            <img src="<?php echo htmlspecialchars($record['photo_path']); ?>" class="w-full h-auto object-cover max-h-48">
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center">
                                <a href="<?php echo htmlspecialchars($record['photo_path']); ?>" target="_blank" class="bg-white text-gray-800 px-3 py-1.5 rounded-lg text-xs font-semibold">فتح الصورة</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 border border-dashed rounded-lg text-gray-400 text-xs">لا توجد صورة ميدانية مرفقة</div>
                    <?php endif; ?>

                    <?php if ($record['pdf_path']): ?>
                        <a href="<?php echo htmlspecialchars($record['pdf_path']); ?>" target="_blank" class="w-full flex items-center justify-center space-x-2 space-x-reverse bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 font-semibold py-2.5 px-4 rounded-lg transition text-sm">
                            <i class="fa-solid fa-file-pdf text-lg"></i>
                            <span>فتح مستند الـ PDF</span>
                        </a>
                    <?php else: ?>
                        <div class="text-center py-4 border border-dashed rounded-lg text-gray-400 text-xs">لا يوجد ملف PDF مرفق</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- العمود الأيسر (البيانات والمدخلات الفنية) -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                <div class="flex justify-between items-center mb-6 border-b pb-3">
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-clipboard-list text-xl"></i></div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">بيانات السجل الفنية</h3>
                            <span class="text-xs text-blue-600 font-semibold bg-blue-50 px-2 py-0.5 rounded"><?php echo htmlspecialchars($record['type_label']); ?></span>
                        </div>
                    </div>
                    <div class="text-left">
                        <span class="text-xs text-gray-400 block">تاريخ التوثيق</span>
                        <span class="text-sm font-semibold text-gray-600"><?php echo date('Y-m-d H:i', strtotime($record['created_at'])); ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
                        <span class="text-xs text-gray-400 block mb-1">الموظف المسؤول</span>
                        <span class="text-sm font-bold text-gray-700"><i class="fa-regular fa-user ml-1 text-gray-400"></i> <?php echo htmlspecialchars($record['username']); ?></span>
                    </div>

                    <?php foreach ($dynamic_values as $key => $value): ?>
                        <?php if (isset($fieldsList[$key])): ?>
                            <div class="bg-gray-50 p-4 rounded-xl border border-gray-100">
                                <span class="text-xs text-gray-400 block mb-1"><?php echo htmlspecialchars($fieldsList[$key]); ?></span>
                                <span class="text-sm font-bold text-gray-700">
                                    <?php if ($value === 'نعم'): ?>
                                        <span class="text-emerald-600"><i class="fa-solid fa-circle-check ml-1"></i> نعم</span>
                                    <?php elseif ($value === 'لا'): ?>
                                        <span class="text-gray-400"><i class="fa-solid fa-circle-xmark ml-1"></i> لا</span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($value); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php if ($record['latitude'] && $record['longitude']): ?>
<script>
    // تمرير قائمة القوالب لـ JS في صفحة العرض أيضاً
    const printTemplates = <?php echo json_encode($pdo->query("SELECT id, template_name FROM print_templates ORDER BY id DESC")->fetchAll(), JSON_UNESCAPED_UNICODE); ?>;

    document.addEventListener("DOMContentLoaded", function() {
        var lat = <?php echo $record['latitude']; ?>;
        var lng = <?php echo $record['longitude']; ?>;
        
        var miniMap = L.map('mini-map', { zoomControl: false, attributionControl: false }).setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(miniMap);
        
        var myIcon = L.divIcon({
            html: '<i class="fa-solid fa-location-dot text-3xl" style="color: <?php echo $record['color'] ?: '#3085d6'; ?>;"></i>',
            iconSize: [30, 42],
            iconAnchor: [15, 42]
        });
        L.marker([lat, lng], {icon: myIcon}).addTo(miniMap);
    });

    function openPrintWizardDirect(recordId) {
        if (printTemplates.length === 0) {
            window.open('print.php?id=' + recordId, '_blank');
            return;
        }

        let optionsHTML = '';
        printTemplates.forEach(t => {
            optionsHTML += `<option value="${t.id}">${t.template_name}</option>`;
        });

        Swal.fire({
            title: 'اختر نموذج وقالب الطباعة',
            html: `<p class="text-xs text-gray-400 mb-2">حدد النموذج الإداري المعتمر لطباعة محضر هذا السجل:</p>
                   <select id="swal-template-id" class="w-full px-3 py-2 border rounded-lg text-xs font-bold text-gray-700 bg-white focus:outline-none font-sans">${optionsHTML}</select>`,
            showCancelButton: true,
            confirmButtonText: 'بدء الطباعة الرسمية',
            cancelButtonText: 'إلغاء وتراجع',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) {
                const templateId = document.getElementById('swal-template-id').value;
                window.open(`print.php?id=${recordId}&template_id=${templateId}`, '_blank');
            }
        });
    }
</script>
<?php endif; ?>