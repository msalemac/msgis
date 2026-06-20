<?php
// pages/map-view.php - الخريطة التفاعلية والحدود الجغرافية (النسخة النهائية الفائقة الأمان لبيئات PHP 8.4)
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

// ----------------- [بوابة استقبال وحفظ مضلعات الحدود الإدارية المرسومة مباشرة من الخريطة] -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_drawn_boundary') {
    header('Content-Type: application/json');
    
    // الصلاحية حصراً لمدير النظام (Admin)
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'غير مصرح لك بإجراء هذه العملية الإدارية.']);
        exit;
    }

    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);

    // التحقق الأمني الإجباري من توكن حماية CSRF لمنع هجمات التزوير الخارجية لـ AJAX API
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).']);
        exit;
    }

    if ($data && !empty($data['name']) && !empty($data['kml_data'])) {
        try {
            // فك تشفير كود الـ KML المرمّز بـ Base64 لنجاح الاستقبال والحفظ بأمان وتفادي WAF
            $kml_decoded = base64_decode($data['kml_data']);

            if (empty($kml_decoded)) {
                echo json_encode(['success' => false, 'error' => 'كود الـ KML المرسل غير صالح بعد فك التشفير.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO boundaries (name, kml_data, color) VALUES (?, ?, ?)");
            $stmt->execute([
                trim((string)$data['name']),
                $kml_decoded,
                !empty($data['color']) ? trim((string)$data['color']) : '#ff7800'
            ]);
            
            // تسجيل الإجراء بجدول الرقابة والأنشطة آلياً
            logActivity($pdo, "رسم حدود إدارية", "قام مدير النظام برسم منطقة حدود إدارية جديدة وحفظها باسم: " . $data['name']);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'فشل الحفظ بقاعدة البيانات: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'البيانات المرسلة غير صالحة للتحليل.']);
    }
    exit;
}

// 1. جلب السجلات الجغرافية المصرح للموظف برؤيتها فقط
try {
    $role = isset($role) ? $role : ($_SESSION['role'] ?? 'user');
    $allowed_types = !empty($_SESSION['allowed_types']) ? $_SESSION['allowed_types'] : '0';

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
} catch (PDOException $e) { 
    die("خطأ قاعدة البيانات: " . $e->getMessage()); 
}

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

<!-- حقل أمان مخفي لتزويد الـ JS بالتوكن المعتمد للـ AJAX API -->
<input type="hidden" id="ajax_csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

<div class="bg-white p-4 md:p-6 rounded-2xl shadow-xl border border-gray-200/80 h-[calc(100vh-140px)] flex flex-col relative overflow-hidden">
    
    <!-- شريط الأدوات العلوي العائم بتصميم زجاجي ضبابي فاخر ومتجاوب -->
    <div class="bg-slate-900/95 text-white p-3 rounded-2xl border border-slate-800 flex flex-wrap items-center justify-between gap-3 z-10 shadow-lg backdrop-blur-md mb-4">
        
        <div class="flex items-center space-x-2 space-x-reverse flex-1 min-w-[300px] max-w-xl">
            <div class="relative flex-1">
                <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input type="text" id="smart-search" oninput="filterMarkers()" placeholder="ابحث برقم السجل، الاسم، أو إحداثي (lat,lng)..." class="w-full bg-slate-800/80 border border-slate-700 text-white rounded-xl pl-10 pr-9 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition font-semibold placeholder-slate-400">
                <button onclick="clearSearch()" class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 hover:text-white transition">
                    <i class="fa-solid fa-circle-xmark text-xs"></i>
                </button>
            </div>
            <button onclick="searchByCoordinates()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-black py-2 px-4 rounded-xl transition shadow-md">
                بحث جيو
            </button>
            <button onclick="locateMe()" class="p-2.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-emerald-400 hover:text-emerald-350 rounded-xl transition flex items-center justify-center shadow-inner" title="تحديد موقعي">
                <i class="fa-solid fa-crosshairs text-sm text-emerald-400"></i>
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- دليل مساعدة الرسم للأدمن فقط -->
            <?php if ($role === 'admin'): ?>
                <div class="hidden lg:flex items-center space-x-1.5 space-x-reverse text-[10px] text-orange-400 bg-orange-950/45 border border-orange-900/50 px-3 py-2 rounded-xl font-black">
                    <i class="fa-solid fa-pen-ruler animate-pulse"></i>
                    <span>لرسم مضلع جديد: اختر أداة الرسم <i class="fa-solid fa-draw-polygon mx-0.5 text-white"></i> يسار الخريطة</span>
                </div>
            <?php endif; ?>

            <!-- تصفية الأقسام الميدانية المتاحة للموظف -->
            <div class="relative inline-block text-right">
                <button onclick="toggleDropdown('types-dropdown')" class="flex items-center space-x-1.5 space-x-reverse bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-3 py-2 rounded-xl text-xs font-bold transition shadow-sm">
                    <i class="fa-solid fa-tags text-blue-400"></i>
                    <span>تصفية الأقسام</span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                </button>
                <div id="types-dropdown" class="absolute left-0 mt-2 w-56 rounded-2xl shadow-2xl bg-white ring-1 ring-black ring-opacity-5 z-20 p-4 hidden text-gray-800 text-right">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer text-xs font-black text-slate-900 pb-2 border-b border-gray-100 mb-2">
                        <input type="checkbox" id="select-all-types" checked onchange="toggleAllTypes(this)" class="w-4 h-4 text-blue-600 rounded cursor-pointer">
                        <span>كل الأقسام</span>
                    </label>
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        <?php foreach ($types as $type): ?>
                            <label class="flex items-center space-x-2 space-x-reverse cursor-pointer text-xs font-bold text-slate-800 hover:text-slate-950 transition">
                                <input type="checkbox" name="type_filter" value="<?php echo $type['id']; ?>" checked onchange="filterMarkers()" class="type-checkbox w-4 h-4 rounded cursor-pointer" style="color: <?php echo $type['color']; ?>;">
                                <span class="w-2.5 h-2.5 rounded-full" style="background-color: <?php echo $type['color']; ?>;"></span>
                                <span><?php echo htmlspecialchars($type['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- طبقة الحدود الإدارية KML المنسقة -->
            <?php if ($role === 'admin'): ?>
            <div class="relative inline-block text-right">
                <button onclick="toggleDropdown('boundaries-dropdown')" class="flex items-center space-x-1.5 space-x-reverse bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-3 py-2 rounded-xl text-xs font-bold transition shadow-sm">
                    <i class="fa-solid fa-map-location text-orange-400"></i>
                    <span>الحدود الإدارية</span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                </button>
                <div id="boundaries-dropdown" class="absolute left-0 mt-2 w-56 rounded-2xl shadow-2xl bg-white ring-1 ring-black ring-opacity-5 z-20 p-4 hidden text-gray-800 text-right">
                    <span class="block text-xs font-black text-slate-900 border-b border-gray-100 pb-2 mb-2">الحدود المتاحة</span>
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        <?php foreach ($boundaries as $b): ?>
                            <label class="flex items-center space-x-2 space-x-reverse cursor-pointer text-xs font-bold text-slate-800 hover:text-slate-950 transition">
                                <input type="checkbox" value="<?php echo $b['id']; ?>" checked onchange="toggleBoundary(<?php echo $b['id']; ?>, this.checked)" class="w-4 h-4 rounded text-orange-600 cursor-pointer">
                                <span class="w-3 h-1.5 rounded" style="background-color: <?php echo $b['color']; ?>;"></span>
                                <span><?php echo htmlspecialchars($b['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- الفلترة الزمنية للمعاينة -->
            <div class="relative inline-block text-right">
                <button onclick="toggleDropdown('dates-dropdown')" class="flex items-center space-x-1.5 space-x-reverse bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white px-3 py-2 rounded-xl text-xs font-bold transition shadow-sm">
                    <i class="fa-solid fa-calendar-days text-emerald-400"></i>
                    <span>الفلترة الزمنية</span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                </button>
                <div id="dates-dropdown" class="absolute left-0 mt-2 w-64 rounded-2xl shadow-2xl bg-white ring-1 ring-black ring-opacity-5 z-20 p-4 hidden text-gray-800 text-right space-y-3">
                    <span class="block text-xs font-black text-slate-900 border-b border-gray-100 pb-2">تحديد المدى الزمني</span>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-[10px] text-slate-500 font-bold block mb-1">من تاريخ</span>
                            <input type="date" id="date-from" onchange="filterMarkers()" class="w-full px-2 py-1 border border-gray-200 rounded-lg text-xs font-semibold focus:outline-none">
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-500 font-bold block mb-1">إلى تاريخ</span>
                            <input type="date" id="date-to" onchange="filterMarkers()" class="w-full px-2 py-1 border border-gray-200 rounded-lg text-xs font-semibold focus:outline-none">
                        </div>
                    </div>
                    <button onclick="clearDates()" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 text-[10px] font-black py-1.5 rounded-lg transition">إعادة تعيين التواريخ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- حاوية الخريطة -->
    <div id="main-map" class="h-[500px] md:h-full min-h-[400px] flex-1 rounded-2xl border border-gray-200/60 shadow-inner z-0"></div>
</div>

<script>
    const pointsData = <?php echo json_encode($allPoints, JSON_UNESCAPED_UNICODE); ?>;
    const boundariesData = <?php echo json_encode($boundaries, JSON_UNESCAPED_UNICODE); ?>;
    const highlightId = <?php echo $highlightId; ?>;
    const isAdmin = <?php echo ($role === 'admin') ? 'true' : 'false'; ?>; 
    
    let map;
    
    // تجميع الأيقونات لسرعة وخفة لا تصدق
    let markersLayer = L.markerClusterGroup({
        disableClusteringAtZoom: 17, 
        spiderfyOnMaxZoom: true,     
        showCoverageOnHover: false   
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
            "قمر صناعي (Google Satellite)": satelliteTiles
        };
        L.control.layers(baseMaps, null, { position: 'topleft' }).addTo(map);

        markersLayer.addTo(map);
        boundariesLayer.addTo(map);

        drawBoundaries();
        drawMarkers();

        // إدوات الرسم للمدير (Admin) فقط
        if (isAdmin) {
            map.pm.addControls({
                position: 'topleft',
                drawMarker: false,
                drawCircleMarker: false,
                drawPolyline: false,
                drawRectangle: false,
                drawCircle: false,
                drawPolygon: true, 
                editMode: true,
                dragMode: true,
                cutPolygon: false,
                removalMode: true
            });
            
            map.pm.setLang('ar');

            map.on('pm:create', function(e) {
                const layer = e.layer;
                if (e.shape === 'Polygon') {
                    const latlngs = layer.getLatLngs()[0]; 
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

    // دالة حفظ المضلع المرسوم يدوياً وتحويله لـ KML (تحت حماية الـ CSRF التامة لـ AJAX)
    function promptSaveDrawnBoundary(latlngs, layer) {
        Swal.fire({
            title: 'حفظ المنطقة المرسومة كحدود إدارية جديدة',
            html: `
                <div class="text-right space-y-3 text-xs text-gray-700" dir="rtl">
                    <div>
                        <label class="block font-black text-slate-800 mb-1">اسم المنطقة الإدارية الجديدة:</label>
                        <input type="text" id="drawn-boundary-name" placeholder="مثال: حي غرب الجديد" class="w-full px-3 py-2 border border-gray-250 rounded-xl text-xs focus:outline-none bg-white font-bold">
                    </div>
                    <div>
                        <label class="block font-black text-slate-800 mb-1">لون مضلع الحدود على الخريطة:</label>
                        <input type="color" id="drawn-boundary-color" value="#ff7800" class="w-full h-10 border rounded-xl cursor-pointer bg-white">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#4f46e5', 
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'حفظ وتثبيت المنطقة الإدارية',
            cancelButtonText: 'إلغاء الرسم',
            preConfirm: () => {
                const name = document.getElementById('drawn-boundary-name').value.trim();
                const color = document.getElementById('drawn-boundary-color').value;
                if (!name) {
                    Swal.showValidationMessage('يرجى إدخال اسم المنطقة لتثبيتها!');
                }
                return { name, color };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const { name, color } = result.value;
                const kmlData = generateKmlFromLatLngs(name, latlngs);
                const encodedKml = btoa(unescape(encodeURIComponent(kmlData)));
                
                // جلب توكن الـ CSRF من الحقل المخفي بالصفحة وإدراجه بالـ AJAX
                const ajaxToken = document.getElementById('ajax_csrf_token').value;

                fetch('index.php?page=map-view&action=save_drawn_boundary', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: ajaxToken, // حقن التوكن لإتمام التحقق الرقابي ومنع حظر WAF
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
                            window.location.reload(); 
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'خطأ في المعالجة', text: data.error || 'عذراً، فشل حفظ المضلع.' });
                        map.removeLayer(layer); 
                    }
                })
                .catch(err => {
                    Swal.fire({ icon: 'error', title: 'خطأ اتصال', text: 'فشلت عملية المزامنة مع السيرفر.' });
                    map.removeLayer(layer);
                });
            } else {
                map.removeLayer(layer); 
            }
        });
    }

    function generateKmlFromLatLngs(name, latlngs) {
        let coordsStr = "";
        const points = Array.isArray(latlngs[0]) ? latlngs[0] : latlngs;
        
        points.forEach(ll => {
            coordsStr += `${ll.lng},${ll.lat},0 `;
        });
        
        coordsStr += `${points[0].lng},${points[0].lat},0`;
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
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'فشلت عملية التحديد الجغرافي لموقعك.' });
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
                <div class="text-right p-2 space-y-3 font-sans" dir="rtl" style="min-width: 200px; font-family: 'Cairo', sans-serif;">
                    <span class="text-xs bg-red-100 text-red-800 font-black px-2.5 py-1 rounded-lg border border-red-200 shadow-sm">نقطة استعلام جغرافية</span>
                    <div class="text-[10px] text-slate-800 font-mono text-left font-bold" dir="ltr">${lat.toFixed(6)}, ${lng.toFixed(6)}</div>
                    <hr class="my-1 border-gray-150">
                    <a href="index.php?page=add-record&lat=${lat}&lng=${lng}" class="w-full block bg-blue-600 hover:bg-blue-700 text-white text-center text-[10px] font-black py-2 rounded-xl transition shadow-sm">توثيق سجل جديد هنا</a>
                </div>
            `).openPopup();
        } else {
            Swal.fire({ icon: 'warning', title: 'تنسيق خاطئ', text: 'ادخل الاحداثيات مثل: 30.044,31.235' });
        }
    }

    function clearSearch() { document.getElementById('smart-search').value = ''; filterMarkers(); }
    function clearDates() { document.getElementById('date-from').value = ''; document.getElementById('date-to').value = ''; filterMarkers(); }

    function drawBoundaries() {
        boundariesLayer.clearLayers();
        bLayersMap = {};

        boundariesData.forEach(b => {
            try {
                const parser = new DOMParser();
                const xmlDoc = parser.parseFromString(b.kml_data, "text/xml");
                const coordNodes = xmlDoc.getElementsByTagName("coordinates");
                
                const boundaryGroup = L.featureGroup();

                let strokeColor = '#ff7800';
                let fillColor = '#ff7800';
                let weight = 2.5;
                let fillOpacity = 0.15;

                if (b.color) {
                    if (b.color.startsWith('{')) {
                        try {
                            const parsed = JSON.parse(b.color);
                            strokeColor = parsed.stroke_color || strokeColor;
                            weight = parseFloat(parsed.weight) || weight;
                            fillColor = parsed.fill_color || fillColor;
                            fillOpacity = parseFloat(parsed.fill_opacity) !== undefined ? parseFloat(parsed.fill_opacity) : fillOpacity;
                        } catch(e) {}
                    } else if (b.color.startsWith('#')) {
                        strokeColor = b.color;
                        fillColor = b.color;
                    }
                }

                for (let i = 0; i < coordNodes.length; i++) {
                    const coordsText = coordNodes[i].textContent.trim();
                    if (!coordsText) continue;

                    const coordsArr = coordsText.split(/\s+/);
                    let ring = [];
                    coordsArr.forEach(pointStr => {
                        const parts = pointStr.split(',');
                        if (parts.length >= 2) {
                            const lng = parseFloat(parts[0]);
                            const lat = parseFloat(parts[1]);
                            if (!isNaN(lat) && !isNaN(lng)) {
                                ring.push([lat, lng]);
                            }
                        }
                    });

                    if (ring.length >= 3) {
                        let isPolygon = false;
                        let parent = coordNodes[i].parentNode;
                        while (parent && parent.tagName !== 'Document') {
                            if (parent.tagName === 'Polygon') {
                                isPolygon = true;
                                break;
                            }
                            parent = parent.parentNode;
                        }

                        if (isPolygon) {
                            L.polygon(ring, {
                                color: strokeColor,
                                weight: weight,
                                fillColor: fillColor,
                                fillOpacity: fillOpacity
                            }).addTo(boundaryGroup).bindPopup(`<b>الحدود الإدارية: ${b.name}</b>`);
                        } else {
                            L.polyline(ring, {
                                color: strokeColor,
                                weight: weight
                            }).addTo(boundaryGroup).bindPopup(`<b>الحدود الإدارية: ${b.name}</b>`);
                        }
                    } else if (ring.length > 0) {
                        L.polyline(ring, {
                            color: strokeColor,
                            weight: weight
                        }).addTo(boundaryGroup).bindPopup(`<b>الحدود الإدارية: ${b.name}</b>`);
                    }
                }

                if (boundaryGroup.getLayers().length > 0) {
                    boundariesLayer.addLayer(boundaryGroup);
                    bLayersMap[b.id] = boundaryGroup;
                }

            } catch (err) {
                console.error("حدث خطأ أثناء رسم حدود KML المعقدة: ", err);
            }
        });
    }

    function toggleBoundary(id, isVisible) {
        if (bLayersMap[id]) {
            if (isVisible) {
                boundariesLayer.addLayer(bLayersMap[id]);
            } else {
                boundariesLayer.removeLayer(bLayersMap[id]);
            }
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
                html: `<i class="fa-solid ${iconClass} text-2.5xl" style="color: ${color}; filter: drop-shadow(0px 2px 4px rgba(0,0,0,0.35));"></i>`,
                iconSize: [30, 42],
                iconAnchor: [15, 42],
                popupAnchor: [0, -40],
                className: 'custom-div-icon'
            });

            let popupHTML = `
                <div class="text-right p-2 font-sans space-y-3" dir="rtl" style="min-width: 210px; font-family: 'Cairo', sans-serif;">
                    <div class="border-b pb-2 mb-2 border-gray-200/60 flex justify-between items-center">
                        <span class="text-[10px] bg-slate-900 text-white font-black px-2.5 py-1 rounded-lg border border-slate-800 shadow-sm">${p.type_label}</span>
                        <span class="text-xs font-black text-slate-900 font-mono">#${p.id}</span>
                    </div>
            `;

            if (p.photo_path) {
                popupHTML += `<div class="w-full h-24 rounded-lg overflow-hidden border border-gray-100 mb-2 bg-gray-50"><img src="${p.photo_path}" class="w-full h-full object-cover"></div>`;
            }

            popupHTML += `
                    <div class="text-xs text-slate-900 space-y-1.5 font-bold">
                        <div class="flex items-center"><i class="fa-regular fa-user ml-1.5 text-slate-500"></i> المسؤول: <span class="font-black text-slate-950">${p.username}</span></div>
                        <div class="flex items-center"><i class="fa-regular fa-calendar ml-1.5 text-slate-500"></i> التاريخ: <span class="font-black text-slate-950">${p.created_at.substring(0, 16)}</span></div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-3 border-t border-gray-150 mt-2 font-sans">
                        <a href="index.php?page=view-record&id=${p.id}" class="inline-flex items-center justify-center bg-indigo-600 hover:bg-indigo-700 text-white text-center text-[10px] font-black py-2 rounded-xl transition shadow-sm shadow-indigo-200">
                            <i class="fa-solid fa-eye ml-1"></i> تفاصيل المعاينة
                        </a>
                        <a href="https://www.google.com/maps/search/?api=1&query=${lat},${lng}" target="_blank" class="inline-flex items-center justify-center bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200 text-center text-[10px] font-black py-2 rounded-xl transition shadow-sm">
                            <i class="fa-solid fa-map-location-dot ml-1 text-slate-500"></i> خرائط جوجل
                        </a>
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

    function toggleAllTypes(source) {
        const checkboxes = document.querySelectorAll('.type-checkbox');
        checkboxes.forEach(cb => cb.checked = source.checked);
        filterMarkers();
    }
</script>