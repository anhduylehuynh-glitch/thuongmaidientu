<?php
require_once 'config/config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user'])) {
    header("Location: login_page.php?redirect=post_product.php");
    exit;
}

$is_logged_in = true;
$db_error = false;
$error = '';
$success = '';

try {
    $db = getDBConnection();
    $session_user = $_SESSION['user'];

    // Truy vấn dữ liệu người dùng mới nhất
    $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
    $stmt->execute(['id' => $session_user['MaNguoiDung']]);
    $user_data = $stmt->fetch();

    if (!$user_data) {
        session_destroy();
        header("Location: login_page.php");
        exit;
    }

    $_SESSION['user'] = $user_data;

    // Truy vấn vai trò của người dùng
    $role_stmt = $db->prepare("
        SELECT vt.TenVaiTro 
        FROM `NguoiDung_VaiTro` ndvt 
        JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro 
        WHERE ndvt.MaNguoiDung = :id
    ");
    $role_stmt->execute(['id' => $user_data['MaNguoiDung']]);
    $user_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);

    $is_seller = in_array('SELLER', $user_roles) || in_array('ADMIN', $user_roles);

    // Tự động khởi tạo danh mục mẫu nếu bảng DanhMuc đang trống
    $cat_count = $db->query("SELECT COUNT(*) FROM `DanhMuc`")->fetchColumn();
    if ($cat_count == 0) {
        $db->exec("INSERT INTO `DanhMuc` (`TenDanhMuc`, `MoTa`) VALUES
            ('Điện thoại', 'Điện thoại thông minh, máy tính bảng đồ cũ'),
            ('Máy tính & Laptop', 'Laptop, máy tính để bàn, phụ kiện máy tính'),
            ('Phụ kiện máy tính', 'Bàn phím, chuột, tai nghe, màn hình'),
            ('Thiết bị âm thanh', 'Tai nghe, loa, micro, dàn âm thanh'),
            ('Đồ gia dụng', 'Thiết bị điện gia dụng, đồ dùng nhà bếp'),
            ('Thời trang', 'Quần áo, giày dép, phụ kiện thời trang');
        ");
    }

    $categories = $db->query("SELECT * FROM `DanhMuc` ORDER BY `MaDanhMuc` ASC")->fetchAll();

} catch (Exception $e) {
    $db_error = true;
    $error = "Lỗi kết nối CSDL: " . $e->getMessage();
}

// Xử lý Form 1: Đăng ký quyền Người Bán (SELLER Registration)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_seller') {
    try {
        $phone = trim($_POST['phone'] ?? '');
        $cccd = trim($_POST['cccd'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_account = trim($_POST['bank_account'] ?? '');
        $account_holder = trim($_POST['account_holder'] ?? '');

        if (empty($phone) || empty($address) || empty($bank_name) || empty($bank_account) || empty($account_holder)) {
            throw new Exception("Vui lòng nhập đầy đủ các thông tin bắt buộc.");
        }

        // 1. Cập nhật Số điện thoại người dùng
        $upd_user = $db->prepare("UPDATE `NguoiDung` SET `SoDienThoai` = :phone WHERE `MaNguoiDung` = :uid");
        $upd_user->execute(['phone' => $phone, 'uid' => $user_data['MaNguoiDung']]);

        // 2. Thêm sổ địa chỉ mặc định
        $ins_addr = $db->prepare("INSERT INTO `SoDiaChi` (`MaNguoiDung`, `DiaChiChiTiet`, `ViDo`, `KinhDo`, `LaDiaChiMacDinh`) VALUES (:uid, :addr, 10.762622, 106.660172, 1)");
        $ins_addr->execute(['uid' => $user_data['MaNguoiDung'], 'addr' => $address]);

        // 3. Thêm tài khoản ngân hàng liên kết
        $ins_bank = $db->prepare("INSERT INTO `TaiKhoanNganHangLienKet` (`MaNguoiDung`, `TenNganHang`, `SoTaiKhoan`, `TenChuTaiKhoan`) VALUES (:uid, :bname, :bacc, :bholder)");
        $ins_bank->execute([
            'uid' => $user_data['MaNguoiDung'],
            'bname' => $bank_name,
            'bacc' => $bank_account,
            'bholder' => $account_holder
        ]);

        // 4. Cấp quyền SELLER trong NguoiDung_VaiTro
        $seller_role_stmt = $db->prepare("SELECT `MaVaiTro` FROM `VaiTro` WHERE `TenVaiTro` = 'SELLER'");
        $seller_role_stmt->execute();
        $seller_rid = $seller_role_stmt->fetchColumn();

        if (!$seller_rid) {
            $db->exec("INSERT INTO `VaiTro` (`TenVaiTro`, `MoTa`) VALUES ('SELLER', 'Người bán hàng')");
            $seller_rid = $db->lastInsertId();
        }

        $chk_role = $db->prepare("SELECT COUNT(*) FROM `NguoiDung_VaiTro` WHERE `MaNguoiDung` = :uid AND `MaVaiTro` = :rid");
        $chk_role->execute(['uid' => $user_data['MaNguoiDung'], 'rid' => $seller_rid]);
        if ($chk_role->fetchColumn() == 0) {
            $grant_seller = $db->prepare("INSERT INTO `NguoiDung_VaiTro` (`MaNguoiDung`, `MaVaiTro`) VALUES (:uid, :rid)");
            $grant_seller->execute(['uid' => $user_data['MaNguoiDung'], 'rid' => $seller_rid]);
        }

        // Cập nhật lại biến vai trò & thông báo thành công
        $user_roles[] = 'SELLER';
        $is_seller = true;
        $success = "Chúc mừng! Bạn đã đăng ký thành công quyền Bán Hàng (SELLER). Giờ đây bạn có thể đăng bán sản phẩm đầu tiên!";

    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

// Xử lý Form 2: Đăng sản phẩm mới (Product Creation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_product') {
    try {
        if (!$is_seller) {
            throw new Exception("Bạn cần hoàn tất đăng ký thông tin bán hàng trước khi đăng sản phẩm.");
        }

        $title = trim($_POST['title'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $condition = trim($_POST['condition'] ?? 'Mới 99%');
        $weight = (float)($_POST['weight'] ?? 0.5);
        $description = trim($_POST['description'] ?? '');
        $image_url_input = trim($_POST['image_url'] ?? '');

        // 1. Thu thập danh sách đường dẫn ảnh đã tải lên thư mục server
        $uploaded_image_paths = [];
        if (isset($_POST['uploaded_images']) && is_array($_POST['uploaded_images'])) {
            foreach ($_POST['uploaded_images'] as $img_p) {
                $clean_p = trim($img_p);
                if (!empty($clean_p) && !in_array($clean_p, $uploaded_image_paths)) {
                    $uploaded_image_paths[] = $clean_p;
                }
            }
        }

        // Tải ảnh trực tiếp từ file input nếu gửi form truyền thống
        if (isset($_FILES['product_images']) && is_array($_FILES['product_images']['name'])) {
            $img_count = count($_FILES['product_images']['name']);
            $upload_img_dir = __DIR__ . '/uploads/images/';
            if (!is_dir($upload_img_dir)) mkdir($upload_img_dir, 0777, true);

            for ($i = 0; $i < min($img_count, 7); $i++) {
                if ($_FILES['product_images']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['product_images']['tmp_name'][$i];
                    $orig_name = $_FILES['product_images']['name'][$i];
                    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                        $new_name = 'img_' . time() . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($tmp_name, $upload_img_dir . $new_name)) {
                            $uploaded_image_paths[] = 'uploads/images/' . $new_name;
                        }
                    }
                }
            }
        }

        // Fallback sang URL ảnh nhập tay nếu chưa có ảnh nào
        if (empty($uploaded_image_paths) && !empty($image_url_input)) {
            $uploaded_image_paths[] = $image_url_input;
        }

        $main_image_path = trim($_POST['main_image_path'] ?? '');
        if (empty($main_image_path) && !empty($uploaded_image_paths)) {
            $main_image_path = $uploaded_image_paths[0];
        }

        // 2. Thu thập Video đã lưu từ AJAX hoặc File upload
        $uploaded_video_path = trim($_POST['uploaded_video'] ?? '');
        if (empty($uploaded_video_path) && isset($_FILES['product_video']) && $_FILES['product_video']['error'] === UPLOAD_ERR_OK) {
            $v_size = $_FILES['product_video']['size'];
            if ($v_size <= 100 * 1024 * 1024) {
                $v_tmp = $_FILES['product_video']['tmp_name'];
                $v_name = $_FILES['product_video']['name'];
                $v_ext = strtolower(pathinfo($v_name, PATHINFO_EXTENSION));
                if (in_array($v_ext, ['mp4', 'webm', 'ogg', 'mov', 'avi'])) {
                    $upload_vid_dir = __DIR__ . '/uploads/videos/';
                    if (!is_dir($upload_vid_dir)) mkdir($upload_vid_dir, 0777, true);
                    $new_v_name = 'vid_' . time() . '_' . uniqid() . '.' . $v_ext;
                    if (move_uploaded_file($v_tmp, $upload_vid_dir . $new_v_name)) {
                        $uploaded_video_path = 'uploads/videos/' . $new_v_name;
                    }
                }
            }
        }

        if (empty($title) || $category_id <= 0 || $price <= 0 || empty($description)) {
            throw new Exception("Vui lòng điền đầy đủ các thông tin bắt buộc (Tên, Danh mục, Giá bán, Mô tả chi tiết).");
        }

        // 3. Đăng sản phẩm vào bảng SanPham
        $ins_prod = $db->prepare("INSERT INTO `SanPham` 
            (`MaNguoiBan`, `MaDanhMuc`, `TenSanPham`, `MoTaChiTiet`, `TinhTrang`, `KhoiLuong_Kg`, `GiaBan`, `VideoThucTe`, `TrangThaiDuyet`, `TrangThaiBan`) 
            VALUES 
            (:seller_id, :cat_id, :title, :desc, :cond, :weight, :price, :video, b'01', b'00')");

        $ins_prod->execute([
            'seller_id' => $user_data['MaNguoiDung'],
            'cat_id' => $category_id,
            'title' => $title,
            'desc' => $description,
            'cond' => $condition,
            'weight' => $weight,
            'price' => $price,
            'video' => !empty($uploaded_video_path) ? $uploaded_video_path : null
        ]);

        $new_product_id = $db->lastInsertId();

        // 4. Lưu danh sách hình ảnh vào bảng HinhAnhSP (Đánh dấu AnhChinh)
        if (!empty($uploaded_image_paths)) {
            foreach ($uploaded_image_paths as $idx => $path) {
                $is_main = ($path === $main_image_path || ($idx === 0 && empty($main_image_path))) ? 1 : 0;
                $ins_img = $db->prepare("INSERT INTO `HinhAnhSP` (`MaSanPham`, `DuongDanAnh`, `AnhChinh`) VALUES (:pid, :img, :main)");
                $ins_img->execute(['pid' => $new_product_id, 'img' => $path, 'main' => $is_main]);
            }
        }

        $success = "Đăng bán sản phẩm thành công! Bài đăng đã sẵn sàng hiển thị trên trang chủ.";

    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_seller ? 'Đăng Bán Sản Phẩm Mới' : 'Đăng Ký Quyền Bán Hàng'; ?> - Chợ Đồ Cũ</title>
    <!-- Google Fonts Inter & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .post-container {
            width: 100%;
            max-width: 760px;
            margin: 40px auto 60px;
            padding: 0 24px;
        }

        .post-card {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 24px;
            padding: 36px;
            box-shadow: 0 20px 40px rgba(14, 165, 233, 0.1);
            backdrop-filter: blur(16px);
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 8px;
            text-align: center;
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 28px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            background: rgba(255, 255, 255, 0.9);
            font-size: 0.95rem;
            color: var(--text-main);
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            border-radius: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, #0369a1 100%);
            color: white;
            font-weight: 700;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            box-shadow: 0 10px 20px -5px rgba(2, 132, 199, 0.4);
            transition: all 0.2s ease;
            margin-top: 10px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 24px -5px rgba(2, 132, 199, 0.5);
        }

        .info-box {
            background: rgba(2, 132, 199, 0.08);
            border: 1px solid rgba(2, 132, 199, 0.2);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 24px;
            font-size: 0.88rem;
            color: #0369a1;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
    </style>
</head>

<body>
    <!-- Background hiệu ứng mờ -->
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
                    <a href="post_product.php" class="nav-link active" style="color: var(--primary); font-weight: 700;">Đăng Bán</a>

                    <?php if (in_array('ADMIN', $user_roles)): ?>
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
                                <a href="admin.php" class="dropdown-item">Trang Quản Lý Admin</a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="logout.php" class="dropdown-item" style="color: var(--error)">Đăng xuất</a>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Main Content Container -->
        <main class="post-container">
            <div class="post-card">

                <?php if (!empty($success)): ?>
                    <div style="margin-bottom: 24px; padding: 14px 20px; border-radius: 12px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div style="margin-bottom: 24px; padding: 14px 20px; border-radius: 12px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$is_seller): ?>
                    <!-- FORM XÁC MINH & ĐĂNG KÝ QUYỀN BÁN HÀNG (SELLER REGISTRATION) -->
                    <h1 class="page-title">Đăng Ký Quyền Bán Hàng</h1>
                    <p class="page-subtitle">Vui lòng cung cấp thêm thông tin liên hệ và tài khoản ngân hàng để nhận tiền bán hàng an toàn.</p>

                    <div class="info-box">
                        <div>
                            <b>Vì sao cần cung cấp thông tin này?</b><br>
                            Thông tin địa chỉ lấy hàng và số tài khoản ngân hàng giúp hệ thống tự động tính phí vận chuyển và chuyển tiền doanh thu từ sản phẩm bán được vào ví của bạn.
                        </div>
                    </div>

                    <form method="POST" action="post_product.php">
                        <input type="hidden" name="action" value="register_seller">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Số điện thoại liên hệ *</label>
                                <input type="tel" id="phone" name="phone" class="form-control" placeholder="VD: 0901234567" value="<?php echo htmlspecialchars($user_data['SoDienThoai'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="cccd">Số CCCD / CMND *</label>
                                <input type="text" id="cccd" name="cccd" class="form-control" placeholder="VD: 079198765432" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address">Địa chỉ kho / Lấy hàng thanh lý *</label>
                            <input type="text" id="address" name="address" class="form-control" placeholder="VD: 123 Nguyễn Văn Cừ, Phường 4, Quận 5, TP.HCM" required>
                        </div>

                        <h3 style="font-size: 1.1rem; font-weight: 700; margin: 24px 0 16px; color: var(--text-main); border-top: 1px solid rgba(0,0,0,0.06); padding-top: 20px;">
                            Thông tin ngân hàng nhận tiền bán hàng
                        </h3>

                        <div class="form-group">
                            <label for="bank_name">Tên Ngân Hàng *</label>
                            <select id="bank_name" name="bank_name" class="form-control" required>
                                <option value="">-- Chọn ngân hàng --</option>
                                <option value="Vietcombank">Vietcombank - Ngân hàng TMCP Ngoại thương Việt Nam</option>
                                <option value="MBBank">MBBank - Ngân hàng Quân Đội</option>
                                <option value="Techcombank">Techcombank - Ngân hàng Kỹ thương Việt Nam</option>
                                <option value="VietinBank">VietinBank - Ngân hàng Công Thương Việt Nam</option>
                                <option value="BIDV">BIDV - Ngân hàng Đầu tư và Phát triển Việt Nam</option>
                                <option value="VPBank">VPBank - Ngân hàng Việt Nam Thịnh Vượng</option>
                                <option value="TPBank">TPBank - Ngân hàng Tiên Phong</option>
                                <option value="ACB">ACB - Ngân hàng Á Châu</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="bank_account">Số tài khoản *</label>
                                <input type="text" id="bank_account" name="bank_account" class="form-control" placeholder="VD: 99012345678" required>
                            </div>

                            <div class="form-group">
                                <label for="account_holder">Tên chủ tài khoản *</label>
                                <input type="text" id="account_holder" name="account_holder" class="form-control" placeholder="VD: NGUYEN VAN A" value="<?php echo strtoupper(htmlspecialchars($user_data['HoTen'] ?? '')); ?>" required>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">Xác Nhận & Cấp Quyền Bán Hàng</button>
                    </form>

                <?php else: ?>
                    <!-- FORM ĐĂNG BÁN SẢN PHẨM MỚI (PRODUCT POSTING) -->
                    <h1 class="page-title">Đăng Bán Đồ Cũ</h1>
                    <p class="page-subtitle">Đăng tải thông tin chi tiết sản phẩm cần thanh lý lên hệ thống Chợ Đồ Cũ.</p>

                    <form method="POST" action="post_product.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="post_product">

                        <div class="form-group">
                            <label for="title">Tên sản phẩm *</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="VD: iPhone 13 Pro Max - 256GB Gold cũ" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="category_id">Danh mục sản phẩm *</label>
                                <select id="category_id" name="category_id" class="form-control" required>
                                    <option value="">-- Chọn danh mục --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['MaDanhMuc']; ?>"><?php echo htmlspecialchars($cat['TenDanhMuc']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="price">Giá bán (VNĐ) *</label>
                                <input type="number" id="price" name="price" class="form-control" placeholder="VD: 14500000" min="1000" step="1000" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="condition">Tình trạng thực tế *</label>
                                <select id="condition" name="condition" class="form-control" required>
                                    <option value="Mới 99%">Mới 99% (Hầu như chưa trầy xước)</option>
                                    <option value="Mới 95%">Mới 95% (Cũ xước nhẹ theo thời gian)</option>
                                    <option value="Hoạt động tốt">Hoạt động tốt (Đồ cũ còn dùng ngon)</option>
                                    <option value="Fullbox - Mới nguyên seal">Fullbox - Mới nguyên seal</option>
                                    <option value="Cần sửa chữa nhẹ">Cần sửa chữa nhẹ</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="weight">Khối lượng ước tính (Kg) *</label>
                                <input type="number" id="weight" name="weight" class="form-control" placeholder="VD: 0.5" step="0.1" min="0.1" value="0.5" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label style="display: block; font-weight: 700; margin-bottom: 8px;">Hình ảnh sản phẩm (Tải lên từng ảnh hoặc chọn nhiều ảnh, tối đa 7 hình)</label>
                            
                            <input type="file" id="ajax_image_picker" accept="image/*" multiple style="display: none;">
                            
                            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 12px;">
                                <button type="button" class="btn btn-outline" onclick="triggerImagePicker()" style="width: auto; padding: 10px 20px; font-weight: 600;">
                                    + Chọn Ảnh Từ Máy Tính
                                </button>
                                <span id="img_upload_status" style="font-size: 0.85rem; font-weight: 600; color: var(--primary);">Đã tải lên: 0 / 7 hình ảnh</span>
                            </div>

                            <div id="image_preview_grid" style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px; min-height: 40px;"></div>
                        </div>

                        <div class="form-group">
                            <label for="image_url">Hoặc nhập link URL hình ảnh mẫu</label>
                            <input type="url" id="image_url" name="image_url" class="form-control" placeholder="https://example.com/image.jpg">
                        </div>

                        <div class="form-group">
                            <label style="display: block; font-weight: 700; margin-bottom: 8px;">Video quay thực tế sản phẩm (Tối đa 100MB, 1080p, 60s)</label>
                            <input type="file" id="ajax_video_picker" accept="video/*" style="display: none;">
                            <input type="hidden" name="uploaded_video" id="uploaded_video_path">

                            <button type="button" class="btn btn-outline" onclick="document.getElementById('ajax_video_picker').click()" style="width: auto; padding: 10px 20px; font-weight: 600;">
                                Tải Video Từ Máy Tính
                            </button>

                            <div id="video_preview_box" style="margin-top: 12px; display: none; width: 100%; max-width: 560px; aspect-ratio: 16 / 9; background: #000000; border-radius: 16px; overflow: hidden; position: relative; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                                <video id="preview_video_player" controls style="width: 100%; height: 100%; object-fit: contain; background: #000000;"></video>
                            </div>
                            <div id="video_info" style="font-size: 0.85rem; color: var(--primary); font-weight: 600; margin-top: 8px;"></div>
                        </div>

                        <div class="form-group">
                            <label for="description">Mô tả chi tiết sản phẩm *</label>
                            <textarea id="description" name="description" class="form-control" placeholder="Nêu rõ tình trạng sử dụng, thời gian mua, lý do thanh lý, đầy đủ phụ kiện hay không..." required></textarea>
                        </div>

                        <button type="submit" class="btn-submit">Đăng Bán Sản Phẩm Ngay</button>
                    </form>
                <?php endif; ?>

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

    <!-- Script điều khiển Tải File Tức Thì (Instant Upload) & Chọn Ảnh Chính -->
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

        let uploadedImagePaths = [];
        let selectedMainImagePath = '';

        function triggerImagePicker() {
            if (uploadedImagePaths.length >= 7) {
                alert('Bạn đã tải lên đủ số lượng tối đa 7 hình ảnh.');
                return;
            }
            document.getElementById('ajax_image_picker').click();
        }

        const ajaxImagePicker = document.getElementById('ajax_image_picker');
        const imgGrid = document.getElementById('image_preview_grid');
        const imgStatus = document.getElementById('img_upload_status');

        if (ajaxImagePicker) {
            ajaxImagePicker.addEventListener('change', async function(e) {
                const files = Array.from(e.target.files);
                if (files.length === 0) return;

                const availableSlots = 7 - uploadedImagePaths.length;
                if (availableSlots <= 0) {
                    alert('Bạn đã tải lên tối đa 7 hình ảnh.');
                    return;
                }

                const filesToUpload = files.slice(0, availableSlots);

                for (let file of filesToUpload) {
                    imgStatus.textContent = 'Đang tải ảnh lên...';
                    const formData = new FormData();
                    formData.append('type', 'image');
                    formData.append('file', file);

                    try {
                        const res = await fetch('upload_media.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();

                        if (data.success && data.file_path) {
                            uploadedImagePaths.push(data.file_path);
                            if (!selectedMainImagePath) {
                                selectedMainImagePath = data.file_path;
                            }
                            renderImageGrid();
                        } else {
                            alert(data.message || 'Lỗi tải ảnh.');
                        }
                    } catch (err) {
                        alert('Lỗi kết nối tải ảnh.');
                    }
                }
                ajaxImagePicker.value = '';
            });
        }

        function renderImageGrid() {
            if (!uploadedImagePaths.includes(selectedMainImagePath)) {
                selectedMainImagePath = uploadedImagePaths[0] || '';
            }

            imgGrid.innerHTML = '';
            imgStatus.textContent = `Đã tải lên: ${uploadedImagePaths.length} / 7 hình ảnh (Chọn radio để làm Ảnh chính)`;

            uploadedImagePaths.forEach((path, index) => {
                const isMain = (path === selectedMainImagePath);

                const card = document.createElement('div');
                card.className = 'img-preview-card';
                card.style.cssText = 'position: relative; width: 130px; text-align: center; border: ' + (isMain ? '2px solid #0284c7' : '1px solid #cbd5e1') + '; border-radius: 12px; padding: 8px; background: ' + (isMain ? '#f0f9ff' : '#ffffff') + '; transition: all 0.2s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.06); cursor: pointer;';

                // Nút Xóa ảnh
                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.textContent = '✕';
                delBtn.style.cssText = 'position: absolute; top: -6px; right: -6px; background: #ef4444; color: #fff; border: none; border-radius: 50%; width: 22px; height: 22px; font-size: 11px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.2); z-index: 5;';
                delBtn.onclick = function(e) {
                    e.stopPropagation();
                    uploadedImagePaths.splice(index, 1);
                    if (selectedMainImagePath === path) {
                        selectedMainImagePath = uploadedImagePaths[0] || '';
                    }
                    renderImageGrid();
                };

                const img = document.createElement('img');
                img.src = path;
                img.style.cssText = 'width: 100%; height: 95px; object-fit: cover; border-radius: 8px; margin-bottom: 6px;';

                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'uploaded_images[]';
                hiddenInput.value = path;

                const radioLabel = document.createElement('label');
                radioLabel.style.cssText = 'font-size: 0.78rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; color: var(--text-main); user-select: none;';

                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'main_image_path';
                radio.value = path;
                if (isMain) radio.checked = true;

                const badgeSpan = document.createElement('span');
                badgeSpan.className = 'main-badge-span';
                badgeSpan.textContent = (isMain ? '✓ Ảnh chính' : 'Ảnh phụ');
                badgeSpan.style.color = (isMain ? '#0284c7' : '#64748b');

                card.onclick = function() {
                    selectedMainImagePath = path;
                    renderImageGrid();
                };

                radio.addEventListener('change', function(e) {
                    e.stopPropagation();
                    selectedMainImagePath = path;
                    renderImageGrid();
                });

                radioLabel.appendChild(radio);
                radioLabel.appendChild(badgeSpan);

                card.appendChild(delBtn);
                card.appendChild(img);
                card.appendChild(hiddenInput);
                card.appendChild(radioLabel);
                imgGrid.appendChild(card);
            });
        }

        // Tải & Xem trước Video (Tối đa 100MB, 1080p, 60s)
        const ajaxVideoPicker = document.getElementById('ajax_video_picker');
        const videoBox = document.getElementById('video_preview_box');
        const videoPlayer = document.getElementById('preview_video_player');
        const videoInfo = document.getElementById('video_info');
        const hiddenVideoInput = document.getElementById('uploaded_video_path');

        if (ajaxVideoPicker) {
            ajaxVideoPicker.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;

                if (file.size > 100 * 1024 * 1024) {
                    alert('Kích thước video vượt quá 100MB (' + (file.size / (1024*1024)).toFixed(1) + 'MB). Vui lòng chọn video nhỏ hơn.');
                    ajaxVideoPicker.value = '';
                    return;
                }

                videoInfo.textContent = 'Đang kiểm tra thông tin video...';
                videoBox.style.display = 'block';

                const tempVideo = document.createElement('video');
                tempVideo.preload = 'metadata';
                tempVideo.src = URL.createObjectURL(file);

                tempVideo.onloadedmetadata = async function() {
                    URL.revokeObjectURL(tempVideo.src);
                    const duration = tempVideo.duration;
                    const height = tempVideo.videoHeight;
                    const width = tempVideo.videoWidth;

                    let errorMsg = '';
                    if (duration > 60) {
                        errorMsg += '• Thời lượng video vượt quá 60 giây (' + Math.round(duration) + 's).\n';
                    }
                    if (Math.min(width, height) > 1080) {
                        errorMsg += '• Độ phân giải video vượt quá chuẩn 1080p (' + width + 'x' + height + ').\n';
                    }

                    if (errorMsg) {
                        alert('Video chưa hợp lệ quy định:\n' + errorMsg + '\nVui lòng chọn video khác.');
                        ajaxVideoPicker.value = '';
                        videoPlayer.src = '';
                        videoBox.style.display = 'none';
                        videoInfo.textContent = '';
                        return;
                    }

                    // Tiến hành upload AJAX sang server
                    videoInfo.textContent = 'Đang tải video lên server...';
                    const formData = new FormData();
                    formData.append('type', 'video');
                    formData.append('file', file);

                    try {
                        const res = await fetch('upload_media.php', { method: 'POST', body: formData });
                        const data = await res.json();

                        if (data.success && data.file_path) {
                            hiddenVideoInput.value = data.file_path;
                            videoPlayer.src = data.file_path;
                            videoInfo.textContent = `✓ Đã tải video lên server! Dung lượng: ${(file.size / (1024*1024)).toFixed(1)}MB | Thời lượng: ${Math.round(duration)}s | Độ phân giải: ${width}x${height}`;
                        } else {
                            alert(data.message || 'Lỗi tải video lên server.');
                            videoBox.style.display = 'none';
                            videoInfo.textContent = '';
                        }
                    } catch (err) {
                        alert('Lỗi kết nối tải video.');
                        videoBox.style.display = 'none';
                        videoInfo.textContent = '';
                    }
                };

                tempVideo.onerror = function() {
                    alert('Không thể đọc thông tin file video này. Vui lòng chọn file video hợp lệ.');
                    videoBox.style.display = 'none';
                    videoInfo.textContent = '';
                };
            });
        }
    </script>
</body>

</html>
