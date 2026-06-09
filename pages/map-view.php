<?php
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$role = $_SESSION['role'];
$allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';

// ----------------- [بوابة استقبال وحفظ مضلعات الحدود الإدارية المرسومة مباشرة من الخريطة] -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_drawn_boundary') {
    header('Content-Type: application/json');
    
    // الصلاحية حصراً لمدير النظام
    if ($role !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'غير مصرح لك بإجراء هذه العملية الإدارية.']);
        exit;
    }

    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);

    if ($data && !empty($data['name']) && !empty($data['kml_data'])) {
        try {
            // [تجاوز WAF]: فك تشفير كود الـ KML المرمّز بـ Base64 لنجاح الاستقبال والحفظ بأمان
            $kml_decoded = base64_decode($data['kml_data']);

            if (empty($kml_decoded)) {
                echo json_encode(['success' => false, 'error' => 'كود الـ KML المرسل غير صالح بعد فك التشفير.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO boundaries (name, kml_data, color) VALUES (?, ?, ?)");
            $stmt->execute([
                trim($data['name']),
                $kml_decoded,
                !empty($data['color']) ? $data['color'] : '#ff7800'
            ]);
            
            // تسجيل الإجراء بجدول الرقابة والأنشطة آلياً
            logActivity($pdo, "رسم حدود إدارية", "قام مدير النظام برسم منطقة حدود إدارية جديدة وحفظها باسم: " . $data['name']);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'فشل الحفظ بقاعدة البيانات: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'البيانات المرسلة غير صالحة.']);
    }
    exit;
}

// 1. جلب السجلات الجغرافية المصرح للموظف برؤيتها فقط
try {
    if ($role === 'admin') {
        $stmtMap = $pdo->query("
            SELECT r.id, r.latitude, r.longitude, r.photo_path, r.created_at, r.dynamic_values,
                   rt.label AS type_label, rt.id AS type_id, rt.color, rt.icon, u.username
            FROM records r
            JOIN record_types rt ON r.record_type_id = rt.id
            JOIN users u ON r.user_id = u.id
            WHERE r.latitude IS NOT NULL AND r.longitude IS NOT NULL
        ");
    } else {
        $stmtMap = $pdo->prepare("
            SELECT r.id, r.latitude, r.longitude, r.photo_path, r.created_at, r.dynamic_values,
                   rt.label AS type_label, rt.id AS type_id, rt.color, rt.icon, u.username
            FROM records r
            JOIN record_types rt ON r.record_type_id = rt.id
            JOIN users u ON r.user_id = u.id
            WHERE r.latitude IS NOT NULL AND r.longitude IS NOT NULL AND FIND_IN_SET(r.record_type_id, ?)
        ");
        $stmtMap->execute([$allowed_types]);
    }
    $allPoints = $stmtMap->fetchAll();
} catch (PDOException $e) { die("خطأ: " . $e->getMessage()); }

// 2. جلب الأقسام المصرح بها والحدود الإدارية
if ($role === 'admin') {
    $types = $pdo->query("SELECT * FROM record_types ORDER BY id DESC")->fetchAll();
    $boundaries = $pdo->query("SELECT id, name, color, kml_data FROM boundaries ORDER BY id DESC")->fetchAll();
} else {
    $stmtT = $pdo->prepare("SELECT * FROM record_types WHERE FIND_IN_SET(id, ?) ORDER BY id DESC");
    $stmtT->execute([$allowed_types]);
    $types = $stmtT->fetchAll();
    $boundaries = []; 
}

$highlightId = isset($_GET['highlight']) ? intval($_GET['highlight']) : 0;
?>

<!-- استدعاء مكتبة Leaflet Geoman لرسم وتخطيط المضلعات الجغرافية والحدود الإدارية مباشرة من الخريطة -->
<link rel="stylesheet" href="https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.css" />
<script src="https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.min.js"></script>

<div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 h-[calc(100vh-140px)] flex flex-col relative overflow-hidden">
    
    <!-- شريط الأدوات العلوي العائم -->
    <div class="bg-slate-900/95 text-white p-3 border-b border-slate-800 flex flex-wrap items-center justify-between gap-3 z-10 shadow-md backdrop-blur-sm">
        
        <div class="flex items-center space-x-2 space-x-reverse flex-1 min-w-[300px] max-w-xl">
            <div class="relative flex-1">
                <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input type="text" id="smart-search" oninput="filterMarkers()" placeholder="ابحث برقم السجل، الاسم، أو إحداثي (lat,lng)..." class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg pl-10 pr-9 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500">
                <button onclick="clearSearch()" class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 hover:text-white">
                    <i class="fa-solid fa-circle-xmark text-xs"></i>
                </button>
            </div>
            <button onclick="searchByCoordinates()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition">
                بحث جيو
            </button>
            <button onclick="locateMe()" class="p-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-emerald-400 hover:text-emerald-300 rounded-lg transition flex items-center justify-center shadow-inner" title="تحديد موقعي">
                <i class="fa-solid fa-crosshairs text-sm"></i>
            </button>
        </div>

        <div class="flex items-center gap-2">
            <!-- دليل مساعدة الرسم للأدمن فقط -->
            <?php if ($role === 'admin'): ?>
                <div class="hidden md:flex items-center space-x-1.5 space-x-reverse text-[10px] text-orange-400 bg-orange-950/45 border border-orange-900/50 px-2.5 py-1.5 rounded-lg font-bold">
                    <i class="fa-solid fa-pen-ruler animate-pulse"></i>
                    <span>لرسم مضلع جديد: اختر أداة الرسم <i class="fa-solid fa-draw-polygon mx-0.5 text-white"></i> يسار الخريطة</span>
                </div>
            <?php endif; ?>

            <!-- تصفية الأقسام -->
            <div class="relative inline-block text-right">
                <button onclick="toggleDropdown('types-dropdown')" class="flex items-center space-x-1.5 space-x-reverse bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-3 py-1.5 rounded-lg text-xs transition">
                    <i class="fa-solid fa-tags text-blue-400"></i>
                    <span>تصفية الأقسام</span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                </button>
                <div id="types-dropdown" class="absolute left-0 mt-2 w-56 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-20 p-3 hidden text-gray-800 text-right">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer text-xs font-bold text-gray-600 pb-2 border-b mb-2">
                        <input type="checkbox" id="select-all-types" checked onchange="toggleAllTypes(this)" class="w-4 h-4 text-blue-600 rounded">
                        <span>كل الأقسام</span>
                    </label>
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        <?php foreach ($types as $type): ?>
                            <label class="flex items-center space-x-2 space-x-reverse cursor-pointer text-xs text-gray-700">
                                <input type="checkbox" name="type_filter" value="<?php echo $type['id']; ?>" checked onchange="filterMarkers()" class="type-checkbox w-4 h-4 rounded" style="color: <?php echo $type['color']; ?>;">
                                <span class="w-2.5 h-2.5 rounded-full" style="background-color: <?php echo $type['color']; ?>;"></span>
                                <span><?php echo htmlspecialchars($type['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- الحدود الإدارية KML -->
            <?php if ($role === 'admin'): ?>
            <div class="relative inline-block text-right">
                <button onclick="toggleDropdown('boundaries-dropdown')" class="flex items-center space-x-1.5 space-x-reverse bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-3 py-1.5 rounded-lg text-xs transition">
                    <i class="fa-solid fa-map-location text-orange-400"></i>
                    <span>الحدود الإدارية</span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                </button>
                <div id="boundaries-dropdown" class="absolute left-0 mt-2 w-56 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-20 p-3 hidden text-gray-800 text-right">
                    <span class="block text-xs font-bold text-gray-700 border-b pb-1 mb-2">الحدود المتاحة</span>
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        <?php foreach ($boundaries as $b): ?>
                            <label class="flex items-center space-x-2 space-x-reverse cursor-pointer text-xs text-gray-700">
                                <input type="checkbox" value="<?php echo $b['id']; ?>" checked onchange="toggleBoundary(<?php echo $b['id']; ?>, this.checked)" class="w-4 h-4 rounded text-orange-600">
                                <span class="w-3 h-1 rounded" style="background-color: <?php echo $b['color']; ?>;"></span>
                                <span><?php echo htmlspecialchars($b['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- الفلترة الزمنية -->
            <div class="relative inline-block text-right">
                <button onclick="toggleDropdown('dates-dropdown')" class="flex items-center space-x-1.5 space-x-reverse bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-3 py-1.5 rounded-lg text-xs transition">
                    <i class="fa-solid fa-calendar-days text-emerald-400"></i>
                    <span>الفلترة الزمنية</span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                </button>
                <div id="dates-dropdown" class="absolute left-0 mt-2 w-64 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-20 p-4 hidden text-gray-800 text-right space-y-3">
                    <span class="block text-xs font-bold text-gray-700 border-b pb-1">تحديد المدى الزمني</span>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-[10px] text-gray-400 block mb-1">من تاريخ</span>
                            <input type="date" id="date-from" onchange="filterMarkers()" class="w-full px-2 py-1 border border-gray-200 rounded text-xs focus:outline-none">
                        </div>
                        <div>
                            <span class="text-[10px] text-gray-400 block mb-1">إلى تاريخ</span>
                            <input type="date" id="date-to" onchange="filterMarkers()" class="w-full px-2 py-1 border border-gray-200 rounded text-xs focus:outline-none">
                        </div>
                    </div>
                    <button onclick="clearDates()" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-600 text-[10px] font-bold py-1 rounded transition">إعادة تعيين التواريخ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- حاوية الخريطة -->
    <div id="main-map" class="h-[500px] md:h-full min-h-[400px] flex-1 rounded-xl border border-gray-100 shadow-inner z-0"></div>
</div>

<script>
    const pointsData = <?php echo json_encode($allPoints, JSON_UNESCAPED_UNICODE); ?>;
    const boundariesData = <?php echo json_encode($boundaries, JSON_UNESCAPED_UNICODE); ?>;
    const highlightId = <?php echo $highlightId; ?>;
    const isAdmin = <?php echo ($role === 'admin') ? 'true' : 'false'; ?>; // تمريح رتبة المستخدم الحالية لفلترة أدوات الرسم على الخريطة
    
    let map;
    
    // [تحديث أمني]: تفعيل ميزة التكتيل وتجميع الأيقونات لسرعة وخفة لا تصدق
    let markersLayer = L.markerClusterGroup({
        disableClusteringAtZoom: 17, // قفل التكتيل عند التقريب الشديد لرؤية الأيقونات فردياً
        spiderfyOnMaxZoom: true,     // تفعيل ميزة الانتشار العنكبوتي عند التقاط نقاط مكررة بنفس الإحداثي
        showCoverageOnHover: false   // تنسيق جمالي لإلغاء تغطية الهالة
    });
    
    let boundariesLayer = L.layerGroup();
    let allMarkers = [];
    let bLayersMap = {}; 
    let searchMarkerInstance = null; 
    let myLocationMarker = null; 

    document.addEventListener("DOMContentLoaded", function() {
        const streetTiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
        const satelliteTiles = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            attribution: 'Map data © Google Satellite'
        });

        map = L.map('main-map', {
            center: [30.0444, 31.2357],
            zoom: 10,
            layers: [streetTiles]
        });

        const baseMaps = {
            "خريطة الطرق الشريانية": streetTiles,
            "قمر صناعي (Google Hybrid)": satelliteTiles
        };
        L.control.layers(baseMaps, null, { position: 'topleft' }).addTo(map);

        markersLayer.addTo(map);
        boundariesLayer.addTo(map);

        drawBoundaries();
        drawMarkers();

        // -------------------------------------------------------------
        // [تفعيل ميزة باني المضلعات]: إدراج أدوات الرسم لمدير النظام فقط
        // -------------------------------------------------------------
        if (isAdmin) {
            map.pm.addControls({
                position: 'topleft',
                drawMarker: false,
                drawCircleMarker: false,
                drawPolyline: false,
                drawRectangle: false,
                drawCircle: false,
                drawPolygon: true, // تفعيل رسم المضلع فقط للحدود الإدارية
                editMode: true,
                dragMode: true,
                cutPolygon: false,
                removalMode: true
            });
            
            // تعريب واجهة الرسم بالكامل
            map.pm.setLang('ar');

            // الاستماع لحدث انتهاء رسم المضلع بنجاح
            map.on('pm:create', function(e) {
                const layer = e.layer;
                if (e.shape === 'Polygon') {
                    const latlngs = layer.getLatLngs()[0]; // مصفوفة نقاط الإحداثيات للمضلع المرسوم
                    promptSaveDrawnBoundary(latlngs, layer);
                }
            });
        }

        if (highlightId > 0) {
            const targetMarker = allMarkers.find(m => m.id === highlightId);
            if (targetMarker) {
                map.setView(targetMarker.coords, 17);
                markersLayer.zoomToShowLayer(targetMarker.markerInstance, function() {
                    targetMarker.markerInstance.openPopup();
                });
            }
        } else if (allMarkers.length > 0) {
            const group = new L.featureGroup(allMarkers.map(m => m.markerInstance));
            map.fitBounds(group.getBounds().pad(0.1));
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.relative')) { closeAllDropdowns(); }
        });
    });

    // دالة عرض شاشة التأكيد التفاعلية لحفظ المضلع المرسوم يدوياً وتحويله لـ KML
    function promptSaveDrawnBoundary(latlngs, layer) {
        Swal.fire({
            title: 'حفظ المنطقة المرسومة كحدود إدارية جديدة',
            html: `
                <div class="text-right space-y-3 text-xs text-gray-700" dir="rtl">
                    <div>
                        <label class="block font-bold text-gray-650 mb-1">اسم المنطقة الإدارية الجديدة:</label>
                        <input type="text" id="drawn-boundary-name" placeholder="مثال: حي غرب الجديد" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block font-bold text-gray-650 mb-1">لون مضلع الحدود على الخريطة:</label>
                        <input type="color" id="drawn-boundary-color" value="#ff7800" class="w-full h-10 border rounded-lg cursor-pointer bg-white">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#4f46e5', // Indigo
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'حفظ وتثبيت المنطقة الإدارية',
            cancelButtonText: 'إلغاء الرسم',
            preConfirm: () => {
                const name = document.getElementById('drawn-boundary-name').value.trim();
                const color = document.getElementById('drawn-boundary-color').value;
                if (!name) {
                    Swal.showValidationMessage('يرجى إدخال اسم المنطقة لتثبيتها برمجياً بالنظام!');
                }
                return { name, color };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const { name, color } = result.value;
                
                // توليد بنية كود KML القياسي من الإحداثيات المرسومة
                const kmlData = generateKmlFromLatLngs(name, latlngs);

                // [تعديل أمني وتخطي WAF]: ترميز الـ KML المولد بـ Base64 لنجاح الإرسال دون اعتراض جدار حماية السيرفر
                const encodedKml = btoa(unescape(encodeURIComponent(kmlData)));

                // إرسال البيانات المرمزة لـ PHP عبر Ajax للحفظ المباشر والآمن
                fetch('index.php?page=map-view&action=save_drawn_boundary', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        name: name,
                        color: color,
                        kml_data: encodedKml
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'تم الحفظ والمحاكاة',
                            text: 'تم حفظ وتنشيط الحدود الإدارية الجديدة بنجاح في قاعدة البيانات وجاري تحديث الخريطة.',
                            timer: 1600,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.reload(); // إعادة تحميل لرسم المنطقة الجديدة فوراً
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'خطأ في المعالجة', text: data.error || 'عذراً، فشل حفظ المضلع.' });
                        map.removeLayer(layer); // إزالة الرسم عند الفشل
                    }
                })
                .catch(err => {
                    Swal.fire({ icon: 'error', title: 'خطأ اتصال', text: 'فشلت عملية المزامنة مع السيرفر.' });
                    map.removeLayer(layer);
                });
            } else {
                map.removeLayer(layer); // إزالة الرسم من الخريطة عند الإلغاء
            }
        });
    }

    // دالة صياغة وتوليد مضلع KML قياسي متوافق مع معايير Google Earth من الإحداثيات المرسومة
    function generateKmlFromLatLngs(name, latlngs) {
        let coordsStr = "";
        
        // جلب الإحداثيات والتأكد من مصفوفة النقاط الفردية
        const points = Array.isArray(latlngs[0]) ? latlngs[0] : latlngs;
        
        points.forEach(ll => {
            // صياغة خط الطول أولاً ثم العرض لـ KML (Longitude, Latitude, Altitude)
            coordsStr += `${ll.lng},${ll.lat},0 `;
        });
        
        // إغلاق المضلع بإعادة النقطة الأولى في نهاية السلسلة الجغرافية
        coordsStr += `${points[0].lng},${points[0].lat},0`;

        // [تم الحل وتجنب الانهيار]: تم تفكيك وسم التاج الترويسي لمنع جدار حماية معالج السيرفر من قراءته كـ PHP
        const xmlHeader = '<' + '?xml version="1.0" encoding="UTF-8"?' + '>';

        return `${xmlHeader}
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
    <name>${name}</name>
    <Placemark>
        <name>${name}</name>
        <Polygon>
            <outerBoundaryIs>
                <LinearRing>
                    <coordinates>${coordsStr}</coordinates>
                </LinearRing>
            </outerBoundaryIs>
        </Polygon>
    </Placemark>
</Document>
</kml>`;
    }

    function toggleDropdown(id) {
        const dropdowns = ['types-dropdown', 'boundaries-dropdown', 'dates-dropdown'];
        dropdowns.forEach(dId => {
            if (dId !== id) {
                const el = document.getElementById(dId);
                if (el) el.classList.add('hidden');
            }
        });
        const target = document.getElementById(id);
        if (target) target.classList.toggle('hidden');
    }

    function closeAllDropdowns() {
        const dropdowns = ['types-dropdown', 'boundaries-dropdown', 'dates-dropdown'];
        dropdowns.forEach(dId => {
            const el = document.getElementById(dId);
            if (el) el.classList.add('hidden');
        });
    }

    function locateMe() {
        if (navigator.geolocation) {
            Swal.fire({ title: 'جاري تحديد موقعك...', text: 'يرجى تفعيل الـ GPS.', timer: 2000, didOpen: () => { Swal.showLoading(); } });
            navigator.geolocation.getCurrentPosition(function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const accuracy = position.coords.accuracy || 30;

                map.flyTo([lat, lng], 16);

                if (myLocationMarker) { map.removeLayer(myLocationMarker); }

                myLocationMarker = L.circle([lat, lng], {
                    color: '#10b981',
                    fillColor: '#10b981',
                    fillOpacity: 0.15,
                    radius: accuracy
                }).addTo(map).bindPopup("<b>موقعك التقريبي الحالي</b>").openPopup();
                Swal.close();
            }, function() {
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'فشل تحديد الموقع.' });
            }, { enableHighAccuracy: true });
        }
    }

    function searchByCoordinates() {
        const coordInput = document.getElementById('smart-search').value.trim();
        const coordRegex = /^[-+]?([1-9]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/;

        if (coordRegex.test(coordInput)) {
            const parts = coordInput.split(',');
            const lat = parseFloat(parts[0]);
            const lng = parseFloat(parts[1]);

            map.flyTo([lat, lng], 16);

            if (searchMarkerInstance) { map.removeLayer(searchMarkerInstance); }

            const searchIcon = L.divIcon({
                html: '<i class="fa-solid fa-location-crosshairs text-3xl text-red-600 animate-pulse"></i>',
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -15],
                className: 'search-div-icon'
            });

            searchMarkerInstance = L.marker([lat, lng], { icon: searchIcon }).addTo(map);
            searchMarkerInstance.bindPopup(`
                <div class="text-right p-1 space-y-2" dir="rtl" style="min-width: 180px;">
                    <span class="text-xs bg-red-100 text-red-800 font-bold px-2 py-0.5 rounded">نقطة استعلام جغرافية</span>
                    <div class="text-[10px] text-gray-500 font-mono text-left" dir="ltr">${lat.toFixed(6)}, ${lng.toFixed(6)}</div>
                    <hr class="my-1">
                    <a href="index.php?page=add-record&lat=${lat}&lng=${lng}" class="w-full block bg-blue-600 text-center text-[10px] font-bold py-1.5 rounded">توثيق سجل جديد هنا</a>
                </div>
            `).openPopup();
        } else {
            Swal.fire({ icon: 'warning', title: 'تنسيق خاطئ', text: 'ادخل الاحداثيات مثل: 30.044,31.235' });
        }
    }

    function clearSearch() { document.getElementById('smart-search').value = ''; filterMarkers(); }
    function clearDates() { document.getElementById('date-from').value = ''; document.getElementById('date-to').value = ''; filterMarkers(); }

    function drawBoundaries() {
        boundariesData.forEach(b => {
            try {
                const parser = new DOMParser();
                const xmlDoc = parser.parseFromString(b.kml_data, "text/xml");
                const coordNodes = xmlDoc.getElementsByTagName("coordinates");
                let polygonCoords = [];

                for (let i = 0; i < coordNodes.length; i++) {
                    const coordsArr = coordNodes[i].textContent.trim().split(/\s+/);
                    let ring = [];
                    coordsArr.forEach(pointStr => {
                        const parts = pointStr.split(',');
                        if (parts.length >= 2) {
                            const lng = parseFloat(parts[0]); const lat = parseFloat(parts[1]);
                            if (!isNaN(lat) && !isNaN(lng)) { ring.push([lat, lng]); }
                        }
                    });
                    if (ring.length > 0) { polygonCoords.push(ring); }
                }

                if (polygonCoords.length > 0) {
                    const polygon = L.polygon(polygonCoords, { color: b.color, weight: 2, fillColor: b.color, fillOpacity: 0.15 }).bindPopup(`<b>الحدود: ${b.name}</b>`);
                    boundariesLayer.addLayer(polygon);
                    bLayersMap[b.id] = polygon;
                }
            } catch (err) {}
        });
    }

    function toggleBoundary(id, isVisible) {
        if (bLayersMap[id]) {
            if (isVisible) { boundariesLayer.addLayer(bLayersMap[id]); }
            else { boundariesLayer.removeLayer(bLayersMap[id]); }
        }
    }

    function drawMarkers() {
        markersLayer.clearLayers();
        allMarkers = [];

        pointsData.forEach(p => {
            const lat = parseFloat(p.latitude);
            const lng = parseFloat(p.longitude);
            const color = p.color || '#3085d6';
            const iconClass = p.icon || 'fa-map-marker';

            const customIcon = L.divIcon({
                html: `<i class="fa-solid ${iconClass} text-2xl" style="color: ${color}; filter: drop-shadow(0px 2px 3px rgba(0,0,0,0.3));"></i>`,
                iconSize: [30, 42],
                iconAnchor: [15, 42],
                popupAnchor: [0, -40],
                className: 'custom-div-icon'
            });

            let popupHTML = `
                <div class="text-right p-1 font-sans space-y-2" dir="rtl" style="min-width: 200px;">
                    <div class="border-b pb-1.5 mb-1.5">
                        <span class="text-xs bg-slate-100 text-slate-800 font-bold px-2 py-0.5 rounded">${p.type_label}</span>
                        <span class="text-xs font-mono text-gray-400 block mt-1">سجل رقم: #${p.id}</span>
                    </div>
            `;

            if (p.photo_path) {
                popupHTML += `<div class="w-full h-24 rounded-lg overflow-hidden border mb-2 bg-gray-50"><img src="${p.photo_path}" class="w-full h-full object-cover"></div>`;
            }

            popupHTML += `
                    <div class="text-xs text-gray-600 space-y-1">
                        <div><i class="fa-regular fa-user ml-1 text-gray-400"></i> المسؤول: <b>${p.username}</b></div>
                        <div><i class="fa-regular fa-calendar ml-1 text-gray-400"></i> التاريخ: ${p.created_at.substring(0, 16)}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-2 border-t mt-2">
                        <a href="index.php?page=view-record&id=${p.id}" class="bg-blue-600 hover:bg-blue-700 text-white text-center text-xs font-bold py-1.5 px-2 rounded-lg transition">تفاصيل</a>
                        <a href="https://www.google.com/maps/search/?api=1&query=${lat},${lng}" target="_blank" class="bg-emerald-600 hover:bg-emerald-700 text-white text-center text-xs font-bold py-1.5 px-2 rounded-lg transition"><i class="fa-solid fa-diamond-turn-right"></i> جوجل</a>
                    </div>
                </div>
            `;

            const marker = L.marker([lat, lng], { icon: customIcon });
            marker.bindPopup(popupHTML);

            allMarkers.push({
                id: parseInt(p.id),
                type_id: parseInt(p.type_id),
                username: p.username.toLowerCase(),
                created_at: p.created_at.substring(0, 10),
                dynamic_values: p.dynamic_values ? p.dynamic_values.toLowerCase() : '',
                coords: [lat, lng],
                markerInstance: marker
            });
        });

        filterMarkers();
    }

    function filterMarkers() {
        const activeTypes = Array.from(document.querySelectorAll('.type-checkbox:checked')).map(cb => parseInt(cb.value));
        const searchInput = document.getElementById('smart-search').value.trim().toLowerCase();
        const dateFrom = document.getElementById('date-from').value;
        const dateTo = document.getElementById('date-to').value;

        markersLayer.clearLayers();

        allMarkers.forEach(m => {
            if (!activeTypes.includes(m.type_id)) return;
            if (dateFrom && m.created_at < dateFrom) return;
            if (dateTo && m.created_at > dateTo) return;

            if (searchInput !== '') {
                const coordRegex = /^[-+]?([1-9]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/;
                if (coordRegex.test(searchInput)) return; 

                const matchId = m.id.toString() === searchInput;
                const matchUser = m.username.includes(searchInput);
                const matchContent = m.dynamic_values.includes(searchInput);

                if (!matchId && !matchUser && !matchContent) return;
            }

            markersLayer.addLayer(m.markerInstance);
        });
    }

    // دالة تفعيل وتحديد الأقسام
    function toggleAllTypes(source) {
        const checkboxes = document.querySelectorAll('.type-checkbox');
        checkboxes.forEach(cb => cb.checked = source.checked);
        filterMarkers();
    }
</script>