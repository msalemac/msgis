<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $recaptcha_response = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';

    if (!empty($username) && !empty($password)) {
        
        $recaptcha_passed = false;
        
        if (RECAPTCHA_SITE_KEY === 'YOUR_SITE_KEY_HERE' || RECAPTCHA_SECRET_KEY === 'YOUR_SECRET_KEY_HERE') {
            $recaptcha_passed = true; 
        } else {
            if (!empty($recaptcha_response)) {
                $verify_url = "https://www.google.com/recaptcha/api/siteverify";
                $response = file_get_contents($verify_url . "?secret=" . RECAPTCHA_SECRET_KEY . "&response=" . $recaptcha_response . "&remoteip=" . $_SERVER['REMOTE_ADDR']);
                $response_data = json_decode($response);
                if (isset($response_data->success) && $response_data->success) {
                    $recaptcha_passed = true;
                } else {
                    $error = "فشل اختبار الأمان (reCAPTCHA).";
                }
            } else {
                $error = "يرجى التحقق من تفعيل اختبار الأمان أولاً.";
            }
        }

        if ($recaptcha_passed) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    
                    // [تحديث أمني]: تخزين تصاريح الأقسام والصفحات المسموحة للموظف في الجلسة الأمنية
                    $_SESSION['allowed_types'] = $user['allowed_types'];
                    $_SESSION['allowed_pages'] = $user['allowed_pages'];

                    header("Location: index.php");
                    exit;
                } else { $error = "اسم المستخدم أو كلمة المرور غير صحيحة."; }
            } catch (PDOException $e) { $error = "حدث خطأ في النظام."; }
        }
    } else { $error = "يرجى ملء جميع الحقول."; }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - GIS Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style> body { font-family: 'Cairo', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-2xl shadow-md w-full max-w-md">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">GIS MANAGER</h1>
            <p class="text-gray-500 text-xs mt-1">نظام إدارة السجلات الجغرافية الميدانية المؤمن</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-xl relative mb-4 text-xs text-center">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="space-y-4">
            <div>
                <label class="block text-gray-700 text-xs font-semibold mb-1">اسم المستخدم</label>
                <input type="text" name="username" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-left" dir="ltr">
            </div>
            <div>
                <label class="block text-gray-700 text-xs font-semibold mb-1">كلمة المرور</label>
                <input type="password" name="password" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-left" dir="ltr">
            </div>
<div>
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-gray-700 text-xs font-semibold">كلمة المرور</label>
                    <a href="forgot-password.php" class="text-[10px] text-blue-600 hover:underline">نسيت كلمة المرور؟</a> <!-- الرابط الجديد -->
                </div>
            </div>
            <?php if (RECAPTCHA_SITE_KEY !== 'YOUR_SITE_KEY_HERE'): ?>
                <div class="flex justify-center py-2">
                    <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                </div>
            <?php endif; ?>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl transition duration-200 shadow-md">
                تسجيل الدخول الآمن
            </button>
        </form>
    </div>
</body>
</html>