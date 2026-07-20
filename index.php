<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;
$user_roles = [];
$db_error = false;
$db_error_message = '';
$raw_db_row = null;

$error = '';
$success = '';

// Xử lý gửi Form đăng ký / đăng nhập thông thường
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_login'])) {
        $username_or_email = trim($_POST['username_or_email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username_or_email) || empty($password)) {
            $error = 'Vui lòng nhập đầy đủ tên đăng nhập/email và mật khẩu.';
        } else {
            try {
                $db = getDBConnection();
                $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `TenDangNhap` = :login OR `Email` = :email");
                $stmt->execute(['login' => $username_or_email, 'email' => $username_or_email]);
                $user = $stmt->fetch();

                if ($user && !empty($user['MatKhau']) && password_verify($password, $user['MatKhau'])) {
                    // Đăng nhập thành công
                    $_SESSION['user'] = $user;
                    header("Location: index.php");
                    exit;
                } else {
                    $error = 'Tên đăng nhập/Email hoặc mật khẩu không chính xác.';
                }
            } catch (Exception $e) {
                $error = 'Lỗi hệ thống: ' . $e->getMessage();
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
        } elseif ($password !== $confirm_password) {
            $error = 'Mật khẩu xác nhận không khớp.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Định dạng email không hợp lệ.';
        } else {
            try {
                $db = getDBConnection();

                // Kiểm tra trùng lặp tên đăng nhập hoặc email
                $stmt = $db->prepare("SELECT COUNT(*) FROM `NguoiDung` WHERE `TenDangNhap` = :username OR `Email` = :email");
                $stmt->execute(['username' => $username, 'email' => $email]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Tên đăng nhập hoặc Email đã tồn tại trên hệ thống.';
                } else {
                    // Mã hóa mật khẩu
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Thêm người dùng mới
                    $insert_stmt = $db->prepare("INSERT INTO `NguoiDung` 
                        (`TenDangNhap`, `MatKhau`, `HoTen`, `Email`, `SoDienThoai`, `DiemUyTin`, `HangThanhVien`, `TrangThaiTaiKhoan`) 
                        VALUES 
                        (:username, :password, :fullname, :email, :phone, 0, 'Đồng', b'1')");

                    $insert_stmt->execute([
                        'username' => $username,
                        'password' => $hashed_password,
                        'fullname' => $fullname,
                        'email'    => $email,
                        'phone'    => !empty($phone) ? $phone : null
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
                    $_SESSION['user'] = $stmt->fetch();

                    $success = 'Đăng ký tài khoản thành công!';
                    header("Location: index.php");
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Lỗi đăng ký: ' . $e->getMessage();
            }
        }
    }
}


// Kiểm tra xem đã đăng nhập chưa
if (isset($_SESSION['user'])) {
    try {
        $db = getDBConnection();
        $session_user = $_SESSION['user'];
        
        // Truy vấn dữ liệu mới nhất từ CSDL
        $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
        $stmt->execute(['id' => $session_user['MaNguoiDung']]);
        $user_data = $stmt->fetch();
        
        if ($user_data) {
            $is_logged_in = true;
            $raw_db_row = $user_data; // Lưu lại để hiển thị dạng JSON debug
            
            // Lấy danh sách vai trò
            $role_stmt = $db->prepare("
                SELECT vt.TenVaiTro 
                FROM `NguoiDung_VaiTro` ndvt 
                JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro 
                WHERE ndvt.MaNguoiDung = :id
            ");
            $role_stmt->execute(['id' => $user_data['MaNguoiDung']]);
            $user_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Trường hợp tài khoản bị xóa trong DB khi session vẫn còn
            session_destroy();
            header("Location: index.php");
            exit;
        }
    } catch (Exception $e) {
        $db_error = true;
        $db_error_message = $e->getMessage();
        // Vẫn cho phép hiển thị thông tin từ Session nếu lỗi kết nối DB xảy ra sau khi đăng nhập
        $is_logged_in = true;
        $user_data = $_SESSION['user'];
        $user_roles = ['Mất kết nối DB'];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thử Nghiệm Đăng Nhập Google - PHP & MySQL 3307</title>
    <!-- Google Fonts Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Background hiệu ứng mờ và vòng tròn màu sắc -->
    <div class="background-decor"></div>

    <div class="card">
        <?php if (!$is_logged_in): ?>
            <!-- GIAO DIỆN ĐĂNG NHẬP / ĐĂNG KÝ -->
            <div style="text-align: center;">
                <div style="display: inline-flex; padding: 12px; border-radius: 16px; background: rgba(2, 132, 199, 0.1); border: 1px solid rgba(2, 132, 199, 0.2); margin-bottom: 20px;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 12 2 12 2ZM12 5C13.66 5 15 6.34 15 8C15 9.66 13.66 11 12 11C10.34 11 9 9.66 9 8C9 6.34 10.34 5 12 5ZM12 19.2C9.5 19.2 7.29 17.92 6 15.98C6.03 13.99 10 12.9 12 12.9C13.99 12.9 17.97 13.99 18 15.98C16.71 17.92 14.5 19.2 12 19.2Z" fill="#0284c7"/>
                    </svg>
                </div>
                
                <h1 style="margin-bottom: 20px;">Hệ Thống Đăng Nhập</h1>

                <!-- Thông báo lỗi hoặc thành công -->
                <?php if (!empty($error)): ?>
                    <div class="alert-message error">
                        ⚠️ <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                    <div class="alert-message success">
                        ✅ <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Khung chuyển Tabs -->
                <div class="tabs-container">
                    <button type="button" id="login-tab-btn" class="tab-btn active" onclick="switchTab('login')">Đăng Nhập</button>
                    <button type="button" id="register-tab-btn" class="tab-btn" onclick="switchTab('register')">Đăng Ký</button>
                </div>

                <!-- Form Đăng Nhập -->
                <div id="login-view" class="form-view active">
                    <form method="POST" action="index.php">
                        <div class="form-group">
                            <label for="login_username">Tên đăng nhập hoặc Email</label>
                            <input type="text" name="username_or_email" id="login_username" class="form-control" placeholder="Nhập tên đăng nhập hoặc email" required>
                        </div>
                        <div class="form-group">
                            <label for="login_password">Mật khẩu</label>
                            <input type="password" name="password" id="login_password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <button type="submit" name="action_login" class="btn btn-primary" style="margin-top: 10px;">Đăng Nhập</button>
                    </form>
                </div>

                <!-- Form Đăng Ký -->
                <div id="register-view" class="form-view">
                    <form method="POST" action="index.php">
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
                            <input type="email" name="email" id="reg_email" class="form-control" placeholder="email@viethan.edu.vn" required>
                        </div>
                        <div class="form-group">
                            <label for="reg_phone">Số điện thoại</label>
                            <input type="text" name="phone" id="reg_phone" class="form-control" placeholder="0905xxxxxx">
                        </div>
                        <div class="form-group">
                            <label for="reg_password">Mật khẩu <span style="color:var(--error)">*</span></label>
                            <input type="password" name="password" id="reg_password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <div class="form-group">
                            <label for="reg_confirm_password">Xác nhận mật khẩu <span style="color:var(--error)">*</span></label>
                            <input type="password" name="confirm_password" id="reg_confirm_password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <button type="submit" name="action_register" class="btn btn-primary" style="margin-top: 10px;">Đăng Ký Tài Khoản</button>
                    </form>
                </div>

                <!-- Đường chia và nút Đăng nhập bằng Google -->
                <div class="divider-container">Hoặc đăng nhập bằng</div>

                <!-- Nút Đăng nhập Google chuẩn chính hãng -->
                <a href="login.php" class="btn-google">
                    <!-- Biểu tượng chữ G chuẩn Google -->
                    <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22Coloured%22_logo.svg" alt="Google Logo" onerror="this.src='https://developers.google.com/static/identity/images/g-logo.png'">
                    Đăng nhập bằng tài khoản Google
                </a>

                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(226, 232, 240, 0.8); font-size: 13px; color: var(--text-muted);">
                    Chưa cài đặt cấu trúc bảng trong CSDL?
                    <br>
                    <a href="db_setup.php" style="color: var(--primary); text-decoration: none; font-weight: 600; display: inline-block; margin-top: 5px;">
                        👉 Chạy Script Cấu Hình DB Tự Động
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- GIAO DIỆN ĐÃ ĐĂNG NHẬP THÀNH CÔNG -->
            <div class="profile-card">
                <div class="avatar-wrapper">
                    <?php if (!empty($user_data['google_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['google_picture']); ?>" alt="Avatar" class="avatar">
                    <?php else: ?>
                        <div class="avatar-fallback">
                            <?php echo strtoupper(substr($user_data['HoTen'] ?? 'U', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <h2>Chào, <?php echo htmlspecialchars($user_data['HoTen'] ?? 'Thành viên'); ?>!</h2>
                <p style="color: var(--success); font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; margin-top: 5px;">
                    <span style="display:inline-block; width:6px; height:6px; background-color:var(--success); border-radius:50%"></span>
                    <?php if (!empty($user_data['google_id'])): ?>
                        Đã đăng nhập qua tài khoản Google
                    <?php else: ?>
                        Đã đăng nhập bằng tài khoản hệ thống
                    <?php endif; ?>
                </p>


                <!-- Hiển thị badge vai trò người dùng -->
                <div class="user-badge">
                    Vai trò: <?php echo !empty($user_roles) ? implode(', ', $user_roles) : 'Chưa gán'; ?>
                </div>

                <!-- Lưới thông tin tài khoản -->
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Mã thành viên (ID)</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_data['MaNguoiDung']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Tên đăng nhập (Tự sinh)</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_data['TenDangNhap']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Địa chỉ Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_data['Email'] ?? 'Trống'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Mã định danh Google ID</div>
                        <div class="info-value code"><?php echo htmlspecialchars($user_data['google_id'] ?? 'Trống'); ?></div>
                    </div>
                    <div class="info-item" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <div class="info-label">Hạng thành viên</div>
                            <div class="info-value" style="color: #d97706; font-weight: 600;"><?php echo htmlspecialchars($user_data['HangThanhVien'] ?? 'Đồng'); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Điểm uy tín</div>
                            <div class="info-value"><?php echo htmlspecialchars($user_data['DiemUyTin'] ?? '0'); ?> ⭐</div>
                        </div>
                    </div>
                </div>

                <!-- Trình quan sát dữ liệu database thực tế -->
                <?php if ($raw_db_row): ?>
                    <div class="db-visualizer">
                        <h4>Xem hàng dữ liệu thực tế trong MySQL</h4>
                        <pre class="db-code-block"><?php echo json_encode($raw_db_row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
                    </div>
                <?php endif; ?>

                <a href="logout.php" class="btn btn-outline" style="margin-top: 24px;">Đăng Xuất</a>
            </div>
        <?php endif; ?>

        <?php if ($db_error): ?>
            <div style="background: var(--error-bg); border: 1px solid var(--error-border); border-radius: 12px; padding: 12px; margin-top: 20px; font-size: 13px; color: var(--error);">
                ⚠️ <strong>Cảnh báo Database (Port <?php echo DB_PORT; ?>):</strong> <?php echo htmlspecialchars($db_error_message); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer links -->
    <div class="footer-links">
        <a href="db_setup.php">Thiết lập Database</a>
        <span style="color: var(--text-muted)">•</span>
        <a href="https://console.cloud.google.com/" target="_blank">Google Developer Console</a>
    </div>
    <script>
        function switchTab(tabName) {
            // Deactivate all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            // Hide all form views
            document.querySelectorAll('.form-view').forEach(view => {
                view.classList.remove('active');
            });

            // Activate chosen tab button
            const activeBtn = document.getElementById(tabName + '-tab-btn');
            if (activeBtn) activeBtn.classList.add('active');
            
            // Show chosen form view
            const activeView = document.getElementById(tabName + '-view');
            if (activeView) activeView.classList.add('active');

            // Save active tab in sessionStorage so it persists on reload if needed
            sessionStorage.setItem('active_auth_tab', tabName);
        }

        // Initialize default tab (either login or register, default to login)
        window.addEventListener('DOMContentLoaded', () => {
            const savedTab = sessionStorage.getItem('active_auth_tab') || 'login';
            // Only switch if the forms exist
            if (document.getElementById('login-view')) {
                switchTab(savedTab);
            }
        });
    </script>
</body>
</html>

