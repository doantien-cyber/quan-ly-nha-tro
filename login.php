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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f0f2f5; margin: 0; }
        .login-card { background: #fff; padding: 2rem 2.5rem; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.15); width: 100%; max-width: 380px; }
        .login-card h1 { margin: 0 0 1.5rem; font-size: 1.4rem; text-align: center; color: #1a1a2e; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: .35rem; font-size: .9rem; color: #444; }
        .form-group input { width: 100%; padding: .6rem .75rem; border: 1px solid #ccc; border-radius: 5px; font-size: 1rem; box-sizing: border-box; }
        .form-group input:focus { outline: none; border-color: #4a6fa5; box-shadow: 0 0 0 3px rgba(74,111,165,.15); }
        .btn-login { width: 100%; padding: .7rem; background: #4a6fa5; color: #fff; border: none; border-radius: 5px; font-size: 1rem; cursor: pointer; margin-top: .5rem; }
        .btn-login:hover { background: #3a5a8a; }
        .alert-error { background: #fde8e8; color: #c0392b; border: 1px solid #f5c6c6; border-radius: 5px; padding: .6rem .9rem; margin-bottom: 1rem; font-size: .9rem; }
    </style>
</head>
<body>
<div class="login-card">
    <h1><?= APP_NAME ?></h1>

    <?php if ($error !== ''): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="username">Tên đăng nhập</label>
            <input type="text" id="username" name="username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username" autofocus required>
        </div>
        <div class="form-group">
            <label for="password">Mật khẩu</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn-login">Đăng Nhập</button>
    </form>
</div>
</body>
</html>