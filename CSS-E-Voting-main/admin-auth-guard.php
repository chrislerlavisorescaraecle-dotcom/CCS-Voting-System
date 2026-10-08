<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['account_id']) || !isset($_SESSION['role'])) {
    header('Location: ../landing-page/log-in.php');
    exit;
}

if (($_SESSION['role'] ?? null) !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admins only.');
}

require_once __DIR__ . '/database/connect.php';

try {
    $adminId = (int) $_SESSION['account_id'];
    $adminQuery = $conn->prepare("SELECT account_id, fullname FROM accounts WHERE account_id = ? AND role = 'admin' AND status = 'active' LIMIT 1");
    $adminQuery->bind_param('i', $adminId);
    $adminQuery->execute();
    $activeAdmin = $adminQuery->get_result()->fetch_assoc();
    $adminQuery->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Admin authorization error: ' . $exception->getMessage());
    $conn->close();
    http_response_code(500);
    exit('Administrator access is temporarily unavailable.');
}

if (!$activeAdmin) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../landing-page/log-in.php');
    exit;
}

$_SESSION['account_name'] = $activeAdmin['fullname'];