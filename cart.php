<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;

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

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle Cart Actions (POST)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_qty') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        
        if ($is_logged_in) {
            try {
                $db = getDBConnection();
                $stmt = $db->prepare("UPDATE GioHang SET SoLuong = :qty WHERE MaNguoiDung = :uid AND MaSanPham = :pid");
                $stmt->execute(['qty' => $qty, 'uid' => $user_data['MaNguoiDung'], 'pid' => $pid]);
                $_SESSION['flash_success'] = "Đã cập nhật số lượng!";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Lỗi cập nhật: " . $e->getMessage();
            }
        } else {
            if (isset($_SESSION['cart'][$pid])) {
                $_SESSION['cart'][$pid] = $qty;
                $_SESSION['flash_success'] = "Đã cập nhật số lượng!";
            }
        }
        header("Location: cart.php");
        exit;
    }

    if ($action === 'remove_item') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($is_logged_in) {
            try {
                $db = getDBConnection();
                $stmt = $db->prepare("DELETE FROM GioHang WHERE MaNguoiDung = :uid AND MaSanPham = :pid");
                $stmt->execute(['uid' => $user_data['MaNguoiDung'], 'pid' => $pid]);
                $_SESSION['flash_success'] = "Đã xóa sản phẩm khỏi giỏ hàng!";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Lỗi xóa sản phẩm: " . $e->getMessage();
            }
        } else {
            if (isset($_SESSION['cart'][$pid])) {
                unset($_SESSION['cart'][$pid]);
                $_SESSION['flash_success'] = "Đã xóa sản phẩm khỏi giỏ hàng!";
            }
        }
        header("Location: cart.php");
        exit;
    }

    if ($action === 'clear_cart') {
        if ($is_logged_in) {
            try {
                $db = getDBConnection();
                $stmt = $db->prepare("DELETE FROM GioHang WHERE MaNguoiDung = :uid");
                $stmt->execute(['uid' => $user_data['MaNguoiDung']]);
            } catch (Exception $e) {}
        } else {
            unset($_SESSION['cart']);
        }
        $_SESSION['flash_success'] = "Đã xóa tất cả sản phẩm khỏi giỏ hàng!";
        header("Location: cart.php");
        exit;
    }
}

// Fetch Cart Data
$cart_items = [];
$subtotal = 0;

if ($is_logged_in) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT gh.MaGioHang, gh.SoLuong, gh.MaSanPham, sp.TenSanPham, sp.GiaBan, sp.TinhTrang, sp.KhoiLuong_Kg, sp.MaNguoiBan, nd.HoTen as TenNguoiBan,
                   (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC, MaHinhAnh ASC LIMIT 1) as DuongDanAnh
            FROM GioHang gh
            JOIN SanPham sp ON gh.MaSanPham = sp.MaSanPham
            JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
            WHERE gh.MaNguoiDung = :uid
            ORDER BY gh.NgayThem DESC
        ");
        $stmt->execute(['uid' => $user_data['MaNguoiDung']]);
        $cart_items = $stmt->fetchAll();
    } catch (Exception $e) {
        $cart_items = [];
    }
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
        } catch (Exception $e) {
            // Fallback for mock items in session
            $mock_all = [
                1 => ['MaSanPham' => 1, 'TenSanPham' => 'iPhone 15 Pro Max 256GB Natural Titanium', 'GiaBan' => 22500000, 'TinhTrang' => 'Likenew 99%', 'TenNguoiBan' => 'Nguyễn Văn A', 'DuongDanAnh' => 'uploads/images/iphone.png'],
                2 => ['MaSanPham' => 2, 'TenSanPham' => 'MacBook Pro M2 2022 13 inch 8GB 256GB Gray', 'GiaBan' => 18900000, 'TinhTrang' => 'Mới 98%', 'TenNguoiBan' => 'Trần Thị B', 'DuongDanAnh' => 'uploads/images/macbook.png'],
                3 => ['MaSanPham' => 3, 'TenSanPham' => 'Tai nghe Sony WH-1000XM5 Wireless Noise Canceling', 'GiaBan' => 5800000, 'TinhTrang' => 'Mới 95%', 'TenNguoiBan' => 'Lê Thị D', 'DuongDanAnh' => 'uploads/images/headphone.png']
            ];
            foreach ($session_cart as $pid => $qty) {
                if (isset($mock_all[$pid])) {
                    $item = $mock_all[$pid];
                    $item['SoLuong'] = $qty;
                    $cart_items[] = $item;
                }
            }
        }
    }
}

foreach ($cart_items as $item) {
    $subtotal += ($item['GiaBan'] * $item['SoLuong']);
}

// Shipping Fee Config
$ship_fee = count($cart_items) > 0 ? 15000 : 0;
$total_pay = $subtotal + $ship_fee;
$cart_count = getCartItemCount();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giỏ Hàng - Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .cart-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .cart-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 32px;
        }
        @media (max-width: 900px) {
            .cart-grid { grid-template-columns: 1fr; }
        }
        .cart-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 24px;
        }
        .cart-item-row {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .cart-item-row:last-child { border-bottom: none; }
        .cart-thumb {
            width: 90px;
            height: 90px;
            border-radius: 16px;
            object-fit: cover;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .qty-box {
            display: inline-flex;
            align-items: center;
            border: 1px solid #cbd5e1;
            border-radius: 50px;
            overflow: hidden;
        }
        .qty-box button {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 36px;
            font-weight: bold;
            cursor: pointer;
        }
        .qty-box input {
            width: 40px;
            text-align: center;
            border: none;
            font-weight: 700;
        }
        .order-summary-box {
            background: #ffffff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            position: sticky;
            top: 100px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 14px;
            font-size: 0.95rem;
            color: var(--text-muted);
        }
        .summary-row.total {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--text-main);
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
            margin-top: 16px;
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
                    <a href="cart.php" class="nav-link active" style="display: flex; align-items: center; gap: 6px;">
                        Giỏ Hàng
                        <?php if ($cart_count > 0): ?>
                            <span style="background: var(--primary); color: white; border-radius: 50px; padding: 2px 8px; font-size: 0.75rem; font-weight: 700;">
                                <?php echo $cart_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="post_product.php" class="nav-link" style="color: var(--primary); font-weight: 700;">Đăng Bán</a>
                    <?php if ($is_logged_in): ?>
                        <a href="profile.php" class="nav-link">Hồ Sơ</a>
                    <?php else: ?>
                        <a href="login_page.php" class="btn btn-primary" style="padding: 8px 18px; font-size: 0.9rem; border-radius: 50px;">Đăng Nhập</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <div class="cart-container">
            <h1 style="font-size: 1.8rem; font-weight: 800; margin-bottom: 24px;">Giỏ Hàng Của Bạn (<?php echo count($cart_items); ?> sản phẩm)</h1>

            <?php if (!empty($flash_success)): ?>
                <div style="background: #dcfce7; color: #166534; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">
                    ✓ <?php echo htmlspecialchars($flash_success); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($flash_error)): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">
                    ✕ <?php echo htmlspecialchars($flash_error); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($cart_items)): ?>
                <div class="cart-card" style="text-align: center; padding: 60px 20px;">
                    <h2 style="font-size: 1.3rem; margin-bottom: 8px;">Giỏ hàng của bạn đang trống</h2>
                    <p style="color: var(--text-muted); margin-bottom: 24px;">Hãy chọn các món đồ cũ chất lượng và giá rẻ trên Chợ Đồ Cũ nhé!</p>
                    <a href="index.php" class="btn btn-primary" style="border-radius: 50px; padding: 12px 30px;">Khám Phá Sản Phẩm</a>
                </div>
            <?php else: ?>
                <div class="cart-grid">
                    <div>
                        <div class="cart-card">
                            <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9; margin-bottom: 10px;">
                                <span style="font-weight: 700; color: var(--text-main);">Danh sách mặt hàng</span>
                                <form method="POST" action="cart.php" onsubmit="return confirm('Bạn có chắc muốn xóa toàn bộ giỏ hàng?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                                    <button type="submit" name="action" value="clear_cart" style="background: none; border: none; color: #ef4444; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                                        Xóa tất cả
                                    </button>
                                </form>
                            </div>

                            <?php foreach ($cart_items as $item): ?>
                                <?php 
                                    $item_sub = $item['GiaBan'] * $item['SoLuong'];
                                    $img_src = !empty($item['DuongDanAnh']) ? $item['DuongDanAnh'] : 'assets/images/no-image.png';
                                ?>
                                <div class="cart-item-row">
                                    <img src="<?php echo htmlspecialchars($img_src); ?>" alt="Thumb" class="cart-thumb">
                                    <div style="flex: 1;">
                                        <a href="product_detail.php?id=<?php echo $item['MaSanPham']; ?>" style="font-weight: 700; color: var(--text-main); text-decoration: none; font-size: 1.05rem; display: block; margin-bottom: 6px;">
                                            <?php echo htmlspecialchars($item['TenSanPham']); ?>
                                        </a>
                                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">
                                            Người bán: <strong><?php echo htmlspecialchars($item['TenNguoiBan']); ?></strong> • <?php echo htmlspecialchars($item['TinhTrang']); ?>
                                        </div>
                                        <div style="font-weight: 800; color: var(--primary); font-size: 1.1rem;">
                                            <?php echo number_format($item['GiaBan'], 0, ',', '.'); ?> đ
                                        </div>
                                    </div>

                                    <div style="text-align: right;">
                                        <!-- Quantity Form -->
                                        <form method="POST" action="cart.php" style="display: inline-block; margin-bottom: 8px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="update_qty">
                                            <input type="hidden" name="product_id" value="<?php echo $item['MaSanPham']; ?>">
                                            <div class="qty-box">
                                                <button type="submit" name="quantity" value="<?php echo max(1, $item['SoLuong'] - 1); ?>">-</button>
                                                <input type="text" value="<?php echo $item['SoLuong']; ?>" readonly>
                                                <button type="submit" name="quantity" value="<?php echo $item['SoLuong'] + 1; ?>">+</button>
                                            </div>
                                        </form>
                                        
                                        <div>
                                            <form method="POST" action="cart.php" style="display: inline-block;">
                                                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="remove_item">
                                                <input type="hidden" name="product_id" value="<?php echo $item['MaSanPham']; ?>">
                                                <button type="submit" style="background: none; border: none; color: #94a3b8; font-size: 0.85rem; cursor: pointer; text-decoration: underline;">
                                                    Xóa
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Summary Column -->
                    <div>
                        <div class="order-summary-box">
                            <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 20px;">Tóm Tắt Đơn Hàng</h3>
                            
                            <div class="summary-row">
                                <span>Tạm tính sản phẩm:</span>
                                <strong><?php echo number_format($subtotal, 0, ',', '.'); ?> đ</strong>
                            </div>
                            <div class="summary-row">
                                <span>Phí vận chuyển dự kiến:</span>
                                <strong><?php echo number_format($ship_fee, 0, ',', '.'); ?> đ</strong>
                            </div>

                            <div class="summary-row total">
                                <span>Tổng thanh toán:</span>
                                <span style="color: var(--primary);"><?php echo number_format($total_pay, 0, ',', '.'); ?> đ</span>
                            </div>

                            <a href="checkout.php" class="btn btn-primary" style="display: block; text-align: center; border-radius: 50px; padding: 14px; font-weight: 700; font-size: 1.05rem; margin-top: 24px; text-decoration: none;">
                                Tiến Hành Thanh Toán ➔
                            </a>
                        </div>
                    </div>
                </div>
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
