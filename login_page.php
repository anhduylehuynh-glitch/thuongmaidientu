<?php
require_once 'config/config.php';

// Nếu đã đăng nhập thì chuyển hướng thẳng về trang chủ
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = $_GET['error'] ?? '';
$success = '';

// Xử lý gửi Form đăng ký / đăng nhập thông thường
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_login'])) {
        $username_or_email = trim($_POST['username_or_email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username_or_email) || empty($password)) {
            $error = 'Vui lòng nhập đầy đủ tên đăng nhập/email và mật khẩu.';
        } else {
            // Kiểm tra chống brute-force trước
            if (!checkBruteForce($username_or_email)) {
                $error = 'Tài khoản của bạn đã bị tạm khóa đăng nhập 15 phút do thử sai quá 5 lần.';
                writeSecurityLog("Brute-force lockout triggered for identifier: " . $username_or_email);
            } else {
                try {
                    $db = getDBConnection();
                    $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `TenDangNhap` = :login OR `Email` = :email");
                    $stmt->execute(['login' => $username_or_email, 'email' => $username_or_email]);
                    $user = $stmt->fetch();

                    // Kiểm tra trạng thái hoạt động của tài khoản
                    $is_active = false;
                    if ($user) {
                        $status_val = $user['TrangThaiTaiKhoan'] ?? null;
                        if (is_null($status_val)) {
                            $is_active = true;
                        } elseif (is_int($status_val)) {
                            $is_active = $status_val === 1;
                        } elseif (is_string($status_val)) {
                            if (strlen($status_val) === 1) {
                                $is_active = (ord($status_val) === 1 || $status_val === '1');
                            } else {
                                $is_active = ($status_val === '1');
                            }
                        } else {
                            $is_active = (bool)$status_val;
                        }
                    }

                    if ($user && $is_active && !empty($user['MatKhau']) && password_verify($password, $user['MatKhau'])) {
                        // Đăng nhập thành công, xóa lịch sử sai
                        clearFailedLogins($username_or_email);

                        // Chống Session Fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['MaNguoiDung'];
                        $_SESSION['user'] = $user;
                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();

                        writeSecurityLog("User ID " . $user['MaNguoiDung'] . " logged in successfully");

                        $redirect = $_GET['redirect'] ?? 'index.php';
                        $allowed_redirects = ['index.php', 'post_product.php', 'profile.php', 'seller.php', 'admin.php'];
                        $parsed = parse_url($redirect);
                        $path = basename($parsed['path'] ?? '');
                        if (!in_array($path, $allowed_redirects)) {
                            $redirect = 'index.php';
                        }
                        header("Location: " . $redirect);
                        exit;
                    } else {
                        // Ghi nhận đăng nhập thất bại để chống brute-force
                        recordFailedLogin($username_or_email);
                        writeSecurityLog("Failed login attempt for: " . $username_or_email);

                        $error = 'Thông tin đăng nhập không chính xác.';
                    }
                } catch (Exception $e) {
                    $error = 'Lỗi hệ thống, vui lòng thử lại sau.';
                }
            }
        }
    } elseif (isset($_POST['action_register'])) {
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($fullname) || empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
            $error = 'Vui lòng điền đầy đủ các thông tin bắt buộc.';
            $active_tab = 'register';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Định dạng email không hợp lệ.';
            $active_tab = 'register';
        } elseif (!empty($phone) && !isValidVNPhoneNumber($phone)) {
            $error = 'Số điện thoại không hợp lệ (phải gồm 10 chữ số và bắt đầu bằng 03, 05, 07, 08, 09).';
            $active_tab = 'register';
        } elseif (mb_strlen($password) < 6) {
            $error = 'Mật khẩu phải có ít nhất 6 ký tự.';
            $active_tab = 'register';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $error = 'Mật khẩu phải chứa ít nhất 1 chữ cái viết hoa, 1 chữ cái viết thường, 1 chữ số và 1 ký tự đặc biệt.';
            $active_tab = 'register';
        } elseif ($password !== $confirm_password) {
            $error = 'Mật khẩu xác nhận không khớp.';
            $active_tab = 'register';
        } else {
            try {
                $db = getDBConnection();

                // Kiểm tra trùng lặp tên đăng nhập hoặc email
                $stmt = $db->prepare("SELECT COUNT(*) FROM `NguoiDung` WHERE `TenDangNhap` = :username OR `Email` = :email");
                $stmt->execute(['username' => $username, 'email' => $email]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Tên đăng nhập hoặc Email đã tồn tại trên hệ thống.';
                    $active_tab = 'register';
                } else {
                    // Tạo mã OTP 6 chữ số
                    $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                    // Lưu thông tin tạm vào SESSION
                    $_SESSION['pending_register'] = [
                        'fullname' => $fullname,
                        'username' => $username,
                        'email'    => $email,
                        'phone'    => !empty($phone) ? $phone : null,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                    ];
                    $_SESSION['otp_data'] = [
                        'code'    => $otp_code,
                        'email'   => $email,
                        'expire'  => time() + 300, // Hết hạn sau 5 phút
                        'purpose' => 'register'
                    ];

                    // Gửi Email OTP
                    if (sendOTPEmail($email, $otp_code, 'register')) {
                        $success = "Mã xác thực OTP đã được gửi đến email <strong>" . htmlspecialchars($email) . "</strong>. Vui lòng nhập mã để hoàn tất đăng ký.";
                        $active_tab = 'verify_register_otp';
                    } else {
                        $error = "Không thể gửi email OTP đến <strong>" . htmlspecialchars($email) . "</strong>. Vui lòng kiểm tra lại địa chỉ email hoặc thử lại sau.";
                        $active_tab = 'register';
                    }
                }
            } catch (Exception $e) {
                $error = 'Lỗi hệ thống đăng ký: ' . $e->getMessage();
                $active_tab = 'register';
            }
        }
    } elseif (isset($_POST['action_verify_register_otp'])) {
        $otp_code = trim($_POST['otp_code'] ?? '');
        $pending = $_SESSION['pending_register'] ?? null;
        $otp_data = $_SESSION['otp_data'] ?? null;

        if (!$pending || !$otp_data || $otp_data['purpose'] !== 'register') {
            $error = 'Phiên xác thực OTP đã hết hạn hoặc không hợp lệ. Vui lòng đăng ký lại.';
            $active_tab = 'register';
        } elseif (empty($otp_code)) {
            $error = 'Vui lòng nhập mã OTP 6 chữ số.';
            $active_tab = 'verify_register_otp';
        } elseif (time() > $otp_data['expire']) {
            $error = 'Mã OTP đã hết hạn (5 phút). Vui lòng bấm gửi lại mã.';
            $active_tab = 'verify_register_otp';
        } elseif ($otp_code !== $otp_data['code']) {
            $error = 'Mã OTP không chính xác. Vui lòng kiểm tra lại email.';
            $active_tab = 'verify_register_otp';
        } else {
            try {
                $db = getDBConnection();

                // Kiểm tra lại trùng lặp trước khi lưu
                $stmt = $db->prepare("SELECT COUNT(*) FROM `NguoiDung` WHERE `TenDangNhap` = :username OR `Email` = :email");
                $stmt->execute(['username' => $pending['username'], 'email' => $pending['email']]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Tên đăng nhập hoặc Email đã tồn tại.';
                    $active_tab = 'register';
                } else {
                    // Thêm người dùng mới chính thức vào CSDL
                    $insert_stmt = $db->prepare("INSERT INTO `NguoiDung` 
                        (`TenDangNhap`, `MatKhau`, `HoTen`, `Email`, `SoDienThoai`, `DiemUyTin`, `HangThanhVien`, `TrangThaiTaiKhoan`) 
                        VALUES 
                        (:username, :password, :fullname, :email, :phone, 0, 'Đồng', b'1')");

                    $insert_stmt->execute([
                        'username' => $pending['username'],
                        'password' => $pending['password'],
                        'fullname' => $pending['fullname'],
                        'email'    => $pending['email'],
                        'phone'    => $pending['phone']
                    ]);

                    $new_user_id = $db->lastInsertId();

                    // Gán vai trò mặc định (BUYER)
                    $role_stmt = $db->prepare("SELECT `MaVaiTro` FROM `VaiTro` WHERE `TenVaiTro` = 'BUYER'");
                    $role_stmt->execute();
                    $role_id = $role_stmt->fetchColumn();

                    if (!$role_id) {
                        $role_stmt = $db->prepare("SELECT `MaVaiTro` FROM `VaiTro` LIMIT 1");
                        $role_stmt->execute();
                        $role_id = $role_stmt->fetchColumn();
                    }

                    if ($role_id) {
                        $user_role_stmt = $db->prepare("INSERT INTO `NguoiDung_VaiTro` (`MaNguoiDung`, `MaVaiTro`) VALUES (:uid, :rid)");
                        $user_role_stmt->execute(['uid' => $new_user_id, 'rid' => $role_id]);
                    }

                    // Tự động đăng nhập
                    $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
                    $stmt->execute(['id' => $new_user_id]);
                    $user = $stmt->fetch();

                    // Xóa dữ liệu OTP tạm
                    unset($_SESSION['pending_register'], $_SESSION['otp_data']);

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['MaNguoiDung'];
                    $_SESSION['user'] = $user;
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();

                    writeSecurityLog("User ID " . $user['MaNguoiDung'] . " verified email and registered successfully");

                    $redirect = $_GET['redirect'] ?? 'index.php';
                    $allowed_redirects = ['index.php', 'post_product.php', 'profile.php', 'seller.php', 'admin.php'];
                    $parsed = parse_url($redirect);
                    $path = basename($parsed['path'] ?? '');
                    if (!in_array($path, $allowed_redirects)) {
                        $redirect = 'index.php';
                    }
                    header("Location: " . $redirect);
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Lỗi lưu tài khoản: ' . $e->getMessage();
                $active_tab = 'verify_register_otp';
            }
        }
    } elseif (isset($_POST['action_request_forgot_otp'])) {
        $forgot_email = trim($_POST['forgot_email'] ?? '');

        if (empty($forgot_email) || !filter_var($forgot_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Vui lòng nhập địa chỉ email hợp lệ.';
            $active_tab = 'forgot';
        } else {
            try {
                $db = getDBConnection();
                $stmt = $db->prepare("SELECT MaNguoiDung FROM `NguoiDung` WHERE `Email` = :email");
                $stmt->execute(['email' => $forgot_email]);
                $user_id = $stmt->fetchColumn();

                if (!$user_id) {
                    $error = 'Địa chỉ Email này chưa được đăng ký trên hệ thống.';
                    $active_tab = 'forgot';
                } else {
                    $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $_SESSION['otp_data'] = [
                        'code'    => $otp_code,
                        'email'   => $forgot_email,
                        'expire'  => time() + 300,
                        'purpose' => 'forgot'
                    ];

                    if (sendOTPEmail($forgot_email, $otp_code, 'forgot')) {
                        $success = "Mã OTP khôi phục mật khẩu đã được gửi đến <strong>" . htmlspecialchars($forgot_email) . "</strong>. Vui lòng kiểm tra hộp thư!";
                        $active_tab = 'verify_forgot_otp';
                    } else {
                        $error = "Không thể gửi email OTP. Vui lòng thử lại sau.";
                        $active_tab = 'forgot';
                    }
                }
            } catch (Exception $e) {
                $error = 'Lỗi hệ thống: ' . $e->getMessage();
                $active_tab = 'forgot';
            }
        }
    } elseif (isset($_POST['action_verify_forgot_otp'])) {
        $otp_code = trim($_POST['otp_code'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_new_password = $_POST['confirm_new_password'] ?? '';
        $otp_data = $_SESSION['otp_data'] ?? null;

        if (!$otp_data || $otp_data['purpose'] !== 'forgot') {
            $error = 'Phiên xác thực đã hết hạn. Vui lòng yêu cầu lại mã OTP.';
            $active_tab = 'forgot';
        } elseif (empty($otp_code)) {
            $error = 'Vui lòng nhập mã OTP.';
            $active_tab = 'verify_forgot_otp';
        } elseif (time() > $otp_data['expire']) {
            $error = 'Mã OTP đã hết hạn (5 phút). Vui lòng yêu cầu lại mã.';
            $active_tab = 'verify_forgot_otp';
        } elseif ($otp_code !== $otp_data['code']) {
            $error = 'Mã OTP không chính xác.';
            $active_tab = 'verify_forgot_otp';
        } elseif (mb_strlen($new_password) < 6) {
            $error = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
            $active_tab = 'verify_forgot_otp';
        } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password) || !preg_match('/[^a-zA-Z0-9]/', $new_password)) {
            $error = 'Mật khẩu mới phải chứa ít nhất 1 chữ cái viết hoa, 1 chữ cái viết thường, 1 chữ số và 1 ký tự đặc biệt.';
            $active_tab = 'verify_forgot_otp';
        } elseif ($new_password !== $confirm_new_password) {
            $error = 'Mật khẩu xác nhận không khớp.';
            $active_tab = 'verify_forgot_otp';
        } else {
            try {
                $db = getDBConnection();
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE `NguoiDung` SET `MatKhau` = :pass WHERE `Email` = :email");
                $stmt->execute(['pass' => $hashed_password, 'email' => $otp_data['email']]);

                writeSecurityLog("Password reset successfully for email: " . $otp_data['email']);
                unset($_SESSION['otp_data']);

                $success = 'Đặt lại mật khẩu thành công! Vui lòng đăng nhập bằng mật khẩu mới.';
                $active_tab = 'login';
            } catch (Exception $e) {
                $error = 'Lỗi cập nhật mật khẩu: ' . $e->getMessage();
                $active_tab = 'verify_forgot_otp';
            }
        }
    } elseif (isset($_POST['action_resend_otp'])) {
        $otp_data = $_SESSION['otp_data'] ?? null;
        if ($otp_data && !empty($otp_data['email'])) {
            $new_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_data']['code'] = $new_code;
            $_SESSION['otp_data']['expire'] = time() + 300;

            if (sendOTPEmail($otp_data['email'], $new_code, $otp_data['purpose'])) {
                $success = "Đã gửi lại mã OTP mới đến email <strong>" . htmlspecialchars($otp_data['email']) . "</strong>.";
            } else {
                $error = "Không thể gửi lại email. Vui lòng thử lại sau.";
            }
            $active_tab = ($otp_data['purpose'] === 'forgot') ? 'verify_forgot_otp' : 'verify_register_otp';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập / Đăng Ký Hệ Thống</title>
    <!-- Google Fonts Inter & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .container {
            max-width: 520px;
            margin: 60px auto;
            width: 100%;
            padding: 0 16px;
        }
        .password-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .password-input-wrapper input {
            padding-right: 42px !important;
            width: 100%;
        }
        .password-toggle-btn {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
            z-index: 5;
        }
        .password-toggle-btn:hover {
            color: var(--primary, #2563eb);
        }
    </style>
</head>
<body>
    <!-- Background hiệu ứng mờ và vòng tròn màu sắc -->
    <div class="background-decor"></div>

    <div class="site-wrapper">
        <!-- Header / Navigation Bar -->
        <header class="site-header">
            <div class="nav-container">
                <a href="index.php" class="brand-logo">
                    Chợ Đồ Cũ
                </a>
                
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Trang Chủ</a>
                    <a href="#" class="nav-link">Sản Phẩm</a>
                    <a href="post_product.php" class="nav-link" style="color: var(--primary); font-weight: 700;">Đăng Bán</a>
                    <a href="login_page.php" class="btn btn-primary" style="padding: 8px 18px; font-size: 0.9rem; border-radius: 50px;">Đăng Nhập</a>
                </nav>
            </div>
        </header>

        <div class="container">
            <div class="card">
            <!-- GIAO DIỆN ĐĂNG NHẬP / ĐĂNG KÝ -->
            <div style="text-align: center;">
                <h1 style="margin-bottom: 20px;">Hệ Thống Đăng Nhập</h1>

                <!-- Thông báo lỗi hoặc thành công -->
                <?php if (!empty($error)): ?>
                    <div class="alert-message error">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                    <div class="alert-message success">
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <!-- Khung chuyển Tabs -->
                <div class="tabs-container">
                    <button type="button" id="login-tab-btn" class="tab-btn active" onclick="switchTab('login')">Đăng Nhập</button>
                    <button type="button" id="register-tab-btn" class="tab-btn" onclick="switchTab('register')">Đăng Ký</button>
                    <button type="button" id="forgot-tab-btn" class="tab-btn" onclick="switchTab('forgot')">Quên Mật Khẩu</button>
                </div>

                <!-- Form Đăng Nhập -->
                <div id="login-view" class="form-view active">
                    <form method="POST" action="login_page.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <div class="form-group">
                            <label for="login_username">Tên đăng nhập hoặc Email</label>
                            <input type="text" name="username_or_email" id="login_username" class="form-control" placeholder="Nhập tên đăng nhập hoặc email" required>
                        </div>
                        <div class="form-group">
                            <label for="login_password">Mật khẩu</label>
                            <div class="password-input-wrapper">
                                <input type="password" name="password" id="login_password" class="form-control" placeholder="••••••••" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('login_password', this)" title="Hiện mật khẩu">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            <div style="text-align: right; margin-top: 6px;">
                                <a href="#" onclick="switchTab('forgot'); return false;" style="color: var(--primary); font-size: 13px; font-weight: 500; text-decoration: none;">Quên mật khẩu?</a>
                            </div>
                        </div>
                        <button type="submit" name="action_login" class="btn btn-primary" style="margin-top: 10px;">Đăng Nhập</button>
                    </form>
                </div>

                <!-- Form Đăng Ký -->
                <div id="register-view" class="form-view">
                    <form method="POST" action="login_page.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>" id="registerForm" onsubmit="return validateRegisterForm(event)">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <div class="form-group">
                            <label for="reg_fullname">Họ và tên <span style="color:var(--error)">*</span></label>
                            <input type="text" name="fullname" id="reg_fullname" class="form-control" placeholder="Nguyễn Văn A" required>
                        </div>
                        <div class="form-group">
                            <label for="reg_username">Tên đăng nhập <span style="color:var(--error)">*</span></label>
                            <input type="text" name="username" id="reg_username" class="form-control" placeholder="username123" required>
                        </div>
                        <div class="form-group">
                            <label for="reg_email">Địa chỉ Email <span style="color:var(--error)">*</span></label>
                            <input type="email" name="email" id="reg_email" class="form-control" placeholder="email@example.com" required>
                        </div>
                        <div class="form-group">
                            <label for="reg_phone">Số điện thoại</label>
                            <input type="tel" name="phone" id="reg_phone" class="form-control" placeholder="0905123456" maxlength="10" pattern="(03|05|07|08|09)[0-9]{8}">
                            <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block; text-align: left;">
                                Số điện thoại di động Việt Nam gồm 10 chữ số (bắt đầu bằng 03, 05, 07, 08, 09).
                            </small>
                        </div>
                        <div class="form-group">
                            <label for="reg_password">Mật khẩu <span style="color:var(--error)">*</span></label>
                            <div class="password-input-wrapper">
                                <input type="password" name="password" id="reg_password" class="form-control" placeholder="••••••••" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('reg_password', this)" title="Hiện mật khẩu">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block; text-align: left;">
                                Mật khẩu phải có ít nhất 6 ký tự (bao gồm ít nhất 1 chữ viết hoa, 1 chữ viết thường, 1 chữ số và 1 ký tự đặc biệt).
                            </small>
                        </div>
                        <div class="form-group">
                            <label for="reg_confirm_password">Xác nhận mật khẩu <span style="color:var(--error)">*</span></label>
                            <div class="password-input-wrapper">
                                <input type="password" name="confirm_password" id="reg_confirm_password" class="form-control" placeholder="••••••••" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('reg_confirm_password', this)" title="Hiện mật khẩu">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                        </div>
                        <button type="submit" name="action_register" class="btn btn-primary" style="margin-top: 10px;">Đăng Ký & Gửi Mã OTP</button>
                    </form>
                </div>

                <!-- Form Xác Thực OTP Đăng Ký -->
                <div id="verify_register_otp-view" class="form-view">
                    <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 8px;">Xác Thực Email Qua OTP</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px;">Vui lòng kiểm tra hộp thư Gmail và nhập mã OTP 6 chữ số để kích hoạt tài khoản.</p>
                    <div style="background: rgba(254, 243, 199, 0.6); border: 1px solid #fde68a; border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; text-align: left; font-size: 12.5px; color: #92400e; line-height: 1.5;">
                        💡 <strong>Lưu ý quan trọng:</strong> Nếu không thấy email trong Hộp thư đến (Inbox), bạn hãy kiểm tra thêm trong thư mục <strong>Thư rác (Spam)</strong> hoặc <strong>Quảng cáo (Promotions)</strong> nhé!
                    </div>
                    <form method="POST" action="login_page.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <div class="form-group">
                            <label for="otp_code_reg">Mã OTP 6 chữ số <span style="color:var(--error)">*</span></label>
                            <input type="text" name="otp_code" id="otp_code_reg" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" style="text-align: center; font-size: 1.4rem; letter-spacing: 6px; font-weight: 700;" required>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 14px;">
                            <button type="submit" name="action_verify_register_otp" class="btn btn-primary" style="flex: 1;">Xác Nhận Kích Hoạt</button>
                        </div>
                    </form>
                    <form method="POST" action="login_page.php" style="margin-top: 10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <button type="submit" name="action_resend_otp" class="btn" style="width: 100%; background: transparent; color: var(--primary); text-decoration: underline; font-size: 13px;">Gửi lại mã OTP qua Gmail</button>
                    </form>
                </div>

                <!-- Form Yêu Cầu Quên Mật Khẩu -->
                <div id="forgot-view" class="form-view">
                    <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 8px;">Khôi Phục Mật Khẩu</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;">Nhập email đã đăng ký của bạn để nhận mã OTP đặt lại mật khẩu.</p>
                    <form method="POST" action="login_page.php">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <div class="form-group">
                            <label for="forgot_email">Địa chỉ Email <span style="color:var(--error)">*</span></label>
                            <input type="email" name="forgot_email" id="forgot_email" class="form-control" placeholder="email@example.com" required>
                        </div>
                        <button type="submit" name="action_request_forgot_otp" class="btn btn-primary" style="margin-top: 10px;">Gửi Mã OTP Khôi Phục</button>
                    </form>
                </div>

                <!-- Form Xác Thực OTP & Đặt Lại Mật Khẩu Mới -->
                <div id="verify_forgot_otp-view" class="form-view">
                    <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 8px;">Đặt Lại Mật Khẩu Mới</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px;">Nhập mã OTP vừa nhận trong Gmail và mật khẩu mới của bạn.</p>
                    <div style="background: rgba(254, 243, 199, 0.6); border: 1px solid #fde68a; border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; text-align: left; font-size: 12.5px; color: #92400e; line-height: 1.5;">
                        💡 <strong>Lưu ý quan trọng:</strong> Nếu không thấy email trong Hộp thư đến (Inbox), bạn hãy kiểm tra thêm trong thư mục <strong>Thư rác (Spam)</strong> hoặc <strong>Quảng cáo (Promotions)</strong> nhé!
                    </div>
                    <form method="POST" action="login_page.php">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <div class="form-group">
                            <label for="otp_code_forgot">Mã OTP 6 chữ số <span style="color:var(--error)">*</span></label>
                            <input type="text" name="otp_code" id="otp_code_forgot" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" style="text-align: center; font-size: 1.4rem; letter-spacing: 6px; font-weight: 700;" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">Mật khẩu mới <span style="color:var(--error)">*</span></label>
                            <div class="password-input-wrapper">
                                <input type="password" name="new_password" id="new_password" class="form-control" placeholder="••••••••" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('new_password', this)" title="Hiện mật khẩu">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_new_password">Xác nhận mật khẩu mới <span style="color:var(--error)">*</span></label>
                            <div class="password-input-wrapper">
                                <input type="password" name="confirm_new_password" id="confirm_new_password" class="form-control" placeholder="••••••••" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirm_new_password', this)" title="Hiện mật khẩu">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                        </div>
                        <button type="submit" name="action_verify_forgot_otp" class="btn btn-primary" style="margin-top: 10px;">Lưu Mật Khẩu Mới</button>
                    </form>
                    <form method="POST" action="login_page.php" style="margin-top: 10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <button type="submit" name="action_resend_otp" class="btn" style="width: 100%; background: transparent; color: var(--primary); text-decoration: underline; font-size: 13px;">Gửi lại mã OTP qua Gmail</button>
                    </form>
                </div>

                <!-- Đường chia và nút Đăng nhập bằng Google -->
                <div class="divider-container">Hoặc đăng nhập bằng</div>

                <!-- Nút Đăng nhập Google -->
                <a href="login.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>" class="btn-google">
                    Đăng nhập bằng tài khoản Google
                </a>

                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.08); font-size: 13px;">
                    <a href="index.php" style="color: var(--primary); text-decoration: none; font-weight: 500;">
                        Quay lại Trang Chủ
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="footer-content">
            <div class="footer-brand">Chợ Đồ Cũ</div>
            <p class="footer-text">
                Nền tảng mua bán đồ cũ trực tuyến hiện đại, kết nối thông minh và giao dịch an toàn với hệ thống điểm uy tín cao.
            </p>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 10px;">
                &copy; 2026 Chợ Đồ Cũ Inc. Bảo lưu mọi quyền.
            </div>
        </div>
    </footer>
</div>

    <script>
        function validateRegisterForm(e) {
            const phone = document.getElementById('reg_phone').value.trim();
            const phoneRegex = /^(03|05|07|08|09)[0-9]{8}$/;

            if (phone !== '' && !phoneRegex.test(phone)) {
                alert('Số điện thoại không hợp lệ! Vui lòng nhập số điện thoại Việt Nam gồm 10 số (bắt đầu bằng 03, 05, 07, 08, 09).');
                e.preventDefault();
                return false;
            }

            const password = document.getElementById('reg_password').value;
            const confirmPassword = document.getElementById('reg_confirm_password').value;

            if (password.length < 6) {
                alert('Mật khẩu phải có ít nhất 6 ký tự.');
                e.preventDefault();
                return false;
            }

            const hasUpper = /[A-Z]/.test(password);
            const hasLower = /[a-z]/.test(password);
            const hasDigit = /[0-9]/.test(password);
            const hasSpecial = /[^a-zA-Z0-9]/.test(password);

            if (!hasUpper || !hasLower || !hasDigit || !hasSpecial) {
                alert('Mật khẩu phải chứa ít nhất 1 chữ cái viết hoa, 1 chữ cái viết thường, 1 chữ số và 1 ký tự đặc biệt.');
                e.preventDefault();
                return false;
            }

            if (password !== confirmPassword) {
                alert('Mật khẩu xác nhận không khớp.');
                e.preventDefault();
                return false;
            }

            return true;
        }

        function switchTab(tabName) {
            let buttonTab = tabName;
            if (tabName === 'verify_register_otp') buttonTab = 'register';
            if (tabName === 'verify_forgot_otp') buttonTab = 'forgot';

            // Deactivate all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            // Hide all form views
            document.querySelectorAll('.form-view').forEach(view => {
                view.classList.remove('active');
            });

            // Activate chosen tab button
            const activeBtn = document.getElementById(buttonTab + '-tab-btn');
            if (activeBtn) activeBtn.classList.add('active');
            
            // Show chosen form view
            const activeView = document.getElementById(tabName + '-view');
            if (activeView) activeView.classList.add('active');

            // Save active tab in sessionStorage so it persists on reload if needed
            sessionStorage.setItem('active_auth_tab', tabName);
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            const eyeOpen = `<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
            const eyeSlash = `<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;

            btn.innerHTML = isPassword ? eyeSlash : eyeOpen;
            btn.setAttribute('title', isPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
        }

        // Initialize default tab (either login, register, forgot, or verify)
        window.addEventListener('DOMContentLoaded', () => {
            const phpActiveTab = '<?php echo $active_tab ?? ""; ?>';
            const savedTab = phpActiveTab || sessionStorage.getItem('active_auth_tab') || 'login';
            if (document.getElementById('login-view')) {
                switchTab(savedTab);
            }
        });
    </script>
</body>
</html>
