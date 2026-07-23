<?php
// ============================================================================
// CẤU HÌNH PHP SESSION BẢO MẬT & TIMEOUT
// ============================================================================

// 1. Cấu hình Cookie và Session an toàn trước khi start session
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);
ini_set('session.use_strict_mode', 1);

// Tự động nhận diện HTTPS để đặt cờ Secure cho cookie
$is_secure = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $is_secure = true;
} elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $is_secure = true;
}

// Cấu hình cookie params với SameSite = Lax, HttpOnly = true
session_set_cookie_params([
    'lifetime' => 0, // Hết hạn khi đóng trình duyệt
    'path'     => '/',
    'domain'   => '', // Mặc định tên miền hiện tại
    'secure'   => $is_secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Khởi tạo session nếu chưa được bật
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Kiểm tra Timeout (chỉ áp dụng cho người dùng đã đăng nhập)
if (isset($_SESSION['user_id'])) {
    $now = time();
    $is_timeout = false;
    $timeout_type = '';

    // A. Idle Timeout: 15 phút không hoạt động (15 * 60 = 900 giây)
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > 900)) {
        $is_timeout = true;
        $timeout_type = 'idle';
    }
    // B. Absolute Timeout: 8 giờ kể từ khi đăng nhập (8 * 3600 = 28800 giây)
    elseif (isset($_SESSION['login_time']) && ($now - $_SESSION['login_time'] > 28800)) {
        $is_timeout = true;
        $timeout_type = 'absolute';
    }

    if ($is_timeout) {
        // Ghi log bảo mật khi phiên làm việc hết hạn
        if (function_exists('writeSecurityLog')) {
            writeSecurityLog("Session timeout ($timeout_type) for User ID: " . $_SESSION['user_id']);
        }

        // Hủy session trên server
        $_SESSION = [];
        session_destroy();

        // Xóa cookie session trên client
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Kiểm tra trang hiện tại
        $current_page = basename($_SERVER['SCRIPT_NAME']);
        $public_pages = ['index.php', 'seller.php'];

        // Nếu là trang bảo mật thì buộc chuyển hướng về trang đăng nhập
        if (!in_array($current_page, $public_pages)) {
            header("Location: login_page.php?timeout=" . $timeout_type);
            exit;
        }
    } else {
        // Cập nhật thời điểm hoạt động cuối cùng
        $_SESSION['last_activity'] = $now;
    }
}
