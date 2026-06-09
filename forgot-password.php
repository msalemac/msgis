<?php
require_once 'db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);

    if (!empty($username) && !empty($email)) {
        try {
            // التحقق مما إذا كان المستخدم موجوداً أصلاً في النظام لمطابقة البيانات
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? AND email = ?");
            $stmtCheck->execute([$username, $email]);
            
            if ($stmtCheck->rowCount() > 0) {
                // التحقق من عدم وجود طلب معلق مسبقاً لنفس المستخدم لتفادي الإغراق
                $stmtReq = $pdo->prepare("SELECT id FROM password_requests WHERE username = ? AND status = 'pending'");
                $stmtReq->execute([$username]);
                
                if ($stmtReq->rowCount() > 0) {
                    $error = "تنبيه: يوجد طلب استرجاع معلق خاص بك بالفعل قيد المراجعة من قِبل الإدارة.";
                } else {
                    // إدراج طلب الاسترجاع معلق للمدير
                    $stmtIns = $pdo->prepare("INSERT INTO password_requests (username, email) VALUES (?, ?)");
                    $stmtIns->execute([$username, $email]);
                    $message = "تم إرسال طلب استرجاع كلمة المرور بنجاح إلى مدير النظام. يرجى التواصل مع الإدارة لاستلام كلمة المرور الجديدة.";
                }
            } else {
                $error = "عذراً، البيانات المدخلة لا تطابق أي حساب مسجل بالنظام.";
            }
        } catch (PDOException $e) { $error = "حدث خطأ في النظام، حاول لاحقاً."; }
    } else { $error = "يرجى ملء جميع الحقول المطلوبة."; }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استرجاع كلمة المرور - GIS Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> body { font-family: 'Cairo', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <?php if (!empty($message)): ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                Swal.fire({
                    icon: 'success',
                    title: 'تم إرسال الطلب',
                    text: '<?php echo $message; ?>',
                    confirmButtonText: 'العودة لصفحة الدخول'
                }).then(() => { window.location.href = 'login.php'; });
            });
        </script>
    <?php endif; ?>

    <div class="bg-white p-8 rounded-2xl shadow-md w-full max-w-md space-y-6">
        <div class="text-center">
            <h1 class="text-xl font-bold text-gray-850">استرجاع حساب GIS MANAGER</h1>
            <p class="text-gray-400 text-xs mt-1">أدخل بياناتك الفردية لإرسال طلب فوري للمدير</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-xl text-xs text-center font-bold">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="forgot-password.php" method="POST" class="space-y-4">
            <div>
                <label class="block text-gray-700 text-xs font-semibold mb-1">اسم المستخدم</label>
                <input type="text" name="username" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-left text-xs" dir="ltr">
            </div>
            <div>
                <label class="block text-gray-700 text-xs font-semibold mb-1">البريد الإلكتروني المربوط بالحساب</label>
                <input type="email" name="email" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-left text-xs" dir="ltr">
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl transition duration-200 shadow-md text-xs">
                إرسال طلب الاسترداد للمشرف
            </button>
        </form>

        <div class="text-center">
            <a href="login.php" class="text-xs text-gray-500 hover:text-blue-600 font-bold transition">← العودة لصفحة تسجيل الدخول</a>
        </div>
    </div>
</body>
</html>