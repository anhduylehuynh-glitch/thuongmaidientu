<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;
$user_roles = [];
$db_error = false;
$db_error_message = '';

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
            header("Location: index.php");
            exit;
        }
    } catch (Exception $e) {
        $db_error = true;
        $db_error_message = $e->getMessage();
        $is_logged_in = true;
        $user_data = $_SESSION['user'];
        $user_roles = ['Mất kết nối DB'];
    }
}

// Truy vấn sản phẩm bán đồ cũ
$products = [];
try {
    $db = getDBConnection();
    $stmt = $db->query("
        SELECT sp.*, nd.HoTen as TenNguoiBan, nd.DiemUyTin, nd.google_picture as SellerAvatar, dm.TenDanhMuc, ha.DuongDanAnh
        FROM SanPham sp
        JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
        JOIN DanhMuc dm ON sp.MaDanhMuc = dm.MaDanhMuc
        LEFT JOIN HinhAnhSP ha ON sp.MaSanPham = ha.MaSanPham AND ha.AnhChinh = 1
        WHERE sp.TrangThaiDuyet = b'01' AND sp.TrangThaiBan = b'00'
        ORDER BY sp.NgayDang DESC
        LIMIT 8
    ");
    $products = $stmt->fetchAll();
} catch (Exception $e) {
    // Không có DB hoặc bảng trống -> Sử dụng sản phẩm mẫu
}

// Nếu không có sản phẩm nào thì tạo danh sách sản phẩm mẫu (Mock Products)
if (empty($products)) {
    $products = [
        [
            'MaSanPham' => 1,
            'TenSanPham' => 'iPhone 13 Pro Max - 256GB Gold',
            'GiaBan' => 14500000,
            'TinhTrang' => 'Cũ xước nhẹ',
            'TenDanhMuc' => 'Điện thoại',
            'TenNguoiBan' => 'Nguyễn Văn A',
            'DiemUyTin' => 95,
            'DuongDanAnh' => '',
            'SellerAvatar' => ''
        ],
        [
            'MaSanPham' => 2,
            'TenSanPham' => 'Laptop Dell XPS 13 9305 Core i7',
            'GiaBan' => 16200000,
            'TinhTrang' => 'Mới 98%',
            'TenDanhMuc' => 'Máy tính & Laptop',
            'TenNguoiBan' => 'Trần Thị B',
            'DiemUyTin' => 88,
            'DuongDanAnh' => '',
            'SellerAvatar' => ''
        ],
        [
            'MaSanPham' => 3,
            'TenSanPham' => 'Bàn phím cơ Keychron K2 V2 Aluminum',
            'GiaBan' => 1500000,
            'TinhTrang' => 'Hoạt động tốt',
            'TenDanhMuc' => 'Phụ kiện máy tính',
            'TenNguoiBan' => 'Phan Văn C',
            'DiemUyTin' => 92,
            'DuongDanAnh' => '',
            'SellerAvatar' => ''
        ],
        [
            'MaSanPham' => 4,
            'TenSanPham' => 'Tai nghe không dây Sony WH-1000XM4',
            'GiaBan' => 4200000,
            'TinhTrang' => 'Fullbox - Mới 99%',
            'TenDanhMuc' => 'Thiết bị âm thanh',
            'TenNguoiBan' => 'Lê Thị D',
            'DiemUyTin' => 99,
            'DuongDanAnh' => '',
            'SellerAvatar' => ''
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chợ Đồ Cũ - Nền Tảng Thương Mại Điện Tử Đồ Cũ Uy Tín</title>
    <!-- Google Fonts Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
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
                    <a href="index.php" class="nav-link active">Trang Chủ</a>
                    <a href="#" class="nav-link">Sản Phẩm</a>
                    <a href="#" class="nav-link">Liên Hệ</a>
                    
                    <!-- Khối người dùng -->
                    <?php if ($is_logged_in): ?>
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
                            
                            <!-- Dropdown menu ẩn/hiện -->
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
                    <?php else: ?>
                        <a href="login_page.php" class="btn btn-primary" style="padding: 8px 18px; font-size: 0.9rem; border-radius: 50px;">Đăng Nhập</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <!-- Hero Banner Section -->
        <section class="hero-section">
            <div class="hero-banner">
                <div>
                    <h1 class="hero-title">Mua Bán Đồ Cũ <br>An Toàn & Uy Tín</h1>
                    <p class="hero-subtitle">
                        Nền tảng kết nối người mua và người bán đồ cũ nhanh chóng. Tích hợp thanh toán an toàn, đánh giá điểm uy tín và hỗ trợ giao dịch P2P tối ưu.
                    </p>
                    <div class="hero-buttons">
                        <a href="#" class="btn btn-primary" style="border-radius: 50px;">Khám phá ngay</a>
                        <a href="#" class="btn btn-outline" style="border-radius: 50px;">Đăng bán đồ cũ</a>
                    </div>
                </div>
                <div class="hero-img-wrapper">
                    <div class="hero-badge-container">
                        <div class="hero-stat-value">50,000+</div>
                        <div class="hero-stat-label">Sản phẩm chất lượng</div>
                        <div style="margin: 20px 0; border-top: 1px solid rgba(255, 255, 255, 0.05);"></div>
                        <div class="hero-stat-value">99%</div>
                        <div class="hero-stat-label">Khách hàng hài lòng</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Products Section -->
        <main class="products-section">
            <div class="section-header">
                <div>
                    <h2 class="section-title">Sản Phẩm Nổi Bật</h2>
                    <p class="section-desc">Khám phá các sản phẩm chất lượng từ những người bán uy tín nhất</p>
                </div>
                <a href="#" style="color: var(--primary); text-decoration: none; font-weight: 600; font-size: 0.95rem;">Xem tất cả →</a>
            </div>

            <!-- Lưới sản phẩm -->
            <div class="products-grid">
                <?php foreach ($products as $prod): ?>
                    <div class="product-card">
                        <div class="product-image-container">
                            <?php if (!empty($prod['DuongDanAnh'])): ?>
                                <img src="<?php echo htmlspecialchars($prod['DuongDanAnh']); ?>" alt="Product" style="position: absolute; top:0; left:0; width:100%; height:100%; object-fit: cover;">
                            <?php else: ?>
                                <div class="product-image-fallback">
                                    📦
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">Hình ảnh chưa cập nhật</span>
                                </div>
                            <?php endif; ?>
                            <span class="product-tag"><?php echo htmlspecialchars($prod['TenDanhMuc']); ?></span>
                            <span class="product-condition"><?php echo htmlspecialchars($prod['TinhTrang']); ?></span>
                        </div>
                        
                        <div class="product-content">
                            <a href="#" class="product-title"><?php echo htmlspecialchars($prod['TenSanPham']); ?></a>
                            <div class="product-price"><?php echo number_format($prod['GiaBan'], 0, ',', '.'); ?> đ</div>
                            
                            <div class="product-footer">
                                <div class="seller-info">
                                    <?php if (!empty($prod['SellerAvatar'])): ?>
                                        <img src="<?php echo htmlspecialchars($prod['SellerAvatar']); ?>" alt="Seller" class="seller-avatar">
                                    <?php else: ?>
                                        <div class="seller-avatar" style="background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: bold; width: 24px; height: 24px; border-radius: 50%;">
                                            <?php echo strtoupper(substr($prod['TenNguoiBan'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <span class="seller-name"><?php echo htmlspecialchars($prod['TenNguoiBan']); ?></span>
                                </div>
                                <span class="seller-reputation">⭐ <?php echo htmlspecialchars($prod['DiemUyTin']); ?> Uy Tín</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>

        <!-- Footer -->
        <footer class="site-footer">
            <div class="footer-content">
                <div class="footer-brand">Chợ Đồ Cũ</div>
                <p class="footer-text">
                    Nền tảng mua bán đồ cũ trực tuyến hiện đại, kết nối thông minh và giao dịch an toàn với hệ thống điểm uy tín cao.
                </p>
                <div style="margin: 10px 0; display: flex; gap: 20px; font-size: 13px; color: var(--text-muted);">
                    <a href="db_setup.php" style="color: var(--text-muted); text-decoration: none;">Cấu hình Database</a>
                    <span>•</span>
                    <a href="login_page.php" style="color: var(--text-muted); text-decoration: none;">Đăng nhập / Đăng ký</a>
                </div>
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
