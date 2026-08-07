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
define('GOOGLE_CLIENT_ID', '387083096653-p1dpa5ml937fqe1tdefchcjohgh2p299.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

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

/**
 * Hàm lấy tổng số lượng sản phẩm trong giỏ hàng hiện tại
 */
function getCartItemCount()
{
    if (isset($_SESSION['user_id'])) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT SUM(SoLuong) FROM GioHang WHERE MaNguoiDung = :uid");
            $stmt->execute(['uid' => $_SESSION['user_id']]);
            $count = $stmt->fetchColumn();
            return $count ? (int)$count : 0;
        } catch (Exception $e) {
            return 0;
        }
    } else {
        $cart = $_SESSION['cart'] ?? [];
        $total = 0;
        foreach ($cart as $qty) {
            $total += (int)$qty;
        }
        return $total;
    }
}

/**
 * Trả về mức cộng/trừ điểm uy tín tương ứng với số sao đánh giá:
 * - 5 sao: +3 điểm
 * - 4 sao: +1 điểm
 * - 3 sao: 0 điểm
 * - 2 sao: -2 điểm
 * - 1 sao: -5 điểm (giảm nhiều nhất)
 */
function getReputationDeltaForStars(int $stars): int
{
    switch ($stars) {
        case 5:
            return 3;
        case 4:
            return 1;
        case 3:
            return 0;
        case 2:
            return -2;
        case 1:
            return -5;
        default:
            return 0;
    }
}

/**
 * Cập nhật điểm uy tín của người bán dựa trên đánh giá sản phẩm (tối đa 100, tối thiểu 0)
 */
function updateSellerReputationByProduct($db, int $productId, int $oldStars = 0, int $newStars = 0)
{
    if (!$db || $productId <= 0) return;

    // Lấy Mã người bán từ ID sản phẩm
    $stmt = $db->prepare("SELECT MaNguoiBan FROM SanPham WHERE MaSanPham = :pid");
    $stmt->execute(['pid' => $productId]);
    $sellerId = $stmt->fetchColumn();

    if (!$sellerId) return;

    $oldDelta = $oldStars > 0 ? getReputationDeltaForStars($oldStars) : 0;
    $newDelta = $newStars > 0 ? getReputationDeltaForStars($newStars) : 0;
    $netDelta = $newDelta - $oldDelta;

    if ($netDelta === 0) return;

    // Lấy điểm uy tín hiện tại của người bán
    $uStmt = $db->prepare("SELECT DiemUyTin FROM NguoiDung WHERE MaNguoiDung = :uid");
    $uStmt->execute(['uid' => $sellerId]);
    $currentRep = (int)($uStmt->fetchColumn() ?? 0);

    // Giới hạn điểm trong khoảng từ 0 đến 100
    $updatedRep = max(0, min(100, $currentRep + $netDelta));

    // Cập nhật lại vào cơ sở dữ liệu
    $upStmt = $db->prepare("UPDATE NguoiDung SET DiemUyTin = :rep WHERE MaNguoiDung = :uid");
    $upStmt->execute(['rep' => $updatedRep, 'uid' => $sellerId]);
}

/**
 * Kiểm tra số điện thoại có đúng định dạng nhà mạng Việt Nam (10 số) hay không
 */
function isValidVNPhoneNumber($phone): bool
{
    $cleanPhone = preg_replace('/[\s\-\.]/', '', trim((string)$phone));
    $pattern = '/^(03|05|07|08|09)[0-9]{8}$/';
    return preg_match($pattern, $cleanPhone) === 1;
}

// ============================================================================
// CẤU HÌNH GMAIL SMTP (DÙNG ĐỂ GỬI MÃ OTP XÁC THỰC & QUÊN MẬT KHẨU)
// ============================================================================
define('SMTP_HOST', 'ssl://smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'anhduylehuynh@gmail.com');
define('SMTP_PASS', 'wqupdhvxfsgfpmmm');
define('SMTP_FROM_NAME', 'Chợ Đồ Cũ');

/**
 * Gửi Email chứa mã OTP 6 số qua Gmail SMTP
 */
function sendOTPEmail(string $toEmail, string $otpCode, string $purpose = 'register'): bool
{
    $socket = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 12);
    if (!$socket) {
        return false;
    }

    $read = function ($socket) {
        $response = '';
        while ($str = @fgets($socket, 512)) {
            $response .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        return $response;
    };

    $write = function ($socket, $cmd) {
        @fputs($socket, $cmd . "\r\n");
    };

    $read($socket); // 220 greeting

    $write($socket, "EHLO localhost");
    $read($socket);

    $write($socket, "AUTH LOGIN");
    $read($socket);

    $write($socket, base64_encode(SMTP_USER));
    $read($socket);

    $write($socket, base64_encode(SMTP_PASS));
    $authResp = $read($socket);

    if (strpos($authResp, '235') === false) {
        @fclose($socket);
        return false;
    }

    $write($socket, "MAIL FROM: <" . SMTP_USER . ">");
    $read($socket);

    $write($socket, "RCPT TO: <" . $toEmail . ">");
    $read($socket);

    $write($socket, "DATA");
    $read($socket);

    $title = ($purpose === 'forgot') ? 'Khôi phục Mật khẩu' : 'Xác thực Đăng ký Tài khoản';
    $actionDesc = ($purpose === 'forgot') 
        ? 'Dưới đây là mã OTP 6 chữ số để xác nhận đặt lại mật khẩu cho tài khoản của bạn trên hệ thống <strong>Chợ Đồ Cũ</strong>:' 
        : 'Cảm ơn bạn đã đăng ký tài khoản tại <strong>Chợ Đồ Cũ</strong>! Dưới đây là mã xác thực OTP của bạn:';

    $subject = "[Chợ Đồ Cũ] Mã xác thực OTP ($title): $otpCode";

    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 520px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 16px; background-color: #ffffff;">
        <div style="text-align: center; padding-bottom: 16px; border-bottom: 2px solid #3b82f6;">
            <h2 style="color: #1e3a8a; margin: 0; font-size: 22px;">🛒 CHỢ ĐỒ CŨ</h2>
            <p style="color: #64748b; font-size: 13px; margin-top: 4px;">Nền tảng mua bán đồ cũ trực tuyến hiện đại</p>
        </div>
        <div style="padding: 20px 0; color: #334155; line-height: 1.6; font-size: 14px;">
            <p>Xin chào,</p>
            <p>' . $actionDesc . '</p>
            <div style="text-align: center; margin: 24px 0;">
                <span style="display: inline-block; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #2563eb; background: #eff6ff; padding: 12px 28px; border-radius: 12px; border: 1px dashed #60a5fa;">
                    ' . $otpCode . '
                </span>
            </div>
            <p style="color: #ef4444; font-size: 13px; text-align: center; font-weight: bold;">
                ⚠️ Mã OTP này có hiệu lực trong 5 phút. Vui lòng tuyệt đối không chia sẻ mã này với bất kỳ ai!
            </p>
        </div>
        <div style="text-align: center; padding-top: 16px; border-top: 1px solid #f1f5f9; color: #94a3b8; font-size: 12px;">
            <p style="margin: 0;">Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email này.</p>
            <p style="margin: 4px 0 0;">&copy; 2026 Chợ Đồ Cũ Inc.</p>
        </div>
    </div>';

    $headers  = "From: =?UTF-8?B?" . base64_encode(SMTP_FROM_NAME) . "?= <" . SMTP_USER . ">\r\n";
    $headers .= "Reply-To: <" . SMTP_USER . ">\r\n";
    $headers .= "To: <" . $toEmail . ">\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    $content = $headers . "\r\n" . $body . "\r\n.\r\n";

    $write($socket, $content);
    $dataResp = $read($socket);

    $write($socket, "QUIT");
    @fclose($socket);

    return strpos($dataResp, '250') !== false;
}

