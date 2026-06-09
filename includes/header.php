<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - GIS Manager</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Leaflet Marker Cluster -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
    <script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
    
    <!-- [جديد]: ربط ميزة الـ PWA والتحويل لتطبيق هاتف ومفتاح الألوان والتجاوب مع هواتف الآيفون والأندرويد -->
    <link rel="manifest" href="manifest.json" />
    <meta name="theme-color" content="#1e293b" />
    <link rel="apple-touch-icon" href="uploads/logos/app_icon_192.png" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />

    <!-- Cairo Font -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        .sidebar-transition {
            transition: all 0.3s ease-in-out;
        }
    </style>

    <!-- [جديد]: سكريبت تسجيل ملف الخدمة لتنشيط التثبيت الفوري الفخم على الجوال -->
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