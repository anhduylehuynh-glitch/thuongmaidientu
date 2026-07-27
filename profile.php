<?php
require_once 'config/config.php';

// Kiểm tra đăng nhập
requireLogin();

$is_logged_in = true;
$db_error = false;
$db_error_message = '';
$error = '';
$success = '';

if (isset($_GET['payment']) && $_GET['payment'] === 'cancel') {
    $error = 'Giao dịch nạp tiền của bạn đã bị hủy bỏ.';
}

$seller_address = null;
$seller_bank = null;
$wallet = null;
$recent_transactions = [];

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

        // Lấy thông tin ví điện tử của user
        $wallet_stmt = $db->prepare("SELECT * FROM `ViDienTu` WHERE `MaNguoiDung` = :uid");
        $wallet_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
        $wallet = $wallet_stmt->fetch();
        
        if (!$wallet) {
            // Tự động tạo ví nếu chưa có
            $ins_wallet = $db->prepare("INSERT INTO `ViDienTu` (`MaNguoiDung`, `SoDu`, `TrangThaiVi`) VALUES (:uid, 0.00, b'1')");
            $ins_wallet->execute(['uid' => $user_data['MaNguoiDung']]);
            
            $wallet_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
            $wallet = $wallet_stmt->fetch();
        }
        
        // Lấy danh sách 5 giao dịch ví gần đây
        $trans_stmt = $db->prepare("
            SELECT * FROM `LichSuGiaoDichVi` 
            WHERE `MaViNguon` = :w_id OR `MaViDich` = :w_id 
            ORDER BY `NgayTao` DESC 
            LIMIT 5
        ");
        $trans_stmt->execute(['w_id' => $wallet['MaVi']]);
        $recent_transactions = $trans_stmt->fetchAll();
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

// Xử lý nạp tiền ví điện tử qua PayOS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_deposit_wallet'])) {
    $amount = (int)($_POST['amount'] ?? 0);
    if ($amount < 10000) {
        $error = 'Số tiền nạp tối thiểu là 10.000 đ.';
    } else {
        try {
            $db = getDBConnection();
            
            // Lấy thông tin ví của user
            $wallet_stmt = $db->prepare("SELECT * FROM `ViDienTu` WHERE `MaNguoiDung` = :uid");
            $wallet_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
            $user_wallet = $wallet_stmt->fetch();
            
            if (!$user_wallet) {
                throw new Exception("Ví điện tử của bạn chưa được khởi tạo.");
            }
            
            // 1. Tạo bản ghi giao dịch chờ xử lý
            $desc = "Naptienvi"; // Tiền tố
            $ins_stmt = $db->prepare("
                INSERT INTO `LichSuGiaoDichVi` 
                (`MaViNguon`, `MaViDich`, `SoTien`, `LoaiGiaoDich`, `TrangThai`, `MoTa`) 
                VALUES 
                (NULL, :mavid, :amount, 'NAP_TIEN', b'00', :mota)
            ");
            $ins_stmt->execute([
                'mavid' => $user_wallet['MaVi'],
                'amount' => $amount,
                'mota' => 'Đang chờ nạp tiền qua PayOS'
            ]);
            
            $transaction_id = (int)$db->lastInsertId();
            $payos_desc = "NP" . $transaction_id;
            
            // Cập nhật lại mô tả chi tiết chứa ID giao dịch
            $upd_desc = $db->prepare("UPDATE `LichSuGiaoDichVi` SET `MoTa` = :mota WHERE `MaGiaoDich` = :tgid");
            $upd_desc->execute([
                'mota' => 'Nạp tiền ví qua PayOS (Mã GD: ' . $transaction_id . ')',
                'tgid' => $transaction_id
            ]);
            
            // 2. Chuẩn bị gọi PayOS API
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
            $basePath = ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');
            
            $cancelUrl = $protocol . '://' . $host . $basePath . '/profile.php?payment=cancel';
            $returnUrl = $protocol . '://' . $host . $basePath . '/payos_return.php';
            
            // Tính toán chữ ký Signature
            $data_to_sign = [
                'amount' => $amount,
                'cancelUrl' => $cancelUrl,
                'description' => $payos_desc,
                'orderCode' => $transaction_id,
                'returnUrl' => $returnUrl
            ];
            ksort($data_to_sign);
            
            $dataString = "";
            foreach ($data_to_sign as $key => $value) {
                if ($dataString !== "") {
                    $dataString .= "&";
                }
                $dataString .= $key . "=" . $value;
            }
            
            $signature = hash_hmac('sha256', $dataString, PAYOS_CHECKSUM_KEY);
            
            $payload = [
                'orderCode' => $transaction_id,
                'amount' => $amount,
                'description' => $payos_desc,
                'cancelUrl' => $cancelUrl,
                'returnUrl' => $returnUrl,
                'signature' => $signature
            ];
            
            // Gọi PayOS API bằng cURL
            $ch = curl_init('https://api-merchant.payos.vn/v2/payment-requests');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'x-client-id: ' . PAYOS_CLIENT_ID,
                'x-api-key: ' . PAYOS_API_KEY
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $res_data = json_decode($response, true);
            if ($http_code === 200 || $http_code === 201) {
                if (isset($res_data['code']) && $res_data['code'] === '00' && isset($res_data['data']['checkoutUrl'])) {
                    $checkoutUrl = $res_data['data']['checkoutUrl'];
                    header("Location: " . $checkoutUrl);
                    exit;
                } else {
                    $error = "Lỗi khởi tạo PayOS: " . ($res_data['desc'] ?? 'Không rõ lỗi');
                }
            } else {
                $err_json = json_decode($response, true);
                if (isset($err_json['desc'])) {
                    $error = "Lỗi PayOS: " . $err_json['desc'];
                } else {
                    $error = "Lỗi kết nối đến cổng thanh toán PayOS (Mã lỗi HTTP: $http_code)";
                }
            }
        } catch (Exception $e) {
            $error = 'Lỗi nạp tiền: ' . $e->getMessage();
        }
    }
    
    // Tải lại lịch sử giao dịch và thông tin ví mới nhất để hiển thị
    try {
        $db = getDBConnection();
        $wallet_stmt = $db->prepare("SELECT * FROM `ViDienTu` WHERE `MaNguoiDung` = :uid");
        $wallet_stmt->execute(['uid' => $user_data['MaNguoiDung']]);
        $wallet = $wallet_stmt->fetch();
        
        $trans_stmt = $db->prepare("
            SELECT * FROM `LichSuGiaoDichVi` 
            WHERE `MaViNguon` = :w_id OR `MaViDich` = :w_id 
            ORDER BY `NgayTao` DESC 
            LIMIT 5
        ");
        $trans_stmt->execute(['w_id' => $wallet['MaVi']]);
        $recent_transactions = $trans_stmt->fetchAll();
    } catch (Exception $ex) {}
}

// Hàm hỗ trợ hiển thị loại giao dịch
function getTransactionTypeLabel($type, $wallet_id, $src_wallet_id) {
    switch ($type) {
        case 'NAP_TIEN':
            return 'Nạp tiền vào ví';
        case 'THANH_TOAN':
            return 'Thanh toán mua hàng';
        case 'ESCROW_TAM_GIU':
            return 'Tạm giữ giao dịch';
        case 'ESCROW_GIAI_NGAN':
            return 'Giải ngân ví';
        case 'RUT_TIEN':
            return 'Rút tiền ngân hàng';
        case 'HOAN_TIEN_KHI_NAI':
            return 'Hoàn tiền khiếu nại';
        default:
            return $type;
    }
}

// Hàm giải mã giá trị cột BIT(2) của TrangThai giao dịch
function decodeTransactionStatus($status_val) {
    if (is_null($status_val)) return 0;
    if (is_int($status_val)) return $status_val;
    if (is_string($status_val)) {
        if (strlen($status_val) === 1) {
            $o = ord($status_val);
            if ($o === 1 || $status_val === '1') return 1;
            if ($o === 2 || $status_val === '2') return 2;
            if ($o === 0 || $status_val === '0') return 0;
            return $o;
        }
        return (int)$status_val;
    }
    return (int)$status_val;
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
        .profile-container {
            max-width: 1100px !important;
        }
        .profile-card-wrapper {
            width: 100%;
        }
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            margin-top: 30px;
            align-items: start;
            width: 100%;
        }
        @media (min-width: 768px) {
            .profile-grid {
                grid-template-columns: 3fr 2fr;
            }
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
                            <a href="javascript:void(0)" onclick="const f = document.createElement('form'); f.method = 'POST'; f.action = 'logout.php'; const i = document.createElement('input'); i.type = 'hidden'; i.name = 'csrf_token'; i.value = '<?php echo getCsrfToken(); ?>'; f.appendChild(i); document.body.appendChild(f); f.submit();" class="dropdown-item" style="color: var(--error)">Đăng xuất</a>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Profile Page Content -->
        <main class="profile-container">
            <div class="profile-grid">
                <!-- Cột trái: Thông tin tài khoản -->
                <div class="card profile-card-wrapper">
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

                <!-- Cột phải: Ví điện tử -->
                <div class="card profile-card-wrapper">
                    <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 20px; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Ví Điện Tử Của Tôi</h3>
                    
                    <!-- Khung số dư -->
                    <div style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); padding: 24px; border-radius: 16px; color: white; margin-bottom: 24px; box-shadow: 0 10px 15px -3px rgba(2, 132, 199, 0.2);">
                        <span style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9; font-weight: 500;">Số dư ví hiện tại</span>
                        <h2 style="font-size: 2.2rem; font-weight: 800; margin: 8px 0 0 0; font-family: 'Outfit', sans-serif;"><?php echo number_format($wallet['SoDu'] ?? 0, 0, ',', '.'); ?> đ</h2>
                    </div>

                    <!-- Form nạp tiền -->
                    <form method="POST" action="profile.php" style="margin-bottom: 28px;">
                        <input type="hidden" name="action_deposit_wallet" value="1">
                        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 12px; color: var(--text-main);">Nạp tiền vào ví</h4>
                        
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label for="deposit_amount" style="font-size: 0.8rem; font-weight: 600; margin-bottom: 6px;">Số tiền nạp (VND)</label>
                            <input type="number" name="amount" id="deposit_amount" min="10000" step="1000" class="form-control" placeholder="Tối thiểu 10.000 đ" required style="font-weight: 600; font-size: 1rem; padding: 12px 16px; border-radius: 12px;">
                        </div>

                        <!-- Chọn nhanh số tiền nạp -->
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 20px;">
                            <button type="button" class="btn btn-outline" onclick="setDepositAmount(50000)" style="padding: 8px 0; font-size: 0.75rem; border-radius: 8px; text-align: center; margin: 0; font-weight: 700; border: 1px solid rgba(0,0,0,0.08); background: #ffffff;">50k</button>
                            <button type="button" class="btn btn-outline" onclick="setDepositAmount(100000)" style="padding: 8px 0; font-size: 0.75rem; border-radius: 8px; text-align: center; margin: 0; font-weight: 700; border: 1px solid rgba(0,0,0,0.08); background: #ffffff;">100k</button>
                            <button type="button" class="btn btn-outline" onclick="setDepositAmount(200000)" style="padding: 8px 0; font-size: 0.75rem; border-radius: 8px; text-align: center; margin: 0; font-weight: 700; border: 1px solid rgba(0,0,0,0.08); background: #ffffff;">200k</button>
                            <button type="button" class="btn btn-outline" onclick="setDepositAmount(500000)" style="padding: 8px 0; font-size: 0.75rem; border-radius: 8px; text-align: center; margin: 0; font-weight: 700; border: 1px solid rgba(0,0,0,0.08); background: #ffffff;">500k</button>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 50px; font-weight: 700; padding: 12px; font-size: 0.95rem; background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); border: none; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);">
                            ⚡ Nạp Tiền Qua PayOS (VietQR)
                        </button>
                    </form>

                    <!-- Lịch sử giao dịch ví -->
                    <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 12px; color: var(--text-main);">Lịch sử giao dịch gần đây</h4>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; font-size: 0.85rem; border-collapse: collapse; text-align: left;">
                            <thead>
                                <tr style="border-bottom: 1px solid rgba(0,0,0,0.08); color: var(--text-muted);">
                                    <th style="padding: 8px 4px; font-weight: 600;">Mã</th>
                                    <th style="padding: 8px 4px; font-weight: 600;">Loại giao dịch</th>
                                    <th style="padding: 8px 4px; font-weight: 600; text-align: right;">Số tiền</th>
                                    <th style="padding: 8px 4px; font-weight: 600; text-align: center;">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_transactions)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 16px 0;">Chưa có giao dịch nào phát sinh.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_transactions as $tx): 
                                        $tx_status = decodeTransactionStatus($tx['TrangThai']);
                                        $badge_class = '';
                                        $status_text = '';
                                        if ($tx_status === 1) {
                                            $badge_class = 'background: #dcfce7; color: #15803d;';
                                            $status_text = 'Thành công';
                                        } elseif ($tx_status === 2) {
                                            $badge_class = 'background: #fee2e2; color: #b91c1c;';
                                            $status_text = 'Thất bại';
                                        } else {
                                            $badge_class = 'background: #fef3c7; color: #b45309;';
                                            $status_text = 'Chờ xử lý';
                                        }
                                        
                                        // Định dạng số tiền
                                        $prefix = ($tx['MaViDich'] == $wallet['MaVi']) ? '+' : '-';
                                        $color = ($tx['MaViDich'] == $wallet['MaVi']) ? '#16a34a' : '#dc2626';
                                    ?>
                                        <tr style="border-bottom: 1px solid rgba(0,0,0,0.04);">
                                            <td style="padding: 10px 4px; font-weight: 600;">#<?php echo $tx['MaGiaoDich']; ?></td>
                                            <td style="padding: 10px 4px; color: var(--text-main);" title="<?php echo htmlspecialchars($tx['MoTa'] ?? ''); ?>">
                                                <?php echo htmlspecialchars(getTransactionTypeLabel($tx['LoaiGiaoDich'], $wallet['MaVi'], $tx['MaViNguon'])); ?>
                                            </td>
                                            <td style="padding: 10px 4px; text-align: right; font-weight: 700; color: <?php echo $color; ?>;">
                                                <?php echo $prefix . number_format($tx['SoTien'], 0, ',', '.'); ?> đ
                                            </td>
                                            <td style="padding: 10px 4px; text-align: center;">
                                                <span style="font-size: 0.7rem; font-weight: 700; padding: 4px 8px; border-radius: 50px; <?php echo $badge_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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

        // Tự động thêm CSRF Token vào tất cả các form POST
        document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(form => {
            if (!form.querySelector('input[name="csrf_token"]')) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = '<?php echo getCsrfToken(); ?>';
                form.appendChild(input);
            }
        });

        // Hàm đặt nhanh số tiền nạp
        function setDepositAmount(amount) {
            const input = document.getElementById('deposit_amount');
            if (input) {
                input.value = amount;
            }
        }
    </script>
</body>
</html>
