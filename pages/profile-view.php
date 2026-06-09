<?php
// حماية الملف من الوصول المباشر
if (!isset($_SESSION['user_id'])) {
    die("غير مسموح بالوصول المباشر.");
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// [حل مشكلة اختفاء التنسيقات]: قراءة رسائل الجلسة المؤقتة ومسحها تلقائياً بعد العرض لثبات الواجهة
if (isset($_SESSION['profile_success_msg'])) { $message = $_SESSION['profile_success_msg']; unset($_SESSION['profile_success_msg']); }
if (isset($_SESSION['profile_error_msg'])) { $error = $_SESSION['profile_error_msg']; unset($_SESSION['profile_error_msg']); }

// 1. جلب بيانات المستخدم الحالية للحقن المسبق بالنماذج
try {
    $stmt = $pdo->prepare("SELECT username, email, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        die("المستخدم غير موجود.");
    }
} catch (PDOException $e) { 
    die("حدث خطأ في قاعدة البيانات أثناء جلب بيانات الحساب."); 
}

// 2. معالجة وتحديث البيانات الشخصية وكلمة المرور (POST Update Handler)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $old_password = trim($_POST['old_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if (!empty($username) && !empty($email)) {
        try {
            $update_ok = true;
            
            // جلب كلمة المرور المشفرة الحالية للتحقق منها
            $stmtPass = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmtPass->execute([$user_id]);
            $current_hashed_pass = $stmtPass->fetchColumn();

            // أ. في حال رغبة المستخدم في تغيير كلمة المرور الخاصة به
            if (!empty($old_password) || !empty($new_password)) {
                if (password_verify($old_password, $current_hashed_pass)) {
                    if ($new_password === $confirm_password) {
                        if (strlen($new_password) >= 6) {
                            $new_hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                            
                            $stmtUp = $pdo->prepare("UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?");
                            $stmtUp->execute([$username, $email, $new_hashed_password, $user_id]);
                            
                            // توثيق التغيير الأمني بالسجل الرقابي الخاص بك
                            logActivity($pdo, "تغيير بيانات وكلمة مرور", "قام المستخدم بتغيير بيانات حسابه الشخصي وتحديث كلمة المرور بنجاح.");
                        } else { $update_ok = false; $_SESSION['profile_error_msg'] = "كلمة المرور الجديدة يجب ألا تقل عن 6 أحرف."; }
                    } else { $update_ok = false; $_SESSION['profile_error_msg'] = "عذراً، كلمة المرور الجديدة وتأكيدها غير متطابقين."; }
                } else { $update_ok = false; $_SESSION['profile_error_msg'] = "كلمة المرور الحالية المدخلة غير صحيحة."; }
            } else {
                // ب. تحديث الاسم والبريد فقط دون لمس كلمة المرور القديمة
                $stmtUp = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmtUp->execute([$username, $email, $user_id]);
                
                logActivity($pdo, "تعديل الملف الشخصي", "قام المستخدم بتحديث بيانات ملفه الشخصي (الاسم والبريد) بنجاح.");
            }

            if ($update_ok) {
                $_SESSION['username'] = $username; // تحديث الجلسة بالاسم الجديد فوراً
                $_SESSION['profile_success_msg'] = "تم حفظ وتحديث بيانات ملفك الشخصي بنجاح.";
            }

        } catch (PDOException $e) { 
            $_SESSION['profile_error_msg'] = "عذراً، اسم المستخدم أو البريد مكرر ومسجل لحساب آخر."; 
        }
    } else { 
        $_SESSION['profile_error_msg'] = "يرجى ملء جميع الحقول المطلوبة."; 
    }

    // [التحويل الفوري المضمون لمنع تعطل التنسيقات عند الحفظ والتحديث]
    header("Location: index.php?page=profile-view");
    exit;
}
?>

<!-- التنبيهات باستخدام SweetAlert2 الفاخرة المنسقة -->
<?php if (!empty($message)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'success', title: 'تم التحديث', text: '<?php echo $message; ?>', confirmButtonText: 'حسناً' }); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>document.addEventListener("DOMContentLoaded", function() { Swal.fire({ icon: 'error', title: 'تنبيه خطأ', text: '<?php echo $error; ?>' }); });</script>
<?php endif; ?>

<!-- تصميم الصفحة المقسم لبوكسات جمالية متجاوبة بأسلوب الشياكة المعتمد -->
<div class="max-w-5xl mx-auto space-y-6 animate-fade">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        
        <!-- الكرت الأيمن (عرض 1/3): بطاقة تعريفية مدمجة بالموظف وصلاحيته -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 lg:col-span-1 text-center space-y-4">
            <div class="flex flex-col items-center space-y-2">
                <div class="p-4 bg-blue-50 text-blue-600 rounded-2xl shadow-inner inline-block">
                    <i class="fa-solid fa-id-card-clip text-3xl"></i>
                </div>
                <h4 class="font-extrabold text-slate-800 text-md"><?php echo htmlspecialchars($user['username']); ?></h4>
                <p class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($user['email']); ?></p>
            </div>
            
            <hr class="border-gray-100">

            <div class="space-y-3 text-right text-xs">
                <div class="flex justify-between">
                    <span class="text-gray-400">الصلاحية بالنظام:</span>
                    <span class="font-bold text-slate-700">
                        <?php echo $user['role'] === 'admin' ? 'مدير نظام مطلق' : ($user['role'] === 'editor' ? 'مسؤول ميداني' : 'مشاهد فقط'); ?>
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">تاريخ انضمام الحساب:</span>
                    <span class="font-bold text-gray-500 font-mono"><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span>
                </div>
            </div>
        </div>

        <!-- الكرت الأيسر (عرض 2/3): استمارة التحديث الفني والأمني -->
        <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100 lg:col-span-2">
            <div class="flex items-center space-x-3 space-x-reverse mb-4 border-b pb-3">
                <div class="p-2 bg-indigo-50 text-indigo-600 rounded-lg"><i class="fa-solid fa-user-pen text-lg"></i></div>
                <h3 class="text-sm font-bold text-gray-800">تحديث البيانات وكلمة المرور الشخصية</h3>
            </div>

            <form action="index.php?page=profile-view" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">اسم الحساب الخاص بك:</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required class="w-full px-4 py-2 border rounded-lg text-left text-xs focus:outline-none" dir="ltr">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-xs font-bold mb-1">البريد الإلكتروني المعتمد:</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="w-full px-4 py-2 border rounded-lg text-left text-xs focus:outline-none" dir="ltr">
                    </div>
                </div>

                <!-- بوكس تغيير كلمة المرور المنسق -->
                <div class="bg-slate-50/50 p-4 rounded-xl border border-gray-100 space-y-4 mt-2">
                    <span class="block text-xs font-bold text-indigo-600 border-b pb-1.5"><i class="fa-solid fa-shield-halved ml-1"></i> تغيير كلمة المرور الشخصية (اترك الحقول فارغة إن لم تكن تود تغييرها)</span>
                    
                    <div>
                        <label class="block text-gray-500 text-[10px] font-bold mb-1">كلمة المرور الحالية (المستخدمة الآن):</label>
                        <input type="password" name="old_password" placeholder="••••••••" class="w-full bg-white px-4 py-1.5 border rounded-lg text-left text-xs focus:outline-none" dir="ltr">
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-500 text-[10px] font-bold mb-1">كلمة المرور الجديدة:</label>
                            <input type="password" name="new_password" placeholder="6 أحرف على الأقل" class="w-full bg-white px-4 py-1.5 border rounded-lg text-left text-xs focus:outline-none" dir="ltr">
                        </div>
                        <div>
                            <label class="block text-gray-500 text-[10px] font-bold mb-1">تأكيد كلمة المرور الجديدة:</label>
                            <input type="password" name="confirm_password" placeholder="أدخل نفس الكلمة مجدداً" class="w-full bg-white px-4 py-1.5 border rounded-lg text-left text-xs focus:outline-none" dir="ltr">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-2 border-t">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-xl transition text-xs shadow">
                        <i class="fa-solid fa-floppy-disk ml-1"></i> حفظ وتحديث البيانات الشخصية
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>