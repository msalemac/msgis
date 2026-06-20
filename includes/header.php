<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - GIS Manager</title>
    
    <!-- شحن محرك التنسيق السريع Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- شحن أيقونات FontAwesome الفخمة والموحدة -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- شحن خط الويب العربي Cairo بوزن عريض وواضح -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <!-- شحن مكتبة التنبيهات والتحققات التفاعلية SweetAlert2 عالمياً لثبات عملها بكافة الصفحات -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <?php
    // جلب وتحديد اسم الصفحة النشطة الحالية لفلترة وشحن المكتبات الهندسية شرطياً
    $active_page_ref = isset($page) ? $page : (isset($_GET['page']) ? trim((string)$_GET['page']) : 'home-view');
    
    // أ. شحن مكتبات الخرائط والرسم والحدود الإدارية (فقط) في الصفحات الجغرافية الأربعة لضمان خفة التحميل
    $gis_pages = ['map-view', 'add-record', 'edit-record', 'view-record'];
    if (in_array($active_page_ref, $gis_pages)):
    ?>
        <!-- Leaflet CSS & JS للخرائط الميدانية -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        
        <!-- Leaflet Marker Cluster لتجميع تكتيل أيقونات المعاينات الميدانية -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
        <script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
        
        <!-- Leaflet Geoman للرسم الجغرافي وحفظ مضلعات الـ KML للحدود الإدارية -->
        <link rel="stylesheet" href="https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.css" />
        <script src="https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.min.js"></script>
    <?php endif; ?>

    <?php
    // ب. شحن مكتبة الرسوم المخططة Chart.js (فقط) بداخل صفحة المؤشرات والرسوم البيانية لسرعة أداء الهواتف
    if ($active_page_ref === 'dashboard-view'):
    ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>

    <!-- ربط ميزة الـ PWA والتحويل لتطبيق هاتف مثبت والتجاوب المطلق مع أجهزة الآيفون والأندرويد -->
    <link rel="manifest" href="manifest.json" />
    <meta name="theme-color" content="#1e293b" />
    <link rel="apple-touch-icon" href="uploads/logos/app_icon_192.png" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />

    <style>
        body { font-family: 'Cairo', sans-serif; }
        .sidebar-transition {
            transition: all 0.3s ease-in-out;
        }
    </style>

    <!-- سكريبت تسجيل ملف الخدمة الفعال لتنشيط التثبيت بالجوال -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('PWA Service Worker registered successfully!', reg.scope))
                    .catch(err => console.log('PWA Service Worker registration failed:', err));
            });
        }
    </script>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden">