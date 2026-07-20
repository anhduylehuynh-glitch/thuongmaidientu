<?php
require_once 'config/config.php';

// Hàm gửi HTTP Request bằng cURL phục vụ trao đổi token & lấy profile
function makeHttpRequest($url, $method = 'GET', $params = [], $headers = []) {
    $ch = curl_init();
    
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } else {
        $query = !empty($params) ? '?' . http_build_query($params) : '';
        curl_setopt($ch, CURLOPT_URL, $url . $query);
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Tắt kiểm tra SSL cục bộ để tránh lỗi 'certificate verify failed' phổ biến trên XAMPP Windows
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception("Lỗi kết nối HTTP: " . $error_msg);
    }
    
    curl_close($ch);
    return json_decode($response, true);
}

try {
    // 1. Kiểm tra tham số trả về từ Google
    if (!isset($_GET['code']) || !isset($_GET['state'])) {
        throw new Exception("Yêu cầu không hợp lệ. Thiếu mã code hoặc state.");
    }
    
    // 2. Xác thực State để chống tấn công CSRF
    if (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        unset($_SESSION['oauth2state']);
        throw new Exception("Xác thực State thất bại. Có thể đây là một cuộc tấn công CSRF.");
    }
    
    // Xóa state sau khi xác thực xong
    unset($_SESSION['oauth2state']);
    
    $code = $_GET['code'];
    
    // 3. Trao đổi Authorization Code lấy Access Token
    $token_url = 'https://oauth2.googleapis.com/token';
    $token_params = [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code'
    ];
    
    $token_data = makeHttpRequest($token_url, 'POST', $token_params);
    
    if (isset($token_data['error'])) {
        throw new Exception("Lỗi lấy Token từ Google: " . $token_data['error_description']);
    }
    
    $access_token = $token_data['access_token'];
    
    // 4. Gọi API Google để lấy thông tin cá nhân (Profile User)
    $profile_url = 'https://www.googleapis.com/oauth2/v3/userinfo';
    $headers = ["Authorization: Bearer " . $access_token];
    
    $profile_data = makeHttpRequest($profile_url, 'GET', [], $headers);
    
    if (isset($profile_data['error'])) {
        throw new Exception("Lỗi lấy thông tin Profile từ Google: " . $profile_data['error']['message']);
    }
    
    // Thông tin người dùng nhận được từ Google
    $google_id = $profile_data['sub'];
    $email = $profile_data['email'];
    $name = $profile_data['name'];
    $picture = isset($profile_data['picture']) ? $profile_data['picture'] : '';
    
    // 5. Kết nối Database & Xử lý đăng nhập / đăng ký
    $db = getDBConnection();
    
    // Kiểm tra xem google_id đã tồn tại trong DB chưa
    $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `google_id` = :google_id");
    $stmt->execute(['google_id' => $google_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Nếu google_id chưa có, kiểm tra xem Email đã tồn tại chưa để liên kết
        $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `Email` = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Đã có tài khoản đăng ký bằng email này trước đó -> Cập nhật liên kết Google ID
            $update_stmt = $db->prepare("UPDATE `NguoiDung` SET `google_id` = :google_id, `google_picture` = :picture WHERE `MaNguoiDung` = :id");
            $update_stmt->execute([
                'google_id' => $google_id,
                'picture'   => $picture,
                'id'        => $user['MaNguoiDung']
            ]);
            
            // Cập nhật lại thông tin user trong bộ nhớ để đăng nhập
            $user['google_id'] = $google_id;
            $user['google_picture'] = $picture;
        } else {
            // Chưa có tài khoản -> Đăng ký mới
            
            // Tạo TenDangNhap duy nhất dựa trên Email prefix
            $email_prefix = explode('@', $email)[0];
            $username = $email_prefix;
            
            // Đảm bảo username không trùng lặp và không vượt quá 50 kí tự
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM `NguoiDung` WHERE `TenDangNhap` = :username");
            $counter = 1;
            while (true) {
                $check_stmt->execute(['username' => $username]);
                if ($check_stmt->fetchColumn() == 0) {
                    break;
                }
                $suffix = "_" . $counter;
                $username = substr($email_prefix, 0, 50 - strlen($suffix)) . $suffix;
                $counter++;
            }
            
            // Thêm người dùng mới vào bảng NguoiDung
            $insert_stmt = $db->prepare("INSERT INTO `NguoiDung` 
                (`TenDangNhap`, `MatKhau`, `HoTen`, `Email`, `google_id`, `google_picture`, `DiemUyTin`, `HangThanhVien`, `TrangThaiTaiKhoan`) 
                VALUES 
                (:username, NULL, :name, :email, :google_id, :picture, 0, 'Đồng', b'1')");
            
            $insert_stmt->execute([
                'username'  => $username,
                'name'      => $name,
                'email'     => $email,
                'google_id' => $google_id,
                'picture'   => $picture
            ]);
            
            $new_user_id = $db->lastInsertId();
            
            // Gán vai trò mặc định (Ví dụ: 'BUYER') cho người dùng mới
            $role_stmt = $db->prepare("SELECT `MaVaiTro` FROM `VaiTro` WHERE `TenVaiTro` = 'BUYER'");
            $role_stmt->execute();
            $role_id = $role_stmt->fetchColumn();
            
            if (!$role_id) {
                // Nếu vai trò BUYER chưa có, tìm vai trò đầu tiên có trong bảng
                $role_stmt = $db->prepare("SELECT `MaVaiTro` FROM `VaiTro` LIMIT 1");
                $role_stmt->execute();
                $role_id = $role_stmt->fetchColumn();
            }
            
            if ($role_id) {
                $user_role_stmt = $db->prepare("INSERT INTO `NguoiDung_VaiTro` (`MaNguoiDung`, `MaVaiTro`) VALUES (:uid, :rid)");
                $user_role_stmt->execute(['uid' => $new_user_id, 'rid' => $role_id]);
            }
            
            // Lấy thông tin user vừa tạo để đăng nhập
            $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
            $stmt->execute(['id' => $new_user_id]);
            $user = $stmt->fetch();
        }
    }
    
    // Lưu thông tin người dùng vào Session
    $_SESSION['user'] = $user;
    
    // Chuyển hướng về trang chủ
    header('Location: index.php');
    exit;
    
} catch (Exception $e) {
    // Hiển thị giao diện lỗi sang trọng
    die("
    <!DOCTYPE html>
    <html lang='vi'>
    <head>
        <meta charset='UTF-8'>
        <title>Lỗi Đăng Nhập - Google Login</title>
        <link href='https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
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
                <div class='error-icon'>❌</div>
                <h2>Đăng Nhập Thất Bại</h2>
                <p style='margin-top: 15px; color: #fecaca;'>" . htmlspecialchars($e->getMessage()) . "</p>
                <a href='index.php' class='btn btn-primary'>Quay Lại Trang Chủ</a>
            </div>
        </div>
    </body>
    </html>
    ");
}
