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
        SELECT sp.*, nd.HoTen as TenNguoiBan, nd.DiemUyTin, nd.google_picture as SellerAvatar, dm.TenDanhMuc
        FROM SanPham sp
        JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
        JOIN DanhMuc dm ON sp.MaDanhMuc = dm.MaDanhMuc
        WHERE sp.TrangThaiDuyet = b'01' AND sp.TrangThaiBan = b'00'
        ORDER BY sp.NgayDang DESC
        LIMIT 12
    ");
    $products = $stmt->fetchAll();

    foreach ($products as &$p) {
        $img_stmt = $db->prepare("SELECT DuongDanAnh, AnhChinh FROM HinhAnhSP WHERE MaSanPham = :pid ORDER BY AnhChinh DESC, MaHinhAnh ASC");
        $img_stmt->execute(['pid' => $p['MaSanPham']]);
        $p['Images'] = $img_stmt->fetchAll();
        $p['DuongDanAnh'] = !empty($p['Images']) ? $p['Images'][0]['DuongDanAnh'] : '';
    }
    unset($p);
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
                <a href="#" style="color: var(--primary); text-decoration: none; font-weight: 600; font-size: 0.95rem;">Xem tất cả</a>
            </div>

            <!-- Lưới sản phẩm -->
            <div class="products-grid">
                <?php foreach ($products as $prod): ?>
                    <?php 
                        $img_list_json = htmlspecialchars(json_encode($prod['Images'] ?? []), ENT_QUOTES, 'UTF-8');
                        $vid_path = htmlspecialchars($prod['VideoThucTe'] ?? '', ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="product-card" onclick="openProductModal('<?php echo addslashes(htmlspecialchars($prod['TenSanPham'])); ?>', '<?php echo number_format($prod['GiaBan'], 0, ',', '.'); ?> đ', '<?php echo addslashes(htmlspecialchars($prod['TenDanhMuc'])); ?>', '<?php echo addslashes(htmlspecialchars($prod['TinhTrang'])); ?>', '<?php echo addslashes(htmlspecialchars($prod['TenNguoiBan'])); ?>', '<?php echo $prod['DiemUyTin']; ?>', '<?php echo addslashes(htmlspecialchars($prod['MoTaChiTiet'] ?? 'Chưa có mô tả')); ?>', '<?php echo $img_list_json; ?>', '<?php echo $vid_path; ?>')" style="cursor: pointer;">
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
                            <div style="font-size: 0.85rem; color: var(--text-muted);">Người bán: <b id="modal_seller" style="color: var(--text-main);"></b> (<span id="modal_rep" style="color: #d97706; font-weight: 700;"></span> Uy Tín)</div>
                        </div>

                        <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 8px;">Mô tả sản phẩm:</h4>
                        <div id="modal_desc" style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.6; white-space: pre-line; max-height: 180px; overflow-y: auto;"></div>

                        <button onclick="alert('Tính năng liên hệ người mua/đặt hàng đang mở rộng!')" class="btn btn-primary" style="margin-top: 20px; border-radius: 50px; width: 100%;">Liên Hệ Người Bán</button>
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

        function openProductModal(title, price, cat, cond, seller, rep, desc, imagesJson, videoPath) {
            document.getElementById('modal_title').textContent = title;
            document.getElementById('modal_price').textContent = price;
            document.getElementById('modal_cat').textContent = cat;
            document.getElementById('modal_cond').textContent = cond;
            document.getElementById('modal_seller').textContent = seller;
            document.getElementById('modal_rep').textContent = rep;
            document.getElementById('modal_desc').textContent = desc;

            const largeImg = document.getElementById('modal_large_img');
            const largeVideo = document.getElementById('modal_large_video');
            const thumbStrip = document.getElementById('modal_thumb_strip');

            thumbStrip.innerHTML = '';
            largeVideo.style.display = 'none';
            largeVideo.pause();
            largeImg.style.display = 'block';

            let images = [];
            try {
                images = JSON.parse(imagesJson);
            } catch(e) {}

            if (images.length > 0) {
                largeImg.src = images[0].DuongDanAnh;
            } else {
                largeImg.src = '';
            }

            // Render thumbnails
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
                        t.style.border = '1px solid #cbd5e1';
                        t.style.opacity = '0.7';
                    });
                    thumb.style.border = '2px solid #0284c7';
                    thumb.style.opacity = '1';
                };
                thumbStrip.appendChild(thumb);
            });

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