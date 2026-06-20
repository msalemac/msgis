<!-- حاوية مؤقتة ومخفية لحقوق الملكية والفوتر لتفادي كسر الـ Flexbox العام للموقع -->
    <div id="gis_dynamic_footer" class="hidden w-full mt-auto no-print">
        <footer class="bg-white border-t border-gray-150 py-4 px-6 w-full">
            <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-3 text-xs font-semibold text-gray-500 text-center md:text-right" dir="rtl">
                
                <!-- الجانب الأيمن: حقوق النشر والملكية الفكرية -->
                <div class="space-x-1 space-x-reverse">
                    <span>جميع الحقوق محفوظة © <?php echo date('Y'); ?></span>
                    <span class="text-gray-300">|</span>
                    <span class="text-slate-700">تطوير وصيانة نظام <b>منصة GIS MANAGER السحابية</b></span>
                </div>
                
                <!-- الجانب الأيسر: إصدار المنصة والرمز التفاعلي النشط -->
                <div class="flex items-center space-x-1.5 space-x-reverse justify-center md:justify-end">
                    <i class="fa-solid fa-earth-africa text-indigo-600 animate-pulse text-sm"></i>
                    <span class="text-indigo-600 font-extrabold">إصدار التأسيس الآمن v2.5</span>
                    <span class="text-gray-300">|</span>
                    <span class="text-slate-400 text-[10px] font-mono">GIS MANAGER PLATFORM</span>
                </div>

            </div>
        </footer>
    </div>

    <!-- كود جافا سكريبت ذكي لحقن وتوطين الفوتر بداخل الـ main تلقائياً دون كسر التنسيقات -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const mainContainer = document.querySelector('main');
            const dynamicFooter = document.getElementById('gis_dynamic_footer');
            if (mainContainer && dynamicFooter) {
                // إظهار الفوتر ونقله ليكون آخر عنصر بداخل حاوية الـ main الرئيسية المتجاوبة
                dynamicFooter.classList.remove('hidden');
                mainContainer.appendChild(dynamicFooter);
            }
        });
    </script>

    <!-- مكتبة الرسوم والمخططات البيانية التفاعلية Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- مكتبة التنبيهات والتحققات التفاعلية SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>