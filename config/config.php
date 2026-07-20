<?php
// Khởi tạo session nếu chưa được bật
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');

// Đường dẫn nhận phản hồi (Redirect URI) từ Google (Chỉ định rõ cổng 8080 của XAMPP Apache)
define('GOOGLE_REDIRECT_URI', 'http://localhost:8080/thuongmaidientu/callback.php');

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
