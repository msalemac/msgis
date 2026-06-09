// Service Worker مبسط وخفيف جداً لتمكين ميزة الـ PWA وتثبيت التطبيق على الجوال دون تخزين أو اعتراض الصفحات
const CACHE_NAME = 'gis-manager-v6-direct-bypass';

// تثبيت ملف الخدمة دون تخزين أي صفحات ديناميكية لتفادي مشاكل انقطاع الاتصال الوهمي
self.addEventListener('install', event => {
    self.skipWaiting();
});

// تفعيل ملف الخدمة وتطهير وحذف أي كاش قديم متراكم بالمتصفحات فوراً لتهيئة المتصفح
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cache => {
                    return caches.delete(cache); // حذف وتدمير كافة الكاشات القديمة تماماً لتبسيط التصفح
                })
            );
        })
    );
    self.clients.claim();
});

// تم إلغاء مستمع حدث الـ fetch تماماً لكي يعتمد المتصفح بالكامل على السيرفر والشبكة بشكل طبيعي ومباشر 100%
// هذا يمنع حدوث أي أخطاء تعليق أو ERR_FAILED في المتصفحات نهائياً.