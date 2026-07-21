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

$seller_address = null;
$seller_bank = null;

// Lấy thông tin user hiện tại
try {
    $db = getDBConnection();
    $session_user = $_SESSION['user'];

    // Truy vấn dữ liệu mới nhất từ CSDL
    $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
    $stmt->execute(['id' => $session_user['MaNguoiDung']]);
    $user_data = $stmt->fetch();

    if ($user_data) {
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

        $is_seller = in_array('SELLER', $user_roles) || in_array('ADMIN', $user_roles);

        if ($is_seller) {
            // Lấy địa chỉ kho hàng / lấy hàng
            $addr_stmt = $db->prepare("SELECT * FROM `SoDiaChi` WHERE `MaNguoiDung` = :uid ORDER BY `LaDiaChiMacDinh` DESC, `MaDiaChi` DESC LIMIT 1");
            $addr_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
            $seller_address = $addr_stmt->fetch();

            // Lấy thông tin tài khoản ngân hàng
            $bank_stmt = $db->prepare("SELECT * FROM `TaiKhoanNganHangLienKet` WHERE `MaNguoiDung` = :uid ORDER BY `MaTaiKhoan` DESC LIMIT 1");
            $bank_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
            $seller_bank = $bank_stmt->fetch();
        }
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
    $is_seller = false;
}

// Xử lý cập nhật hồ sơ cá nhân & thông tin bán hàng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_profile'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_account = trim($_POST['bank_account'] ?? '');
    $account_holder = trim($_POST['account_holder'] ?? '');

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

            // Cập nhật thông tin người bán (nếu có quyền SELLER)
            if ($is_seller) {
                if (!empty($address)) {
                    if ($seller_address) {
                        $upd_addr = $db->prepare("UPDATE `SoDiaChi` SET `DiaChiChiTiet` = :addr WHERE `MaDiaChi` = :aid");
                        $upd_addr->execute(['addr' => $address, 'aid' => $seller_address['MaDiaChi']]);
                    } else {
                        $ins_addr = $db->prepare("INSERT INTO `SoDiaChi` (`MaNguoiDung`, `DiaChiChiTiet`, `ViDo`, `KinhDo`, `LaDiaChiMacDinh`) VALUES (:uid, :addr, 10.762622, 106.660172, 1)");
                        $ins_addr->execute(['uid' => $user_data['MaNguoiDung'], 'addr' => $address]);
                    }
                }

                if (!empty($bank_name) && !empty($bank_account) && !empty($account_holder)) {
                    if ($seller_bank) {
                        $upd_bank = $db->prepare("UPDATE `TaiKhoanNganHangLienKet` SET `TenNganHang` = :bname, `SoTaiKhoan` = :bacc, `TenChuTaiKhoan` = :bholder WHERE `MaTaiKhoan` = :bid");
                        $upd_bank->execute([
                            'bname' => $bank_name,
                            'bacc' => $bank_account,
                            'bholder' => $account_holder,
                            'bid' => $seller_bank['MaTaiKhoan']
                        ]);
                    } else {
                        $ins_bank = $db->prepare("INSERT INTO `TaiKhoanNganHangLienKet` (`MaNguoiDung`, `TenNganHang`, `SoTaiKhoan`, `TenChuTaiKhoan`) VALUES (:uid, :bname, :bacc, :bholder)");
                        $ins_bank->execute([
                            'uid' => $user_data['MaNguoiDung'],
                            'bname' => $bank_name,
                            'bacc' => $bank_account,
                            'bholder' => $account_holder
                        ]);
                    }
                }
            }

            // Lấy lại dữ liệu mới sau khi update
            $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
            $stmt->execute(['id' => $user_data['MaNguoiDung']]);
            $user_data = $stmt->fetch();
            $_SESSION['user'] = $user_data;

            if ($is_seller) {
                $addr_stmt = $db->prepare("SELECT * FROM `SoDiaChi` WHERE `MaNguoiDung` = :uid ORDER BY `LaDiaChiMacDinh` DESC, `MaDiaChi` DESC LIMIT 1");
                $addr_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
                $seller_address = $addr_stmt->fetch();

                $bank_stmt = $db->prepare("SELECT * FROM `TaiKhoanNganHangLienKet` WHERE `MaNguoiDung` = :uid ORDER BY `MaTaiKhoan` DESC LIMIT 1");
                $bank_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
                $seller_bank = $bank_stmt->fetch();
            }
            
            $success = 'Cập nhật hồ sơ cá nhân và thông tin bán hàng thành công!';
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
    <!-- Google Fonts Inter & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .profile-card-wrapper {
            margin-top: 30px;
            width: 100%;
        }
        .warning-box {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.25);
            color: #b45309;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .seller-info-card {
            background: rgba(240, 249, 255, 0.6);
            border: 1px solid rgba(2, 132, 199, 0.15);
            border-radius: 16px;
            padding: 20px;
            margin-top: 24px;
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
                    
                    <?php if ($is_logged_in && in_array('ADMIN', $user_roles)): ?>
                        <a href="admin.php" class="nav-link" style="color: #6366f1; font-weight: 700;">Quản Lý Admin</a>
                    <?php endif; ?>

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
                            <a href="profile.php" class="dropdown-item">Hồ sơ cá nhân</a>
                            <a href="post_product.php" class="dropdown-item" style="color: var(--primary);">Đăng bán sản phẩm</a>
                            <?php if (in_array('ADMIN', $user_roles)): ?>
                                <a href="admin.php" class="dropdown-item" style="color: #6366f1; font-weight: 600;">Trang Quản Lý Admin</a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="logout.php" class="dropdown-item" style="color: var(--error)">Đăng xuất</a>
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
                    
                    <div style="display: flex; justify-content: center; gap: 8px; flex-wrap: wrap;">
                        <span class="badge-reputation"><?php echo htmlspecialchars($user_data['DiemUyTin'] ?? '0'); ?> Uy Tín</span>
                        <span class="user-badge" style="margin: 0; padding: 4px 12px; font-size: 0.8rem;">Hạng: <?php echo htmlspecialchars($user_data['HangThanhVien'] ?? 'Đồng'); ?></span>
                        <?php if ($is_seller): ?>
                            <span class="badge" style="background: #dcfce7; color: #15803d; padding: 4px 12px; border-radius: 50px; font-weight: 700; font-size: 0.8rem;">Người Bán Hàng</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Thông báo lỗi hoặc thành công -->
                <?php if (!empty($error)): ?>
                    <div class="alert-message error" style="padding: 12px 16px; border-radius: 12px; background: #fef2f2; color: #b91c1c; margin-bottom: 20px;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                    <div class="alert-message success" style="padding: 12px 16px; border-radius: 12px; background: #ecfdf5; color: #047857; margin-bottom: 20px;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Form cập nhật thông tin -->
                <form method="POST" action="profile.php">
                    <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; color: var(--text-main);">Thông Tin Tài Khoản</h3>

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
                        <label for="prof_phone">Số điện thoại liên hệ</label>
                        <input type="text" name="phone" id="prof_phone" class="form-control" value="<?php echo htmlspecialchars($user_data['SoDienThoai'] ?? ''); ?>" placeholder="Nhập số điện thoại (Ví dụ: 0905123456)">
                    </div>

                    <?php if ($is_seller): ?>
                        <!-- KHỐI CHỈNH SỬA THÔNG TIN NGƯỜI BÁN (SELLER PROFILE) -->
                        <div class="seller-info-card">
                            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                                Thông Tin Người Bán Hàng & Doanh Thu
                            </h3>

                            <div class="form-group">
                                <label for="prof_address">Địa chỉ kho hàng / Lấy hàng thanh lý</label>
                                <input type="text" name="address" id="prof_address" class="form-control" value="<?php echo htmlspecialchars($seller_address['DiaChiChiTiet'] ?? ''); ?>" placeholder="VD: 123 Nguyễn Văn Cừ, Phường 4, Quận 5, TP.HCM">
                            </div>

                            <div class="form-group">
                                <label for="prof_bank_name">Tên Ngân Hàng Nhận Tiền</label>
                                <select id="prof_bank_name" name="bank_name" class="form-control">
                                    <?php 
                                        $current_bname = $seller_bank['TenNganHang'] ?? ''; 
                                        $banks = ['Vietcombank', 'MBBank', 'Techcombank', 'VietinBank', 'BIDV', 'VPBank', 'TPBank', 'ACB'];
                                    ?>
                                    <option value="">-- Chọn ngân hàng --</option>
                                    <?php foreach ($banks as $b): ?>
                                        <option value="<?php echo $b; ?>" <?php echo ($current_bname === $b) ? 'selected' : ''; ?>><?php echo $b; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                <div class="form-group">
                                    <label for="prof_bank_account">Số tài khoản ngân hàng</label>
                                    <input type="text" name="bank_account" id="prof_bank_account" class="form-control" value="<?php echo htmlspecialchars($seller_bank['SoTaiKhoan'] ?? ''); ?>" placeholder="VD: 99012345678">
                                </div>

                                <div class="form-group">
                                    <label for="prof_account_holder">Tên chủ tài khoản</label>
                                    <input type="text" name="account_holder" id="prof_account_holder" class="form-control" value="<?php echo htmlspecialchars($seller_bank['TenChuTaiKhoan'] ?? ''); ?>" placeholder="VD: NGUYEN VAN A">
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- THÔNG BÁO NẾU CHƯA ĐĂNG KÝ BÁN HÀNG -->
                        <div style="margin-top: 24px; padding: 16px; border-radius: 16px; background: rgba(99, 102, 241, 0.08); border: 1px solid rgba(99, 102, 241, 0.2); text-align: center;">
                            <p style="font-size: 0.9rem; color: var(--text-main); margin-bottom: 12px;">Bạn muốn thanh lý đồ cũ của mình?</p>
                            <a href="post_product.php" class="btn btn-primary" style="padding: 8px 24px; font-size: 0.9rem; border-radius: 50px; text-decoration: none;">Đăng Ký Bán Hàng Ngay</a>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="action_update_profile" class="btn btn-primary" style="margin-top: 20px; width: 100%; border-radius: 50px;">Lưu Thay Đổi Hồ Sơ</button>
                </form>

                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(0, 0, 0, 0.06); text-align: center; font-size: 13px;">
                    <a href="index.php" style="color: var(--text-muted); text-decoration: none;">
                        Quay lại Trang Chủ
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
