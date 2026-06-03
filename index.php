<?php
session_start();
require_once __DIR__ . '/config/constants.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;