<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/logger.php';

// Xác thực tài khoản và khởi tạo session; trả về true nếu thành công
function login(string $username, string $password): bool {
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, full_name, role FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        ghi_log('LOGIN_FAILED', 'username=' . $username);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];

    ghi_log('LOGIN_SUCCESS', 'username=' . $username);
    return true;
}

// Hủy session và chuyển về trang đăng nhập
function logout(): void {
    ghi_log('LOGOUT');
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Chuyển hướng về login nếu chưa đăng nhập
function require_login(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

// Kiểm tra user hiện tại có role admin không
function is_admin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}