<?php
require_once __DIR__ . '/../config/constants.php';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<nav class="app-nav">
    <div class="nav-inner">

        <!-- Cột trái: tên app -->
        <a href="<?= BASE_URL ?>/pages/dashboard.php" class="nav-brand">
            <?= APP_NAME ?>
        </a>

        <!-- Cột giữa: nav links -->
        <div class="nav-links">
            <?php
            $nav_items = [
                ['dashboard.php', 'Tổng Quan'],
                ['rooms.php',     'Phòng'],
                ['tenants.php',   'Khách Thuê'],
                ['contracts.php', 'Hợp Đồng'],
                ['utilities.php', 'Điện Nước'],
                ['invoices.php',  'Hóa Đơn'],
            ];
            if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
                $nav_items[] = ['bao-cao.php', 'Báo Cáo'];
            }
            foreach ($nav_items as [$nav_page, $nav_label]):
                $is_active = ($current_page === $nav_page);
            ?>
            <a href="<?= BASE_URL ?>/pages/<?= $nav_page ?>"
               class="nav-link<?= $is_active ? ' active' : '' ?>">
                <?= $nav_label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Cột phải: user + logout -->
        <div class="nav-user">
            <div class="nav-user-info">
                <span class="nav-user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></span>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin'): ?>
                    <span class="user-badge staff">Staff</span>
                <?php else: ?>
                    <span class="user-badge admin">Admin</span>
                <?php endif; ?>
            </div>
            <a href="<?= BASE_URL ?>/logout.php?action=logout" class="btn-logout-nav">
                Đăng Xuất
            </a>
        </div>

    </div>
</nav>

<main class="main-wrap">
