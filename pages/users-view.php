<?php
// pages/users-view.php - إدارة الموظفين والصلاحيات والامتيازات (النسخة النهائية الفائقة الأمان لبيئات PHP 8.4)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("غير مسموح بالوصول المباشر.");
}

$message = '';
$error = '';

// قراءة رسائل الجلسة الأمنية
if (isset($_SESSION['users_success_msg'])) { $message = $_SESSION['users_success_msg']; unset($_SESSION['users_success_msg']); }
if (isset($_SESSION['users_error_msg'])) { $error = $_SESSION['users_error_msg']; unset($_SESSION['users_error_msg']); }

/**
 * دالة إعادة التوجيه الفائقة والمقاومة لقيود البفر والـ Headers في بيئة PHP 8.4
 */
if (!function_exists('safeRedirect')) {
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
}

// معالجة كافة طلبات POST (الحفظ والحذف والاعتماد والحل) تحت حماية CSRF المزدوجة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // التحقق الأمني الإجباري لتوكن الجلسة CSRF لحماية حسابات الموظفين والصلاحيات
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die("خطأ أمني: انتهت صلاحية الجلسة الآمنة، يرجى تحديث الصفحة والمحاولة مجدداً (CSRF Validation Failed).");
    }

    // 1. إضافة أو تعديل مستخدم وتوثيق تصاريح أقسامه وصلاحياته الديناميكية المفتوحة
    if (isset($_POST['action']) && $_POST['action'] === 'save_user') {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));
        $role = trim((string)($_POST['role'] ?? 'viewer'));
        
        $allowed_types_arr = isset($_POST['allowed_types_ids']) ? (array)$_POST['allowed_types_ids'] : [];
        $allowed_types_str = implode(',', array_map('intval', $allowed_types_arr));

        // معالجة وحزم مصفوفة الصلاحيات الثلاثية المحددة وحفظها كـ JSON في قاعدة البيانات لضمان المرونة
        $permissions_arr = isset($_POST['allowed_permissions']) ? (array)$_POST['allowed_permissions'] : [];
        $allowed_pages_json = json_encode($permissions_arr, JSON_UNESCAPED_UNICODE);

        if (!empty($username) && !empty($email)) {
            try {
                if ($user_id > 0) {
                    if ($user_id === intval($_SESSION['user_id'])) {
                        $_SESSION['users_error_msg'] = "عذراً، لا يمكنك تعديل حسابك الشخصي من هنا لتفادي حظر نفسك بالخطأ.";
                    } else {
                        if (!empty($password)) {
                            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                            $stmtUp = $pdo->prepare("UPDATE users SET username = ?, email = ?, password = ?, role = ?, allowed_types = ?, allowed_pages = ? WHERE id = ?");
                            $stmtUp->execute([$username, $email, $hashed_password, $role, $allowed_types_str, $allowed_pages_json, $user_id]);
                        } else {
                            $stmtUp = $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ?, allowed_types = ?, allowed_pages = ? WHERE id = ?");
                            $stmtUp->execute([$username, $email, $role, $allowed_types_str, $allowed_pages_json, $user_id]);
                        }
                        
                        // فك وحل أي طلب استرجاع معلق لهذا الموظف
                        $stmtClearReq = $pdo->prepare("UPDATE password_requests SET status = 'completed' WHERE username = ?");
                        $stmtClearReq->execute([$username]);

                        logActivity($pdo, "تعديل حساب موظف", "قام المشرف بتعديل حساب وتصاريح الموظف: " . $username);
                        $_SESSION['users_success_msg'] = "تم تحديث حساب الموظف وتصاريح الصلاحيات والصفحات بنجاح.";
                    }
                } else {
                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        $stmtIns = $pdo->prepare("INSERT INTO users (username, email, password, role, allowed_types, allowed_pages) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmtIns->execute([$username, $email, $hashed_password, $role, $allowed_types_str, $allowed_pages_json]);
                        
                        logActivity($pdo, "إنشاء حساب موظف", "قام المشرف بإنشاء وتفعيل حساب موظف جديد باسم: " . $username);
                        $_SESSION['users_success_msg'] = "تم إنشاء وتفعيل حساب الموظف وتحديد صلاحياته بنجاح.";
                    } else { 
                        $_SESSION['users_error_msg'] = "كلمة المرور الافتراضية مطلوبة لتأسيس الحسابات الجديدة."; 
                    }
                }
            } catch (PDOException $e) { $_SESSION['users_error_msg'] = "عذراً، اسم المستخدم أو البريد مكرر بالنظام."; }
        } else {
            $_SESSION['users_error_msg'] = "يرجى ملء الحقول الأساسية للحساب.";
        }
        
        safeRedirect("index.php?page=users-view");
    }

    // 2. حذف حساب موظف
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $del_id = intval($_POST['user_id'] ?? 0);
        if ($del_id !== intval($_SESSION['user_id'])) {
            try {
                $stmtUsr = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $stmtUsr->execute([$del_id]);
                $del_name = $stmtUsr->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$del_id]);
                
                logActivity($pdo, "حذف حساب موظف", "قام المشرف بمسح وحذف حساب الموظف نهائياً: " . $del_name);
                $_SESSION['users_success_msg'] = "تم حذف الحساب ومحوه من النظام بنجاح.";
            } catch (PDOException $e) { $_SESSION['users_error_msg'] = "لا يمكن حذف المستخدم لارتباطه بسجلات توثيق في النظام."; }
        }
        safeRedirect("index.php?page=users-view");
    }

    // 3. مسح واعتماد طلب استرجاع الباسورد المعلق
    if (isset($_POST['action']) && $_POST['action'] === 'complete_request') {
        $req_id = intval($_POST['request_id'] ?? 0);
        try {
            $stmtC = $pdo->prepare("UPDATE password_requests SET status = 'completed' WHERE id = ?");
            $stmtC->execute([$req_id]);
            $_SESSION['users_success_msg'] = "تم اعتماد وحل طلب استرجاع كلمة المرور بنجاح.";
        } catch (PDOException $e) { $_SESSION['users_error_msg'] = "فشل تحديث واعتماد طلب الاستعادة المعلق."; }
        safeRedirect("index.php?page=users-view");
    }
}

// جلب البيانات الأساسية للجدول
$users = $pdo->query("SELECT id, username, email, role, allowed_types, allowed_pages, created_at FROM users ORDER BY id DESC")->fetchAll();
$record_types = $pdo->query("SELECT id, label FROM record_types ORDER BY id DESC")->fetchAll();

// جلب طلبات الاسترجاع المعلقة
$pending_requests = $pdo->query("SELECT * FROM password_requests WHERE status = 'pending' ORDER BY id DESC")->fetchAll();

// مصفوفة الموديولات والصفحات الاحتياطية (تستعمل كمنقذ فقط في حال عدم توفر متغير $titles من الموجه الرئيسي)
$fallback_modules_list = [
    'add-record'     => 'إضافة سجل جديد',
    'map-view'       => 'الخريطة التفاعلية والحدود',
    'records-manage' => 'إدارة السجلات الميدانية',
    'dashboard-view' => 'لوحة المؤشرات والرسوم (Dashboard)',
    'reports-view'   => 'منشئ ومستخرج التقارير التفصيلية',
    'transfers-view' => 'إدارة الصادر والوارد والحركة المستندية'
];
$active_system_modules = isset($titles) ? $titles : $fallback_modules_list;
?>

<!-- التنبيهات بـ SweetAlert -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تمت العملية', text: '<?php echo htmlspecialchars($message); ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'خطأ', text: '<?php echo htmlspecialchars($error); ?>' }); });</script>
<?php endif; ?>

<!-- حقل أمان تفاعلي مخفي لتزويد الـ JS بالتوكن المعتمد للـ SweetAlert Form -->
<input type="hidden" id="ajax_csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

<div class="space-y-6 animate-fade text-right" dir="rtl">

    <!-- كارت طلبات الاسترجاع المعلقة الواردة من الموظفين -->
    <?php if (count($pending_requests) > 0): ?>
        <div class="bg-red-50 p-6 rounded-2xl border border-red-200 space-y-4">
            <div class="flex items-center space-x-3 space-x-reverse text-red-800">
                <i class="fa-solid fa-triangle-exclamation text-xl animate-pulse"></i>
                <h3 class="font-extrabold text-sm">تنبيه: توجد طلبات استعادة معلقة لكلمات المرور بالأسفل!</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($pending_requests as $req): ?>
                    <div class="bg-white p-4 rounded-xl border border-red-100 flex justify-between items-center shadow-sm">
                        <div class="text-right space-y-1">
                            <span class="text-xs font-extrabold text-slate-800"><i class="fa-solid fa-user-lock ml-1 text-red-500"></i> الحساب المطلوب: <?php echo htmlspecialchars($req['username']); ?></span>
                            <span class="text-[10px] text-gray-400 block font-mono">البريد: <?php echo htmlspecialchars($req['email']); ?></span>
                        </div>
                        <div class="flex space-x-2 space-x-reverse">
                            <button type="button" onclick="editUserByRequest('<?php echo htmlspecialchars($req['username']); ?>', '<?php echo htmlspecialchars($req['email']); ?>')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-3 rounded text-[10px]">تعديل وباسورد جديد</button>
                            
                            <!-- اعتماد وحل الطلب مباشرة (مؤمن بالـ CSRF) -->
                            <form action="index.php?page=users-view" method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="hidden" name="action" value="complete_request">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-1 px-2 rounded text-[10px]">موافق وحل</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        
        <!-- استمارة الإضافة والتعديل -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 lg:col-span-1">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i class="fa-solid fa-user-plus text-xl"></i></div>
                <h3 id="user-form-title" class="text-sm font-bold text-gray-800">إدارة حسابات الموظفين</h3>
            </div>

            <form id="user-form" action="index.php?page=users-view" method="POST" class="space-y-4">
                
                <!-- حقل الأمان CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" id="user_id" value="0">
                
                <div>
                    <label class="block text-gray-600 text-[11px] font-bold mb-1">اسم المستخدم (بالإنجليزي)</label>
                    <input type="text" name="username" id="username" placeholder="username" required class="w-full px-4 py-2 border rounded-lg text-left text-xs focus:outline-none bg-white font-bold" dir="ltr">
                </div>
                
                <div>
                    <label class="block text-gray-600 text-[11px] font-bold mb-1">البريد الإلكتروني</label>
                    <input type="email" name="email" id="email" placeholder="example@gismanger.vip" required class="w-full px-4 py-2 border rounded-lg text-left text-xs focus:outline-none bg-white font-bold" dir="ltr">
                </div>

                <div>
                    <label class="block text-gray-600 text-[11px] font-bold mb-1" id="pass-label">كلمة المرور الافتراضية</label>
                    <input type="password" name="password" id="password" class="w-full px-4 py-2 border rounded-lg text-left text-xs focus:outline-none bg-white font-bold" dir="ltr">
                    <p id="pass-help" class="text-[9px] text-gray-400 mt-1 hidden">اترك الحقل فارغاً للاحتفاظ بكلمة المرور القديمة دون تعديل.</p>
                </div>

                <div>
                    <label class="block text-gray-600 text-[11px] font-bold mb-1">تحديد دور وصلاحية المستخدم</label>
                    <select name="role" id="role" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-bold text-gray-700 focus:outline-none bg-white">
                        <option value="viewer">مشاهد فقط (Viewer)</option>
                        <option value="editor">مسؤول ميداني (Editor)</option>
                        <option value="admin">مدير نظام كامل (Admin)</option>
                    </select>
                </div>

                <!-- أقسام الخريطة المصرح بها -->
                <div>
                    <label class="block text-gray-600 text-[11px] font-bold mb-1.5"><i class="fa-solid fa-lock text-red-500"></i> تحديد الأقسام المصرح له برؤيتها:</label>
                    <div class="space-y-2 bg-gray-50 p-3 rounded-xl border border-gray-100 max-h-28 overflow-y-auto">
                        <?php if (count($record_types) === 0): ?>
                            <span class="text-[10px] text-gray-400 text-center block">يرجى تشييد الأقسام أولاً.</span>
                        <?php else: ?>
                            <?php foreach ($record_types as $type): ?>
                                <label class="flex items-center space-x-2 space-x-reverse cursor-pointer select-none text-[11px] text-gray-700">
                                    <input type="checkbox" name="allowed_types_ids[]" value="<?php echo $type['id']; ?>" class="user-allowed-cb w-4 h-4 text-blue-600 border-gray-300 rounded cursor-pointer">
                                    <span><?php echo htmlspecialchars($type['label']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- شبكة الصلاحيات الديناميكية التفصيلية الثلاثية (العرض - الحفظ والتعديل - الحذف) لجميع موديولات النظام -->
                <div class="space-y-2">
                    <label class="block text-gray-600 text-[11px] font-bold mb-1.5"><i class="fa-solid fa-shield-halved text-purple-600 ml-1"></i> صلاحيات وموديولات النظام المسموحة تفصيلياً:</label>
                    <div class="overflow-x-auto rounded-xl border border-gray-200 shadow-inner bg-gray-50/50 p-2 max-h-64 overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-right text-[10px] bg-white rounded-lg" dir="rtl">
                            <thead class="bg-purple-100 text-purple-800 font-bold sticky top-0 z-10">
                                <tr>
                                    <th class="px-2 py-1.5">الموديول</th>
                                    <th class="px-2 py-1.5 text-center">عرض (قراءة)</th>
                                    <th class="px-2 py-1.5 text-center">حفظ (تعديل)</th>
                                    <th class="px-2 py-1.5 text-center">حذف إداري</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-750 font-bold">
                                <?php foreach ($active_system_modules as $slug => $label): ?>
                                    <tr class="hover:bg-slate-50/50 transition">
                                        <td class="px-2 py-1.5 font-bold text-slate-800 truncate max-w-[120px]" title="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="checkbox" name="allowed_permissions[<?php echo $slug; ?>][read]" value="1" data-module="<?php echo $slug; ?>" data-action="read" class="user-permission-cb w-4 h-4 text-emerald-600 border-gray-300 rounded cursor-pointer">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="checkbox" name="allowed_permissions[<?php echo $slug; ?>][write]" value="1" data-module="<?php echo $slug; ?>" data-action="write" class="user-permission-cb w-4 h-4 text-orange-600 border-gray-300 rounded cursor-pointer">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="checkbox" name="allowed_permissions[<?php echo $slug; ?>][delete]" value="1" data-module="<?php echo $slug; ?>" data-action="delete" class="user-permission-cb w-4 h-4 text-red-600 border-gray-300 rounded cursor-pointer">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex space-x-2 space-x-reverse pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-xl transition text-xs shadow-sm">حفظ الحساب</button>
                    <button type="button" onclick="cancelUserEdit()" id="cancel-user-btn" class="hidden bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2.5 px-4 rounded-xl text-xs">إلغاء</button>
                </div>
            </form>
        </div>

        <!-- جدول الحسابات الحالية بالمنصة -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 lg:col-span-2 hover:shadow-lg transition duration-300">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-purple-100 text-purple-600 rounded-lg"><i class="fa-solid fa-users text-xl"></i></div>
                <h3 class="text-lg font-bold text-gray-800">قائمة الحسابات والجهات المصرحة بالعمل</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-right text-xs">
                    <thead class="bg-gray-50 text-gray-700 font-bold uppercase">
                        <tr>
                            <th class="px-4 py-3">اسم المستخدم</th>
                            <th class="px-4 py-3">البريد الإلكتروني</th>
                            <th class="px-4 py-3">الصلاحية</th>
                            <th class="px-4 py-3">الأقسام والصفحات المسموحة له</th>
                            <th class="px-4 py-3 text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200 text-gray-600 font-semibold">
                        <?php foreach ($users as $user): 
                            $allowed_ids = explode(',', $user['allowed_types'] ?? '');
                            $allowed_labels = [];
                            foreach ($record_types as $rt) {
                                if (in_array($rt['id'], $allowed_ids)) { $allowed_labels[] = $rt['label']; }
                            }
                            $allowed_str = count($allowed_labels) > 0 ? implode(' + ', $allowed_labels) : 'غير مصرح بأي قسم';

                            // فك الصلاحيات المنسقة بالـ JSON وعرضها مفصّلة للمشرف
                            $allowed_pages_raw = $user['allowed_pages'] ?? '';
                            $allowed_pages_labels = [];
                            $perms_decoded = json_decode($allowed_pages_raw, true);

                            if (is_array($perms_decoded)) {
                                foreach ($perms_decoded as $slug => $actions) {
                                    $actions_str = [];
                                    if (!empty($actions['read'])) $actions_str[] = 'عرض';
                                    if (!empty($actions['write'])) $actions_str[] = 'تعديل';
                                    if (!empty($actions['delete'])) $actions_str[] = 'حذف';
                                    
                                    if (count($actions_str) > 0) {
                                        $module_name = isset($active_system_modules[$slug]) ? $active_system_modules[$slug] : $slug;
                                        $allowed_pages_labels[] = htmlspecialchars($module_name) . " (" . implode('/', $actions_str) . ")";
                                    }
                                }
                            } else {
                                // التوافق الارتجاعي لحسابات الصلاحيات القديمة
                                $allowed_pages_slugs = explode(',', $allowed_pages_raw);
                                foreach ($active_system_modules as $slug => $label) {
                                    if (in_array($slug, $allowed_pages_slugs)) {
                                        $allowed_pages_labels[] = htmlspecialchars($label) . " (عرض/تعديل)";
                                    }
                                }
                            }
                            $allowed_pages_str = count($allowed_pages_labels) > 0 ? implode(' | ', $allowed_pages_labels) : 'غير مصرح بموديول';
                        ?>
                            <tr class="hover:bg-gray-50/50 transition border-b">
                                <td class="px-4 py-3 font-bold text-slate-800">
                                    <i class="fa-regular fa-circle-user text-gray-300 ml-1.5 text-sm"></i>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                    <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                        <span class="text-[9px] bg-blue-50 text-blue-600 font-bold px-1.5 py-0.5 rounded ml-1">حسابك</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 font-mono"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <span class="bg-red-50 text-red-700 font-extrabold px-2 py-0.5 rounded">مدير نظام</span>
                                    <?php elseif ($user['role'] === 'editor'): ?>
                                        <span class="bg-emerald-50 text-emerald-700 font-bold px-2 py-0.5 rounded">مسؤول ميداني</span>
                                    <?php else: ?>
                                        <span class="bg-gray-100 text-gray-600 font-semibold px-2 py-0.5 rounded">مشاهد</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 space-y-1">
                                    <div class="text-[10px] text-blue-600 font-bold">الأقسام: <?php echo $user['role'] === 'admin' ? 'كل الأقسام' : htmlspecialchars($allowed_str); ?></div>
                                    <div class="text-[10px] text-purple-650 font-bold">الموديولات: <?php echo $user['role'] === 'admin' ? 'كل الصفحات والأذونات' : htmlspecialchars($allowed_pages_str); ?></div>
                                </td>
                                <td class="px-4 py-3 text-center space-x-2 space-x-reverse">
                                    <button type="button" onclick="editUser(<?php echo htmlspecialchars(json_encode($user, JSON_UNESCAPED_UNICODE)); ?>)" class="text-blue-500 hover:text-blue-750 font-bold"><i class="fa-solid fa-pen-to-square"></i> تعديل</button>
                                    <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                        <button onclick="confirmUserDelete(<?php echo $user['id']; ?>)" class="text-red-500 hover:text-red-700 font-bold"><i class="fa-solid fa-trash-can"></i> حذف</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- نموذج الحذف المخفي (محدث بالـ CSRF Token) -->
<form id="user-delete-form" method="POST" action="index.php?page=users-view" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete-user-id">
</form>

<script>
    function editUser(user) {
        document.getElementById('user-form-title').innerText = "تعديل حساب: " + user.username;
        document.getElementById('user_id').value = user.id;
        document.getElementById('username').value = user.username;
        document.getElementById('email').value = user.email;
        document.getElementById('role').value = user.role;
        document.getElementById('password').placeholder = "تحديث كلمة المرور (اختياري)";
        document.getElementById('pass-label').innerText = "تغيير كلمة المرور (اختياري)";
        document.getElementById('pass-help').classList.remove('hidden');

        const cbTypes = document.querySelectorAll('.user-allowed-cb');
        cbTypes.forEach(cb => cb.checked = false);

        if (user.allowed_types) {
            const allowedIds = user.allowed_types.split(',');
            allowedIds.forEach(id => {
                const cb = document.querySelector(`.user-allowed-cb[value="${id}"]`);
                if (cb) cb.checked = true;
            });
        }

        // تصفير وتفعيل التوقيعات والصلاحيات الثلاثية للـ JSON والنسخ القديمة (Backward Compatibility)
        const cbPerms = document.querySelectorAll('.user-permission-cb');
        cbPerms.forEach(cb => cb.checked = false);

        if (user.allowed_pages) {
            try {
                // محاولة القراءة وفك الـ JSON التفصيلي الجديد
                const perms = JSON.parse(user.allowed_pages);
                for (const [module, actions] of Object.entries(perms)) {
                    for (const [action, value] of Object.entries(actions)) {
                        if (value == 1) {
                            const cb = document.querySelector(`.user-permission-cb[data-module="${module}"][data-action="${action}"]`);
                            if (cb) cb.checked = true;
                        }
                    }
                }
            } catch (e) {
                // التراجع الآمن وتفسير الصلاحيات القديمة (كومة نصوص مفصولة بفاصلة) وتفعيل القراءة والكتابة لها تلقائياً
                const oldSlugs = user.allowed_pages.split(',');
                oldSlugs.forEach(slug => {
                    const cbRead = document.querySelector(`.user-permission-cb[data-module="${slug}"][data-action="read"]`);
                    if (cbRead) cbRead.checked = true;
                    
                    const cbWrite = document.querySelector(`.user-permission-cb[data-module="${slug}"][data-action="write"]`);
                    if (cbWrite) cbWrite.checked = true;
                });
            }
        }

        document.getElementById('cancel-user-btn').classList.remove('hidden');
        window.scrollTo({ top: document.getElementById('user-form').offsetTop - 100, behavior: 'smooth' });
    }

    // دالة شحن طلب الاسترجاع فوراً لتغيير الباسورد
    function editUserByRequest(username, email) {
        cancelUserEdit();
        document.getElementById('username').value = username;
        document.getElementById('email').value = email;
        document.getElementById('password').placeholder = "اكتب الباسورد الجديد هنا";
        document.getElementById('user-form-title').innerText = "تحديث كلمة مرور الحساب المطلوب: " + username;
        
        window.scrollTo({ top: document.getElementById('user-form').offsetTop - 100, behavior: 'smooth' });
    }

    function cancelUserEdit() {
        document.getElementById('user-form-title').innerText = "إدارة حسابات الموظفين";
        document.getElementById('user_id').value = "0";
        document.getElementById('user-form').reset();
        document.getElementById('password').placeholder = "";
        document.getElementById('pass-label').innerText = "كلمة المرور الافتراضية";
        document.getElementById('pass-help').classList.add('hidden');
        document.getElementById('cancel-user-btn').classList.add('hidden');
    }

    function confirmUserDelete(id) {
        Swal.fire({
            title: 'هل تريد حذف الحساب؟',
            text: "لن يتمكن الموظف من الدخول للسيستم مجدداً!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3b82f6',
            confirmButtonText: 'احذفه!',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete-user-id').value = id;
                document.getElementById('user-delete-form').submit();
            }
        });
    }
</script>