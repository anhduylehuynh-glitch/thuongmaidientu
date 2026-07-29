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

// Redirect to login if not logged in
if (!$is_logged_in) {
    header("Location: login_page.php");
    exit;
}

// Helper to format order status
function formatOrderStatus($status_val) {
    $val = is_numeric($status_val) ? (int)$status_val : (int)bindec(decbin(ord((string)$status_val)));
    switch ($val) {
        case 0: return ['text' => 'Chờ xác nhận', 'color' => '#d97706', 'bg' => '#fef3c7'];
        case 1: return ['text' => 'Đang xử lý', 'color' => '#2563eb', 'bg' => '#dbeafe'];
        case 2: return ['text' => 'Đang giao', 'color' => '#0284c7', 'bg' => '#e0f2fe'];
        case 3: return ['text' => 'Đã giao', 'color' => '#16a34a', 'bg' => '#dcfce7'];
        case 4: return ['text' => 'Khiếu nại', 'color' => '#dc2626', 'bg' => '#fee2e2'];
        case 5: return ['text' => 'Hoàn tất', 'color' => '#16a34a', 'bg' => '#dcfce7'];
        case 6: return ['text' => 'Đã hủy', 'color' => '#6b7280', 'bg' => '#f3f4f6'];
        default: return ['text' => 'Chờ xác nhận', 'color' => '#d97706', 'bg' => '#fef3c7'];
    }
}

// Helper to format payment status
function formatPaymentStatus($status_val) {
    $val = is_numeric($status_val) ? (int)$status_val : (int)bindec(decbin(ord((string)$status_val)));
    switch ($val) {
        case 0: return 'Chưa thanh toán';
        case 1: return 'Đã thanh toán';
        case 2: return 'Tạm giữ (Escrow)';
        case 3: return 'Đã giải ngân';
        case 4: return 'Đã hoàn tiền';
        default: return 'Chưa thanh toán';
    }
}

// Fetch user's orders
$orders = [];
try {
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT dh.*, sdc.DiaChiChiTiet
        FROM DonHang dh
        LEFT JOIN SoDiaChi sdc ON dh.MaDiaChiGiao = sdc.MaDiaChi
        WHERE dh.MaNguoiMua = :uid
        ORDER BY dh.NgayTao DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    $orders = $stmt->fetchAll();

    foreach ($orders as &$ord) {
        $item_stmt = $db->prepare("
            SELECT ct.*, sp.TenSanPham, sp.TinhTrang, nd.HoTen as TenNguoiBan,
                   (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC, MaHinhAnh ASC LIMIT 1) as DuongDanAnh
            FROM ChiTietDonHang ct
            JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
            JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
            WHERE ct.MaDonHang = :oid
        ");
        $item_stmt->execute(['oid' => $ord['MaDonHang']]);
        $ord['Items'] = $item_stmt->fetchAll();
    }
    unset($ord);
} catch (Exception $e) {
    $orders = [];
}

$cart_count = getCartItemCount();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn Hàng Của Tôi - Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .orders-container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .order-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 24px;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .order-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .order-status-badge {
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-block;
        }
        .order-item-row {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 14px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .order-item-row:last-child { border-bottom: none; }
        .order-item-img {
            width: 75px;
            height: 75px;
            border-radius: 12px;
            object-fit: cover;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .order-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 18px;
            border-top: 1px solid #f1f5f9;
            margin-top: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
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
                    <a href="cart.php" class="nav-link" style="display: flex; align-items: center; gap: 6px; position: relative;">
                        Giỏ Hàng
                        <?php if ($cart_count > 0): ?>
                            <span style="background: var(--primary); color: white; border-radius: 50px; padding: 2px 8px; font-size: 0.75rem; font-weight: 700;">
                                <?php echo $cart_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="post_product.php" class="nav-link" style="color: var(--primary); font-weight: 700;">Đăng Bán</a>
                    <a href="profile.php" class="nav-link">Hồ Sơ</a>
                </nav>
            </div>
        </header>

        <div class="orders-container">
            <h1 style="font-size: 1.8rem; font-weight: 800; margin-bottom: 24px;">Đơn Hàng Của Tôi (<?php echo count($orders); ?>)</h1>

            <?php if (empty($orders)): ?>
                <div class="order-card" style="text-align: center; padding: 60px 20px;">
                    <h2 style="font-size: 1.3rem; margin-bottom: 8px; color: var(--text-main);">Bạn chưa có đơn hàng nào</h2>
                    <p style="color: var(--text-muted); margin-bottom: 24px;">Hãy khám phá danh sách sản phẩm giá tốt và trải nghiệm mua sắm trên Chợ Đồ Cũ!</p>
                    <a href="index.php" class="btn btn-primary" style="border-radius: 50px; padding: 12px 30px; text-decoration: none;">Khám Phá Sản Phẩm</a>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $ord): ?>
                    <?php 
                        $status_info = formatOrderStatus($ord['TrangThaiDonHang']);
                        $payment_text = formatPaymentStatus($ord['TrangThaiThanhToan']);
                    ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div>
                                <strong style="font-size: 1.1rem; color: var(--text-main);">
                                    Đơn hàng #DH-<?php echo sprintf('%05d', $ord['MaDonHang']); ?>
                                </strong>
                                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                                    Ngày đặt: <?php echo date('d/m/Y H:i', strtotime($ord['NgayTao'])); ?>
                                </div>
                            </div>
                            <div>
                                <span class="order-status-badge" style="background: <?php echo $status_info['bg']; ?>; color: <?php echo $status_info['color']; ?>;">
                                    <?php echo $status_info['text']; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Items List -->
                        <div style="margin-bottom: 16px;">
                            <?php foreach ($ord['Items'] as $item): ?>
                                <div class="order-item-row">
                                    <img src="<?php echo htmlspecialchars(!empty($item['DuongDanAnh']) ? $item['DuongDanAnh'] : 'assets/images/no-image.png'); ?>" alt="Thumb" class="order-item-img">
                                    <div style="flex: 1;">
                                        <a href="product_detail.php?id=<?php echo $item['MaSanPham']; ?>" style="font-weight: 700; color: var(--text-main); text-decoration: none; font-size: 1rem; display: block; margin-bottom: 4px;">
                                            <?php echo htmlspecialchars($item['TenSanPham']); ?>
                                        </a>
                                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                                            Người bán: <strong><?php echo htmlspecialchars($item['TenNguoiBan']); ?></strong> • Tình trạng: <?php echo htmlspecialchars($item['TinhTrang']); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 2px;">
                                            Số lượng: <?php echo $item['SoLuong']; ?> x <?php echo number_format($item['GiaChotMua'], 0, ',', '.'); ?> đ
                                        </div>
                                    </div>
                                    <div style="font-weight: 800; color: var(--primary); font-size: 1.05rem;">
                                        <?php echo number_format($item['GiaChotMua'] * $item['SoLuong'], 0, ',', '.'); ?> đ
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Order Footer -->
                        <div class="order-footer">
                            <div style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.6;">
                                <div>Địa chỉ giao: <strong><?php echo htmlspecialchars($ord['DiaChiChiTiet'] ?? 'Chưa cập nhật'); ?></strong></div>
                                <div>Thanh toán: <strong><?php echo htmlspecialchars($ord['PhuongThucThanhToan']); ?></strong> (<?php echo $payment_text; ?>)</div>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.9rem; color: var(--text-muted);">Tổng thanh toán: </span>
                                <strong style="font-size: 1.25rem; color: var(--primary);"><?php echo number_format($ord['TongTienThanhToan'], 0, ',', '.'); ?> đ</strong>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
