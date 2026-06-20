<?php
// pages/view-record.php - استعراض تفاصيل السجل الميداني الجغرافي (النسخة النهائية المتزنة والمؤمنة بالكامل)
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
        SELECT r.*, rt.label AS type_label, rt.id AS type_id, rt.color, u.username 
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

    // 3. جلب الحدود الجغرافية المعتمدة لعرضها على الخريطة المصغرة ديناميكياً
    $boundaries = $pdo->query("SELECT name, kml_data, color FROM boundaries")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}
?>

<!-- استدعاء ملفات مكتبة الخرائط Leaflet لضمان عمل الخريطة بالشكل الصحيح -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="max-w-5xl mx-auto space-y-6 animate-fade text-right" dir="rtl">
    <!-- شريط الإجراءات العلوي -->
    <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm border border-gray-100">
        <a href="index.php?page=records-manage" class="flex items-center space-x-2 space-x-reverse text-gray-650 hover:text-blue-600 font-semibold transition">
            <i class="fa-solid fa-arrow-right"></i>
            <span>العودة لإدارة السجلات الميدانية</span>
        </a>
        <button type="button" onclick="openPrintWizardDirect(<?php echo $record_id; ?>)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold flex items-center space-x-2 space-x-reverse transition shadow-sm">
            <i class="fa-solid fa-print"></i>
            <span>طباعة هذا السجل</span>
        </button>
    </div>

    <!-- شبكة التخطيط الرئيسية المتزنة RTL (البيانات يميناً، والمرفقات الجغرافية يساراً) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- العمود الأيمن (البيانات والمدخلات الفنية بالخط الأسود العريض والواضح) - يأخذ مساحة 2/3 يميناً -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                <div class="flex justify-between items-center mb-6 border-b pb-3 border-gray-100">
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i class="fa-solid fa-clipboard-list text-xl"></i></div>
                        <div>
                            <h3 class="text-lg font-extrabold text-gray-850">بيانات السجل الفنية</h3>
                            <span class="text-xs text-blue-700 font-bold bg-blue-50 px-2 py-0.5 rounded border border-blue-100"><?php echo htmlspecialchars($record['type_label']); ?></span>
                        </div>
                    </div>
                    <div class="text-left">
                        <span class="text-xs text-slate-500 font-bold block">تاريخ التوثيق</span>
                        <span class="text-sm font-black text-slate-800"><?php echo date('Y-m-d H:i', strtotime($record['created_at'])); ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-gray-50/80 p-4 rounded-xl border border-gray-200/60 shadow-sm">
                        <span class="text-xs text-slate-500 font-bold block mb-1">الموظف المسؤول</span>
                        <span class="text-sm font-black text-slate-900"><i class="fa-regular fa-user ml-1 text-slate-500"></i> <?php echo htmlspecialchars($record['username']); ?></span>
                    </div>

                    <?php foreach ($dynamic_values as $key => $value): ?>
                        <?php if (isset($fieldsList[$key])): ?>
                            <div class="bg-gray-50/80 p-4 rounded-xl border border-gray-200/60 shadow-sm">
                                <span class="text-xs text-slate-500 font-bold block mb-1"><?php echo htmlspecialchars($fieldsList[$key]); ?></span>
                                <span class="text-sm font-black text-slate-900">
                                    <?php if ($value === 'نعم'): ?>
                                        <span class="text-emerald-700 font-black"><i class="fa-solid fa-circle-check ml-1 text-emerald-600"></i> نعم</span>
                                    <?php elseif ($value === 'لا'): ?>
                                        <span class="text-red-700 font-black"><i class="fa-solid fa-circle-xmark ml-1 text-red-500"></i> لا</span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars((string)$value); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- العمود الأيسر (معلومات السجل الجغرافي والمرفقات) - يأخذ مساحة 1/3 يساراً -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                <div class="flex items-center space-x-2 space-x-reverse mb-4 border-b pb-2 border-gray-100">
                    <i class="fa-solid fa-map-pin text-blue-600 text-lg"></i>
                    <h3 class="font-extrabold text-gray-850">الموقع الجغرافي</h3>
                </div>

                <?php if ($record['latitude'] && $record['longitude']): ?>
                    <div id="mini-map" class="h-56 rounded-lg border border-gray-200 mb-4 z-10"></div>
                    <div class="text-xs space-y-1 bg-gray-550/10 p-3 rounded-lg mb-4 text-left font-mono font-bold text-slate-800" dir="ltr">
                        <div>Lat: <?php echo htmlspecialchars($record['latitude']); ?></div>
                        <div>Lng: <?php echo htmlspecialchars($record['longitude']); ?></div>
                    </div>
                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $record['latitude']; ?>,<?php echo $record['longitude']; ?>" target="_blank" class="w-full flex items-center justify-center space-x-2 space-x-reverse bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg transition text-sm shadow-sm">
                        <i class="fa-solid fa-diamond-turn-right text-white"></i>
                        <span class="text-white">خرائط Google Maps</span>
                    </a>
                <?php else: ?>
                    <p class="text-gray-400 text-sm text-center py-4">لم يتم التقاط موقع جغرافي لهذا السجل.</p>
                <?php endif; ?>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100">
                <div class="flex items-center space-x-2 space-x-reverse mb-4 border-b pb-2 border-gray-100">
                    <i class="fa-solid fa-folder-open text-amber-600 text-lg"></i>
                    <h3 class="font-extrabold text-gray-850">الملفات المرفقة</h3>
                </div>

                <div class="space-y-4">
                    <?php if ($record['photo_path']): ?>
                        <div class="border rounded-lg overflow-hidden bg-gray-50 group relative">
                            <img src="<?php echo htmlspecialchars($record['photo_path']); ?>" class="w-full h-auto object-cover max-h-48">
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center">
                                <a href="<?php echo htmlspecialchars($record['photo_path']); ?>" target="_blank" class="bg-white text-gray-800 px-3 py-1.5 rounded-lg text-xs font-bold">فتح الصورة المعاينة</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 border border-dashed rounded-lg text-gray-400 text-xs font-bold">لا توجد صورة ميدانية مرفقة</div>
                    <?php endif; ?>

                    <?php if ($record['pdf_path']): ?>
                        <a href="<?php echo htmlspecialchars($record['pdf_path']); ?>" target="_blank" class="w-full flex items-center justify-center space-x-2 space-x-reverse bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 font-bold py-2.5 px-4 rounded-lg transition text-sm">
                            <i class="fa-solid fa-file-pdf text-lg"></i>
                            <span>فتح مستند الـ PDF</span>
                        </a>
                    <?php else: ?>
                        <div class="text-center py-4 border border-dashed rounded-lg text-gray-400 text-xs font-bold">لا يوجد ملف PDF مرفق</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- سكريبتات التحكم بالخريطة والحدود ومعالج الطباعة -->
<script>
    // تمرير قائمة القوالب لـ JS للتمكن من اختيار قالب الطباعة
    const printTemplates = <?php echo json_encode($pdo->query("SELECT id, template_name FROM print_templates ORDER BY id DESC")->fetchAll(), JSON_UNESCAPED_UNICODE); ?>;

    <?php if ($record['latitude'] && $record['longitude']): ?>
    document.addEventListener("DOMContentLoaded", function() {
        var lat = <?php echo $record['latitude']; ?>;
        var lng = <?php echo $record['longitude']; ?>;
        
        // إنشاء الخريطة المصغرة
        var miniMap = L.map('mini-map', { zoomControl: true, attributionControl: false }).setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(miniMap);
        
        // رسم علامة السجل (Marker) باللون المخصص للقسم الميداني
        var myIcon = L.divIcon({
            html: '<i class="fa-solid fa-location-dot text-3xl" style="color: <?php echo $record['color'] ?: '#3085d6'; ?>; text-shadow: 0 1px 3px rgba(0,0,0,0.3);"></i>',
            iconSize: [30, 42],
            iconAnchor: [15, 42]
        });
        L.marker([lat, lng], {icon: myIcon}).addTo(miniMap);

        // جلب ورسم مضلعات الحدود والكي إم إل (Boundaries KML) الممررة من قاعدة البيانات
        const boundariesData = <?php echo json_encode($boundaries, JSON_UNESCAPED_UNICODE); ?>;
        if (boundariesData && boundariesData.length > 0) {
            boundariesData.forEach(b => {
                try {
                    const parser = new DOMParser();
                    const xml = parser.parseFromString(b.kml_data, "text/xml");
                    const placemarks = xml.getElementsByTagName("Placemark");
                    
                    for (let i = 0; i < placemarks.length; i++) {
                        const pm = placemarks[i];
                        const coordElems = pm.getElementsByTagName("coordinates");
                        
                        for (let j = 0; j < coordElems.length; j++) {
                            const coordStr = coordElems[j].textContent.trim();
                            const coords = coordStr.split(/\s+/).map(pair => {
                                const parts = pair.split(',');
                                return [parseFloat(parts[1]), parseFloat(parts[0])]; // [lat, lng]
                            }).filter(c => !isNaN(c[0]) && !isNaN(c[1]));

                            if (coords.length > 0) {
                                // التحقق إذا كان مضلعاً أو خطاً مغلقاً لرسمه بشكل صحيح
                                if (pm.getElementsByTagName("Polygon").length > 0) {
                                    L.polygon(coords, {
                                        color: b.color || '#ff7800',
                                        fillColor: b.color || '#ff7800',
                                        fillOpacity: 0.15,
                                        weight: 2.5
                                    }).addTo(miniMap).bindPopup("<b>الحدود: </b>" + b.name);
                                } else {
                                    L.polyline(coords, {
                                        color: b.color || '#ff7800',
                                        weight: 2.5
                                    }).addTo(miniMap).bindPopup("<b>الحدود: </b>" + b.name);
                                }
                            }
                        }
                    }
                } catch (e) {
                    console.error("حدث خطأ أثناء معالجة ورسم ملفات الحدود جغرافياً: ", e);
                }
            });
        }
    });
    <?php endif; ?>

    // دالة فتح صندوق قوالب الطباعة (Wizard) لتعمل بشكل مستقل تماماً
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
            html: `<p class="text-xs text-gray-400 mb-2">حدد النموذج الإداري المعتمد لطباعة محضر هذا السجل:</p>
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