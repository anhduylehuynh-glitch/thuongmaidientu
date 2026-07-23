<?php
require_once 'config/config.php';

// Chỉ cho phép đăng xuất qua phương thức POST để tránh CSRF / Pre-fetching
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    writeSecurityLog("Unauthorized GET request to logout.php blocked");
    http_response_code(405);
    die("Phương thức không được hỗ trợ. Đăng xuất yêu cầu yêu cầu POST bảo mật.");
}

$user_id = $_SESSION['user_id'] ?? 'Unknown';

// Xóa toàn bộ Session
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

writeSecurityLog("User ID $user_id logged out successfully");

// Quay lại trang chủ
header("Location: index.php");
exit;
