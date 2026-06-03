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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<nav class="navbar">
    <div class="navbar-brand">
        <a href="<?= BASE_URL ?>/pages/dashboard.php"><?= APP_NAME ?></a>
    </div>
    <ul class="navbar-menu">
        <li>
            <a href="<?= BASE_URL ?>/pages/dashboard.php"
               class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                Tổng Quan
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/pages/rooms.php"
               class="<?= $current_page === 'rooms.php' ? 'active' : '' ?>">
                Phòng
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/pages/tenants.php"
               class="<?= $current_page === 'tenants.php' ? 'active' : '' ?>">
                Khách Thuê
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/pages/contracts.php"
               class="<?= $current_page === 'contracts.php' ? 'active' : '' ?>">
                Hợp Đồng
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/pages/utilities.php"
               class="<?= $current_page === 'utilities.php' ? 'active' : '' ?>">
                Điện Nước
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>/pages/invoices.php"
               class="<?= $current_page === 'invoices.php' ? 'active' : '' ?>">
                Hóa Đơn
            </a>
        </li>
    </ul>
    <div class="navbar-user">
        <span>Xin chào, <strong><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></strong></span>
        <a href="<?= BASE_URL ?>/login.php?action=logout" class="btn-logout">Đăng Xuất</a>
    </div>
</nav>
<main class="main-content">