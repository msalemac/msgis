// sw.js - ملف الخدمة المطور لتسريع الفتح الفوري للهواتف وتخزين المكتبات الثابتة (النسخة الكاملة والمحدثة)

const CACHE_NAME = 'gis-manager-v8-static-speed';

// مصفوفة المكتبات والخطوط والأيقونات الثابتة والضخمة المطلوب توطينها وحفظها بذاكرة الهاتف المادية
const STATIC_ASSETS = [
    'https://cdn.tailwindcss.com',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css',
    'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css',
    'https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js',
    'https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.css',
    'https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
    'https://cdn.jsdelivr.net/npm/chart.js',
    'https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap'
];

// أ. عند تثبيت التطبيق: شحن وتخزين كافة المكتبات الثابتة بذاكرة الهاتف الكاش الفورية
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

// ب. تفعيل ملف الخدمة وتدمير أي كاش قديم
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cache => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache); // مسح الكاشات القديمة
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// جـ. مستمع حدث الـ fetch الذكي والسرّع:
// إذا كان الملف المطلوب هو إحدى المكتبات الثابتة المخزنة بالهاتف، يرسلها المتصفح فوراً من الذاكرة المحلية (بسرعة 0.1 ثانية)
// وإذا كان ملفاً ديناميكياً (صفحة PHP)، فيرسلها المتصفح مباشرة من السيرفر لضمان المزامنة الحية لبيانات المعاينات
self.addEventListener('fetch', event => {
    const requestUrl = new URL(event.request.url);
    
    // التحقق مما إذا كان الملف المطلوب من ضمن أصول المكتبات الثابتة
    const isStaticAsset = STATIC_ASSETS.some(asset => event.request.url.startsWith(asset)) || 
                          requestUrl.hostname.includes('fonts.gstatic.com') || 
                          requestUrl.hostname.includes('cdnjs.cloudflare.com');

    if (isStaticAsset) {
        event.respondWith(
            caches.match(event.request).then(cachedResponse => {
                if (cachedResponse) {
                    return cachedResponse; // إرسال فوري من الهاتف
                }
                // في حال عدم توفره بالكاش، يتم جلب وتحميل وتخزين الملف مستقبلاً
                return fetch(event.request).then(networkResponse => {
                    return caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, networkResponse.clone());
                        return networkResponse;
                    });
                }).catch(() => fetch(event.request));
            })
        );
    } else {
        // تمرير الصفحات الحية ومعاينات قاعدة البيانات مباشرة للشبكة لمنع التعليق
        event.respondWith(
            fetch(event.request).catch(err => {
                return Promise.reject(err);
            })
        );
    }
});