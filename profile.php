<?php
require_once 'config/config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user'])) {
    header("Location: login_page.php");
    exit;
}

$is_logged_in = true;
$db_error = false;
$db_error_message = '';
$error = '';
$success = '';

// Lấy thông tin user hiện tại
try {
    $db = getDBConnection();
    $session_user = $_SESSION['user'];

    // Truy vấn dữ liệu mới nhất từ CSDL
    $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
    $stmt->execute(['id' => $session_user['MaNguoiDung']]);
    $user_data = $stmt->fetch();

    if ($user_data) {
        // Đồng bộ lại session
        $_SESSION['user'] = $user_data;
        
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
        session_destroy();
        header("Location: login_page.php");
        exit;
    }
} catch (Exception $e) {
    $db_error = true;
    $db_error_message = $e->getMessage();
    $user_data = $_SESSION['user'];
    $user_roles = ['Mất kết nối DB'];
}

// Xử lý cập nhật hồ sơ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_profile'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($fullname)) {
        $error = 'Họ và tên không được để trống.';
    } else {
        try {
            $db = getDBConnection();
            $update_stmt = $db->prepare("UPDATE `NguoiDung` SET `HoTen` = :fullname, `SoDienThoai` = :phone WHERE `MaNguoiDung` = :id");
            $update_stmt->execute([
                'fullname' => $fullname,
                'phone' => !empty($phone) ? $phone : null,
                'id' => $user_data['MaNguoiDung']
            ]);

            // Lấy lại dữ liệu mới sau khi update
            $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
            $stmt->execute(['id' => $user_data['MaNguoiDung']]);
            $user_data = $stmt->fetch();
            
            // Cập nhật lại session
            $_SESSION['user'] = $user_data;
            
            $success = 'Cập nhật hồ sơ cá nhân thành công!';
        } catch (Exception $e) {
            $error = 'Lỗi cập nhật dữ liệu: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ Sơ Cá Nhân - Chợ Đồ Cũ</title>
    <!-- Google Fonts Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .profile-card-wrapper {
            margin-top: 30px;
            width: 100%;
        }
        .warning-box {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.25);
            color: #fcd34d;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
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
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="fill: url(#logoGradient);">
                        <defs>
                            <linearGradient id="logoGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#6366f1" />
                                <stop offset="100%" stop-color="#a855f7" />
                            </linearGradient>
                        </defs>
                        <path d="M12 2L2 22H22L12 2ZM12 6L18.8 19.6H5.2L12 6Z"/>
                    </svg>
                    Chợ Đồ Cũ
                </a>
                
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Trang Chủ</a>
                    <a href="#" class="nav-link">Sản Phẩm</a>
                    <a href="#" class="nav-link">Liên Hệ</a>
                    
                    <div class="user-menu-wrapper">
                        <div class="user-trigger-btn" id="userDropdownTrigger">
                            <?php if (!empty($user_data['google_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($user_data['google_picture']); ?>" alt="Avatar" class="user-avatar-mini">
                            <?php else: ?>
                                <div class="user-avatar-mini-fallback">
                                    <?php echo strtoupper(substr($user_data['HoTen'] ?? 'U', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <span class="user-name-mini"><?php echo htmlspecialchars($user_data['HoTen'] ?? 'Thành viên'); ?></span>
                        </div>
                        
                        <div class="dropdown-menu" id="userDropdownMenu">
                            <div style="padding: 12px 18px; font-size: 0.8rem; color: var(--text-muted); border-bottom: 1px solid var(--card-border);">
                                Đăng nhập từ: <b><?php echo !empty($user_data['google_id']) ? 'Google' : 'Hệ thống'; ?></b>
                            </div>
                            <a href="profile.php" class="dropdown-item">
                                👤 Hồ sơ cá nhân
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="logout.php" class="dropdown-item" style="color: var(--error)">
                                🚪 Đăng xuất
                            </a>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Profile Page Content -->
        <main class="profile-container">
            <div class="card profile-card-wrapper" style="max-width: 100%;">
                <div style="text-align: center; margin-bottom: 24px;">
                    <?php if (!empty($user_data['google_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($user_data['google_picture']); ?>" alt="Avatar" class="profile-avatar-large">
                    <?php else: ?>
                        <div class="profile-avatar-large-fallback">
                            <?php echo strtoupper(substr($user_data['HoTen'] ?? 'U', 0, 1)); ?>
                        </div>
                    <?php endif; ?>

                    <h2 style="font-size: 1.6rem; color: var(--text-main);"><?php echo htmlspecialchars($user_data['HoTen']); ?></h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px;">@<?php echo htmlspecialchars($user_data['TenDangNhap']); ?></p>
                    
                    <div style="display: flex; justify-content: center; gap: 8px;">
                        <span class="badge-reputation">⭐ <?php echo htmlspecialchars($user_data['DiemUyTin'] ?? '0'); ?> Uy Tín</span>
                        <span class="user-badge" style="margin: 0; padding: 4px 12px; font-size: 0.8rem;">Hạng: <?php echo htmlspecialchars($user_data['HangThanhVien'] ?? 'Đồng'); ?></span>
                    </div>
                </div>

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

                <!-- Nhắc nhở bổ sung số điện thoại nếu thiếu -->
                <?php if (empty($user_data['SoDienThoai'])): ?>
                    <div class="warning-box">
                        <span>⚠️</span>
                        <span><b>Thông tin chưa hoàn thiện:</b> Bạn chưa cập nhật số điện thoại. Vui lòng bổ sung số điện thoại bên dưới để phục vụ giao dịch và giao nhận hàng tốt nhất.</span>
                    </div>
                <?php endif; ?>

                <!-- Form cập nhật thông tin -->
                <form method="POST" action="profile.php">
                    <div class="form-group">
                        <label>Tên đăng nhập (Username)</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['TenDangNhap']); ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                    </div>
                    
                    <div class="form-group">
                        <label>Địa chỉ Email</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['Email'] ?? 'Trống'); ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                    </div>
                    
                    <div class="form-group">
                        <label for="prof_fullname">Họ và tên <span style="color:var(--error)">*</span></label>
                        <input type="text" name="fullname" id="prof_fullname" class="form-control" value="<?php echo htmlspecialchars($user_data['HoTen']); ?>" placeholder="Nhập họ và tên đầy đủ" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="prof_phone">Số điện thoại</label>
                        <input type="text" name="phone" id="prof_phone" class="form-control" value="<?php echo htmlspecialchars($user_data['SoDienThoai'] ?? ''); ?>" placeholder="Nhập số điện thoại (Ví dụ: 0905123456)">
                    </div>

                    <button type="submit" name="action_update_profile" class="btn btn-primary" style="margin-top: 15px;">Cập Nhật Thông Tin</button>
                </form>

                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.08); text-align: center; font-size: 13px;">
                    <a href="index.php" style="color: var(--text-muted); text-decoration: none;">
                        ← Quay lại Trang Chủ
                    </a>
                </div>
            </div>
        </main>

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

    <!-- Script điều khiển Dropdown người dùng -->
    <script>
        const trigger = document.getElementById('userDropdownTrigger');
        const menu = document.getElementById('userDropdownMenu');
        
        if (trigger && menu) {
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.classList.toggle('show');
            });
            
            document.addEventListener('click', () => {
                menu.classList.remove('show');
            });
        }
    </script>
</body>
</html>
