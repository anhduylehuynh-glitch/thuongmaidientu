<?php
require_once 'config/config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user'])) {
    header("Location: login_page.php");
    exit;
}

$is_logged_in = true;
$db_error = false;
$db_error_message = '';
$error = '';
$success = '';

// Lấy thông tin user hiện tại & kiểm tra quyền ADMIN
try {
    $db = getDBConnection();
    $session_user = $_SESSION['user'];

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

        // KIỂM TRA QUYỀN ADMIN (Nếu không có quyền -> Chuyển về trang chủ)
        if (!in_array('ADMIN', $user_roles)) {
            header("Location: index.php");
            exit;
        }
    } else {
        session_destroy();
        header("Location: login_page.php");
        exit;
    }
} catch (Exception $e) {
    header("Location: index.php");
    exit;
}

// Xử lý các thao tác Admin (POST / GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'approve_product' && isset($_POST['product_id'])) {
            $pid = (int)$_POST['product_id'];
            $st = $db->prepare("UPDATE `SanPham` SET `TrangThaiDuyet` = b'01' WHERE `MaSanPham` = :pid");
            $st->execute(['pid' => $pid]);
            $success = "Đã duyệt bài đăng #" . $pid . ". Sản phẩm đã sẵn sàng hiển thị trên trang chủ.";
        }

        if ($_POST['action'] === 'ban_product' && isset($_POST['product_id'])) {
            $pid = (int)$_POST['product_id'];
            $st = $db->prepare("UPDATE `SanPham` SET `TrangThaiDuyet` = b'10' WHERE `MaSanPham` = :pid");
            $st->execute(['pid' => $pid]);
            $success = "Đã cấm/từ chối sản phẩm #" . $pid . ". Bài đăng sẽ bị ẩn khỏi trang chủ.";
        }

        if ($_POST['action'] === 'pend_product' && isset($_POST['product_id'])) {
            $pid = (int)$_POST['product_id'];
            $st = $db->prepare("UPDATE `SanPham` SET `TrangThaiDuyet` = b'00' WHERE `MaSanPham` = :pid");
            $st->execute(['pid' => $pid]);
            $success = "Đã chuyển sản phẩm #" . $pid . " về trạng thái Chờ duyệt.";
        }

        if ($_POST['action'] === 'toggle_product_status' && isset($_POST['product_id'])) {
            $pid = (int)$_POST['product_id'];
            $current_status = (int)$_POST['current_status'];
            $new_status = $current_status === 1 ? 0 : 1;

            $update_stmt = $db->prepare("UPDATE `SanPham` SET `TrangThaiDuyet` = :st WHERE `MaSanPham` = :pid");
            $update_stmt->bindValue(':st', $new_status, PDO::PARAM_INT);
            $update_stmt->bindValue(':pid', $pid, PDO::PARAM_INT);
            $update_stmt->execute();
            $success = "Đã cập nhật trạng thái duyệt sản phẩm #" . $pid;
        }

        if ($_POST['action'] === 'delete_product' && isset($_POST['product_id'])) {
            $pid = (int)$_POST['product_id'];
            $del_stmt = $db->prepare("DELETE FROM `SanPham` WHERE `MaSanPham` = :pid");
            $del_stmt->execute(['pid' => $pid]);
            $success = "Đã xóa sản phẩm #" . $pid;
        }

        if ($_POST['action'] === 'toggle_user_status' && isset($_POST['user_id'])) {
            $uid = (int)$_POST['user_id'];
            $current_st = (int)$_POST['current_status'];
            $new_st = $current_st === 1 ? 0 : 1;

            $user_st = $db->prepare("UPDATE `NguoiDung` SET `TrangThaiTaiKhoan` = :st WHERE `MaNguoiDung` = :uid");
            $user_st->bindValue(':st', $new_st, PDO::PARAM_INT);
            $user_st->bindValue(':uid', $uid, PDO::PARAM_INT);
            $user_st->execute();
            $success = "Đã cập nhật trạng thái tài khoản người dùng #" . $uid;
        }

        if ($_POST['action'] === 'add_admin_role' && isset($_POST['user_id'])) {
            $uid = (int)$_POST['user_id'];
            $admin_role_stmt = $db->prepare("SELECT `MaVaiTro` FROM `VaiTro` WHERE `TenVaiTro` = 'ADMIN'");
            $admin_role_stmt->execute();
            $admin_rid = $admin_role_stmt->fetchColumn();

            if ($admin_rid) {
                $check_role = $db->prepare("SELECT COUNT(*) FROM `NguoiDung_VaiTro` WHERE `MaNguoiDung` = :uid AND `MaVaiTro` = :rid");
                $check_role->execute(['uid' => $uid, 'rid' => $admin_rid]);
                if ($check_role->fetchColumn() == 0) {
                    $grant = $db->prepare("INSERT INTO `NguoiDung_VaiTro` (`MaNguoiDung`, `MaVaiTro`) VALUES (:uid, :rid)");
                    $grant->execute(['uid' => $uid, 'rid' => $admin_rid]);
                    $success = "Đã cấp quyền ADMIN cho người dùng #" . $uid;
                }
            }
        }

        if ($_POST['action'] === 'add_category') {
            $cat_name = trim($_POST['cat_name'] ?? '');
            $cat_desc = trim($_POST['cat_desc'] ?? '');
            if (empty($cat_name)) {
                throw new Exception("Tên danh mục không được để trống.");
            }
            $ins_cat = $db->prepare("INSERT INTO `DanhMuc` (`TenDanhMuc`, `MoTa`) VALUES (:name, :desc)");
            $ins_cat->execute(['name' => $cat_name, 'desc' => $cat_desc]);
            $success = "Đã thêm danh mục mới: " . htmlspecialchars($cat_name);
        }

        if ($_POST['action'] === 'edit_category') {
            $cat_id = (int)($_POST['cat_id'] ?? 0);
            $cat_name = trim($_POST['cat_name'] ?? '');
            $cat_desc = trim($_POST['cat_desc'] ?? '');
            if ($cat_id <= 0 || empty($cat_name)) {
                throw new Exception("Thông tin danh mục không hợp lệ.");
            }
            $upd_cat = $db->prepare("UPDATE `DanhMuc` SET `TenDanhMuc` = :name, `MoTa` = :desc WHERE `MaDanhMuc` = :id");
            $upd_cat->execute(['name' => $cat_name, 'desc' => $cat_desc, 'id' => $cat_id]);
            $success = "Đã cập nhật danh mục #" . $cat_id;
        }

        if ($_POST['action'] === 'delete_category') {
            $cat_id = (int)($_POST['cat_id'] ?? 0);
            $count_stmt = $db->prepare("SELECT COUNT(*) FROM `SanPham` WHERE `MaDanhMuc` = :id");
            $count_stmt->execute(['id' => $cat_id]);
            if ($count_stmt->fetchColumn() > 0) {
                throw new Exception("Không thể xóa danh mục này vì đang có sản phẩm thuộc danh mục!");
            }
            $del_cat = $db->prepare("DELETE FROM `DanhMuc` WHERE `MaDanhMuc` = :id");
            $del_cat->execute(['id' => $cat_id]);
            $success = "Đã xóa danh mục #" . $cat_id;
        }
    } catch (Exception $ex) {
        $error = "Lỗi thao tác: " . $ex->getMessage();
    }
}

// Lấy danh sách thống kê
$total_users = 0;
$total_products = 0;
$pending_products = 0;
$user_list = [];
$product_list = [];
$category_list = [];

try {
    $total_users = $db->query("SELECT COUNT(*) FROM `NguoiDung`")->fetchColumn();
    $total_products = $db->query("SELECT COUNT(*) FROM `SanPham`")->fetchColumn();
    $pending_products = $db->query("SELECT COUNT(*) FROM `SanPham` WHERE `TrangThaiDuyet` = b'00'")->fetchColumn();

    // Lấy danh sách người dùng kèm vai trò
    $user_sql = "
        SELECT nd.*, GROUP_CONCAT(vt.TenVaiTro SEPARATOR ', ') as DanhSachVaiTro
        FROM `NguoiDung` nd
        LEFT JOIN `NguoiDung_VaiTro` ndvt ON nd.MaNguoiDung = ndvt.MaNguoiDung
        LEFT JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro
        GROUP BY nd.MaNguoiDung
        ORDER BY nd.NgayTao DESC
    ";
    $user_list = $db->query($user_sql)->fetchAll();

    // Lấy danh sách sản phẩm kèm ảnh và người bán
    $product_sql = "
        SELECT sp.*, nd.HoTen as TenNguoiBan, nd.DiemUyTin, dm.TenDanhMuc
        FROM `SanPham` sp
        JOIN `NguoiDung` nd ON sp.MaNguoiBan = nd.MaNguoiDung
        JOIN `DanhMuc` dm ON sp.MaDanhMuc = dm.MaDanhMuc
        ORDER BY sp.NgayDang DESC
    ";
    $product_list = $db->query($product_sql)->fetchAll();

    foreach ($product_list as &$p) {
        $img_st = $db->prepare("SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = :pid ORDER BY AnhChinh DESC, MaHinhAnh ASC");
        $img_st->execute(['pid' => $p['MaSanPham']]);
        $p['Images'] = $img_st->fetchAll(PDO::FETCH_COLUMN);
        $p['DuongDanAnh'] = !empty($p['Images']) ? $p['Images'][0] : '';
    }
    unset($p);

    // Lấy danh sách danh mục kèm số sản phẩm
    $category_sql = "
        SELECT dm.*, COUNT(sp.MaSanPham) as SoLuongSanPham
        FROM `DanhMuc` dm
        LEFT JOIN `SanPham` sp ON dm.MaDanhMuc = sp.MaDanhMuc
        GROUP BY dm.MaDanhMuc
        ORDER BY dm.MaDanhMuc ASC
    ";
    $category_list = $db->query($category_sql)->fetchAll();
} catch (Exception $e) {
    //
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang Quản Lý Admin - Chợ Đồ Cũ</title>
    <!-- Google Fonts Inter & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .admin-container {
            width: 100%;
            max-width: 1200px;
            margin: 30px auto 60px;
            padding: 0 24px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .admin-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-main);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 20px;
            padding: 20px 24px;
            box-shadow: 0 10px 30px rgba(14, 165, 233, 0.08);
            backdrop-filter: blur(10px);
        }

        .stat-card-title {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .stat-card-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--primary);
        }

        .admin-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            border-bottom: 2px solid rgba(0, 0, 0, 0.06);
            padding-bottom: 8px;
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: transparent;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 50px;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
        }

        .admin-table-card {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.05);
            overflow-x: auto;
        }

        table.admin-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }

        table.admin-table th {
            padding: 14px 16px;
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            background: rgba(240, 249, 255, 0.5);
        }

        table.admin-table td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: middle;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .badge-success {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-warning {
            background: #fef3c7;
            color: #b45309;
        }

        .badge-danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge-role {
            background: #e0f2fe;
            color: #0369a1;
            margin-right: 4px;
        }

        .btn-action {
            padding: 6px 12px;
            font-size: 0.8rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: opacity 0.2s;
        }

        .btn-action:hover {
            opacity: 0.85;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
                    <a href="admin.php" class="nav-link active" style="color: #6366f1; font-weight: 700;">Quản Lý Admin</a>

                    <!-- Khối người dùng -->
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
                                Vai trò: <b><?php echo implode(', ', $user_roles); ?></b>
                            </div>
                            <a href="profile.php" class="dropdown-item">Hồ sơ cá nhân</a>
                            <a href="admin.php" class="dropdown-item" style="color: var(--primary);">Trang Quản Lý Admin</a>
                            <div class="dropdown-divider"></div>
                            <a href="logout.php" class="dropdown-item" style="color: var(--error)">Đăng xuất</a>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Admin Main Content -->
        <main class="admin-container">
            <div class="admin-header">
                <div>
                    <h1 class="admin-title">Bảng Quản Trị Hệ Thống</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Quản lý sản phẩm, tài khoản người dùng và phân quyền hệ thống</p>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px 20px; border-radius: 12px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px; padding: 12px 20px; border-radius: 12px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-title">Tổng Người Dùng</div>
                    <div class="stat-card-value"><?php echo number_format($total_users); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-title">Tổng Sản Phẩm</div>
                    <div class="stat-card-value"><?php echo number_format($total_products); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-title">Sản Phẩm Chờ Duyệt</div>
                    <div class="stat-card-value" style="color: #d97706;"><?php echo number_format($pending_products); ?></div>
                </div>
            </div>

            <!-- Admin Tabs Navigation -->
            <div class="admin-tabs">
                <button class="tab-btn active" onclick="switchTab('products-tab', this)">Quản Lý Sản Phẩm</button>
                <button class="tab-btn" onclick="switchTab('users-tab', this)">Quản Lý Người Dùng</button>
                <button class="tab-btn" onclick="switchTab('categories-tab', this)">Quản Lý Danh Mục</button>
            </div>

            <!-- Tab 1: Quản lý sản phẩm -->
            <div id="products-tab" class="tab-content active">
                <div class="admin-table-card">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sản Phẩm</th>
                                <th>Danh Mục</th>
                                <th>Giá Bán</th>
                                <th>Người Bán</th>
                                <th>Trạng Thái Duyệt</th>
                                <th>Hành Động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($product_list)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có sản phẩm nào trong hệ thống CSDL.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($product_list as $p): ?>
                                    <?php 
                                        $st_val = ord($p['TrangThaiDuyet'] ?? "\x00");
                                        $img_json = htmlspecialchars(json_encode($p['Images'] ?? []), ENT_QUOTES, 'UTF-8');
                                        $vid_path = htmlspecialchars($p['VideoThucTe'] ?? '', ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td>#<?php echo $p['MaSanPham']; ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <?php if (!empty($p['DuongDanAnh'])): ?>
                                                    <img src="<?php echo htmlspecialchars($p['DuongDanAnh']); ?>" alt="Img" style="width: 44px; height: 44px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;">
                                                <?php else: ?>
                                                    <div style="width: 44px; height: 44px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #64748b;">No img</div>
                                                <?php endif; ?>
                                                <div>
                                                    <strong style="display: block; font-size: 0.95rem; color: var(--text-main);"><?php echo htmlspecialchars($p['TenSanPham']); ?></strong>
                                                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($p['TinhTrang']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($p['TenDanhMuc']); ?></td>
                                        <td><strong style="color: var(--primary); font-weight: 700;"><?php echo number_format($p['GiaBan'], 0, ',', '.'); ?> đ</strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($p['TenNguoiBan']); ?></strong>
                                            <div style="font-size: 0.75rem; color: #d97706;"><?php echo $p['DiemUyTin']; ?> Uy Tín</div>
                                        </td>
                                        <td>
                                            <?php if ($st_val === 1): ?>
                                                <span class="badge badge-success">✓ Đã duyệt</span>
                                            <?php elseif ($st_val === 2): ?>
                                                <span class="badge badge-danger">🚫 Đã cấm/Từ chối</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">⏳ Chờ duyệt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                                <button type="button" class="btn-action" style="background: #e0f2fe; color: #0369a1;" onclick="openAdminProductModal(<?php echo $p['MaSanPham']; ?>, '<?php echo addslashes(htmlspecialchars($p['TenSanPham'])); ?>', '<?php echo number_format($p['GiaBan'], 0, ',', '.'); ?> đ', '<?php echo addslashes(htmlspecialchars($p['TenDanhMuc'])); ?>', '<?php echo addslashes(htmlspecialchars($p['TinhTrang'])); ?>', '<?php echo addslashes(htmlspecialchars($p['TenNguoiBan'])); ?>', '<?php echo $p['DiemUyTin']; ?>', '<?php echo addslashes(htmlspecialchars($p['MoTaChiTiet'] ?? 'Chưa có mô tả')); ?>', '<?php echo $img_json; ?>', '<?php echo $vid_path; ?>', <?php echo $st_val; ?>)">Xem</button>

                                                <?php if ($st_val !== 1): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="approve_product">
                                                        <input type="hidden" name="product_id" value="<?php echo $p['MaSanPham']; ?>">
                                                        <button type="submit" class="btn-action" style="background: #dcfce7; color: #15803d;">Duyệt bài</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($st_val !== 2): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="ban_product">
                                                        <input type="hidden" name="product_id" value="<?php echo $p['MaSanPham']; ?>">
                                                        <button type="submit" class="btn-action" style="background: #fee2e2; color: #b91c1c;">Cấm bài</button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc muốn xóa sản phẩm này khỏi CSDL?');">
                                                    <input type="hidden" name="action" value="delete_product">
                                                    <input type="hidden" name="product_id" value="<?php echo $p['MaSanPham']; ?>">
                                                    <button type="submit" class="btn-action" style="background: #f1f5f9; color: #475569;">Xóa</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 2: Quản lý người dùng -->
            <div id="users-tab" class="tab-content">
                <div class="admin-table-card">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Họ Và Tên</th>
                                <th>Tên Đăng Nhập / Email</th>
                                <th>Vai Trò</th>
                                <th>Điểm Uy Tín</th>
                                <th>Trạng Thái</th>
                                <th>Hành Động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_list as $u): ?>
                                <?php 
                                    $is_active = ord($u['TrangThaiTaiKhoan'] ?? "\x01") === 1;
                                    $has_admin = str_contains($u['DanhSachVaiTro'] ?? '', 'ADMIN');
                                ?>
                                <tr>
                                    <td>#<?php echo $u['MaNguoiDung']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($u['HoTen']); ?></strong>
                                    </td>
                                    <td>
                                        <div>@<?php echo htmlspecialchars($u['TenDangNhap']); ?></div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($u['Email'] ?? 'Chưa cập nhật'); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                            $roles = explode(', ', $u['DanhSachVaiTro'] ?? '');
                                            foreach ($roles as $r) {
                                                if (!empty($r)) {
                                                    $bg = ($r === 'ADMIN') ? '#fee2e2' : '#e0f2fe';
                                                    $fg = ($r === 'ADMIN') ? '#b91c1c' : '#0369a1';
                                                    echo '<span class="badge" style="background: ' . $bg . '; color: ' . $fg . '; margin-right: 4px;">' . htmlspecialchars($r) . '</span>';
                                                }
                                            }
                                        ?>
                                    </td>
                                    <td><?php echo $u['DiemUyTin']; ?> Uy Tín (<?php echo htmlspecialchars($u['HangThanhVien']); ?>)</td>
                                    <td>
                                        <?php if ($is_active): ?>
                                            <span class="badge badge-success">Hoạt động</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Bị khóa</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_user_status">
                                                <input type="hidden" name="user_id" value="<?php echo $u['MaNguoiDung']; ?>">
                                                <input type="hidden" name="current_status" value="<?php echo $is_active ? 1 : 0; ?>">
                                                <button type="submit" class="btn-action" style="background: <?php echo $is_active ? '#fee2e2; color: #b91c1c;' : '#dcfce7; color: #15803d;'; ?>">
                                                    <?php echo $is_active ? 'Khóa TK' : 'Mở khóa'; ?>
                                                </button>
                                            </form>

                                            <?php if (!$has_admin): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="add_admin_role">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['MaNguoiDung']; ?>">
                                                    <button type="submit" class="btn-action" style="background: #e0e7ff; color: #4338ca;">Cấp Admin</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 3: Quản lý danh mục -->
            <div id="categories-tab" class="tab-content">
                <!-- Form thêm danh mục mới -->
                <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 24px; margin-bottom: 24px;">
                    <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; color: var(--text-main);">Thêm Danh Mục Sản Phẩm Mới</h3>
                    <form method="POST" action="admin.php" style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 16px; align-items: flex-end;">
                        <input type="hidden" name="action" value="add_category">
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Tên danh mục *</label>
                            <input type="text" name="cat_name" class="form-control" placeholder="VD: Điện thoại" required>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Mô tả danh mục</label>
                            <input type="text" name="cat_desc" class="form-control" placeholder="VD: Điện thoại thông minh, máy đọc sách cũ...">
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding: 12px 24px; border-radius: 12px; font-size: 0.9rem;">Thêm Danh Mục</button>
                    </form>
                </div>

                <!-- Bảng danh sách danh mục -->
                <div class="admin-table-card">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tên Danh Mục</th>
                                <th>Mô Tả</th>
                                <th>Số Sản Phẩm</th>
                                <th>Hành Động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($category_list as $c): ?>
                                <tr>
                                    <td>#<?php echo $c['MaDanhMuc']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($c['TenDanhMuc']); ?></strong></td>
                                    <td><span style="color: var(--text-muted); font-size: 0.9rem;"><?php echo htmlspecialchars($c['MoTa'] ?? 'Chưa có mô tả'); ?></span></td>
                                    <td><span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700;"><?php echo number_format($c['SoLuongSanPham']); ?> sản phẩm</span></td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <button type="button" class="btn-action" style="background: #e0e7ff; color: #4338ca;" onclick="openEditCatModal(<?php echo $c['MaDanhMuc']; ?>, '<?php echo addslashes(htmlspecialchars($c['TenDanhMuc'])); ?>', '<?php echo addslashes(htmlspecialchars($c['MoTa'] ?? '')); ?>')">Sửa</button>

                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc muốn xóa danh mục này?');">
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="cat_id" value="<?php echo $c['MaDanhMuc']; ?>">
                                                <button type="submit" class="btn-action" style="background: #fee2e2; color: #b91c1c;" <?php echo $c['SoLuongSanPham'] > 0 ? 'title="Không thể xóa vì đang có sản phẩm"' : ''; ?>>Xóa</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Sửa Danh Mục -->
            <div id="editCatModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 500px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Chỉnh Sửa Danh Mục</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="action" value="edit_category">
                        <input type="hidden" name="cat_id" id="edit_cat_id">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Tên danh mục *</label>
                            <input type="text" name="cat_name" id="edit_cat_name" class="form-control" required>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Mô tả danh mục</label>
                            <input type="text" name="cat_desc" id="edit_cat_desc" class="form-control">
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeEditCatModal()" style="width: auto; padding: 10px 20px;">Hủy</button>
                            <button type="submit" class="btn btn-primary" style="width: auto; padding: 10px 24px;">Lưu Thay Đổi</button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Modal Admin Xem Chi Tiết Kiểm Duyệt Sản Phẩm -->
            <div id="adminProductModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.6); backdrop-filter: blur(6px); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
                <div style="background: #ffffff; width: 100%; max-width: 820px; max-height: 90vh; overflow-y: auto; border-radius: 24px; padding: 30px; box-shadow: 0 25px 50px rgba(0,0,0,0.25); position: relative;">
                    <button onclick="closeAdminProductModal()" style="position: absolute; top: 20px; right: 20px; background: #f1f5f9; border: none; font-size: 1.2rem; cursor: pointer; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">✕</button>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                        <!-- Gallery Media Bên Trái -->
                        <div>
                            <div id="admin_modal_media_box" style="width: 100%; aspect-ratio: 16 / 9; border-radius: 16px; overflow: hidden; background: #000000; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; position: relative;">
                                <img id="admin_modal_img" src="" alt="Large" style="width: 100%; height: 100%; object-fit: contain;">
                                <video id="admin_modal_video" controls style="width: 100%; height: 100%; display: none; background: #000000; object-fit: contain;"></video>
                            </div>
                            <div id="admin_modal_thumb_strip" style="display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px;"></div>
                        </div>

                        <!-- Thông Tin Kiểm Duyệt Bên Phải -->
                        <div>
                            <span id="admin_modal_cat" class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; margin-bottom: 8px; display: inline-block;"></span>
                            <h2 id="admin_modal_title" style="font-size: 1.4rem; color: var(--text-main); margin-bottom: 8px;"></h2>
                            <div id="admin_modal_price" style="font-size: 1.5rem; font-weight: 800; color: var(--primary); margin-bottom: 14px;"></div>

                            <div style="background: rgba(248, 250, 252, 0.8); border: 1px solid rgba(226, 232, 240, 0.8); border-radius: 12px; padding: 12px; margin-bottom: 14px; font-size: 0.85rem;">
                                <div>Tình trạng: <b id="admin_modal_cond" style="color: var(--text-main);"></b></div>
                                <div style="margin-top: 4px;">Người bán: <b id="admin_modal_seller" style="color: var(--text-main);"></b> (<span id="admin_modal_rep" style="color: #d97706; font-weight: 700;"></span> Uy Tín)</div>
                            </div>

                            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 6px;">Mô tả sản phẩm:</h4>
                            <div id="admin_modal_desc" style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; white-space: pre-line; max-height: 140px; overflow-y: auto; margin-bottom: 16px;"></div>

                            <!-- Nút Thao Tác Duyệt / Cấm -->
                            <div style="display: flex; gap: 10px;">
                                <form method="POST" action="admin.php" style="flex: 1;">
                                    <input type="hidden" name="action" value="approve_product">
                                    <input type="hidden" name="product_id" id="admin_modal_pid_approve">
                                    <button type="submit" class="btn" style="background: #16a34a; color: #fff; width: 100%; border-radius: 12px; padding: 10px; font-weight: 700;">✓ Duyệt Cho Bán</button>
                                </form>
                                <form method="POST" action="admin.php" style="flex: 1;">
                                    <input type="hidden" name="action" value="ban_product">
                                    <input type="hidden" name="product_id" id="admin_modal_pid_ban">
                                    <button type="submit" class="btn" style="background: #dc2626; color: #fff; width: 100%; border-radius: 12px; padding: 10px; font-weight: 700;">🚫 Cấm Bài Đăng</button>
                                </form>
                            </div>
                        </div>
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

    <!-- Script chuyển đổi tab & dropdown & modal -->
    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }

        function openEditCatModal(id, name, desc) {
            document.getElementById('edit_cat_id').value = id;
            document.getElementById('edit_cat_name').value = name;
            document.getElementById('edit_cat_desc').value = desc;
            document.getElementById('editCatModal').style.display = 'flex';
        }

        function closeEditCatModal() {
            document.getElementById('editCatModal').style.display = 'none';
        }

        function openAdminProductModal(pid, title, price, cat, cond, seller, rep, desc, imagesJson, videoPath, stVal) {
            document.getElementById('admin_modal_pid_approve').value = pid;
            document.getElementById('admin_modal_pid_ban').value = pid;
            document.getElementById('admin_modal_title').textContent = title;
            document.getElementById('admin_modal_price').textContent = price;
            document.getElementById('admin_modal_cat').textContent = cat;
            document.getElementById('admin_modal_cond').textContent = cond;
            document.getElementById('admin_modal_seller').textContent = seller;
            document.getElementById('admin_modal_rep').textContent = rep;
            document.getElementById('admin_modal_desc').textContent = desc;

            const largeImg = document.getElementById('admin_modal_img');
            const largeVideo = document.getElementById('admin_modal_video');
            const thumbStrip = document.getElementById('admin_modal_thumb_strip');

            thumbStrip.innerHTML = '';
            largeVideo.style.display = 'none';
            largeVideo.pause();
            largeImg.style.display = 'block';

            let images = [];
            try {
                images = JSON.parse(imagesJson);
            } catch(e) {}

            if (images.length > 0) {
                largeImg.src = images[0];
            } else {
                largeImg.src = '';
            }

            images.forEach((imgUrl, i) => {
                const thumb = document.createElement('img');
                thumb.src = imgUrl;
                thumb.style.cssText = 'width: 50px; height: 50px; object-fit: cover; border-radius: 8px; cursor: pointer; border: ' + (i === 0 ? '2px solid #0284c7' : '1px solid #cbd5e1') + '; opacity: ' + (i === 0 ? '1' : '0.7') + '; transition: all 0.2s;';
                thumb.onclick = function() {
                    largeVideo.style.display = 'none';
                    largeVideo.pause();
                    largeImg.style.display = 'block';
                    largeImg.src = imgUrl;
                    Array.from(thumbStrip.children).forEach(t => { t.style.border = '1px solid #cbd5e1'; t.style.opacity = '0.7'; });
                    thumb.style.border = '2px solid #0284c7';
                    thumb.style.opacity = '1';
                };
                thumbStrip.appendChild(thumb);
            });

            if (videoPath) {
                const vidBtn = document.createElement('div');
                vidBtn.style.cssText = 'width: 50px; height: 50px; border-radius: 8px; cursor: pointer; border: 1px solid #cbd5e1; background: #0f172a; color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; opacity: 0.85;';
                vidBtn.textContent = 'VIDEO';

                vidBtn.onclick = function() {
                    largeImg.style.display = 'none';
                    largeVideo.style.display = 'block';
                    largeVideo.src = videoPath;
                    largeVideo.play();
                    Array.from(thumbStrip.children).forEach(t => { t.style.border = '1px solid #cbd5e1'; t.style.opacity = '0.7'; });
                    vidBtn.style.border = '2px solid #0284c7';
                    vidBtn.style.opacity = '1';
                };
                thumbStrip.appendChild(vidBtn);
            }

            document.getElementById('adminProductModal').style.display = 'flex';
        }

        function closeAdminProductModal() {
            const videoPlayer = document.getElementById('admin_modal_video');
            if (videoPlayer) {
                videoPlayer.pause();
                videoPlayer.src = '';
            }
            document.getElementById('adminProductModal').style.display = 'none';
        }

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
