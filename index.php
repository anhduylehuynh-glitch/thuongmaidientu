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

// Lấy danh mục sản phẩm động từ CSDL
$categories = [];
try {
    $db = getDBConnection();
    $cat_stmt = $db->query("SELECT * FROM DanhMuc ORDER BY TenDanhMuc ASC");
    $categories = $cat_stmt->fetchAll();
} catch (Exception $e) {
    // DB error, will populate fallback categories below
}

if (empty($categories)) {
    $categories = [
        ['MaDanhMuc' => 1, 'TenDanhMuc' => 'Điện thoại'],
        ['MaDanhMuc' => 2, 'TenDanhMuc' => 'Máy tính & Laptop'],
        ['MaDanhMuc' => 3, 'TenDanhMuc' => 'Phụ kiện máy tính'],
        ['MaDanhMuc' => 4, 'TenDanhMuc' => 'Thiết bị âm thanh'],
        ['MaDanhMuc' => 5, 'TenDanhMuc' => 'Đồ gia dụng'],
        ['MaDanhMuc' => 6, 'TenDanhMuc' => 'Đồ điện tử'],
    ];
}

// Xử lý tham số tìm kiếm, lọc danh mục và sắp xếp
$keyword = trim($_GET['keyword'] ?? '');
$category = trim($_GET['category'] ?? '');
$sort = trim($_GET['sort'] ?? 'newest');

$products = [];
try {
    $db = getDBConnection();
    
    $sql = "
        SELECT sp.*, nd.HoTen as TenNguoiBan, nd.DiemUyTin, nd.google_picture as SellerAvatar, dm.TenDanhMuc
        FROM SanPham sp
        JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
        JOIN DanhMuc dm ON sp.MaDanhMuc = dm.MaDanhMuc
        WHERE sp.TrangThaiDuyet = b'01' AND sp.TrangThaiBan = b'00'
    ";
    
    $params = [];
    
    if (!empty($keyword)) {
        $sql .= " AND (sp.TenSanPham LIKE :kw OR sp.MoTaChiTiet LIKE :kw OR dm.TenDanhMuc LIKE :kw)";
        $params['kw'] = '%' . $keyword . '%';
    }
    
    if (!empty($category)) {
        if (is_numeric($category)) {
            $sql .= " AND sp.MaDanhMuc = :cat_id";
            $params['cat_id'] = (int)$category;
        } else {
            $sql .= " AND dm.TenDanhMuc = :cat_name";
            $params['cat_name'] = $category;
        }
    }
    
    switch ($sort) {
        case 'oldest':
            $sql .= " ORDER BY sp.NgayDang ASC";
            break;
        case 'price_asc':
            $sql .= " ORDER BY sp.GiaBan ASC";
            break;
        case 'price_desc':
            $sql .= " ORDER BY sp.GiaBan DESC";
            break;
        case 'newest':
        default:
            $sql .= " ORDER BY sp.NgayDang DESC";
            break;
    }
    
    $sql .= " LIMIT 24";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    foreach ($products as &$p) {
        $img_stmt = $db->prepare("SELECT DuongDanAnh, AnhChinh FROM HinhAnhSP WHERE MaSanPham = :pid ORDER BY AnhChinh DESC, MaHinhAnh ASC");
        $img_stmt->execute(['pid' => $p['MaSanPham']]);
        $p['Images'] = $img_stmt->fetchAll();
        $p['DuongDanAnh'] = !empty($p['Images']) ? $p['Images'][0]['DuongDanAnh'] : '';
    }
    unset($p);
} catch (Exception $e) {
    // Không có DB hoặc bảng trống -> Sử dụng sản phẩm mẫu bên dưới
}

// Nếu không có sản phẩm nào từ DB thì tạo danh sách sản phẩm mẫu (Mock Products)
if (empty($products) && empty($keyword) && empty($category)) {
    $products = [
        [
            'MaSanPham' => 1,
            'MaNguoiBan' => 1,
            'TenSanPham' => 'iPhone 15 Pro Max 256GB Natural Titanium',
            'GiaBan' => 22500000,
            'TinhTrang' => 'Likenew 99%',
            'TenDanhMuc' => 'Điện thoại',
            'MaDanhMuc' => 1,
            'TenNguoiBan' => 'Nguyễn Văn A',
            'DiemUyTin' => 95,
            'DuongDanAnh' => 'uploads/images/iphone.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-23 09:30:00',
            'MoTaChiTiet' => 'Máy mua chính hãng VN/A, nguyên zin 100%, sạc 45 lần pin 100%. Đầy đủ hộp và cáp sạc theo máy.'
        ],
        [
            'MaSanPham' => 2,
            'MaNguoiBan' => 2,
            'TenSanPham' => 'Laptop Apple MacBook Pro 14" M2 Pro',
            'GiaBan' => 31800000,
            'TinhTrang' => 'Mới 98%',
            'TenDanhMuc' => 'Máy tính & Laptop',
            'MaDanhMuc' => 2,
            'TenNguoiBan' => 'Trần Thị B',
            'DiemUyTin' => 88,
            'DuongDanAnh' => 'uploads/images/macbook.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-22 14:15:00',
            'MoTaChiTiet' => 'Bản 16GB RAM, SSD 512GB Space Gray. Máy dùng giữ gìn không cấn móp, hiệu năng mạnh mẽ cho đồ họa và lập trình.'
        ],
        [
            'MaSanPham' => 3,
            'MaNguoiBan' => 4,
            'TenSanPham' => 'Tai nghe chống ồn Sony WH-1000XM4 Black',
            'GiaBan' => 4200000,
            'TinhTrang' => 'Fullbox - Mới 99%',
            'TenDanhMuc' => 'Thiết bị âm thanh',
            'MaDanhMuc' => 4,
            'TenNguoiBan' => 'Lê Thị D',
            'DiemUyTin' => 99,
            'DuongDanAnh' => 'uploads/demo/headphone.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-21 16:45:00',
            'MoTaChiTiet' => 'Chống ồn chủ động ANC đỉnh cao, âm bass sâu trầm ấm. Ít dùng còn rất mới, tặng kèm hộp đựng chống va đập.'
        ],
        [
            'MaSanPham' => 4,
            'MaNguoiBan' => 6,
            'TenSanPham' => 'Máy chơi game PlayStation 5 Slim 1TB Disc',
            'GiaBan' => 10900000,
            'TinhTrang' => 'Mới 99%',
            'TenDanhMuc' => 'Đồ điện tử',
            'MaDanhMuc' => 6,
            'TenNguoiBan' => 'Hoàng Văn F',
            'DiemUyTin' => 85,
            'DuongDanAnh' => 'uploads/demo/ps5.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-23 08:10:00',
            'MoTaChiTiet' => 'Bản ổ đĩa Slim gọn nhẹ, kèm 1 tay cầm DualSense trắng xịn đét. Đã cài sẵn một số game hot gia đình.'
        ],
        [
            'MaSanPham' => 5,
            'MaNguoiBan' => 3,
            'TenSanPham' => 'Bàn phím cơ Keychron K2 V2 Aluminum Red Switch',
            'GiaBan' => 1550000,
            'TinhTrang' => 'Hoạt động tốt',
            'TenDanhMuc' => 'Phụ kiện máy tính',
            'MaDanhMuc' => 3,
            'TenNguoiBan' => 'Phan Văn C',
            'DiemUyTin' => 92,
            'DuongDanAnh' => 'uploads/images/macbook.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-19 09:15:00',
            'MoTaChiTiet' => 'Bản khung nhôm Led RGB, switch Gateron Red gõ cực êm. Kết nối không dây Bluetooth 5.1 nhận diện 3 thiết bị.'
        ],
        [
            'MaSanPham' => 6,
            'MaNguoiBan' => 5,
            'TenSanPham' => 'Nồi chiên không dầu Philips HD9252 4.1L',
            'GiaBan' => 1200000,
            'TinhTrang' => 'Mới 95%',
            'TenDanhMuc' => 'Đồ gia dụng',
            'MaDanhMuc' => 5,
            'TenNguoiBan' => 'Vũ Thị E',
            'DiemUyTin' => 90,
            'DuongDanAnh' => 'uploads/images/ps5.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-20 11:20:00',
            'MoTaChiTiet' => 'Công nghệ Rapid Air giảm 90% lượng mỡ thừa, lòng nồi chống dính cao cấp dễ vệ sinh.'
        ],
        [
            'MaSanPham' => 7,
            'MaNguoiBan' => 1,
            'TenSanPham' => 'Đồng hồ Apple Watch Series 8 45mm GPS',
            'GiaBan' => 5600000,
            'TinhTrang' => 'Mới 97%',
            'TenDanhMuc' => 'Đồng hồ & Phụ kiện',
            'MaDanhMuc' => 1,
            'TenNguoiBan' => 'Nguyễn Văn A',
            'DiemUyTin' => 95,
            'DuongDanAnh' => 'uploads/images/iphone.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-18 10:00:00',
            'MoTaChiTiet' => 'Bản nhôm đen Midnight, theo dõi nhịp tim & giấc ngủ chuẩn xác. Dây thể thao kèm dây sạc từ tính zin.'
        ],
        [
            'MaSanPham' => 8,
            'MaNguoiBan' => 2,
            'TenSanPham' => 'Máy ảnh Mirrorless Sony Alpha A6400 + Lens Kit',
            'GiaBan' => 14200000,
            'TinhTrang' => 'Likenew 99%',
            'TenDanhMuc' => 'Máy tính & Laptop',
            'MaDanhMuc' => 2,
            'TenNguoiBan' => 'Trần Thị B',
            'DiemUyTin' => 88,
            'DuongDanAnh' => 'uploads/images/macbook.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-17 15:30:00',
            'MoTaChiTiet' => 'Chụp khoảng 3.000 shot, lấy nét tự động siêu nhanh. Phụ kiện gồm 2 pin, sạc đôi, thẻ nhớ 64GB Speed.'
        ]
    ];
} elseif (empty($products) && (!empty($keyword) || !empty($category))) {
    // Nếu lọc trên sản phẩm mẫu
    $mock_all = [
        [
            'MaSanPham' => 1,
            'MaNguoiBan' => 1,
            'TenSanPham' => 'iPhone 15 Pro Max 256GB Natural Titanium',
            'GiaBan' => 22500000,
            'TinhTrang' => 'Likenew 99%',
            'TenDanhMuc' => 'Điện thoại',
            'MaDanhMuc' => 1,
            'TenNguoiBan' => 'Nguyễn Văn A',
            'DiemUyTin' => 95,
            'DuongDanAnh' => 'uploads/images/iphone.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-23 09:30:00',
            'MoTaChiTiet' => 'Máy mua chính hãng VN/A, nguyên zin 100%, sạc 45 lần pin 100%. Đầy đủ hộp và cáp sạc theo máy.'
        ],
        [
            'MaSanPham' => 2,
            'MaNguoiBan' => 2,
            'TenSanPham' => 'Laptop Apple MacBook Pro 14" M2 Pro',
            'GiaBan' => 31800000,
            'TinhTrang' => 'Mới 98%',
            'TenDanhMuc' => 'Máy tính & Laptop',
            'MaDanhMuc' => 2,
            'TenNguoiBan' => 'Trần Thị B',
            'DiemUyTin' => 88,
            'DuongDanAnh' => 'uploads/images/macbook.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-22 14:15:00',
            'MoTaChiTiet' => 'Bản 16GB RAM, SSD 512GB Space Gray. Máy dùng giữ gìn không cấn móp, hiệu năng mạnh mẽ cho đồ họa và lập trình.'
        ],
        [
            'MaSanPham' => 3,
            'MaNguoiBan' => 4,
            'TenSanPham' => 'Tai nghe chống ồn Sony WH-1000XM4 Black',
            'GiaBan' => 4200000,
            'TinhTrang' => 'Fullbox - Mới 99%',
            'TenDanhMuc' => 'Thiết bị âm thanh',
            'MaDanhMuc' => 4,
            'TenNguoiBan' => 'Lê Thị D',
            'DiemUyTin' => 99,
            'DuongDanAnh' => 'uploads/images/headphone.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-21 16:45:00',
            'MoTaChiTiet' => 'Chống ồn chủ động ANC đỉnh cao, âm bass sâu trầm ấm. Ít dùng còn rất mới, tặng kèm hộp đựng chống va đập.'
        ],
        [
            'MaSanPham' => 4,
            'MaNguoiBan' => 6,
            'TenSanPham' => 'Máy chơi game PlayStation 5 Slim 1TB Disc',
            'GiaBan' => 10900000,
            'TinhTrang' => 'Mới 99%',
            'TenDanhMuc' => 'Đồ điện tử',
            'MaDanhMuc' => 6,
            'TenNguoiBan' => 'Hoàng Văn F',
            'DiemUyTin' => 85,
            'DuongDanAnh' => 'uploads/images/ps5.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-23 08:10:00',
            'MoTaChiTiet' => 'Bản ổ đĩa Slim gọn nhẹ, kèm 1 tay cầm DualSense trắng xịn đét. Đã cài sẵn một số game hot gia đình.'
        ],
        [
            'MaSanPham' => 5,
            'MaNguoiBan' => 3,
            'TenSanPham' => 'Bàn phím cơ Keychron K2 V2 Aluminum Red Switch',
            'GiaBan' => 1550000,
            'TinhTrang' => 'Hoạt động tốt',
            'TenDanhMuc' => 'Phụ kiện máy tính',
            'MaDanhMuc' => 3,
            'TenNguoiBan' => 'Phan Văn C',
            'DiemUyTin' => 92,
            'DuongDanAnh' => 'uploads/images/macbook.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-19 09:15:00',
            'MoTaChiTiet' => 'Bản khung nhôm Led RGB, switch Gateron Red gõ cực êm. Kết nối không dây Bluetooth 5.1 nhận diện 3 thiết bị.'
        ],
        [
            'MaSanPham' => 6,
            'MaNguoiBan' => 5,
            'TenSanPham' => 'Nồi chiên không dầu Philips HD9252 4.1L',
            'GiaBan' => 1200000,
            'TinhTrang' => 'Mới 95%',
            'TenDanhMuc' => 'Đồ gia dụng',
            'MaDanhMuc' => 5,
            'TenNguoiBan' => 'Vũ Thị E',
            'DiemUyTin' => 90,
            'DuongDanAnh' => 'uploads/images/ps5.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-20 11:20:00',
            'MoTaChiTiet' => 'Công nghệ Rapid Air giảm 90% lượng mỡ thừa, lòng nồi chống dính cao cấp dễ vệ sinh.'
        ],
        [
            'MaSanPham' => 7,
            'MaNguoiBan' => 1,
            'TenSanPham' => 'Đồng hồ Apple Watch Series 8 45mm GPS',
            'GiaBan' => 5600000,
            'TinhTrang' => 'Mới 97%',
            'TenDanhMuc' => 'Đồng hồ & Phụ kiện',
            'MaDanhMuc' => 1,
            'TenNguoiBan' => 'Nguyễn Văn A',
            'DiemUyTin' => 95,
            'DuongDanAnh' => 'uploads/demo/iphone.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-18 10:00:00',
            'MoTaChiTiet' => 'Bản nhôm đen Midnight, theo dõi nhịp tim & giấc ngủ chuẩn xác. Dây thể thao kèm dây sạc từ tính zin.'
        ],
        [
            'MaSanPham' => 8,
            'MaNguoiBan' => 2,
            'TenSanPham' => 'Máy ảnh Mirrorless Sony Alpha A6400 + Lens Kit',
            'GiaBan' => 14200000,
            'TinhTrang' => 'Likenew 99%',
            'TenDanhMuc' => 'Máy tính & Laptop',
            'MaDanhMuc' => 2,
            'TenNguoiBan' => 'Trần Thị B',
            'DiemUyTin' => 88,
            'DuongDanAnh' => 'uploads/demo/macbook.png',
            'SellerAvatar' => '',
            'NgayDang' => '2026-07-17 15:30:00',
            'MoTaChiTiet' => 'Chụp khoảng 3.000 shot, lấy nét tự động siêu nhanh. Phụ kiện gồm 2 pin, sạc đôi, thẻ nhớ 64GB Speed.'
        ]
    ];
    
    $products = array_values(array_filter($mock_all, function($p) use ($keyword, $category) {
        $match_kw = true;
        if (!empty($keyword)) {
            $kw_lower = mb_strtolower($keyword, 'UTF-8');
            $title_lower = mb_strtolower($p['TenSanPham'], 'UTF-8');
            $cat_lower = mb_strtolower($p['TenDanhMuc'], 'UTF-8');
            $match_kw = (mb_strpos($title_lower, $kw_lower) !== false) || (mb_strpos($cat_lower, $kw_lower) !== false);
        }
        
        $match_cat = true;
        if (!empty($category)) {
            if (is_numeric($category)) {
                $match_cat = ($p['MaDanhMuc'] == $category);
            } else {
                $match_cat = (mb_strtolower($p['TenDanhMuc'], 'UTF-8') === mb_strtolower($category, 'UTF-8'));
            }
        }
        
        return $match_kw && $match_cat;
    }));
    
    usort($products, function($a, $b) use ($sort) {
        if ($sort === 'price_asc') {
            return $a['GiaBan'] <=> $b['GiaBan'];
        } elseif ($sort === 'price_desc') {
            return $b['GiaBan'] <=> $a['GiaBan'];
        } elseif ($sort === 'oldest') {
            return strtotime($a['NgayDang']) <=> strtotime($b['NgayDang']);
        } else {
            return strtotime($b['NgayDang']) <=> strtotime($a['NgayDang']);
        }
    });
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chợ Đồ Cũ - Nền Tảng Thương Mại Điện Tử Đồ Cũ Uy Tín</title>
    <!-- Google Fonts Inter & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
                    Chợ Đồ Cũ
                </a>

                <nav class="nav-menu">
                    <a href="index.php" class="nav-link active">Trang Chủ</a>
                    <a href="#" class="nav-link">Sản Phẩm</a>
                    <a href="post_product.php" class="nav-link" style="color: var(--primary); font-weight: 700;">Đăng Bán</a>

                    <?php if ($is_logged_in && in_array('ADMIN', $user_roles)): ?>
                        <a href="admin.php" class="nav-link" style="color: #6366f1; font-weight: 700;">Quản Lý Admin</a>
                    <?php endif; ?>

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
                                    Hồ sơ cá nhân
                                </a>
                                <a href="post_product.php" class="dropdown-item" style="color: var(--primary);">
                                    Đăng bán sản phẩm
                                </a>
                                <?php if (in_array('ADMIN', $user_roles)): ?>
                                    <a href="admin.php" class="dropdown-item" style="color: #6366f1; font-weight: 600;">
                                        Trang Quản Lý Admin
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a href="logout.php" class="dropdown-item" style="color: var(--error)">
                                    Đăng xuất
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
                        <a href="#featured-products" class="btn btn-primary" style="border-radius: 50px;">Khám phá ngay</a>
                        <a href="post_product.php" class="btn btn-outline" style="border-radius: 50px;">Đăng bán đồ cũ</a>
                    </div>
                </div>
                <div class="hero-img-wrapper">
                    <div class="hero-badge-container">
                        <div class="hero-stat-value">50,000+</div>
                        <div class="hero-stat-label">Sản phẩm chất lượng</div>
                        <div style="margin: 20px 0; border-top: 1px solid rgba(0, 0, 0, 0.06);"></div>
                        <div class="hero-stat-value">99%</div>
                        <div class="hero-stat-label">Khách hàng hài lòng</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Products Section -->
        <main class="products-section" id="featured-products">
            <div class="section-header">
                <div>
                    <h2 class="section-title">Sản Phẩm Nổi Bật</h2>
                    <p class="section-desc">Khám phá các sản phẩm chất lượng từ những người bán uy tín nhất</p>
                </div>
                <a href="index.php#featured-products" style="color: var(--primary); text-decoration: none; font-weight: 600; font-size: 0.95rem;">Xem tất cả</a>
            </div>

            <!-- Thanh Tìm Kiếm & Bộ Lọc Sản Phẩm -->
            <div class="filter-wrapper">
                <form method="GET" action="index.php#featured-products" class="filter-form">
                    <!-- Ô Tìm kiếm từ khóa -->
                    <div class="filter-group filter-search-box">
                        <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" name="keyword" class="filter-input" placeholder="Tìm tên sản phẩm, từ khóa..." value="<?php echo htmlspecialchars($keyword); ?>">
                        <?php if (!empty($keyword)): ?>
                            <a href="index.php?category=<?php echo urlencode($category); ?>&sort=<?php echo urlencode($sort); ?>#featured-products" class="clear-search-btn" title="Xóa từ khóa">&times;</a>
                        <?php endif; ?>
                    </div>

                    <!-- Ô Chọn Danh mục (Loại hàng) -->
                    <div class="filter-group">
                        <select name="category" class="filter-select" onchange="this.form.submit()">
                            <option value="">-- Tất cả loại hàng --</option>
                            <?php foreach ($categories as $cat): ?>
                                <?php 
                                    $cat_val = $cat['MaDanhMuc'] ?? $cat['TenDanhMuc'];
                                    $selected = ($category == $cat_val || $category == $cat['TenDanhMuc']) ? 'selected' : '';
                                ?>
                                <option value="<?php echo htmlspecialchars($cat_val); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($cat['TenDanhMuc']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Ô Chọn Sắp xếp -->
                    <div class="filter-group">
                        <select name="sort" class="filter-select" onchange="this.form.submit()">
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>🕒 Ngày đăng: Mới nhất</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>⏳ Ngày đăng: Cũ nhất</option>
                            <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>💲 Giá bán: Thấp đến Cao</option>
                            <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>💲 Giá bán: Cao đến Thấp</option>
                        </select>
                    </div>

                    <!-- Nút Thao tác -->
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary filter-btn">
                            Tìm kiếm
                        </button>
                        <?php if (!empty($keyword) || !empty($category) || $sort !== 'newest'): ?>
                            <a href="index.php#featured-products" class="btn btn-outline reset-btn">
                                Xóa lọc
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Thống kê & Tag đang lọc -->
                <?php if (!empty($keyword) || !empty($category) || $sort !== 'newest'): ?>
                    <div class="filter-summary">
                        <span>Đang lọc:</span>
                        <?php if (!empty($keyword)): ?>
                            <span class="filter-badge">Từ khóa: "<b><?php echo htmlspecialchars($keyword); ?></b>"</span>
                        <?php endif; ?>
                        <?php if (!empty($category)): ?>
                            <?php
                                $cat_display = $category;
                                foreach ($categories as $c) {
                                    if (($c['MaDanhMuc'] ?? '') == $category || $c['TenDanhMuc'] == $category) {
                                        $cat_display = $c['TenDanhMuc'];
                                        break;
                                    }
                                }
                            ?>
                            <span class="filter-badge">Loại hàng: <b><?php echo htmlspecialchars($cat_display); ?></b></span>
                        <?php endif; ?>
                        <?php if ($sort !== 'newest'): ?>
                            <span class="filter-badge">Sắp xếp: <b>
                                <?php 
                                    if ($sort === 'price_asc') echo 'Giá tăng dần';
                                    elseif ($sort === 'price_desc') echo 'Giá giảm dần';
                                    elseif ($sort === 'oldest') echo 'Ngày đăng cũ nhất';
                                ?>
                            </b></span>
                        <?php endif; ?>
                        <span class="filter-count">(Tìm thấy <?php echo count($products); ?> sản phẩm)</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Trường hợp không tìm thấy sản phẩm nào -->
            <?php if (empty($products)): ?>
                <div class="empty-products-state">
                    <div style="font-size: 3rem; margin-bottom: 12px;">🔍</div>
                    <h3 style="font-size: 1.2rem; margin-bottom: 8px; color: var(--text-main);">Không tìm thấy sản phẩm phù hợp</h3>
                    <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 0.9rem;">Rất tiếc, không có sản phẩm nào khớp với các tiêu chí tìm kiếm hoặc bộ lọc của bạn.</p>
                    <a href="index.php#featured-products" class="btn btn-primary" style="border-radius: 50px;">Xem tất cả sản phẩm</a>
                </div>
            <?php else: ?>
                <!-- Lưới sản phẩm -->
                <div class="products-grid">
                    <?php foreach ($products as $prod): ?>
                        <?php 
                            $img_list_json = htmlspecialchars(json_encode($prod['Images'] ?? []), ENT_QUOTES, 'UTF-8');
                            $vid_path = htmlspecialchars($prod['VideoThucTe'] ?? '', ENT_QUOTES, 'UTF-8');
                            $seller_id_val = $prod['MaNguoiBan'] ?? $prod['MaSanPham'] ?? 1;
                        ?>
                        <div class="product-card" onclick="openProductModal('<?php echo addslashes(htmlspecialchars($prod['TenSanPham'])); ?>', '<?php echo number_format($prod['GiaBan'], 0, ',', '.'); ?> đ', '<?php echo addslashes(htmlspecialchars($prod['TenDanhMuc'])); ?>', '<?php echo addslashes(htmlspecialchars($prod['TinhTrang'])); ?>', '<?php echo addslashes(htmlspecialchars($prod['TenNguoiBan'])); ?>', '<?php echo $prod['DiemUyTin']; ?>', '<?php echo addslashes(htmlspecialchars($prod['MoTaChiTiet'] ?? 'Chưa có mô tả')); ?>', '<?php echo $img_list_json; ?>', '<?php echo $vid_path; ?>', '<?php echo $seller_id_val; ?>', '<?php echo addslashes(htmlspecialchars($prod['DuongDanAnh'] ?? '')); ?>')" style="cursor: pointer;">
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
                                    <a href="seller.php?id=<?php echo $seller_id_val; ?>" onclick="event.stopPropagation();" title="Xem trang người bán" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                                        <div class="seller-info">
                                            <?php if (!empty($prod['SellerAvatar'])): ?>
                                                <img src="<?php echo htmlspecialchars($prod['SellerAvatar']); ?>" alt="Seller" class="seller-avatar">
                                            <?php else: ?>
                                                <div class="seller-avatar" style="background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: bold; width: 24px; height: 24px; border-radius: 50%;">
                                                    <?php echo strtoupper(substr($prod['TenNguoiBan'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span class="seller-name" style="text-decoration: underline;"><?php echo htmlspecialchars($prod['TenNguoiBan']); ?></span>
                                        </div>
                                    </a>
                                    <span class="seller-reputation"><?php echo htmlspecialchars($prod['DiemUyTin']); ?> Uy Tín</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>

        <!-- Modal Xem Chi Tiết Sản Phẩm & Thư Viện Ảnh/Video -->
        <div id="productDetailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.6); backdrop-filter: blur(6px); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; border-radius: 24px; padding: 30px; box-shadow: 0 25px 50px rgba(0,0,0,0.25); position: relative;">
                <button onclick="closeProductModal()" style="position: absolute; top: 20px; right: 20px; background: #f1f5f9; border: none; font-size: 1.2rem; cursor: pointer; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">✕</button>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <!-- Gallery Bên Trái -->
                    <div>
                        <div id="modal_large_media_box" style="width: 100%; aspect-ratio: 16 / 9; border-radius: 16px; overflow: hidden; background: #000000; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; position: relative; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                            <img id="modal_large_img" src="" alt="Large" style="width: 100%; height: 100%; object-fit: contain;">
                            <video id="modal_large_video" controls style="width: 100%; height: 100%; display: none; background: #000000; object-fit: contain;"></video>
                        </div>
                        
                        <!-- Thumbnail strip -->
                        <div id="modal_thumb_strip" style="display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px;"></div>
                    </div>

                    <!-- Thông Tin Bên Phải -->
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
                            <a href="#" id="modal_store_btn" class="btn btn-outline" style="border-radius: 50px; text-decoration: none; text-align: center; display: inline-flex; align-items: center; justify-content: center; padding: 0 16px; font-size: 0.9rem; font-weight: 600;">Xem Cửa Hàng</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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

    <!-- Script điều khiển Dropdown & Product Detail Modal Gallery -->
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
            try {
                images = JSON.parse(imagesJson);
            } catch(e) {}

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

            // Render thumbnails
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

            // If video exists, add video thumbnail icon
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
    </script>
</body>

</html>