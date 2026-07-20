<?php
require_once 'config/config.php';

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

// Quay lại trang chủ
header("Location: index.php");
exit;
