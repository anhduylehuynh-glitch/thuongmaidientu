<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;
$user_id = 0;

if (isset($_SESSION['user_id'])) {
    try {
        $db = getDBConnection();
        $user_id = $_SESSION['user_id'];
        $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
        $stmt->execute(['id' => $user_id]);
        $user_data = $stmt->fetch();
        if ($user_data) {
            $is_logged_in = true;
        }
    } catch (Exception $e) {
        $is_logged_in = false;
    }
}

$direct_sp = isset($_GET['direct_sp']) ? (int)$_GET['direct_sp'] : 0;
$direct_qty = isset($_GET['qty']) ? max(1, (int)$_GET['qty']) : 1;

$cart_items = [];
$subtotal = 0;

// Fetch Items to Checkout
if ($direct_sp > 0) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT sp.MaSanPham, sp.TenSanPham, sp.GiaBan, sp.TinhTrang, sp.KhoiLuong_Kg, sp.MaNguoiBan, nd.HoTen as TenNguoiBan,
                   (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC, MaHinhAnh ASC LIMIT 1) as DuongDanAnh
            FROM SanPham sp
            JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
            WHERE sp.MaSanPham = :id
        ");
        $stmt->execute(['id' => $direct_sp]);
        $p = $stmt->fetch();
        if ($p) {
            $p['SoLuong'] = $direct_qty;
            $cart_items[] = $p;
        }
    } catch (Exception $e) {}
}

if (empty($cart_items)) {
    if ($is_logged_in) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("
                SELECT gh.SoLuong, gh.MaSanPham, sp.TenSanPham, sp.GiaBan, sp.TinhTrang, sp.KhoiLuong_Kg, sp.MaNguoiBan, nd.HoTen as TenNguoiBan,
                       (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC, MaHinhAnh ASC LIMIT 1) as DuongDanAnh
                FROM GioHang gh
                JOIN SanPham sp ON gh.MaSanPham = sp.MaSanPham
                JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
                WHERE gh.MaNguoiDung = :uid
            ");
            $stmt->execute(['uid' => $user_id]);
            $cart_items = $stmt->fetchAll();
        } catch (Exception $e) {}
    } else {
        $session_cart = $_SESSION['cart'] ?? [];
        if (!empty($session_cart)) {
            try {
                $db = getDBConnection();
                $pids = array_keys($session_cart);
                $in_clause = implode(',', array_fill(0, count($pids), '?'));
                $stmt = $db->prepare("
                    SELECT sp.MaSanPham, sp.TenSanPham, sp.GiaBan, sp.TinhTrang, sp.KhoiLuong_Kg, sp.MaNguoiBan, nd.HoTen as TenNguoiBan,
                           (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC, MaHinhAnh ASC LIMIT 1) as DuongDanAnh
                    FROM SanPham sp
                    JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
                    WHERE sp.MaSanPham IN ($in_clause)
                ");
                $stmt->execute($pids);
                $db_prods = $stmt->fetchAll();
                foreach ($db_prods as $p) {
                    $p['SoLuong'] = $session_cart[$p['MaSanPham']] ?? 1;
                    $cart_items[] = $p;
                }
            } catch (Exception $e) {}
        }
    }
}

foreach ($cart_items as $item) {
    $subtotal += ($item['GiaBan'] * $item['SoLuong']);
}

$ship_fee = count($cart_items) > 0 ? 15000 : 0;
$total_pay = $subtotal + $ship_fee;
$cart_count = getCartItemCount();

$order_created = false;
$order_id = 0;
$error_msg = '';

// Handle Order Placement POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    $ho_ten = trim($_POST['ho_ten'] ?? '');
    $so_dien_thoai = trim($_POST['so_dien_thoai'] ?? '');
    $dia_chi = trim($_POST['dia_chi'] ?? '');
    $phuong_thuc = trim($_POST['phuong_thuc'] ?? 'COD');

    if (empty($ho_ten) || empty($so_dien_thoai) || empty($dia_chi)) {
        $error_msg = 'Vui lòng điền đầy đủ họ tên, số điện thoại và địa chỉ giao hàng.';
    } elseif (empty($cart_items)) {
        $error_msg = 'Giỏ hàng của bạn đang trống.';
    } else {
        try {
            $db = getDBConnection();
            $db->beginTransaction();

            // Find or Create User if guest
            $buyer_id = $user_id;
            if (!$is_logged_in) {
                // Check if user exists by phone/email or create temporary buyer
                $chk = $db->prepare("SELECT MaNguoiDung FROM NguoiDung WHERE SoDienThoai = :phone LIMIT 1");
                $chk->execute(['phone' => $so_dien_thoai]);
                $buyer_id = $chk->fetchColumn();

                if (!$buyer_id) {
                    $ins_u = $db->prepare("INSERT INTO NguoiDung (TenDangNhap, HoTen, SoDienThoai) VALUES (:username, :name, :phone)");
                    $ins_u->execute([
                        'username' => 'guest_' . time() . rand(100, 999),
                        'name' => $ho_ten,
                        'phone' => $so_dien_thoai
                    ]);
                    $buyer_id = $db->lastInsertId();
                }
            }

            // Create Address record in SoDiaChi
            $ins_addr = $db->prepare("INSERT INTO SoDiaChi (MaNguoiDung, DiaChiChiTiet, ViDo, KinhDo) VALUES (:uid, :addr, 10.762622, 106.660172)");
            $ins_addr->execute(['uid' => $buyer_id, 'addr' => $dia_chi]);
            $addr_id = $db->lastInsertId();

            // Create Order
            $ins_order = $db->prepare("
                INSERT INTO DonHang (MaNguoiMua, MaDiaChiGiao, PhuongThucThanhToan, TongTienThanhToan, TrangThaiDonHang, TrangThaiThanhToan)
                VALUES (:buyer, :addr, :pt, :total, b'000', b'000')
            ");
            $ins_order->execute([
                'buyer' => $buyer_id,
                'addr' => $addr_id,
                'pt' => $phuong_thuc,
                'total' => $total_pay
            ]);
            $order_id = $db->lastInsertId();

            // Create Order Details
            $ins_detail = $db->prepare("
                INSERT INTO ChiTietDonHang (MaDonHang, MaSanPham, SoLuong, GiaChotMua, PhiShipGoc, PhiShipThucTe)
                VALUES (:oid, :pid, :qty, :gia, :ship_goc, :ship_tt)
            ");
            foreach ($cart_items as $item) {
                $ins_detail->execute([
                    'oid' => $order_id,
                    'pid' => $item['MaSanPham'],
                    'qty' => $item['SoLuong'],
                    'gia' => $item['GiaBan'],
                    'ship_goc' => 15000,
                    'ship_tt' => 15000
                ]);
            }

            // Clear Cart if order placed from cart
            if ($direct_sp == 0) {
                if ($is_logged_in) {
                    $del_cart = $db->prepare("DELETE FROM GioHang WHERE MaNguoiDung = :uid");
                    $del_cart->execute(['uid' => $user_id]);
                } else {
                    unset($_SESSION['cart']);
                }
            }

            $db->commit();
            $order_created = true;
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $error_msg = 'Đã xảy ra lỗi khi tạo đơn hàng: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thanh Toán - Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .checkout-container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .checkout-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 32px;
        }
        @media (max-width: 900px) {
            .checkout-grid { grid-template-columns: 1fr; }
        }
        .checkout-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 24px;
        }
        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 6px;
        }
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.95rem;
            margin-bottom: 16px;
        }
        .payment-radio-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 8px;
        }
        .payment-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .payment-option:hover {
            border-color: var(--primary);
            background: #f8fafc;
        }
        .payment-option input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }
        .order-item-mini {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .order-item-mini:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="background-decor"></div>
    <div class="site-wrapper">
        <header class="site-header">
            <div class="nav-container">
                <a href="index.php" class="brand-logo">Chợ Đồ Cũ</a>
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Trang Chủ</a>
                    <a href="cart.php" class="nav-link" style="display: flex; align-items: center; gap: 6px;">
                        Giỏ Hàng
                        <?php if ($cart_count > 0): ?>
                            <span style="background: var(--primary); color: white; border-radius: 50px; padding: 2px 8px; font-size: 0.75rem; font-weight: 700;">
                                <?php echo $cart_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </nav>
            </div>
        </header>

        <div class="checkout-container">
            <?php if ($order_created): ?>
                <!-- Success Order Screen -->
                <div class="checkout-card" style="text-align: center; padding: 60px 20px;">
                    <h1 style="font-size: 1.8rem; font-weight: 800; color: var(--text-main); margin-bottom: 8px;">Đặt Hàng Thành Công!</h1>
                    <p style="color: var(--text-muted); font-size: 1.05rem; margin-bottom: 24px;">
                        Mã đơn hàng của bạn là: <strong style="color: var(--primary);">#DH-<?php echo sprintf('%05d', $order_id); ?></strong>
                    </p>
                    <div style="background: #f8fafc; border-radius: 16px; padding: 20px; max-width: 500px; margin: 0 auto 28px; text-align: left; border: 1px solid #e2e8f0;">
                        <div style="font-weight: 700; margin-bottom: 8px; color: var(--text-main);">Chi tiết thanh toán:</div>
                        <div style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.8;">
                            <div>Phương thức: <strong><?php echo htmlspecialchars($phuong_thuc); ?></strong></div>
                            <div>Tổng thanh toán: <strong style="color: var(--primary); font-size: 1.1rem;"><?php echo number_format($total_pay, 0, ',', '.'); ?> đ</strong></div>
                            <div>Trạng thái: <strong>Chờ người bán xác nhận & đóng gói</strong></div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                        <a href="orders.php" class="btn btn-primary" style="border-radius: 50px; padding: 14px 32px; text-decoration: none; font-weight: 700;">Xem Đơn Hàng Của Tôi</a>
                        <a href="index.php" class="btn btn-outline" style="border-radius: 50px; padding: 14px 32px; text-decoration: none; font-weight: 700;">Tiếp Tục Mua Sắm</a>
                    </div>
                </div>
            <?php elseif (empty($cart_items)): ?>
                <div class="checkout-card" style="text-align: center; padding: 60px 20px;">
                    <h2 style="font-size: 1.3rem; margin-bottom: 16px;">Không có sản phẩm nào để thanh toán!</h2>
                    <a href="index.php" class="btn btn-primary" style="border-radius: 50px; padding: 12px 30px;">Về Trang Chủ</a>
                </div>
            <?php else: ?>
                <h1 style="font-size: 1.8rem; font-weight: 800; margin-bottom: 24px;">Xác Nhận & Thanh Toán Đơn Hàng</h1>

                <?php if (!empty($error_msg)): ?>
                    <div style="background: #fee2e2; color: #991b1b; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">
                        ✕ <?php echo htmlspecialchars($error_msg); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="checkout.php<?php echo $direct_sp > 0 ? "?direct_sp=$direct_sp&qty=$direct_qty" : ""; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="place_order">

                    <div class="checkout-grid">
                        <!-- Left Form Column -->
                        <div>
                            <!-- Address Box -->
                            <div class="checkout-card">
                                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 20px;">
                                    Thông Tin Nhận Hàng
                                </h3>

                                <div>
                                    <label class="form-label">Họ và tên người nhận (*)</label>
                                    <input type="text" name="ho_ten" class="form-input" placeholder="Ví dụ: Nguyễn Văn A" value="<?php echo htmlspecialchars($user_data['HoTen'] ?? ''); ?>" required>
                                </div>

                                <div>
                                    <label class="form-label">Số điện thoại liên hệ (*)</label>
                                    <input type="tel" name="so_dien_thoai" class="form-input" placeholder="Ví dụ: 0912345678" value="<?php echo htmlspecialchars($user_data['SoDienThoai'] ?? ''); ?>" required>
                                </div>

                                <div>
                                    <label class="form-label">Địa chỉ nhận hàng chi tiết (*)</label>
                                    <textarea name="dia_chi" rows="3" class="form-input" placeholder="Số nhà, tên đường, Phường/Xã, Quận/Huyện, Tỉnh/Thành phố" required></textarea>
                                </div>
                            </div>

                            <!-- Payment Method Box -->
                            <div class="checkout-card">
                                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 20px;">
                                    Phương Thức Thanh Toán
                                </h3>

                                <div class="payment-radio-group">
                                    <label class="payment-option">
                                        <input type="radio" name="phuong_thuc" value="COD" checked>
                                        <div>
                                            <div style="font-weight: 700; color: var(--text-main);">Thanh toán khi nhận hàng (COD)</div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">Trả tiền mặt cho shipper khi nhận được gói hàng</div>
                                        </div>
                                    </label>

                                    <label class="payment-option">
                                        <input type="radio" name="phuong_thuc" value="Ví điện tử">
                                        <div>
                                            <div style="font-weight: 700; color: var(--text-main);">Ví Điện Tử Sàn</div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">Thanh toán trực tiếp từ số dư ví hệ thống</div>
                                        </div>
                                    </label>

                                    <label class="payment-option">
                                        <input type="radio" name="phuong_thuc" value="VNPay">
                                        <div>
                                            <div style="font-weight: 700; color: var(--text-main);">Cổng Thanh Toán VNPay / QR Code</div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">Quét mã QR Ngân hàng hoặc thẻ ATM/Visa</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Right Summary Column -->
                        <div>
                            <div class="checkout-card" style="position: sticky; top: 100px;">
                                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 16px;">Sản Phẩm Đặt Mua</h3>

                                <div style="max-height: 280px; overflow-y: auto; margin-bottom: 20px;">
                                    <?php foreach ($cart_items as $item): ?>
                                        <div class="order-item-mini">
                                            <img src="<?php echo htmlspecialchars(!empty($item['DuongDanAnh']) ? $item['DuongDanAnh'] : 'assets/images/no-image.png'); ?>" alt="Thumb" style="width: 50px; height: 50px; border-radius: 10px; object-fit: cover;">
                                            <div style="flex: 1;">
                                                <div style="font-weight: 600; font-size: 0.9rem; color: var(--text-main); line-height: 1.3;">
                                                    <?php echo htmlspecialchars($item['TenSanPham']); ?>
                                                </div>
                                                <div style="font-size: 0.8rem; color: var(--text-muted);">
                                                    <?php echo number_format($item['GiaBan'], 0, ',', '.'); ?> đ x <?php echo $item['SoLuong']; ?>
                                                </div>
                                            </div>
                                            <div style="font-weight: 700; font-size: 0.95rem; color: var(--primary);">
                                                <?php echo number_format($item['GiaBan'] * $item['SoLuong'], 0, ',', '.'); ?> đ
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div style="border-top: 1px solid #f1f5f9; padding-top: 16px;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; color: var(--text-muted);">
                                        <span>Tiền sản phẩm:</span>
                                        <strong><?php echo number_format($subtotal, 0, ',', '.'); ?> đ</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 16px; font-size: 0.9rem; color: var(--text-muted);">
                                        <span>Phí vận chuyển:</span>
                                        <strong><?php echo number_format($ship_fee, 0, ',', '.'); ?> đ</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 800; color: var(--text-main);">
                                        <span>Tổng cộng:</span>
                                        <span style="color: var(--primary);"><?php echo number_format($total_pay, 0, ',', '.'); ?> đ</span>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 50px; padding: 14px; font-weight: 700; font-size: 1.05rem; margin-top: 24px;">
                                    Xác Nhận Đặt Hàng ➔
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <footer class="site-footer">
            <div class="footer-content">
                <div class="footer-brand">Chợ Đồ Cũ</div>
                <p class="footer-text">&copy; 2026 Chợ Đồ Cũ Inc. Bảo lưu mọi quyền.</p>
            </div>
        </footer>
    </div>
</body>
</html>
