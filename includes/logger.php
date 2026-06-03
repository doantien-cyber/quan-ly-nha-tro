<?php
// Ghi một dòng log vào logs/app.log
function ghi_log(string $action, string $detail = ''): void {
    $time   = date('Y-m-d H:i:s');
    $user   = $_SESSION['username'] ?? 'anonymous';
    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $detail = $detail !== '' ? " $detail" : '';
    $line   = "[$time] USER=$user IP=$ip ACTION=$action$detail\n";
    file_put_contents(__DIR__ . '/../logs/app.log', $line, FILE_APPEND | LOCK_EX);
}