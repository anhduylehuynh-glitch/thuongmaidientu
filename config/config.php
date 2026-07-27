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
//define('GOOGLE_CLIENT_ID', 's');
//define('GOOGLE_CLIENT_SECRET', 's');

// Đường dẫn nhận phản hồi (Redirect URI) từ Google (Tự động nhận diện cổng và đường dẫn linh hoạt)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$basePath = ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');

define('GOOGLE_REDIRECT_URI', $protocol . '://' . $host . $basePath . '/callback.php');

// ============================================================================
// CẤU HÌNH CỔNG THANH TOÁN PAYOS
// ============================================================================
define('PAYOS_CLIENT_ID', '55bc1fb2-41d8-4619-9bca-400c0a9d2983');
define('PAYOS_API_KEY', 'e2daa6c4-a08c-4da4-91fc-45e0b310db53');

// LƯU Ý: Nhấn nút Copy (sao chép) bên cạnh Checksum Key trên trang PayOS để lấy toàn bộ mã rồi dán vào đây:
define('PAYOS_CHECKSUM_KEY', '7a0e7d1896f1ca6eaaf1ad30118e12f28b9f653b5e4fd37568192ced8d3b1615');

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
