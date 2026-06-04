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

// Chỉ cho admin truy cập; staff bị redirect về dashboard kèm flash error
function require_admin(): void {
    if (!is_admin()) {
        ghi_log('UNAUTHORIZED_ACCESS',
            'uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' role=' . ($_SESSION['role'] ?? 'unknown')
        );
        http_response_code(403);
        $_SESSION['error'] = 'Bạn không có quyền truy cập chức năng này.';
        header('Location: ' . BASE_URL . '/pages/dashboard.php');
        exit;
    }
}

// Chặn staff thực hiện action nhạy cảm trong trang dùng chung (hỗn hợp role)
// Nếu là API request → die JSON; nếu là form → flash + redirect về trang hiện tại
function block_staff(string $action): void {
    if (!is_admin()) {
        ghi_log('UNAUTHORIZED_ACCESS',
            'action=' . $action
            . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '')
            . ' role=' . ($_SESSION['role'] ?? 'unknown')
        );
        $is_api = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)
               || (strtolower($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json')
               || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
        if ($is_api) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode(['error' => 'Không có quyền thực hiện thao tác này.', 'code' => 403]));
        }
        $_SESSION['error'] = 'Bạn không có quyền thực hiện thao tác này.';
        header('Location: ' . basename($_SERVER['PHP_SELF'] ?? 'dashboard.php'));
        exit;
    }
}