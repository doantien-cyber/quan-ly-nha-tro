<?php
session_start();
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';

// Đã đăng nhập thì chuyển thẳng vào dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.';
    } elseif (login($username, $password)) {
        header('Location: ' . BASE_URL . '/pages/dashboard.php');
        exit;
    } else {
        $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
    }
}

// Xử lý logout từ header navbar
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout(); // hàm này tự redirect về login.php
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-[#f0f7ff] flex items-center justify-center font-sans antialiased">

<div class="w-full max-w-sm px-4">

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(59,130,246,0.1)] border border-blue-100 px-8 py-9">

        <!-- Header -->
        <div class="text-center mb-7">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-blue-50 border border-blue-200 mb-4">
                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
            </div>
            <h1 class="text-[1.15rem] font-bold text-slate-900 tracking-tight"><?= APP_NAME ?></h1>
            <p class="text-xs text-slate-400 mt-1">Đăng nhập để tiếp tục</p>
        </div>

        <!-- Error -->
        <?php if ($error !== ''): ?>
        <div class="mb-5 px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-start gap-2">
            <svg class="w-4 h-4 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                      d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                      clip-rule="evenodd"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="username" class="block text-xs font-600 text-slate-600 mb-1.5 font-semibold">
                    Tên đăng nhập
                </label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" autofocus required
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-800
                              bg-white outline-none transition
                              focus:border-blue-400 focus:ring-3 focus:ring-blue-100
                              placeholder:text-slate-300"
                       placeholder="Nhập tên đăng nhập">
            </div>
            <div>
                <label for="password" class="block text-xs font-600 text-slate-600 mb-1.5 font-semibold">
                    Mật khẩu
                </label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-800
                              bg-white outline-none transition
                              focus:border-blue-400 focus:ring-3 focus:ring-blue-100
                              placeholder:text-slate-300"
                       placeholder="••••••••">
            </div>
            <button type="submit"
                    class="w-full mt-2 py-2.5 px-4 rounded-xl bg-blue-500 hover:bg-blue-600 active:bg-blue-700
                           text-white text-sm font-semibold tracking-wide
                           transition-colors duration-150 cursor-pointer border-none">
                Đăng Nhập
            </button>
        </form>

    </div>

    <p class="text-center text-xs text-slate-400 mt-5">
        &copy; <?= date('Y') ?> <?= APP_NAME ?>
    </p>
</div>

</body>
</html>
