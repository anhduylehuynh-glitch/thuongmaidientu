<?php
require_once 'config/config.php';

if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

// Tạo mã state bảo mật để tránh tấn công CSRF (Cross-Site Request Forgery)
if (empty($_SESSION['oauth2state'])) {
    $_SESSION['oauth2state'] = bin2hex(random_bytes(16));
}

$state = $_SESSION['oauth2state'];

// Các tham số cấu hình gửi lên Google OAuth v2
$params = [
    'response_type' => 'code',
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'offline',
    'prompt'        => 'select_account'
];

// Tạo URL chuyển hướng
$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

// Kiểm tra xem Client ID có bị bỏ trống hay là mặc định không
if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com' || empty(GOOGLE_CLIENT_ID)) {
    die("
    <!DOCTYPE html>
    <html lang='vi'>
    <head>
        <meta charset='UTF-8'>
        <title>Lỗi Cấu Hình - Google Login</title>
        <link href='https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap' rel='stylesheet'>
        <link rel='stylesheet' href='assets/css/style.css'>
        <style>
            .container { max-width: 500px; margin: 80px auto; }
            .card { text-align: center; }
            .error-icon { font-size: 4rem; color: #ef4444; margin-bottom: 20px; }
            .btn { margin-top: 24px; display: inline-block; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class='background-decor'></div>
        <div class='container'>
            <div class='card'>
                <div class='error-icon'>⚠️</div>
                <h2>Chưa Cấu Hình Google Client ID</h2>
                <p style='margin-top: 15px;'>Vui lòng mở file <code>config.php</code> và thay thế <code>GOOGLE_CLIENT_ID</code> cùng <code>GOOGLE_CLIENT_SECRET</code> bằng thông tin tài khoản Google Cloud Console của bạn trước khi thử nghiệm đăng nhập.</p>
                <a href='index.php' class='btn btn-primary'>Quay Lại Trang Chủ</a>
            </div>
        </div>
    </body>
    </html>
    ");
}

// Chuyển hướng người dùng sang trang đăng nhập Google
header('Location: ' . $auth_url);
exit;
