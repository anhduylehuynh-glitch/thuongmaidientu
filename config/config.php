<?php
// ============================================================================
// CẤU HÌNH HỆ THỐNG & BẢO MẬT
// ============================================================================

// Định nghĩa môi trường (false = Local/Development, true = Production)
define('ENV_PRODUCTION', false);

// Cấu hình hiển thị và ghi nhận lỗi theo môi trường
if (ENV_PRODUCTION) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Hàm ghi log bảo mật
function writeSecurityLog($message) {
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0700, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $log_line = "[$timestamp] [IP: $ip] $message" . PHP_EOL;
    @error_log($log_line, 3, $log_dir . '/security.log');
}

// Nạp file cấu hình session bảo mật (Đã bao gồm session_start)
require_once __DIR__ . '/session.php';

// Nạp các file middleware bảo mật dùng chung
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/csrf.php';

// Tự động kiểm tra CSRF cho mọi request thay đổi dữ liệu (trừ file cài đặt db_setup.php)
if (basename($_SERVER['SCRIPT_NAME']) !== 'db_setup.php') {
    enforceCsrf();
}

// ============================================================================
// CẤU HÌNH DATABASE (XAMPP Port 3307)
// ============================================================================
define('DB_HOST', '127.0.0.1'); // Sử dụng IP để tránh DNS lookup chậm trên Windows
define('DB_PORT', '3307');      // Port MySQL/MariaDB của XAMPP của bạn
define('DB_USER', 'root');      // Tên đăng nhập mặc định của XAMPP
define('DB_PASS', '');          // Mật khẩu mặc định của XAMPP (rỗng)
define('DB_NAME', 'thuongmaidientu');

// ============================================================================
// CẤU HÌNH GOOGLE OAUTH 2.0
// ============================================================================
// HƯỚNG DẪN: Hãy thay thế bằng Client ID & Client Secret thực tế của bạn


// Đường dẫn nhận phản hồi (Redirect URI) từ Google (Cấu hình chạy trên cổng 80)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host_only = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
define('GOOGLE_REDIRECT_URI', $protocol . '://' . $host_only . '/thuongmaidientu/callback.php');


// ============================================================================
// HÀM KẾT NỐI DATABASE DÙNG CHUNG (PDO)
// ============================================================================
function getDBConnection()
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        throw new Exception("Kết nối database thất bại (Cổng: " . DB_PORT . "): " . $e->getMessage());
    }
}
