<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;
$user_roles = [];

// Kiểm tra trạng thái đăng nhập của người xem
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
        } else {
            $_SESSION = [];
            session_destroy();
        }
    } catch (Exception $e) {
        $is_logged_in = true;
        $user_data = $_SESSION['user'] ?? null;
    }
}

// Lấy ID người bán từ tham số URL
$seller_id = (int)($_GET['id'] ?? 1);

$seller_info = null;
$seller_address = null;
$seller_products = [];

try {
    $db = getDBConnection();
    
    // Truy vấn thông tin người bán
    $s_stmt = $db->prepare("SELECT * FROM NguoiDung WHERE MaNguoiDung = :id");
    $s_stmt->execute(['id' => $seller_id]);
    $seller_info = $s_stmt->fetch();
    
    if ($seller_info) {
        // Lấy địa chỉ người bán
        $addr_stmt = $db->prepare("SELECT * FROM SoDiaChi WHERE MaNguoiDung = :id ORDER BY LaDiaChiMacDinh DESC LIMIT 1");
        $addr_stmt->execute(['id' => $seller_id]);
        $seller_address = $addr_stmt->fetch();
        
        // Lấy danh sách sản phẩm người bán đăng
        $p_stmt = $db->prepare("
            SELECT sp.*, dm.TenDanhMuc 
            FROM SanPham sp
            JOIN DanhMuc dm ON sp.MaDanhMuc = dm.MaDanhMuc
            WHERE sp.MaNguoiBan = :id AND sp.TrangThaiDuyet = b'01' AND sp.TrangThaiBan = b'00'
            ORDER BY sp.NgayDang DESC
        ");
        $p_stmt->execute(['id' => $seller_id]);
        $seller_products = $p_stmt->fetchAll();
        
        foreach ($seller_products as &$sp) {
            $sp['TenNguoiBan'] = $seller_info['HoTen'];
            $sp['DiemUyTin'] = $seller_info['DiemUyTin'] ?? 100;
            $sp['SellerAvatar'] = $seller_info['google_picture'] ?? '';
            
            $img_stmt = $db->prepare("SELECT DuongDanAnh, AnhChinh FROM HinhAnhSP WHERE MaSanPham = :pid ORDER BY AnhChinh DESC, MaHinhAnh ASC");
            $img_stmt->execute(['pid' => $sp['MaSanPham']]);
            $sp['Images'] = $img_stmt->fetchAll();
            $sp['DuongDanAnh'] = !empty($sp['Images']) ? $sp['Images'][0]['DuongDanAnh'] : '';
        }
        unset($sp);
    }
} catch (Exception $e) {
    // Không kết nối được DB -> Chuyển sang dữ liệu mẫu bên dưới
}

// Nếu chưa có thông tin người bán từ DB -> Tạo dữ liệu mẫu (Mock Seller)
if (empty($seller_info)) {
    $mock_sellers = [
        1 => [
            'MaNguoiDung' => 1,
            'HoTen' => 'Nguyễn Văn A',
            'DiemUyTin' => 95,
            'HangThanhVien' => 'Kim Cương',
            'NgayTao' => '2025-01-15 08:30:00',
            'google_picture' => '',
            'DiaChi' => 'Quận 1, TP. Hồ Chí Minh'
        ],
        2 => [
            'MaNguoiDung' => 2,
            'HoTen' => 'Trần Thị B',
            'DiemUyTin' => 88,
            'HangThanhVien' => 'Vàng',
            'NgayTao' => '2025-03-20 14:10:00',
            'google_picture' => '',
            'DiaChi' => 'Quận Cầu Giấy, Hà Nội'
        ],
        3 => [
            'MaNguoiDung' => 3,
            'HoTen' => 'Phan Văn C',
            'DiemUyTin' => 92,
            'HangThanhVien' => 'Bạc',
            'NgayTao' => '2025-05-10 11:20:00',
            'google_picture' => '',
            'DiaChi' => 'Quận Hải Châu, Đà Nẵng'
        ],
        4 => [
            'MaNguoiDung' => 4,
            'HoTen' => 'Lê Thị D',
            'DiemUyTin' => 99,
            'HangThanhVien' => 'Kim Cương',
            'NgayTao' => '2024-11-05 16:45:00',
            'google_picture' => '',
            'DiaChi' => 'Quận 3, TP. Hồ Chí Minh'
        ],
        5 => [
            'MaNguoiDung' => 5,
            'HoTen' => 'Vũ Thị E',
            'DiemUyTin' => 90,
            'HangThanhVien' => 'Vàng',
            'NgayTao' => '2025-02-18 09:15:00',
            'google_picture' => '',
            'DiaChi' => 'Phường Ngọc Hà, Quận Ba Đình, Hà Nội'
        ],
        6 => [
            'MaNguoiDung' => 6,
            'HoTen' => 'Hoàng Văn F',
            'DiemUyTin' => 85,
            'HangThanhVien' => 'Đồng',
            'NgayTao' => '2025-06-01 10:00:00',
            'google_picture' => '',
            'DiaChi' => 'Quận Ninh Kiều, Cần Thơ'
        ]
    ];

    $seller_info = $mock_sellers[$seller_id] ?? [
        'MaNguoiDung' => $seller_id,
        'HoTen' => 'Người bán #' . $seller_id,
        'DiemUyTin' => 90,
        'HangThanhVien' => 'Vàng',
        'NgayTao' => '2025-01-01 00:00:00',
        'google_picture' => '',
        'DiaChi' => 'TP. Hồ Chí Minh'
    ];

    $all_mock_products = [
        [
            'MaSanPham' => 1,
            'MaNguoiBan' => 1,
            'TenSanPham' => 'iPhone 15 Pro Max 256GB Natural Titanium',
            'GiaBan' => 22500000,
            'TinhTrang' => 'Likenew 99%',
            'TenDanhMuc' => 'Điện thoại',
            'TenNguoiBan' => 'Nguyễn Văn A',
            'DiemUyTin' => 95,
            'DuongDanAnh' => 'uploads/images/iphone.png',
            'MoTaChiTiet' => 'Máy mua chính hãng VN/A, nguyên zin 100%, sạc 45 lần pin 100%. Đầy đủ hộp và cáp sạc theo máy.'
        ],
        [
            'MaSanPham' => 2,
            'MaNguoiBan' => 2,
            'TenSanPham' => 'Laptop Apple MacBook Pro 14" M2 Pro',
            'GiaBan' => 31800000,
            'TinhTrang' => 'Mới 98%',
            'TenDanhMuc' => 'Máy tính & Laptop',
            'TenNguoiBan' => 'Trần Thị B',
            'DiemUyTin' => 88,
            'DuongDanAnh' => 'uploads/images/macbook.png',
            'MoTaChiTiet' => 'Bản 16GB RAM, SSD 512GB Space Gray. Máy dùng giữ gìn không cấn móp, hiệu năng mạnh mẽ cho đồ họa và lập trình.'
        ],
        [
            'MaSanPham' => 3,
            'MaNguoiBan' => 4,
            'TenSanPham' => 'Tai nghe chống ồn Sony WH-1000XM4 Black',
            'GiaBan' => 4200000,
            'TinhTrang' => 'Fullbox - Mới 99%',
            'TenDanhMuc' => 'Thiết bị âm thanh',
            'TenNguoiBan' => 'Lê Thị D',
            'DiemUyTin' => 99,
            'DuongDanAnh' => 'uploads/images/headphone.png',
            'MoTaChiTiet' => 'Chống ồn chủ động ANC đỉnh cao, âm bass sâu trầm ấm. Ít dùng còn rất mới, tặng kèm hộp đựng chống va đập.'
        ],
        [
            'MaSanPham' => 4,
            'MaNguoiBan' => 6,
            'TenSanPham' => 'Máy chơi game PlayStation 5 Slim 1TB Disc',
            'GiaBan' => 10900000,
            'TinhTrang' => 'Mới 99%',
            'TenDanhMuc' => 'Đồ điện tử',
            'TenNguoiBan' => 'Hoàng Văn F',
            'DiemUyTin' => 85,
            'DuongDanAnh' => 'uploads/images/ps5.png',
            'MoTaChiTiet' => 'Bản ổ đĩa Slim gọn nhẹ, kèm 1 tay cầm DualSense trắng xịn đét. Đã cài sẵn một số game hot gia đình.'
        ],
        [
            'MaSanPham' => 5,
            'MaNguoiBan' => 3,
            'TenSanPham' => 'Bàn phím cơ Keychron K2 V2 Aluminum Red Switch',
            'GiaBan' => 1550000,
            'TinhTrang' => 'Hoạt động tốt',
            'TenDanhMuc' => 'Phụ kiện máy tính',
            'TenNguoiBan' => 'Phan Văn C',
            'DiemUyTin' => 92,
            'DuongDanAnh' => 'uploads/images/macbook.png',
            'MoTaChiTiet' => 'Bản khung nhôm Led RGB, switch Gateron Red gõ cực êm. Kết nối không dây Bluetooth 5.1 nhận diện 3 thiết bị.'
        ],
        [
            'MaSanPham' => 6,
            'MaNguoiBan' => 5,
            'TenSanPham' => 'Nồi chiên không dầu Philips HD9252 4.1L',
            'GiaBan' => 1200000,
            'TinhTrang' => 'Mới 95%',
            'TenDanhMuc' => 'Đồ gia dụng',
            'TenNguoiBan' => 'Vũ Thị E',
            'DiemUyTin' => 90,
            'MoTaChiTiet' => 'Hình ảnh sắc nét 4K, loa sống động, kết nối Wifi nhanh.'
        ]
    ];

    $seller_products = array_values(array_filter($all_mock_products, function($p) use ($seller_id) {
        return $p['MaNguoiBan'] == $seller_id;
    }));
    
    // Nếu seller không có sản phẩm nào trong mock, hiển thị tối thiểu 1 sản phẩm mẫu cho đẹp
    if (empty($seller_products)) {
        $seller_products = [
            [
                'MaSanPham' => 100 + $seller_id,
                'MaNguoiBan' => $seller_id,
                'TenSanPham' => 'Sản phẩm thanh lý từ ' . $seller_info['HoTen'],
                'GiaBan' => 2500000,
                'TinhTrang' => 'Mới 99%',
                'TenDanhMuc' => 'Đồ điện tử',
                'TenNguoiBan' => $seller_info['HoTen'],
                'DiemUyTin' => $seller_info['DiemUyTin'],
                'DuongDanAnh' => '',
                'MoTaChiTiet' => 'Sản phẩm chính hãng thanh lý giá rẻ.'
            ]
        ];
    }
}

$formatted_address = $seller_info['DiaChi'] ?? ($seller_address['DiaChiChiTiet'] ?? 'Chưa cập nhật địa chỉ');
$created_date = date('m/Y', strtotime($seller_info['NgayTao'] ?? 'now'));
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cửa Hàng Của <?php echo htmlspecialchars($seller_info['HoTen']); ?> - Chợ Đồ Cũ</title>
    <!-- Google Fonts Inter & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <!-- Background hiệu ứng mờ -->
    <div class="background-decor"></div>

    <div class="site-wrapper">
        <!-- Navigation Header -->
        <header class="site-header">
            <div class="nav-container">
                <a href="index.php" class="brand-logo">
                    Chợ Đồ Cũ
                </a>

                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Trang Chủ</a>
                    <a href="index.php#featured-products" class="nav-link">Sản Phẩm</a>
                    <a href="post_product.php" class="nav-link" style="color: var(--primary); font-weight: 700;">Đăng Bán</a>

                    <?php if ($is_logged_in && in_array('ADMIN', $user_roles)): ?>
                        <a href="admin.php" class="nav-link" style="color: #6366f1; font-weight: 700;">Quản Lý Admin</a>
                    <?php endif; ?>

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

                            <div class="dropdown-menu" id="userDropdownMenu">
                                <a href="profile.php" class="dropdown-item">Hồ sơ cá nhân</a>
                                <a href="post_product.php" class="dropdown-item" style="color: var(--primary);">Đăng bán sản phẩm</a>
                                <div class="dropdown-divider"></div>
                                 <a href="javascript:void(0)" onclick="const f = document.createElement('form'); f.method = 'POST'; f.action = 'logout.php'; const i = document.createElement('input'); i.type = 'hidden'; i.name = 'csrf_token'; i.value = '<?php echo getCsrfToken(); ?>'; f.appendChild(i); document.body.appendChild(f); f.submit();" class="dropdown-item" style="color: var(--error)">Đăng xuất</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="login_page.php" class="btn btn-primary" style="padding: 8px 18px; font-size: 0.9rem; border-radius: 50px;">Đăng Nhập</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <!-- Seller Store Profile Banner Section (Shopee Store Style) -->
        <div style="max-width: 1200px; margin: 30px auto 0; padding: 0 24px; width: 100%;">
            <div class="seller-store-card">
                <!-- Cột bên trái: Avatar & Nút Chat/Follow -->
                <div class="seller-store-left">
                    <div class="seller-avatar-wrapper">
                        <?php if (!empty($seller_info['google_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($seller_info['google_picture']); ?>" alt="Avatar" class="seller-store-avatar">
                        <?php else: ?>
                            <div class="seller-store-avatar-fallback">
                                <?php echo strtoupper(substr($seller_info['HoTen'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <span class="seller-badge-preferred"><?php echo htmlspecialchars($seller_info['HangThanhVien'] ?? 'Vàng'); ?></span>
                    </div>

                    <div class="seller-store-main-info">
                        <h1 class="seller-store-name"><?php echo htmlspecialchars($seller_info['HoTen']); ?></h1>
                        <div class="seller-store-status">🟢 Đang hoạt động</div>

                        <div class="seller-store-actions">
                            <button onclick="alert('Tính năng Theo dõi người bán đang cập nhật!')" class="btn btn-outline store-action-btn">
                                ➕ Theo Dõi
                            </button>
                            <button onclick="alert('Tính năng Chat trực tiếp đang mở rộng!')" class="btn btn-primary store-action-btn">
                                💬 Trò Chuyện
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Cột bên phải: Chỉ số thông tin người bán -->
                <div class="seller-store-right">
                    <div class="store-stat-item">
                        <span class="stat-icon">📦</span>
                        <div>
                            <div class="stat-label">Sản phẩm đăng bán:</div>
                            <div class="stat-value"><?php echo count($seller_products); ?> sản phẩm</div>
                        </div>
                    </div>

                    <div class="store-stat-item">
                        <span class="stat-icon">⭐</span>
                        <div>
                            <div class="stat-label">Điểm Uy Tín:</div>
                            <div class="stat-value" style="color: #d97706;"><?php echo htmlspecialchars($seller_info['DiemUyTin'] ?? 100); ?> / 100</div>
                        </div>
                    </div>

                    <div class="store-stat-item">
                        <span class="stat-icon">📍</span>
                        <div>
                            <div class="stat-label">Khu vực / Địa chỉ:</div>
                            <div class="stat-value"><?php echo htmlspecialchars($formatted_address); ?></div>
                        </div>
                    </div>

                    <div class="store-stat-item">
                        <span class="stat-icon">📅</span>
                        <div>
                            <div class="stat-label">Thành viên từ:</div>
                            <div class="stat-value">Tháng <?php echo $created_date; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Điều Hướng Sản Phẩm -->
            <div class="store-tabs-wrapper">
                <button class="store-tab-btn active">Dành Cho Bạn (<?php echo count($seller_products); ?>)</button>
                <button class="store-tab-btn" onclick="alert('Đang tải danh sách đầy đủ...')">Tất Cả Sản Phẩm</button>
                <button class="store-tab-btn" onclick="alert('Tính năng đánh giá uy tín người bán...')">Đánh Giá & Nhận Xét</button>
            </div>
        </div>

        <!-- Danh sách Sản phẩm của Người Bán -->
        <main class="products-section" style="padding-top: 20px;">
            <div class="section-header">
                <div>
                    <h2 class="section-title">Sản Phẩm Đang Đăng Bán</h2>
                    <p class="section-desc">Các sản phẩm cũ thanh lý chất lượng do người bán **<?php echo htmlspecialchars($seller_info['HoTen']); ?>** đăng tải</p>
                </div>
            </div>

            <?php if (empty($seller_products)): ?>
                <div class="empty-products-state">
                    <div style="font-size: 3rem; margin-bottom: 12px;">📦</div>
                    <h3 style="font-size: 1.2rem; margin-bottom: 8px; color: var(--text-main);">Người bán chưa đăng sản phẩm nào</h3>
                    <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 0.9rem;">Hiện tại gian hàng này chưa có sản phẩm sẵn sàng giao dịch.</p>
                    <a href="index.php" class="btn btn-primary" style="border-radius: 50px;">Quay lại Trang Chủ</a>
                </div>
            <?php else: ?>
                <div class="products-grid">
                    <?php foreach ($seller_products as $prod): ?>
                        <?php 
                            $img_list_json = htmlspecialchars(json_encode($prod['Images'] ?? []), ENT_QUOTES, 'UTF-8');
                            $vid_path = htmlspecialchars($prod['VideoThucTe'] ?? '', ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="product-card" onclick="openProductModal('<?php echo addslashes(htmlspecialchars($prod['TenSanPham'])); ?>', '<?php echo number_format($prod['GiaBan'], 0, ',', '.'); ?> đ', '<?php echo addslashes(htmlspecialchars($prod['TenDanhMuc'])); ?>', '<?php echo addslashes(htmlspecialchars($prod['TinhTrang'])); ?>', '<?php echo addslashes(htmlspecialchars($prod['TenNguoiBan'])); ?>', '<?php echo $prod['DiemUyTin']; ?>', '<?php echo addslashes(htmlspecialchars($prod['MoTaChiTiet'] ?? 'Chưa có mô tả')); ?>', '<?php echo $img_list_json; ?>', '<?php echo $vid_path; ?>', '<?php echo $seller_id; ?>', '<?php echo addslashes(htmlspecialchars($prod['DuongDanAnh'] ?? '')); ?>')" style="cursor: pointer;">
                            <div class="product-image-container">
                                <?php if (!empty($prod['DuongDanAnh'])): ?>
                                    <img src="<?php echo htmlspecialchars($prod['DuongDanAnh']); ?>" alt="Product" style="position: absolute; top:0; left:0; width:100%; height:100%; object-fit: cover;">
                                <?php else: ?>
                                    <div class="product-image-fallback">
                                        <span style="font-size: 0.8rem; color: var(--text-muted);">Hình ảnh chưa cập nhật</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($prod['Images']) && count($prod['Images']) > 1): ?>
                                    <span style="position: absolute; bottom: 8px; right: 8px; background: rgba(15, 23, 42, 0.75); color: #fff; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 20px; backdrop-filter: blur(4px);">
                                        <?php echo count($prod['Images']); ?> ảnh
                                    </span>
                                <?php endif; ?>

                                <span class="product-tag"><?php echo htmlspecialchars($prod['TenDanhMuc']); ?></span>
                                <span class="product-condition"><?php echo htmlspecialchars($prod['TinhTrang']); ?></span>
                            </div>

                            <div class="product-content">
                                <a href="javascript:void(0)" class="product-title"><?php echo htmlspecialchars($prod['TenSanPham']); ?></a>
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
                                    <span class="seller-reputation"><?php echo htmlspecialchars($prod['DiemUyTin']); ?> Uy Tín</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>

        <!-- Modal Xem Chi Tiết Sản Phẩm -->
        <div id="productDetailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.6); backdrop-filter: blur(6px); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; border-radius: 24px; padding: 30px; box-shadow: 0 25px 50px rgba(0,0,0,0.25); position: relative;">
                <button onclick="closeProductModal()" style="position: absolute; top: 20px; right: 20px; background: #f1f5f9; border: none; font-size: 1.2rem; cursor: pointer; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">✕</button>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div>
                        <div id="modal_large_media_box" style="width: 100%; aspect-ratio: 16 / 9; border-radius: 16px; overflow: hidden; background: #000000; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; position: relative; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                            <img id="modal_large_img" src="" alt="Large" style="width: 100%; height: 100%; object-fit: contain;">
                            <video id="modal_large_video" controls style="width: 100%; height: 100%; display: none; background: #000000; object-fit: contain;"></video>
                        </div>
                        <div id="modal_thumb_strip" style="display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px;"></div>
                    </div>

                    <div>
                        <span id="modal_cat" class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; margin-bottom: 8px; display: inline-block;"></span>
                        <h2 id="modal_title" style="font-size: 1.5rem; color: var(--text-main); margin-bottom: 10px;"></h2>
                        <div id="modal_price" style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-bottom: 16px;"></div>

                        <div style="background: rgba(248, 250, 252, 0.8); border: 1px solid rgba(226, 232, 240, 0.8); border-radius: 12px; padding: 14px; margin-bottom: 16px;">
                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px;">Tình trạng: <b id="modal_cond" style="color: var(--text-main);"></b></div>
                            <div style="font-size: 0.85rem; color: var(--text-muted);">
                                Người bán: <a href="#" id="modal_seller_link" style="color: var(--primary); font-weight: 700; text-decoration: underline;"><span id="modal_seller"></span></a>
                                (<span id="modal_rep" style="color: #d97706; font-weight: 700;"></span> Uy Tín)
                            </div>
                        </div>

                        <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 8px;">Mô tả sản phẩm:</h4>
                        <div id="modal_desc" style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.6; white-space: pre-line; max-height: 180px; overflow-y: auto;"></div>

                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button onclick="alert('Tính năng liên hệ người mua/đặt hàng đang mở rộng!')" class="btn btn-primary" style="border-radius: 50px; flex: 1;">Liên Hệ Người Bán</button>
                            <a href="#" id="modal_store_btn" class="btn btn-outline" style="border-radius: 50px; text-decoration: none; text-align: center; display: inline-flex; align-items: center; justify-content: center;">Xem Cửa Hàng</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="site-footer">
            <div class="footer-content">
                <div class="footer-brand">Chợ Đồ Cũ</div>
                <p class="footer-text">Nền tảng mua bán đồ cũ trực tuyến hiện đại, kết nối thông minh và giao dịch an toàn với hệ thống điểm uy tín cao.</p>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 10px;">
                    &copy; 2026 Chợ Đồ Cũ Inc. Bảo lưu mọi quyền.
                </div>
            </div>
        </footer>
    </div>

    <!-- Script dropdown & modal -->
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

        function openProductModal(title, price, cat, cond, seller, rep, desc, imagesJson, videoPath, sellerId, mainImg) {
            document.getElementById('modal_title').textContent = title;
            document.getElementById('modal_price').textContent = price;
            document.getElementById('modal_cat').textContent = cat;
            document.getElementById('modal_cond').textContent = cond;
            document.getElementById('modal_seller').textContent = seller;
            document.getElementById('modal_rep').textContent = rep;
            document.getElementById('modal_desc').textContent = desc;

            const sellerLink = document.getElementById('modal_seller_link');
            const storeBtn = document.getElementById('modal_store_btn');
            if (sellerId) {
                sellerLink.href = 'seller.php?id=' + sellerId;
                storeBtn.href = 'seller.php?id=' + sellerId;
            }

            const largeImg = document.getElementById('modal_large_img');
            const largeVideo = document.getElementById('modal_large_video');
            const thumbStrip = document.getElementById('modal_thumb_strip');

            thumbStrip.innerHTML = '';
            largeVideo.style.display = 'none';
            largeVideo.pause();

            let images = [];
            try { images = JSON.parse(imagesJson); } catch(e) {}

            let primaryImgSrc = '';
            if (images.length > 0 && images[0].DuongDanAnh) {
                primaryImgSrc = images[0].DuongDanAnh;
            } else if (mainImg) {
                primaryImgSrc = mainImg;
            }

            if (primaryImgSrc) {
                largeImg.style.display = 'block';
                largeImg.src = primaryImgSrc;
            } else {
                largeImg.style.display = 'none';
            }

            if (images.length > 0) {
                images.forEach((imgObj, i) => {
                    const thumb = document.createElement('img');
                    thumb.src = imgObj.DuongDanAnh;
                    thumb.style.cssText = 'width: 54px; height: 54px; object-fit: cover; border-radius: 8px; cursor: pointer; border: ' + (i === 0 ? '2px solid #0284c7' : '1px solid #cbd5e1') + '; opacity: ' + (i === 0 ? '1' : '0.7') + '; transition: all 0.2s;';
                    thumb.onclick = function() {
                        largeVideo.style.display = 'none';
                        largeVideo.pause();
                        largeImg.style.display = 'block';
                        largeImg.src = imgObj.DuongDanAnh;
                        Array.from(thumbStrip.children).forEach(t => {
                            if (t.tagName === 'IMG') {
                                t.style.border = '1px solid #cbd5e1';
                                t.style.opacity = '0.7';
                            }
                        });
                        thumb.style.border = '2px solid #0284c7';
                        thumb.style.opacity = '1';
                    };
                    thumbStrip.appendChild(thumb);
                });
            } else if (primaryImgSrc) {
                const thumb = document.createElement('img');
                thumb.src = primaryImgSrc;
                thumb.style.cssText = 'width: 54px; height: 54px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid #0284c7; opacity: 1; transition: all 0.2s;';
                thumb.onclick = function() {
                    largeVideo.style.display = 'none';
                    largeVideo.pause();
                    largeImg.style.display = 'block';
                    largeImg.src = primaryImgSrc;
                };
                thumbStrip.appendChild(thumb);
            }

            if (videoPath) {
                const vidBtn = document.createElement('div');
                vidBtn.style.cssText = 'width: 54px; height: 54px; border-radius: 8px; cursor: pointer; border: 1px solid #cbd5e1; background: #0f172a; color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; opacity: 0.85;';
                vidBtn.textContent = 'VIDEO';
                vidBtn.onclick = function() {
                    largeImg.style.display = 'none';
                    largeVideo.style.display = 'block';
                    largeVideo.src = videoPath;
                    largeVideo.play();
                    Array.from(thumbStrip.children).forEach(t => {
                        t.style.border = '1px solid #cbd5e1';
                        t.style.opacity = '0.7';
                    });
                    vidBtn.style.border = '2px solid #0284c7';
                    vidBtn.style.opacity = '1';
                };
                thumbStrip.appendChild(vidBtn);
            }

            document.getElementById('productDetailModal').style.display = 'flex';
        }

        function closeProductModal() {
            const largeVideo = document.getElementById('modal_large_video');
            if (largeVideo) {
                largeVideo.pause();
                largeVideo.src = '';
            }
            document.getElementById('productDetailModal').style.display = 'none';
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
    </script>
</body>

</html>
