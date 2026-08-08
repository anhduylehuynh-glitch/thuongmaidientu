<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;
$user_roles = [];

if (isset($_SESSION['user_id'])) {
    try {
        $db = getDBConnection();
        $user_id = $_SESSION['user_id'];
        $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
        $stmt->execute(['id' => $user_id]);
        $user_data = $stmt->fetch();

        if ($user_data) {
            $is_logged_in = true;
            $role_stmt = $db->prepare("
                SELECT vt.TenVaiTro 
                FROM `NguoiDung_VaiTro` ndvt 
                JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro 
                WHERE ndvt.MaNguoiDung = :id
            ");
            $role_stmt->execute(['id' => $user_data['MaNguoiDung']]);
            $user_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        $is_logged_in = false;
    }
}

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$product = null;
$images = [];
$reviews = [];
$avg_rating = 0;
$total_reviews = 0;
$rating_counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle POST actions: Add to cart, Submit review
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_to_cart' || $action === 'buy_now') {
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        
        try {
            $db = getDBConnection();
            $chk_stock = $db->prepare("SELECT SoLuongTon, TrangThaiBan FROM SanPham WHERE MaSanPham = :pid");
            $chk_stock->execute(['pid' => $product_id]);
            $prod_stock = $chk_stock->fetch();

            $stock_available = $prod_stock ? (int)($prod_stock['SoLuongTon'] ?? 1) : 1;
            $current_in_cart = 0;

            if ($is_logged_in) {
                $cart_chk = $db->prepare("SELECT SoLuong FROM GioHang WHERE MaNguoiDung = :uid AND MaSanPham = :pid");
                $cart_chk->execute(['uid' => $user_data['MaNguoiDung'], 'pid' => $product_id]);
                $current_in_cart = (int)$cart_chk->fetchColumn();
            } else {
                $current_in_cart = (int)($_SESSION['cart'][$product_id] ?? 0);
            }

            if ($stock_available <= 0) {
                $_SESSION['flash_error'] = "Sản phẩm này đã hết hàng trong kho!";
                header("Location: product_detail.php?id=" . $product_id);
                exit;
            }

            if (($current_in_cart + $qty) > $stock_available) {
                $_SESSION['flash_error'] = "Số lượng trong kho không đủ! (Kho còn {$stock_available} sản phẩm, giỏ của bạn đã có {$current_in_cart}).";
                header("Location: product_detail.php?id=" . $product_id);
                exit;
            }

            if ($is_logged_in) {
                $stmt = $db->prepare("
                    INSERT INTO GioHang (MaNguoiDung, MaSanPham, SoLuong)
                    VALUES (:uid, :pid, :qty)
                    ON DUPLICATE KEY UPDATE SoLuong = SoLuong + :qty2
                ");
                $stmt->execute([
                    'uid' => $user_data['MaNguoiDung'],
                    'pid' => $product_id,
                    'qty' => $qty,
                    'qty2' => $qty
                ]);
                $_SESSION['flash_success'] = "Đã thêm sản phẩm vào giỏ hàng!";
            } else {
                if (!isset($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }
                $_SESSION['cart'][$product_id] = ($_SESSION['cart'][$product_id] ?? 0) + $qty;
                $_SESSION['flash_success'] = "Đã thêm sản phẩm vào giỏ hàng!";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Lỗi xử lý giỏ hàng: " . $e->getMessage();
        }
        
        if ($action === 'buy_now') {
            header("Location: checkout.php?direct_sp=" . $product_id . "&qty=" . $qty);
            exit;
        } else {
            header("Location: product_detail.php?id=" . $product_id);
            exit;
        }
    }
    
    if ($action === 'add_review' || $action === 'edit_review') {
        if (!$is_logged_in) {
            $_SESSION['flash_error'] = "Vui lòng đăng nhập để gửi đánh giá!";
            header("Location: product_detail.php?id=" . $product_id);
            exit;
        }
        
        $so_sao = isset($_POST['so_sao']) ? (int)$_POST['so_sao'] : 0;
        $nhan_xet = trim($_POST['nhan_xet'] ?? '');
        
        if ($so_sao < 1 || $so_sao > 5) {
            $_SESSION['flash_error'] = "Vui lòng chọn số sao đánh giá (từ 1 đến 5 sao)!";
            header("Location: product_detail.php?id=" . $product_id);
            exit;
        }
        
        try {
            $db = getDBConnection();

            // Kiểm tra xem người dùng đã đánh giá sản phẩm này chưa
            $check_stmt = $db->prepare("
                SELECT MaDanhGia, SoSao FROM DonDanhGiaSanPham 
                WHERE MaSanPham = :pid AND MaNguoiDanhGia = :uid
            ");
            $check_stmt->execute([
                'pid' => $product_id,
                'uid' => $user_data['MaNguoiDung']
            ]);
            $existing_review = $check_stmt->fetch();

            if ($existing_review) {
                $old_sao = (int)$existing_review['SoSao'];
                $existing_review_id = $existing_review['MaDanhGia'];

                // Cập nhật lại đánh giá đã có
                $update_stmt = $db->prepare("
                    UPDATE DonDanhGiaSanPham 
                    SET SoSao = :sao, NhanXet = :nx, NgayDanhGia = NOW()
                    WHERE MaDanhGia = :rid AND MaNguoiDanhGia = :uid
                ");
                $update_stmt->execute([
                    'sao' => $so_sao,
                    'nx' => $nhan_xet,
                    'rid' => $existing_review_id,
                    'uid' => $user_data['MaNguoiDung']
                ]);

                // Cập nhật điểm uy tín người bán dựa trên chênh lệch số sao
                updateSellerReputationByProduct($db, $product_id, $old_sao, $so_sao);

                $_SESSION['flash_success'] = "Đã cập nhật đánh giá của bạn thành công!";
            } else {
                // Thêm đánh giá mới lần đầu
                $stmt = $db->prepare("
                    INSERT INTO DonDanhGiaSanPham (MaSanPham, MaNguoiDanhGia, SoSao, NhanXet)
                    VALUES (:pid, :uid, :sao, :nx)
                ");
                $stmt->execute([
                    'pid' => $product_id,
                    'uid' => $user_data['MaNguoiDung'],
                    'sao' => $so_sao,
                    'nx' => $nhan_xet
                ]);

                // Cập nhật điểm uy tín người bán cho đánh giá mới
                updateSellerReputationByProduct($db, $product_id, 0, $so_sao);

                $_SESSION['flash_success'] = "Cảm ơn bạn đã gửi đánh giá cho sản phẩm!";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Không thể gửi đánh giá: " . $e->getMessage();
        }
        header("Location: product_detail.php?id=" . $product_id);
        exit;
    }
}

// Fetch Product Details from DB
try {
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT sp.*, nd.HoTen as TenNguoiBan, nd.DiemUyTin, nd.google_picture as SellerAvatar, nd.Email as SellerEmail, dm.TenDanhMuc
        FROM SanPham sp
        JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
        JOIN DanhMuc dm ON sp.MaDanhMuc = dm.MaDanhMuc
        WHERE sp.MaSanPham = :id
    ");
    $stmt->execute(['id' => $product_id]);
    $product = $stmt->fetch();

    if ($product) {
        $img_stmt = $db->prepare("SELECT DuongDanAnh, AnhChinh FROM HinhAnhSP WHERE MaSanPham = :pid ORDER BY AnhChinh DESC, MaHinhAnh ASC");
        $img_stmt->execute(['pid' => $product_id]);
        $images = $img_stmt->fetchAll();

        // Fetch reviews
        $rev_stmt = $db->prepare("
            SELECT dg.*, nd.HoTen, nd.google_picture
            FROM DonDanhGiaSanPham dg
            JOIN NguoiDung nd ON dg.MaNguoiDanhGia = nd.MaNguoiDung
            WHERE dg.MaSanPham = :pid
            ORDER BY dg.NgayDanhGia DESC
        ");
        $rev_stmt->execute(['pid' => $product_id]);
        $reviews = $rev_stmt->fetchAll();

        $my_review = null;
        if ($is_logged_in && !empty($user_data['MaNguoiDung'])) {
            foreach ($reviews as $r) {
                if ((int)$r['MaNguoiDanhGia'] === (int)$user_data['MaNguoiDung']) {
                    $my_review = $r;
                    break;
                }
            }
        }

        $total_reviews = count($reviews);
        if ($total_reviews > 0) {
            $sum_stars = 0;
            foreach ($reviews as $r) {
                $star = (int)$r['SoSao'];
                $sum_stars += $star;
                if (isset($rating_counts[$star])) {
                    $rating_counts[$star]++;
                }
            }
            $avg_rating = round($sum_stars / $total_reviews, 1);
        }
    }
} catch (Exception $e) {
    // DB error fallback handled below
}

// Mock fallback if product not in DB
if (!$product && $product_id > 0) {
    $mock_all = [
        1 => [
            'MaSanPham' => 1, 'MaNguoiBan' => 1, 'TenSanPham' => 'iPhone 15 Pro Max 256GB Natural Titanium', 'GiaBan' => 22500000,
            'TinhTrang' => 'Likenew 99%', 'TenDanhMuc' => 'Điện thoại', 'TenNguoiBan' => 'Nguyễn Văn A', 'DiemUyTin' => 95,
            'KhoiLuong_Kg' => 0.22, 'MoTaChiTiet' => 'Máy chính hãng VN/A mua tại TopZone còn bảo hành dài. Ngoại hình mới 99% không vết xước, pin 98%. Đã dán cường lực xịn và dùng ốp lưng từ ngày đầu.',
            'VideoThucTe' => '', 'SellerAvatar' => '', 'Images' => [['DuongDanAnh' => 'uploads/images/iphone.png']]
        ],
        2 => [
            'MaSanPham' => 2, 'MaNguoiBan' => 2, 'TenSanPham' => 'MacBook Pro M2 2022 13 inch 8GB 256GB Gray', 'GiaBan' => 18900000,
            'TinhTrang' => 'Mới 98%', 'TenDanhMuc' => 'Máy tính & Laptop', 'TenNguoiBan' => 'Trần Thị B', 'DiemUyTin' => 88,
            'KhoiLuong_Kg' => 1.4, 'MoTaChiTiet' => 'Máy dùng giữ gìn, pin sạc hơn 40 lần. Mọi chức năng TouchBar, TouchID nhạy bén. Kèm sạc cáp zin 67W đầy đủ.',
            'VideoThucTe' => '', 'SellerAvatar' => '', 'Images' => [['DuongDanAnh' => 'uploads/images/macbook.png']]
        ],
        3 => [
            'MaSanPham' => 3, 'MaNguoiBan' => 4, 'TenSanPham' => 'Tai nghe Sony WH-1000XM5 Wireless Noise Canceling', 'GiaBan' => 5800000,
            'TinhTrang' => 'Mới 95%', 'TenDanhMuc' => 'Thiết bị âm thanh', 'TenNguoiBan' => 'Lê Thị D', 'DiemUyTin' => 99,
            'KhoiLuong_Kg' => 0.25, 'MoTaChiTiet' => 'Chống ồn chủ động ANC đỉnh cao, âm bass sâu trầm ấm. Ít dùng còn rất mới, tặng kèm hộp đựng chống va đập.',
            'VideoThucTe' => '', 'SellerAvatar' => '', 'Images' => [['DuongDanAnh' => 'uploads/images/headphone.png']]
        ]
    ];
    if (isset($mock_all[$product_id])) {
        $product = $mock_all[$product_id];
        $images = $product['Images'];
        $reviews = [
            ['HoTen' => 'Nguyễn Văn Cường', 'SoSao' => 5, 'NhanXet' => 'Sản phẩm mới đúng như mô tả, người bán thân thiện và đóng gói cẩn thận!', 'NgayDanhGia' => '2026-07-28 10:15:00', 'google_picture' => ''],
            ['HoTen' => 'Phạm Minh Trang', 'SoSao' => 4, 'NhanXet' => 'Máy hoạt động mượt mà, giá cả rất hợp lý cho dòng máy cũ.', 'NgayDanhGia' => '2026-07-25 14:20:00', 'google_picture' => '']
        ];
        $total_reviews = count($reviews);
        $avg_rating = 4.5;
        $rating_counts = [5 => 1, 4 => 1, 3 => 0, 2 => 0, 1 => 0];
    }
}

if (!$product) {
    header("Location: index.php");
    exit;
}

$cart_count = getCartItemCount();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['TenSanPham']); ?> - Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .detail-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 24px;
        }
        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }
        .product-main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            background: #ffffff;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 40px;
        }
        @media (max-width: 868px) {
            .product-main-grid { grid-template-columns: 1fr; }
        }
        .gallery-main-box {
            width: 100%;
            aspect-ratio: 4 / 3;
            border-radius: 16px;
            overflow: hidden;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            position: relative;
            border: 1px solid rgba(0,0,0,0.06);
        }
        .gallery-main-box img, .gallery-main-box video {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .thumb-strip {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 8px;
        }
        .thumb-item {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid transparent;
            cursor: pointer;
            flex-shrink: 0;
            background: #f1f5f9;
        }
        .thumb-item.active {
            border-color: var(--primary);
        }
        .thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-meta-badge {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .product-detail-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 12px;
            line-height: 1.3;
        }
        .product-detail-price {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 20px;
        }
        .seller-card-box {
            background: rgba(248, 250, 252, 0.8);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 16px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .seller-left-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .seller-avatar-lg {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .action-btns-group {
            display: flex;
            gap: 16px;
            margin-top: 28px;
        }
        .quantity-control {
            display: flex;
            align-items: center;
            border: 1px solid #cbd5e1;
            border-radius: 50px;
            overflow: hidden;
            width: 120px;
        }
        .quantity-btn {
            background: #f1f5f9;
            border: none;
            width: 36px;
            height: 42px;
            font-size: 1.2rem;
            cursor: pointer;
            font-weight: bold;
        }
        .quantity-input {
            width: 48px;
            text-align: center;
            border: none;
            font-weight: 700;
            font-size: 1rem;
        }
        .rating-summary-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 40px;
        }
        .rating-overview {
            display: flex;
            align-items: center;
            gap: 40px;
            margin-bottom: 30px;
            padding-bottom: 24px;
            border-bottom: 1px solid #f1f5f9;
        }
        .big-score {
            text-align: center;
        }
        .big-score-val {
            font-size: 3.5rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1;
        }
        .stars-display {
            color: #f59e0b;
            font-size: 1.2rem;
            margin: 6px 0;
        }
        .star-rating-select {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 8px;
            font-size: 1.8rem;
            margin: 12px 0;
        }
        .star-rating-select input { display: none; }
        .star-rating-select label {
            color: #cbd5e1;
            cursor: pointer;
            transition: color 0.2s;
        }
        .star-rating-select input:checked ~ label,
        .star-rating-select label:hover,
        .star-rating-select label:hover ~ label {
            color: #f59e0b;
        }
        .review-item {
            padding: 20px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .review-item:last-child { border-bottom: none; }
        .review-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="background-decor"></div>
    <div class="site-wrapper">
        <!-- Header Nav -->
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
                    <?php if ($is_logged_in): ?>
                        <a href="profile.php" class="nav-link">Hồ Sơ</a>
                    <?php else: ?>
                        <a href="login_page.php" class="btn btn-primary" style="padding: 8px 18px; font-size: 0.9rem; border-radius: 50px;">Đăng Nhập</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <div class="detail-container">
            <!-- Alert Messages -->
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

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="index.php">Trang chủ</a> &rsaquo;
                <span><?php echo htmlspecialchars($product['TenDanhMuc']); ?></span> &rsaquo;
                <span style="color: var(--text-main); font-weight: 600;"><?php echo htmlspecialchars($product['TenSanPham']); ?></span>
            </div>

            <!-- Product Main Grid -->
            <div class="product-main-grid">
                <!-- Gallery Column -->
                <div>
                    <div class="gallery-main-box" id="main_media_container">
                        <?php 
                            $main_img = !empty($images) ? $images[0]['DuongDanAnh'] : ($product['DuongDanAnh'] ?? 'assets/images/no-image.png');
                        ?>
                        <img id="main_display_img" src="<?php echo htmlspecialchars($main_img); ?>" alt="Product">
                        <video id="main_display_video" controls style="display: none;"></video>
                    </div>

                    <?php if (!empty($images) || !empty($product['VideoThucTe'])): ?>
                        <div class="thumb-strip">
                            <?php foreach ($images as $idx => $img): ?>
                                <div class="thumb-item <?php echo $idx === 0 ? 'active' : ''; ?>" onclick="switchMedia('image', '<?php echo htmlspecialchars($img['DuongDanAnh']); ?>', this)">
                                    <img src="<?php echo htmlspecialchars($img['DuongDanAnh']); ?>" alt="Thumb">
                                </div>
                            <?php endforeach; ?>
                            <?php if (!empty($product['VideoThucTe'])): ?>
                                <div class="thumb-item" onclick="switchMedia('video', '<?php echo htmlspecialchars($product['VideoThucTe']); ?>', this)" style="display: flex; align-items: center; justify-content: center; background: #1e293b; color: white; font-weight: bold; font-size: 0.75rem;">
                                    VIDEO
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Detail Info Column -->
                <div>
                    <span class="product-meta-badge"><?php echo htmlspecialchars($product['TenDanhMuc']); ?></span>
                    <h1 class="product-detail-title"><?php echo htmlspecialchars($product['TenSanPham']); ?></h1>
                    
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <div class="stars-display" style="font-size: 1rem;">
                            <?php 
                                $stars_round = (int)round($avg_rating);
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $stars_round ? '★' : '☆';
                                }
                            ?>
                        </div>
                        <span style="font-weight: 700; color: var(--text-main); font-size: 0.95rem;"><?php echo $avg_rating; ?> / 5.0</span>
                        <span style="color: var(--text-muted); font-size: 0.9rem;">(<?php echo $total_reviews; ?> đánh giá)</span>
                    </div>

                    <div class="product-detail-price"><?php echo number_format($product['GiaBan'], 0, ',', '.'); ?> đ</div>

                    <div style="margin-bottom: 20px; font-size: 0.95rem; color: var(--text-muted); line-height: 1.8;">
                        <div>Tình trạng: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($product['TinhTrang']); ?></strong></div>
                        <div>Khối lượng đóng gói: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($product['KhoiLuong_Kg'] ?? '0.5'); ?> kg</strong></div>
                        <div>Số lượng trong kho: <strong style="color: var(--primary);"><?php echo (int)($product['SoLuongTon'] ?? 1); ?> sản phẩm</strong></div>
                    </div>

                    <!-- Seller Info -->
                    <div class="seller-card-box">
                        <div class="seller-left-info">
                            <?php if (!empty($product['SellerAvatar'])): ?>
                                <img src="<?php echo htmlspecialchars($product['SellerAvatar']); ?>" alt="Seller" class="seller-avatar-lg">
                            <?php else: ?>
                                <div class="seller-avatar-lg">
                                    <?php echo strtoupper(substr($product['TenNguoiBan'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($product['TenNguoiBan']); ?></div>
                                <div style="font-size: 0.85rem; color: #d97706; font-weight: 600;"><?php echo htmlspecialchars($product['DiemUyTin']); ?> Điểm Uy Tín</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <a href="chat.php?partner_id=<?php echo $product['MaNguoiBan']; ?>&product_id=<?php echo $product['MaSanPham']; ?>" class="btn btn-primary" style="border-radius: 50px; font-size: 0.85rem; padding: 6px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">💬 Chat</a>
                            <a href="seller.php?id=<?php echo $product['MaNguoiBan']; ?>" class="btn btn-outline" style="border-radius: 50px; font-size: 0.85rem; padding: 6px 14px; text-decoration: none;">Xem Cửa Hàng</a>
                        </div>
                    </div>

                    <!-- Description -->
                    <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 8px;">Mô tả sản phẩm</h3>
                    <div style="font-size: 0.95rem; color: var(--text-muted); line-height: 1.6; white-space: pre-line; margin-bottom: 24px;">
                        <?php echo htmlspecialchars($product['MoTaChiTiet']); ?>
                    </div>

                    <!-- Action Form -->
                    <form method="POST" action="product_detail.php?id=<?php echo $product_id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px;">
                            <label style="font-weight: 600; font-size: 0.9rem;">Số lượng:</label>
                            <div class="quantity-control">
                                <button type="button" class="quantity-btn" onclick="adjustQty(-1)">-</button>
                                <input type="number" id="quantity_input" name="quantity" value="1" min="1" class="quantity-input" readonly>
                                <button type="button" class="quantity-btn" onclick="adjustQty(1)">+</button>
                            </div>
                        </div>

                        <div class="action-btns-group">
                            <button type="submit" name="action" value="add_to_cart" class="btn btn-outline" style="border-radius: 50px; flex: 1; padding: 14px; font-size: 1rem;">
                                Thêm Vào Giỏ Hàng
                            </button>
                            <button type="submit" name="action" value="buy_now" class="btn btn-primary" style="border-radius: 50px; flex: 1; padding: 14px; font-size: 1rem;">
                                Thanh Toán Ngay
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Rating & Reviews Section -->
            <div class="rating-summary-card" id="rating-section">
                <h2 style="font-size: 1.4rem; font-weight: 800; margin-bottom: 24px;">Đánh Giá & Nhận Xét Sản Phẩm</h2>

                <div class="rating-overview">
                    <div class="big-score">
                        <div class="big-score-val"><?php echo $avg_rating; ?></div>
                        <div class="stars-display">
                            <?php 
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $stars_round ? '★' : '☆';
                                }
                            ?>
                        </div>
                        <div style="font-size: 0.85rem; color: var(--text-muted);"><?php echo $total_reviews; ?> nhận xét</div>
                    </div>

                    <div style="flex: 1; max-width: 400px;">
                        <?php for ($s = 5; $s >= 1; $s--): ?>
                            <?php 
                                $cnt = $rating_counts[$s] ?? 0;
                                $pct = $total_reviews > 0 ? round(($cnt / $total_reviews) * 100) : 0;
                            ?>
                            <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem; margin-bottom: 6px;">
                                <span style="width: 45px; font-weight: 600; color: var(--text-muted);"><?php echo $s; ?> sao</span>
                                <div style="flex: 1; height: 8px; background: #f1f5f9; border-radius: 10px; overflow: hidden;">
                                    <div style="width: <?php echo $pct; ?>%; height: 100%; background: #f59e0b; border-radius: 10px;"></div>
                                </div>
                                <span style="width: 35px; text-align: right; color: var(--text-muted);"><?php echo $cnt; ?></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Submit / Edit Review Form -->
                <div style="background: #f8fafc; border-radius: 16px; padding: 24px; margin-bottom: 32px; border: 1px solid #e2e8f0;">
                    <?php if ($is_logged_in): ?>
                        <?php if ($my_review): ?>
                            <!-- Người dùng đã đánh giá -> Hiển thị đánh giá hiện tại và nút Chỉnh Sửa -->
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <h3 style="font-size: 1.05rem; font-weight: 700; margin: 0; color: var(--text-main);">
                                    <span style="display: inline-block; background: #e0f2fe; color: #0284c7; font-size: 0.75rem; padding: 3px 8px; border-radius: 6px; vertical-align: middle; margin-right: 6px;">Đã đánh giá</span>
                                    Đánh giá của bạn
                                </h3>
                                <button type="button" id="toggle-edit-btn" onclick="toggleEditReview()" class="btn btn-sm" style="border: 1px solid #cbd5e1; background: white; color: var(--text-main); font-weight: 600; border-radius: 8px; padding: 6px 14px; cursor: pointer;">
                                    ✏️ Chỉnh sửa đánh giá
                                </button>
                            </div>

                            <!-- Display mode -->
                            <div id="my-review-display" style="background: white; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; margin-top: 10px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                    <div class="stars-display" style="font-size: 1.1rem; color: #f59e0b;">
                                        <?php 
                                            for ($i = 1; $i <= 5; $i++) {
                                                echo $i <= (int)$my_review['SoSao'] ? '★' : '☆';
                                            }
                                        ?>
                                        <span style="font-size: 0.9rem; font-weight: 700; color: var(--text-main); margin-left: 6px;">(<?php echo $my_review['SoSao']; ?>/5 sao)</span>
                                    </div>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">
                                        Cập nhật: <?php echo date('d/m/Y H:i', strtotime($my_review['NgayDanhGia'])); ?>
                                    </span>
                                </div>
                                <?php if (!empty(trim($my_review['NhanXet'] ?? ''))): ?>
                                    <p style="font-size: 0.95rem; color: var(--text-main); margin-top: 8px; line-height: 1.5;">
                                        <?php echo htmlspecialchars($my_review['NhanXet']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Edit Form (Ban đầu ẩn) -->
                            <div id="my-review-edit-form" style="display: none; margin-top: 14px;">
                                <form method="POST" action="product_detail.php?id=<?php echo $product_id; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="edit_review">

                                    <div style="margin-bottom: 12px;">
                                        <label style="font-size: 0.9rem; font-weight: 600; color: var(--text-muted);">Thay đổi mức độ hài lòng:</label>
                                        <div class="star-rating-select">
                                            <?php for ($s = 5; $s >= 1; $s--): ?>
                                                <input type="radio" id="edit_star<?php echo $s; ?>" name="so_sao" value="<?php echo $s; ?>" <?php echo ((int)$my_review['SoSao'] === $s) ? 'checked' : ''; ?>>
                                                <label for="edit_star<?php echo $s; ?>" title="<?php echo $s; ?> sao">★</label>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <div style="margin-bottom: 16px;">
                                        <textarea name="nhan_xet" rows="3" placeholder="Nhập nhận xét của bạn (tùy chọn)..." style="width: 100%; padding: 12px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.95rem; resize: vertical;"><?php echo htmlspecialchars($my_review['NhanXet']); ?></textarea>
                                    </div>

                                    <div style="display: flex; gap: 10px;">
                                        <button type="submit" class="btn btn-primary" style="border-radius: 50px; padding: 8px 20px;">Lưu Thay Đổi</button>
                                        <button type="button" onclick="toggleEditReview()" class="btn" style="border-radius: 50px; padding: 8px 20px; background: #e2e8f0; color: var(--text-main);">Hủy</button>
                                    </div>
                                </form>
                            </div>

                            <script>
                                function toggleEditReview() {
                                    const displayDiv = document.getElementById('my-review-display');
                                    const editDiv = document.getElementById('my-review-edit-form');
                                    const btn = document.getElementById('toggle-edit-btn');
                                    if (!editDiv || !displayDiv) return;

                                    if (editDiv.style.display === 'none' || editDiv.style.display === '') {
                                        editDiv.style.display = 'block';
                                        displayDiv.style.display = 'none';
                                        if (btn) btn.style.display = 'none';
                                        const sec = document.getElementById('rating-section');
                                        if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    } else {
                                        editDiv.style.display = 'none';
                                        displayDiv.style.display = 'block';
                                        if (btn) btn.style.display = 'inline-block';
                                    }
                                }
                            </script>

                        <?php else: ?>
                            <!-- Chưa đánh giá: Form tạo mới lần đầu -->
                            <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 4px;">Viết đánh giá của bạn</h3>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 14px;">Mỗi tài khoản được gửi 1 đánh giá và chọn sao 1 lần cho sản phẩm này (có thể chỉnh sửa sau khi gửi).</p>
                            <form method="POST" action="product_detail.php?id=<?php echo $product_id; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="add_review">

                                <div style="margin-bottom: 12px;">
                                    <label style="font-size: 0.9rem; font-weight: 600; color: var(--text-muted);">Chọn mức độ hài lòng <span style="color:var(--error)">*</span>:</label>
                                    <div class="star-rating-select">
                                        <input type="radio" id="star5" name="so_sao" value="5" required><label for="star5" title="5 sao">★</label>
                                        <input type="radio" id="star4" name="so_sao" value="4"><label for="star4" title="4 sao">★</label>
                                        <input type="radio" id="star3" name="so_sao" value="3"><label for="star3" title="3 sao">★</label>
                                        <input type="radio" id="star2" name="so_sao" value="2"><label for="star2" title="2 sao">★</label>
                                        <input type="radio" id="star1" name="so_sao" value="1"><label for="star1" title="1 sao">★</label>
                                    </div>
                                </div>

                                <div style="margin-bottom: 16px;">
                                    <textarea name="nhan_xet" rows="3" placeholder="Chia sẻ trải nghiệm hoặc cảm nhận của bạn về sản phẩm này (tùy chọn)..." style="width: 100%; padding: 12px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.95rem; resize: vertical;"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary" style="border-radius: 50px; padding: 10px 24px;">Gửi Đánh Giá</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 8px;">Viết đánh giá của bạn</h3>
                        <p style="font-size: 0.9rem; color: var(--text-muted); margin: 0;">
                            Vui lòng <a href="login_page.php" style="color: var(--primary); font-weight: 700;">Đăng Nhập</a> để viết nhận xét và đánh giá sản phẩm.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Reviews List -->
                <div>
                    <?php if (empty($reviews)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 30px 0;">
                            Chưa có nhận xét nào cho sản phẩm này. Hãy là người đầu tiên đánh giá!
                        </div>
                    <?php else: ?>
                        <?php foreach ($reviews as $rev): ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php if (!empty($rev['google_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($rev['google_picture']); ?>" alt="Avatar" style="width: 36px; height: 36px; border-radius: 50%;">
                                        <?php else: ?>
                                            <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--primary); color: white; font-weight: bold; display: flex; align-items: center; justify-content: center;">
                                                <?php echo strtoupper(substr($rev['HoTen'] ?? 'U', 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                                                <?php echo htmlspecialchars($rev['HoTen'] ?? 'Người dùng'); ?>
                                                <?php if ($is_logged_in && (int)$rev['MaNguoiDanhGia'] === (int)$user_data['MaNguoiDung']): ?>
                                                    <span style="background: #e0f2fe; color: #0284c7; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Bạn</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="stars-display" style="font-size: 0.85rem; margin: 0;">
                                                <?php 
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        echo $i <= (int)$rev['SoSao'] ? '★' : '☆';
                                                    }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($rev['NgayDanhGia'])); ?></span>
                                        <?php if ($is_logged_in && (int)$rev['MaNguoiDanhGia'] === (int)$user_data['MaNguoiDung']): ?>
                                            <button type="button" onclick="toggleEditReview()" style="border: none; background: transparent; color: var(--primary); font-size: 0.8rem; font-weight: 600; cursor: pointer; text-decoration: underline;">Sửa</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty(trim($rev['NhanXet'] ?? ''))): ?>
                                    <p style="font-size: 0.95rem; color: var(--text-main); margin-top: 8px; line-height: 1.5;">
                                        <?php echo htmlspecialchars($rev['NhanXet']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <footer class="site-footer">
            <div class="footer-content">
                <div class="footer-brand">Chợ Đồ Cũ</div>
                <p class="footer-text">&copy; 2026 Chợ Đồ Cũ Inc. Bảo lưu mọi quyền.</p>
            </div>
        </footer>
    </div>

    <script>
        function adjustQty(delta) {
            const input = document.getElementById('quantity_input');
            let current = parseInt(input.value) || 1;
            current += delta;
            if (current < 1) current = 1;
            input.value = current;
        }

        function switchMedia(type, src, element) {
            document.querySelectorAll('.thumb-item').forEach(el => el.classList.remove('active'));
            if (element) element.classList.add('active');

            const imgDisplay = document.getElementById('main_display_img');
            const videoDisplay = document.getElementById('main_display_video');

            if (type === 'video') {
                imgDisplay.style.display = 'none';
                videoDisplay.style.display = 'block';
                videoDisplay.src = src;
                videoDisplay.play();
            } else {
                videoDisplay.pause();
                videoDisplay.style.display = 'none';
                imgDisplay.style.display = 'block';
                imgDisplay.src = src;
            }
        }
    </script>
    <?php include_once __DIR__ . '/includes/chatbot.php'; ?>
</body>
</html>
