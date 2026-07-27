<?php
require_once 'config/config.php';

// Kiểm tra đăng nhập và lấy thông tin tài khoản
requireLogin();
$user_id = $_SESSION['user_id'];

$is_logged_in = true;
$db_error = false;
$db_error_message = '';
$error = '';
$success = '';

try {
    $db = getDBConnection();
    
    // Truy vấn dữ liệu mới nhất từ CSDL
    $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
    $stmt->execute(['id' => $user_id]);
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
        $role_stmt->execute(['id' => $user_id]);
        $user_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Khởi tạo CSRF Token nếu chưa có
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Tự động seed các quyền hạn mới và gán cho ADMIN nếu chưa có
        $required_perms = [
            ['name' => 'product.view', 'desc' => 'Quyền xem danh sách và chi tiết sản phẩm'],
            ['name' => 'product.create', 'desc' => 'Quyền đăng bán sản phẩm mới'],
            ['name' => 'product.update', 'desc' => 'Quyền phê duyệt hoặc cập nhật trạng thái sản phẩm'],
            ['name' => 'product.delete', 'desc' => 'Quyền xóa sản phẩm khỏi hệ thống'],
            ['name' => 'user.view', 'desc' => 'Quyền xem danh sách tài khoản'],
            ['name' => 'user.lock', 'desc' => 'Quyền khóa hoặc mở khóa tài khoản'],
            ['name' => 'role.create', 'desc' => 'Quyền tạo vai trò mới'],
            ['name' => 'role.update', 'desc' => 'Quyền chỉnh sửa thông tin vai trò'],
            ['name' => 'role.assign', 'desc' => 'Quyền gán vai trò cho tài khoản'],
            ['name' => 'permission.create', 'desc' => 'Quyền tạo quyền hạn mới'],
            ['name' => 'role.permission.update', 'desc' => 'Quyền cập nhật ma trận phân quyền']
        ];

        $ins_perm_stmt = $db->prepare("INSERT IGNORE INTO `Quyen` (`TenQuyen`, `MoTa`) VALUES (:name, :desc)");
        foreach ($required_perms as $rp) {
            $ins_perm_stmt->execute(['name' => $rp['name'], 'desc' => $rp['desc']]);
        }

        // Gán tất cả các quyền cho vai trò ADMIN
        $admin_role_id_stmt = $db->query("SELECT `MaVaiTro` FROM `VaiTro` WHERE `TenVaiTro` = 'ADMIN' LIMIT 1");
        $admin_role_id = $admin_role_id_stmt->fetchColumn();
        if ($admin_role_id) {
            $all_perm_ids = $db->query("SELECT `MaQuyen` FROM `Quyen`")->fetchAll(PDO::FETCH_COLUMN);
            $ins_vp_stmt = $db->prepare("INSERT IGNORE INTO `VaiTro_Quyen` (`MaVaiTro`, `MaQuyen`) VALUES (:rid, :pid)");
            foreach ($all_perm_ids as $pid) {
                $ins_vp_stmt->execute(['rid' => $admin_role_id, 'pid' => $pid]);
            }
        }

        // KIỂM TRA QUYỀN ADMIN (Nếu không có quyền -> Trả về HTTP 403)
        if (!in_array('ADMIN', $user_roles)) {
            writeSecurityLog("Unauthorized access attempt to admin.php by User ID $user_id");
            http_response_code(403);
            die("
            <!DOCTYPE html>
            <html lang='vi'>
            <head>
                <meta charset='UTF-8'>
                <title>Lỗi Quyền Truy Cập - Chợ Đồ Cũ</title>
                <link href='https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap' rel='stylesheet'>
                <link rel='stylesheet' href='assets/css/style.css'>
                <style>
                    .container { max-width: 500px; margin: 80px auto; }
                    .card { text-align: center; }
                    .error-icon { font-size: 4rem; color: #ef4444; margin-bottom: 20px; }
                    .btn { margin-top: 24px; display: inline-block; text-decoration: none; }
                </style>
            </head>
            <body>
                <div class='background-decor'></div>
                <div class='container'>
                    <div class='card'>
                        <div class='error-icon'>🚫</div>
                        <h2>Không Có Quyền Truy Cập</h2>
                        <p style='margin-top: 15px;'>Tài khoản của bạn không được cấp quyền truy cập trang quản trị Admin.</p>
                        <a href='index.php' class='btn btn-primary'>Quay Lại Trang Chủ</a>
                    </div>
                </div>
            </body>
            </html>
            ");
        }
    } else {
        $_SESSION = [];
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
        // Kiểm tra CSRF Token cho mọi form POST
        $post_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $post_token)) {
            throw new Exception("Yêu cầu không hợp lệ (Lỗi CSRF Token). Vui lòng tải lại trang.");
        }

        if ($_POST['action'] === 'approve_product' && isset($_POST['product_id'])) {
            requirePermission('product.update');
            $pid = (int)$_POST['product_id'];
            $st = $db->prepare("UPDATE `SanPham` SET `TrangThaiDuyet` = b'01' WHERE `MaSanPham` = :pid");
            $st->execute(['pid' => $pid]);
            $success = "Đã duyệt bài đăng #" . $pid . ". Sản phẩm đã sẵn sàng hiển thị trên trang chủ.";
        }

        if ($_POST['action'] === 'ban_product' && isset($_POST['product_id'])) {
            requirePermission('product.update');
            $pid = (int)$_POST['product_id'];
            $st = $db->prepare("UPDATE `SanPham` SET `TrangThaiDuyet` = b'10' WHERE `MaSanPham` = :pid");
            $st->execute(['pid' => $pid]);
            $success = "Đã cấm/từ chối sản phẩm #" . $pid . ". Bài đăng sẽ bị ẩn khỏi trang chủ.";
        }

        if ($_POST['action'] === 'pend_product' && isset($_POST['product_id'])) {
            requirePermission('product.update');
            $pid = (int)$_POST['product_id'];
            $st = $db->prepare("UPDATE `SanPham` SET `TrangThaiDuyet` = b'00' WHERE `MaSanPham` = :pid");
            $st->execute(['pid' => $pid]);
            $success = "Đã chuyển sản phẩm #" . $pid . " về trạng thái Chờ duyệt.";
        }

        if ($_POST['action'] === 'toggle_product_status' && isset($_POST['product_id'])) {
            requirePermission('product.update');
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
            requirePermission('product.delete');
            $pid = (int)$_POST['product_id'];
            $del_stmt = $db->prepare("DELETE FROM `SanPham` WHERE `MaSanPham` = :pid");
            $del_stmt->execute(['pid' => $pid]);
            $success = "Đã xóa sản phẩm #" . $pid;
        }

        if ($_POST['action'] === 'toggle_user_status' && isset($_POST['user_id'])) {
            requirePermission('user.lock');
            $uid = (int)$_POST['user_id'];
            $current_st = (int)$_POST['current_status'];
            $new_st = $current_st === 1 ? 0 : 1;

            if ($uid === (int)$user_id) {
                throw new Exception("Bạn không thể tự khóa tài khoản của chính mình!");
            }

            // Kiểm tra xem user bị khóa có phải ADMIN và là admin hoạt động duy nhất không
            $user_roles_stmt = $db->prepare("
                SELECT vt.TenVaiTro 
                FROM `NguoiDung_VaiTro` ndvt 
                JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro 
                WHERE ndvt.MaNguoiDung = :uid
            ");
            $user_roles_stmt->execute(['uid' => $uid]);
            $target_user_roles = $user_roles_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('ADMIN', $target_user_roles) && $new_st === 0) {
                $active_admins_stmt = $db->query("
                    SELECT COUNT(DISTINCT nd.MaNguoiDung)
                    FROM `NguoiDung` nd
                    JOIN `NguoiDung_VaiTro` ndvt ON nd.MaNguoiDung = ndvt.MaNguoiDung
                    JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro
                    WHERE vt.TenVaiTro = 'ADMIN' AND (nd.TrangThaiTaiKhoan IS NULL OR nd.TrangThaiTaiKhoan = 1)
                ");
                $active_admins = (int)$active_admins_stmt->fetchColumn();
                if ($active_admins <= 1) {
                    throw new Exception("Không thể khóa tài khoản ADMIN duy nhất còn hoạt động trong hệ thống!");
                }
            }

            $sql = ($new_st === 1) 
                ? "UPDATE `NguoiDung` SET `TrangThaiTaiKhoan` = b'1' WHERE `MaNguoiDung` = :uid" 
                : "UPDATE `NguoiDung` SET `TrangThaiTaiKhoan` = b'0' WHERE `MaNguoiDung` = :uid";

            $user_st = $db->prepare($sql);
            $user_st->bindValue(':uid', $uid, PDO::PARAM_INT);
            $user_st->execute();
            $success = "Đã cập nhật trạng thái tài khoản người dùng #" . $uid;
        }

        if ($_POST['action'] === 'create_role') {
            requirePermission('role.create');
            $role_name = strtoupper(trim($_POST['role_name'] ?? ''));
            $role_desc = trim($_POST['role_desc'] ?? '');

            if (empty($role_name)) {
                throw new Exception("Tên vai trò không được để trống.");
            }

            $ins_role = $db->prepare("INSERT INTO `VaiTro` (`TenVaiTro`, `MoTa`) VALUES (:name, :desc)");
            $ins_role->execute(['name' => $role_name, 'desc' => $role_desc]);
            $success = "Đã tạo vai trò mới `" . htmlspecialchars($role_name) . "` thành công.";
        }

        if ($_POST['action'] === 'create_permission') {
            requirePermission('permission.create');
            $perm_name = trim($_POST['perm_name'] ?? '');
            $perm_desc = trim($_POST['perm_desc'] ?? '');

            if (empty($perm_name)) {
                throw new Exception("Tên quyền hạn không được để trống.");
            }

            $ins_perm = $db->prepare("INSERT INTO `Quyen` (`TenQuyen`, `MoTa`) VALUES (:name, :desc)");
            $ins_perm->execute(['name' => $perm_name, 'desc' => $perm_desc]);
            $success = "Đã tạo quyền hạn mới `" . htmlspecialchars($perm_name) . "` thành công.";
        }

        if ($_POST['action'] === 'assign_role_permissions' && isset($_POST['role_id'])) {
            requirePermission('role.permission.update');
            $rid = (int)$_POST['role_id'];
            $selected_perms = $_POST['role_permissions'] ?? [];

            $db->beginTransaction();
            try {
                $del_stmt = $db->prepare("DELETE FROM `VaiTro_Quyen` WHERE `MaVaiTro` = :rid");
                $del_stmt->execute(['rid' => $rid]);

                if (!empty($selected_perms)) {
                    $ins_stmt = $db->prepare("INSERT INTO `VaiTro_Quyen` (`MaVaiTro`, `MaQuyen`) VALUES (:rid, :pid)");
                    foreach ($selected_perms as $pid) {
                        $ins_stmt->execute(['rid' => $rid, 'pid' => (int)$pid]);
                    }
                }
                $db->commit();
                $success = "Đã cập nhật danh sách quyền cho vai trò #" . $rid;
            } catch (Exception $ex) {
                $db->rollBack();
                throw $ex;
            }
        }

        if ($_POST['action'] === 'assign_user_roles' && isset($_POST['user_id'])) {
            requirePermission('role.assign');
            $uid = (int)$_POST['user_id'];
            $selected_roles = $_POST['user_roles'] ?? [];

            $admin_rid_stmt = $db->query("SELECT MaVaiTro FROM VaiTro WHERE TenVaiTro = 'ADMIN' LIMIT 1");
            $admin_rid = (int)$admin_rid_stmt->fetchColumn();

            if ($uid === (int)$user_id && !in_array($admin_rid, $selected_roles)) {
                throw new Exception("Bạn không thể tự gỡ bỏ vai trò ADMIN của chính mình!");
            }

            $has_admin_stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM `NguoiDung_VaiTro` ndvt
                JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro
                WHERE ndvt.MaNguoiDung = :uid AND vt.TenVaiTro = 'ADMIN'
            ");
            $has_admin_stmt->execute(['uid' => $uid]);
            $user_had_admin = (int)$has_admin_stmt->fetchColumn() > 0;

            if ($user_had_admin && !in_array($admin_rid, $selected_roles)) {
                $active_admins_stmt = $db->query("
                    SELECT COUNT(DISTINCT nd.MaNguoiDung)
                    FROM `NguoiDung` nd
                    JOIN `NguoiDung_VaiTro` ndvt ON nd.MaNguoiDung = ndvt.MaNguoiDung
                    JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro
                    WHERE vt.TenVaiTro = 'ADMIN' AND (nd.TrangThaiTaiKhoan IS NULL OR nd.TrangThaiTaiKhoan = 1)
                ");
                $active_admins = (int)$active_admins_stmt->fetchColumn();
                if ($active_admins <= 1) {
                    throw new Exception("Không thể gỡ bỏ vai trò ADMIN của tài khoản quản trị duy nhất còn hoạt động!");
                }
            }

            $db->beginTransaction();
            try {
                $del_stmt = $db->prepare("DELETE FROM `NguoiDung_VaiTro` WHERE `MaNguoiDung` = :uid");
                $del_stmt->execute(['uid' => $uid]);

                if (!empty($selected_roles)) {
                    $ins_stmt = $db->prepare("INSERT INTO `NguoiDung_VaiTro` (`MaNguoiDung`, `MaVaiTro`) VALUES (:uid, :rid)");
                    foreach ($selected_roles as $rid) {
                        $ins_stmt->execute(['uid' => $uid, 'rid' => (int)$rid]);
                    }
                }
                $db->commit();
                $success = "Đã cập nhật vai trò cho người dùng #" . $uid;
            } catch (Exception $ex) {
                $db->rollBack();
                throw $ex;
            }
        }

        if ($_POST['action'] === 'update_permissions_matrix') {
            requirePermission('role.permission.update');
            $matrix = $_POST['matrix_perms'] ?? [];

            $db->beginTransaction();
            try {
                $role_ids = $db->query("SELECT MaVaiTro FROM VaiTro")->fetchAll(PDO::FETCH_COLUMN);

                foreach ($role_ids as $rid) {
                    $del = $db->prepare("DELETE FROM `VaiTro_Quyen` WHERE `MaVaiTro` = :rid");
                    $del->execute(['rid' => $rid]);

                    if (isset($matrix[$rid]) && is_array($matrix[$rid])) {
                        $ins = $db->prepare("INSERT INTO `VaiTro_Quyen` (`MaVaiTro`, `MaQuyen`) VALUES (:rid, :pid)");
                        foreach ($matrix[$rid] as $pid) {
                            $ins->execute(['rid' => $rid, 'pid' => (int)$pid]);
                        }
                    }
                }
                $db->commit();
                $success = "Đã cập nhật ma trận phân quyền thành công.";
            } catch (Exception $ex) {
                $db->rollBack();
                throw $ex;
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

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'message' => $success ?? 'Thao tác thành công.'
            ]);
            exit;
        }
    } catch (Exception $ex) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => $ex->getMessage()
            ]);
            exit;
        }
        $error = "Lỗi thao tác: " . $ex->getMessage();
    }
}

// Phân trang danh sách sản phẩm (hỗ trợ nhập số, chọn nhanh, hoặc tất cả 'all')
$limit_param = $_GET['limit'] ?? '10';
if ($limit_param === 'all') {
    $limit = 999999;
    $page = 1;
    $offset = 0;
} else {
    $limit = (int)$limit_param;
    if ($limit < 1) {
        $limit = 10;
        $limit_param = '10';
    }
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    $offset = ($page - 1) * $limit;
}

// Phân trang, Tìm kiếm, Lọc trạng thái danh sách tài khoản (NguoiDung)
$user_limit_param = $_GET['user_limit'] ?? '10';
if ($user_limit_param === 'all') {
    $user_limit = 999999;
    $user_page = 1;
    $user_offset = 0;
} else {
    $user_limit = (int)$user_limit_param;
    if ($user_limit < 1) {
        $user_limit = 10;
        $user_limit_param = '10';
    }
    $user_page = isset($_GET['user_page']) ? (int)$_GET['user_page'] : 1;
    if ($user_page < 1) {
        $user_page = 1;
    }
    $user_offset = ($user_page - 1) * $user_limit;
}

$user_search = trim($_GET['user_search'] ?? '');
$user_status_filter = $_GET['user_status'] ?? 'active'; // Mặc định hiển thị tab Hoạt động

// Lấy danh sách thống kê
$total_users = 0;
$total_products = 0;
$pending_products = 0;
$user_list = [];
$product_list = [];
$category_list = [];

$total_pending_global = 0;
$total_selling_global = 0;
$total_banned_global = 0;

try {
    $total_users = $db->query("SELECT COUNT(*) FROM `NguoiDung`")->fetchColumn();
    $total_products = $db->query("SELECT COUNT(*) FROM `SanPham`")->fetchColumn();
    $pending_products = $db->query("SELECT COUNT(*) FROM `SanPham` WHERE `TrangThaiDuyet` = b'00'")->fetchColumn();

    $total_pending_global = $pending_products;
    $total_selling_global = $db->query("SELECT COUNT(*) FROM `SanPham` WHERE `TrangThaiDuyet` = b'01'")->fetchColumn();
    $total_banned_global = $db->query("SELECT COUNT(*) FROM `SanPham` WHERE `TrangThaiDuyet` = b'10'")->fetchColumn();

    // Điểm đếm global cho các sub-tabs của User
    $global_active_count = $db->query("SELECT COUNT(*) FROM `NguoiDung` WHERE `TrangThaiTaiKhoan` IS NULL OR `TrangThaiTaiKhoan` = b'1' OR `TrangThaiTaiKhoan` = 1")->fetchColumn();
    $global_banned_count = $db->query("SELECT COUNT(*) FROM `NguoiDung` WHERE `TrangThaiTaiKhoan` = b'0' OR `TrangThaiTaiKhoan` = 0")->fetchColumn();

    // Xây dựng câu SQL lọc và phân trang tài khoản
    $user_where_clauses = [];
    $user_params = [];

    if (!empty($user_search)) {
        $user_where_clauses[] = "(nd.TenDangNhap LIKE :search OR nd.Email LIKE :search OR nd.HoTen LIKE :search)";
        $user_params['search'] = '%' . $user_search . '%';
    }

    if ($user_status_filter === 'active') {
        $user_where_clauses[] = "(nd.TrangThaiTaiKhoan IS NULL OR nd.TrangThaiTaiKhoan = b'1' OR nd.TrangThaiTaiKhoan = 1)";
    } elseif ($user_status_filter === 'banned') {
        $user_where_clauses[] = "(nd.TrangThaiTaiKhoan = b'0' OR nd.TrangThaiTaiKhoan = 0)";
    }

    $user_where_sql = "";
    if (!empty($user_where_clauses)) {
        $user_where_sql = "WHERE " . implode(" AND ", $user_where_clauses);
    }

    // Đếm số lượng tài khoản sau khi lọc để làm phân trang
    $count_user_sql = "
        SELECT COUNT(DISTINCT nd.MaNguoiDung)
        FROM `NguoiDung` nd
        $user_where_sql
    ";
    $count_user_stmt = $db->prepare($count_user_sql);
    $count_user_stmt->execute($user_params);
    $total_filtered_users = $count_user_stmt->fetchColumn();

    // Lấy danh sách tài khoản phân trang
    $user_sql = "
        SELECT nd.*, GROUP_CONCAT(vt.TenVaiTro SEPARATOR ', ') as DanhSachVaiTro
        FROM `NguoiDung` nd
        LEFT JOIN `NguoiDung_VaiTro` ndvt ON nd.MaNguoiDung = ndvt.MaNguoiDung
        LEFT JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro
        $user_where_sql
        GROUP BY nd.MaNguoiDung
        ORDER BY nd.NgayTao DESC
        LIMIT :limit OFFSET :offset
    ";

    $user_stmt = $db->prepare($user_sql);
    foreach ($user_params as $key => $val) {
        $user_stmt->bindValue(':' . $key, $val);
    }
    $user_stmt->bindValue(':limit', $user_limit, PDO::PARAM_INT);
    $user_stmt->bindValue(':offset', $user_offset, PDO::PARAM_INT);
    $user_stmt->execute();
    $user_list = $user_stmt->fetchAll();

    // Hàm check trạng thái tài khoản hoạt động
    function isUserActiveVal($status_val) {
        if (is_null($status_val)) {
            return true;
        } elseif (is_int($status_val)) {
            return $status_val === 1;
        } elseif (is_string($status_val)) {
            if (strlen($status_val) === 1) {
                return (ord($status_val) === 1 || $status_val === '1');
            } else {
                return ($status_val === '1');
            }
        } else {
            return (bool)$status_val;
        }
    }

    $active_users_list = [];
    $banned_users_list = [];
    foreach ($user_list as $u) {
        if (isUserActiveVal($u['TrangThaiTaiKhoan'])) {
            $active_users_list[] = $u;
        } else {
            $banned_users_list[] = $u;
        }
    }

    // Lấy danh sách sản phẩm kèm ảnh và người bán (Phân trang bằng LIMIT OFFSET)
    $product_sql = "
        SELECT sp.*, nd.HoTen as TenNguoiBan, nd.DiemUyTin, dm.TenDanhMuc
        FROM `SanPham` sp
        JOIN `NguoiDung` nd ON sp.MaNguoiBan = nd.MaNguoiDung
        JOIN `DanhMuc` dm ON sp.MaDanhMuc = dm.MaDanhMuc
        ORDER BY sp.NgayDang DESC
        LIMIT :limit OFFSET :offset
    ";
    $product_stmt = $db->prepare($product_sql);
    $product_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $product_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $product_stmt->execute();
    $product_list = $product_stmt->fetchAll();

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

    // Hàm giải mã giá trị cột kiểu BIT(2) an toàn đa tương thích (tránh lỗi ord() trả về 49 với ký tự '1')
    function decodeProductStatus($val) {
        if (is_null($val)) return 0;
        if (is_int($val)) return $val;
        if (is_string($val)) {
            if (strlen($val) === 1) {
                $o = ord($val);
                if ($o === 1 || $val === '1') return 1;
                if ($o === 2 || $val === '2') return 2;
                if ($o === 0 || $val === '0') return 0;
                return $o;
            }
            return (int)$val;
        }
        return (int)$val;
    }

    // Phân loại danh sách sản phẩm phục vụ hiển thị sub-tabs
    $pending_list = [];
    $selling_list = [];
    $banned_list = [];
    foreach ($product_list as $p) {
        $st_val = decodeProductStatus($p['TrangThaiDuyet'] ?? null);
        if ($st_val === 1) {
            $selling_list[] = $p;
        } elseif ($st_val === 2) {
            $banned_list[] = $p;
        } else {
            $pending_list[] = $p;
        }
    }

    // Hàm helper render bảng danh sách sản phẩm để tránh trùng lặp mã
    function renderProductsTable($list, $title_if_empty) {
        ?>
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
                <?php if (empty($list)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;"><?php echo htmlspecialchars($title_if_empty); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list as $p): ?>
                        <?php 
                            $st_val = decodeProductStatus($p['TrangThaiDuyet'] ?? null);
                            $img_json = htmlspecialchars(json_encode($p['Images'] ?? []), ENT_QUOTES, 'UTF-8');
                            $vid_path = htmlspecialchars($p['VideoThucTe'] ?? '', ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr class="product-row" id="product-row-<?php echo $p['MaSanPham']; ?>" data-title="<?php echo htmlspecialchars(mb_strtolower($p['TenSanPham'] ?? '', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-seller="<?php echo htmlspecialchars(mb_strtolower($p['TenNguoiBan'] ?? '', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-category="<?php echo htmlspecialchars($p['TenDanhMuc'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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
                                <a href="seller.php?id=<?php echo $p['MaNguoiBan']; ?>" target="_blank" style="text-decoration: none; color: var(--primary); font-weight: 700; transition: color 0.2s;" onmouseover="this.style.color='#0369a1'" onmouseout="this.style.color='var(--primary)'">
                                    <?php echo htmlspecialchars($p['TenNguoiBan']); ?>
                                </a>
                                <div style="font-size: 0.75rem; color: #d97706;"><?php echo $p['DiemUyTin']; ?> Uy Tín</div>
                            </td>
                            <td>
                                <?php if ($st_val === 1): ?>
                                    <span class="badge badge-success">✓ Đã duyệt</span>
                                <?php elseif ($st_val === 2): ?>
                                    <span class="badge badge-danger">🚫 Đã cấm</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Chờ duyệt</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <button type="button" class="btn-action" style="background: #f1f5f9; color: #475569;" onclick="openAdminProductModal(<?php echo $p['MaSanPham']; ?>, '<?php echo addslashes(htmlspecialchars($p['TenSanPham'])); ?>', '<?php echo number_format($p['GiaBan'], 0, ',', '.'); ?> đ', '<?php echo addslashes(htmlspecialchars($p['TenDanhMuc'])); ?>', '<?php echo addslashes(htmlspecialchars($p['TinhTrang'])); ?>', '<?php echo addslashes(htmlspecialchars($p['TenNguoiBan'])); ?>', '<?php echo $p['DiemUyTin']; ?>', '<?php echo addslashes(htmlspecialchars($p['MoTaChiTiet'] ?? 'Chưa có mô tả')); ?>', '<?php echo $img_json; ?>', '<?php echo $vid_path; ?>', <?php echo $st_val; ?>)">Xem</button>

                                    <form method="POST" style="display: <?php echo ($st_val === 1) ? 'none !important' : 'inline'; ?>;" class="approve-form" onsubmit="handleProductActionAjax(event, this, <?php echo $p['MaSanPham']; ?>, 'approve')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                        <input type="hidden" name="action" value="approve_product">
                                        <input type="hidden" name="product_id" value="<?php echo $p['MaSanPham']; ?>">
                                        <button type="submit" class="btn-action" style="background: #f1f5f9; color: #475569;" <?php echo !hasPermission($_SESSION['user_id'], 'product.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Duyệt bài</button>
                                    </form>

                                    <form method="POST" style="display: <?php echo ($st_val === 2) ? 'none !important' : 'inline'; ?>;" class="ban-form" onsubmit="handleProductActionAjax(event, this, <?php echo $p['MaSanPham']; ?>, 'ban')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                        <input type="hidden" name="action" value="ban_product">
                                        <input type="hidden" name="product_id" value="<?php echo $p['MaSanPham']; ?>">
                                        <button type="submit" class="btn-action" style="background: #f1f5f9; color: #475569;" <?php echo !hasPermission($_SESSION['user_id'], 'product.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Cấm bài</button>
                                    </form>

                                    <form method="POST" style="display: inline;" class="delete-form" onsubmit="handleProductActionAjax(event, this, <?php echo $p['MaSanPham']; ?>, 'delete')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
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
        <?php
    }

    // Hàm helper render bảng danh sách người dùng để tránh trùng lặp mã
    function renderUsersTable($list, $title_if_empty) {
        ?>
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
                <?php if (empty($list)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;"><?php echo htmlspecialchars($title_if_empty); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list as $u): ?>
                        <?php 
                            $status_val = $u['TrangThaiTaiKhoan'] ?? null;
                            $is_active = isUserActiveVal($status_val);
                            $has_admin = str_contains($u['DanhSachVaiTro'] ?? '', 'ADMIN');
                        ?>
                        <tr class="user-row" data-name="<?php echo htmlspecialchars(mb_strtolower($u['HoTen'] ?? '', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-username="<?php echo htmlspecialchars(mb_strtolower($u['TenDangNhap'] ?? '', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-email="<?php echo htmlspecialchars(mb_strtolower($u['Email'] ?? '', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
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
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc chắn muốn <?php echo $is_active ? 'khóa' : 'mở khóa'; ?> tài khoản này không?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                        <input type="hidden" name="action" value="toggle_user_status">
                                        <input type="hidden" name="user_id" value="<?php echo $u['MaNguoiDung']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $is_active ? 1 : 0; ?>">
                                        <button type="submit" class="btn-action" style="background: <?php echo $is_active ? '#fee2e2; color: #b91c1c;' : '#dcfce7; color: #15803d;'; ?>" <?php echo !hasPermission($_SESSION['user_id'], 'user.lock') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                                            <?php echo $is_active ? 'Khóa TK' : 'Mở khóa'; ?>
                                        </button>
                                    </form>

                                    <button type="button" class="btn-action" style="background: #e0e7ff; color: #4338ca;" onclick="openAssignRolesModal(<?php echo $u['MaNguoiDung']; ?>, '<?php echo addslashes(htmlspecialchars($u['HoTen'])); ?>', '<?php echo addslashes(htmlspecialchars($u['DanhSachVaiTro'] ?? '')); ?>')">Phân vai trò</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    // Lấy danh sách tất cả các quyền hạn (Permissions)
    $all_permissions = $db->query("SELECT * FROM `Quyen` ORDER BY `TenQuyen` ASC")->fetchAll();
    // Lấy danh sách tất cả các vai trò (Roles) và các quyền hạn được gán
    $all_roles = $db->query("SELECT * FROM `VaiTro` ORDER BY `TenVaiTro` ASC")->fetchAll();
    foreach ($all_roles as &$role) {
        $perm_stmt = $db->prepare("
            SELECT q.TenQuyen 
            FROM `VaiTro_Quyen` vtq
            JOIN `Quyen` q ON vtq.MaQuyen = q.MaQuyen
            WHERE vtq.MaVaiTro = :rid
        ");
        $perm_stmt->execute(['rid' => $role['MaVaiTro']]);
        $role['Permissions'] = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($role);
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
    <!-- Google Fonts Inter & Be Vietnam Pro -->
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>

<body>
    <!-- Background hiệu ứng mờ -->
    <div class="background-decor"></div>

    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="dashboard-sidebar">
            <div class="sidebar-brand">
                <a href="index.php" onclick="sessionStorage.removeItem('admin_active_tab'); sessionStorage.removeItem('admin_active_tab_title');">Chợ Đồ Cũ <span class="brand-badge">ADMIN</span></a>
            </div>
            
            <div class="sidebar-user">
                <?php if (!empty($user_data['google_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($user_data['google_picture']); ?>" alt="Avatar" class="user-avatar-md">
                <?php else: ?>
                    <div class="user-avatar-md-fallback">
                        <?php echo strtoupper(substr($user_data['HoTen'] ?? 'U', 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <h4 class="user-name"><?php echo htmlspecialchars($user_data['HoTen'] ?? 'Admin'); ?></h4>
                    <span class="user-role">Quản trị viên</span>
                </div>
            </div>

            <nav class="sidebar-menu">
                <button class="menu-item active" id="menu-overview" onclick="switchTab('overview-tab', this, 'Tổng Quan Hệ Thống')">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </span> Tổng quan
                </button>
                <button class="menu-item" id="menu-products" onclick="switchTab('products-tab', this, 'Quản Lý Sản Phẩm')">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line><polygon points="12 22.08 12 12 3 6.92 3 17.08 12 22.08"></polygon><polygon points="12 12 21 6.92 21 17.08 12 22.08"></polygon><polygon points="12 2 3 6.92 12 12 21 6.92 12 2"></polygon><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    </span> Quản lý sản phẩm
                </button>
                <button class="menu-item" id="menu-users" onclick="switchTab('users-tab', this, 'Quản Lý Tài Khoản và Quyền')">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </span> Quản lý tài khoản và phân quyền
                </button>
                <button class="menu-item" id="menu-categories" onclick="switchTab('categories-tab', this, 'Quản Lý Danh Mục')">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    </span> Quản lý danh mục
                </button>
                
                <div class="menu-divider"></div>
                
                <a href="index.php" class="menu-item" onclick="sessionStorage.removeItem('admin_active_tab'); sessionStorage.removeItem('admin_active_tab_title');">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    </span> Về trang chủ
                </a>
                <button onclick="sessionStorage.removeItem('admin_active_tab'); sessionStorage.removeItem('admin_active_tab_title'); const f = document.createElement('form'); f.method = 'POST'; f.action = 'logout.php'; const i = document.createElement('input'); i.type = 'hidden'; i.name = 'csrf_token'; i.value = '<?php echo getCsrfToken(); ?>'; f.appendChild(i); document.body.appendChild(f); f.submit();" class="menu-item text-danger">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    </span> Đăng xuất
                </button>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <div class="dashboard-main">
            <!-- Top Bar -->
            <header class="dashboard-topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle">☰</button>
                    <span class="topbar-page-title" id="pageTitle">Tổng Quan Hệ Thống</span>
                </div>
                <div class="topbar-right">
                    <a href="profile.php" class="topbar-profile-link">
                        <?php if (!empty($user_data['google_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($user_data['google_picture']); ?>" alt="Avatar" class="user-avatar-sm">
                        <?php else: ?>
                            <span class="user-avatar-sm-fallback"><?php echo strtoupper(substr($user_data['HoTen'] ?? 'U', 0, 1)); ?></span>
                        <?php endif; ?>
                        <span class="topbar-username"><?php echo htmlspecialchars($user_data['HoTen']); ?></span>
                    </a>
                </div>
            </header>

            <!-- Main Content Container -->
            <main class="dashboard-content">
                <!-- Alert Messages -->
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success" style="margin-bottom: 24px; padding: 14px 20px; border-radius: 12px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 0.9rem;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" style="margin-bottom: 24px; padding: 14px 20px; border-radius: 12px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; font-size: 0.9rem;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Tab 0: Overview -->
                <div id="overview-tab" class="tab-content active">
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

                    <!-- Welcome Box -->
                    <div class="admin-table-card" style="margin-top: 24px;">
                        <h3 style="margin-bottom: 16px; font-weight: 700; font-size: 1.1rem; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Chào mừng trở lại, <?php echo htmlspecialchars($user_data['HoTen']); ?>!</h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.6; margin: 0 0 20px 0;">Đây là giao diện quản trị hệ thống Chợ Đồ Cũ. Bạn có thể sử dụng menu bên trái để duyệt và quản lý các bài đăng sản phẩm, phân quyền tài khoản người dùng, hoặc chỉnh sửa các danh mục mua bán trên hệ thống.</p>
                        <a href="seed_products.php" style="display: inline-block; background: #e0f2fe; color: #0369a1; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: 700; font-size: 0.85rem; border: 1px solid #bae6fd; transition: all 0.2s;" onmouseover="this.style.background='#bae6fd'" onmouseout="this.style.background='#e0f2fe'">
                            ⚡ Khởi tạo 100 sản phẩm mẫu ngẫu nhiên (Seed Data)
                        </a>
                    </div>
                </div>

                <!-- Tab 1: Quản lý sản phẩm -->
                <div id="products-tab" class="tab-content">
                    <!-- Tìm kiếm & Lọc sản phẩm -->
                    <div style="display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 280px; position: relative;">
                            <input type="text" id="adminProductSearch" oninput="filterAdminProducts()" placeholder="Tìm kiếm tên sản phẩm hoặc tên người bán..." class="form-control" style="width: 100%; padding: 10px 16px 10px 40px; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.08); font-size: 0.9rem; background: #ffffff;">
                            <span style="position: absolute; left: 14px; top: 12px; display: flex; align-items: center; justify-content: center; width: 16px; height: 16px; color: #0f172a;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </span>
                        </div>
                        <div style="width: 220px;">
                            <select id="adminProductCategory" onchange="filterAdminProducts()" class="form-control" style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.08); font-size: 0.9rem; background: #ffffff; cursor: pointer;">
                                <option value="">Tất cả danh mục</option>
                                <?php foreach ($category_list as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['TenDanhMuc'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cat['TenDanhMuc'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <!-- Sub Tabs Navigation -->
                    <div class="sub-tabs-container" style="display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.06); padding-bottom: 8px;">
                        <button type="button" class="sub-tab-btn active" onclick="switchProductSubTab('product-sub-pending', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Chờ duyệt (<?php echo $total_pending_global; ?>)</button>
                        <button type="button" class="sub-tab-btn" onclick="switchProductSubTab('product-sub-selling', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Đang bán (<?php echo $total_selling_global; ?>)</button>
                        <button type="button" class="sub-tab-btn" onclick="switchProductSubTab('product-sub-banned', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Đã cấm (<?php echo $total_banned_global; ?>)</button>
                    </div>

                    <!-- Sub Content 1: Chờ duyệt -->
                    <div id="product-sub-pending" class="product-sub-content active">
                        <div class="admin-table-card">
                            <?php renderProductsTable($pending_list, 'Không có sản phẩm nào đang chờ duyệt.'); ?>
                        </div>
                    </div>

                    <!-- Sub Content 2: Đang bán -->
                    <div id="product-sub-selling" class="product-sub-content">
                        <div class="admin-table-card">
                            <?php renderProductsTable($selling_list, 'Không có sản phẩm nào đang rao bán.'); ?>
                        </div>
                    </div>

                    <!-- Sub Content 3: Đã cấm -->
                    <div id="product-sub-banned" class="product-sub-content">
                        <div class="admin-table-card">
                            <?php renderProductsTable($banned_list, 'Không có sản phẩm nào bị cấm.'); ?>
                        </div>
                    </div>
                    
                    <!-- Phân Trang Sử Dụng LIMIT OFFSET -->
                    <div class="pagination-container" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; gap: 16px;">
                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                            Hiển thị từ <strong><?php echo min($offset + 1, $total_products); ?></strong> đến <strong><?php echo min($offset + count($product_list), $total_products); ?></strong> trong tổng số <strong><?php echo $total_products; ?></strong> sản phẩm
                        </div>
                        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-size: 0.85rem; color: var(--text-muted);">Số dòng hiển thị:</span>
                                <!-- Tự nhập số -->
                                <input type="number" id="customLimitInput" value="<?php echo ($limit_param === 'all') ? '' : $limit; ?>" placeholder="Nhập số..." min="1" style="width: 80px; padding: 6px 10px; border-radius: 8px; border: 1px solid rgba(0,0,0,0.08); font-size: 0.85rem; text-align: center;" onkeypress="if(event.key === 'Enter') applyCustomLimit()">
                                
                                <!-- Chọn nhanh hoặc tất cả -->
                                <select id="quickLimitSelect" onchange="handleQuickLimit(this.value)" class="form-control" style="width: 110px; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; border: 1px solid rgba(0,0,0,0.08); background: #ffffff; cursor: pointer; appearance: auto;">
                                    <option value="10" <?php echo $limit_param === '10' ? 'selected' : ''; ?>>10 dòng</option>
                                    <option value="20" <?php echo $limit_param === '20' ? 'selected' : ''; ?>>20 dòng</option>
                                    <option value="50" <?php echo $limit_param === '50' ? 'selected' : ''; ?>>50 dòng</option>
                                    <option value="100" <?php echo $limit_param === '100' ? 'selected' : ''; ?>>100 dòng</option>
                                    <option value="all" <?php echo $limit_param === 'all' ? 'selected' : ''; ?>>Tất cả</option>
                                    <?php if (!in_array($limit_param, ['10', '20', '50', '100', 'all'])): ?>
                                        <option value="<?php echo htmlspecialchars($limit_param); ?>" selected>Tùy chọn (<?php echo htmlspecialchars($limit_param); ?>)</option>
                                    <?php endif; ?>
                                </select>
                                <button type="button" onclick="applyCustomLimit()" class="btn-action" style="padding: 6px 12px; background: #e0f2fe; color: #0369a1; border-radius: 8px; font-size: 0.85rem; font-weight: 700; border: none; cursor: pointer;">Áp dụng</button>
                            </div>
                            
                            <?php 
                            $total_pages = ceil($total_products / $limit);
                            if ($limit_param !== 'all' && $total_pages > 1): 
                            ?>
                            <div style="display: flex; gap: 6px;">
                                <?php if ($page > 1): ?>
                                    <a href="admin.php?limit=<?php echo $limit_param; ?>&page=<?php echo $page - 1; ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;">Trước</a>
                                <?php endif; ?>
                                
                                <?php 
                                // Hiển thị các số trang thông minh
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="admin.php?limit='.$limit_param.'&page=1" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem;">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span style="padding: 6px; color: var(--text-muted);">...</span>';
                                    }
                                }
                                
                                for ($p_idx = $start_page; $p_idx <= $end_page; $p_idx++): 
                                    if ($p_idx == $page):
                                ?>
                                    <span style="padding: 6px 12px; background: var(--primary); color: white; border-radius: 8px; font-size: 0.85rem; font-weight: 700; display: inline-block;"><?php echo $p_idx; ?></span>
                                <?php else: ?>
                                    <a href="admin.php?limit=<?php echo $limit_param; ?>&page=<?php echo $p_idx; ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;"><?php echo $p_idx; ?></a>
                                <?php 
                                    endif;
                                endfor; 
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span style="padding: 6px; color: var(--text-muted);">...</span>';
                                    }
                                    echo '<a href="admin.php?limit='.$limit_param.'&page='.$total_pages.'" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem;">'.$total_pages.'</a>';
                                }
                                ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="admin.php?limit=<?php echo $limit_param; ?>&page=<?php echo $page + 1; ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;">Sau</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <!-- Tab 2: Quản lý người dùng -->
            <div id="users-tab" class="tab-content">
                <?php if (!hasPermission($_SESSION['user_id'], 'user.view')): ?>
                    <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 40px; text-align: center; color: var(--text-muted); font-size: 1.1rem;">
                        🚫 Bạn không có quyền xem danh sách người dùng và phân quyền.
                    </div>
                <?php else: ?>
                    <!-- Tìm kiếm tài khoản -->
                    <form method="GET" action="admin.php" id="userFilterForm" style="display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; align-items: center; width: 100%;">
                        <input type="hidden" name="tab" value="users">
                        <input type="hidden" name="user_status" id="userStatusInput" value="<?php echo htmlspecialchars($user_status_filter); ?>">
                        
                        <div style="flex: 1; min-width: 280px; position: relative;">
                            <input type="text" name="user_search" value="<?php echo htmlspecialchars($user_search); ?>" placeholder="Tìm kiếm tên đăng nhập, email hoặc họ tên người dùng..." class="form-control" style="width: 100%; padding: 10px 16px 10px 40px; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.08); font-size: 0.9rem; background: #ffffff;">
                            <span style="position: absolute; left: 14px; top: 12px; display: flex; align-items: center; justify-content: center; width: 16px; height: 16px; color: #0f172a;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </span>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" style="padding: 10px 24px; border-radius: 12px; font-size: 0.9rem; font-weight: 700; width: auto; height: auto; margin: 0;">Tìm kiếm</button>
                        <?php if (!empty($user_search) || $user_status_filter !== 'active'): ?>
                            <a href="admin.php?tab=users" class="btn btn-outline" style="padding: 10px 24px; border-radius: 12px; font-size: 0.9rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; width: auto; background: #f1f5f9; color: #475569; border: none; height: auto; margin: 0;">Xóa bộ lọc</a>
                        <?php endif; ?>
                    </form>

                    <!-- Sub Tabs Navigation for Users -->
                    <div class="sub-tabs-container" style="display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.06); padding-bottom: 8px;">
                        <button type="button" class="user-sub-tab-btn <?php echo ($user_status_filter === 'active') ? 'active' : ''; ?>" onclick="changeUserStatusFilter('active')" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Hoạt động (<?php echo $global_active_count; ?>)</button>
                        <button type="button" class="user-sub-tab-btn <?php echo ($user_status_filter === 'banned') ? 'active' : ''; ?>" onclick="changeUserStatusFilter('banned')" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Bị cấm (<?php echo $global_banned_count; ?>)</button>
                    </div>

                    <!-- Sub Content: Danh sách người dùng -->
                    <div class="admin-table-card">
                        <?php if ($user_status_filter === 'active'): ?>
                            <?php renderUsersTable($active_users_list, 'Không có tài khoản nào đang hoạt động.'); ?>
                        <?php else: ?>
                            <?php renderUsersTable($banned_users_list, 'Không có tài khoản nào bị cấm.'); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Phân Trang Tài Khoản Sử Dụng LIMIT OFFSET -->
                    <div class="pagination-container" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; gap: 16px;">
                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                            Hiển thị từ <strong><?php echo min($user_offset + 1, $total_filtered_users); ?></strong> đến <strong><?php echo min($user_offset + count($user_list), $total_filtered_users); ?></strong> trong tổng số <strong><?php echo $total_filtered_users; ?></strong> tài khoản
                        </div>
                        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-size: 0.85rem; color: var(--text-muted);">Số dòng hiển thị:</span>
                                <select id="userQuickLimitSelect" onchange="handleUserQuickLimit(this.value)" class="form-control" style="width: 110px; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; border: 1px solid rgba(0,0,0,0.08); background: #ffffff; cursor: pointer; appearance: auto;">
                                    <option value="5" <?php echo $user_limit_param === '5' ? 'selected' : ''; ?>>5 dòng</option>
                                    <option value="10" <?php echo $user_limit_param === '10' ? 'selected' : ''; ?>>10 dòng</option>
                                    <option value="20" <?php echo $user_limit_param === '20' ? 'selected' : ''; ?>>20 dòng</option>
                                    <option value="50" <?php echo $user_limit_param === '50' ? 'selected' : ''; ?>>50 dòng</option>
                                    <option value="all" <?php echo $user_limit_param === 'all' ? 'selected' : ''; ?>>Tất cả</option>
                                </select>
                            </div>
                            
                            <?php 
                            $total_user_pages = ceil($total_filtered_users / $user_limit);
                            if ($user_limit_param !== 'all' && $total_user_pages > 1): 
                            ?>
                            <div style="display: flex; gap: 6px;">
                                <?php if ($user_page > 1): ?>
                                    <a href="admin.php?tab=users&user_limit=<?php echo $user_limit_param; ?>&user_page=<?php echo $user_page - 1; ?>&user_search=<?php echo urlencode($user_search); ?>&user_status=<?php echo urlencode($user_status_filter); ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;">Trước</a>
                                <?php endif; ?>
                                
                                <?php 
                                $start_u_page = max(1, $user_page - 2);
                                $end_u_page = min($total_user_pages, $user_page + 2);
                                
                                if ($start_u_page > 1) {
                                    echo '<a href="admin.php?tab=users&user_limit='.$user_limit_param.'&user_page=1&user_search='.urlencode($user_search).'&user_status='.urlencode($user_status_filter).'" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem;">1</a>';
                                    if ($start_u_page > 2) {
                                        echo '<span style="padding: 6px; color: var(--text-muted);">...</span>';
                                    }
                                }
                                
                                for ($u_idx = $start_u_page; $u_idx <= $end_u_page; $u_idx++): 
                                    if ($u_idx == $user_page):
                                ?>
                                    <span style="padding: 6px 12px; background: var(--primary); color: white; border-radius: 8px; font-size: 0.85rem; font-weight: 700; display: inline-block;"><?php echo $u_idx; ?></span>
                                <?php else: ?>
                                    <a href="admin.php?tab=users&user_limit=<?php echo $user_limit_param; ?>&user_page=<?php echo $u_idx; ?>&user_search=<?php echo urlencode($user_search); ?>&user_status=<?php echo urlencode($user_status_filter); ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;"><?php echo $u_idx; ?></a>
                                <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($user_page < $total_user_pages): ?>
                                    <a href="admin.php?tab=users&user_limit=<?php echo $user_limit_param; ?>&user_page=<?php echo $user_page + 1; ?>&user_search=<?php echo urlencode($user_search); ?>&user_status=<?php echo urlencode($user_status_filter); ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;">Sau</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quản lý vai trò và quyền hạn độc lập -->
                    <div style="margin-top: 40px; display: flex; flex-direction: column; gap: 24px;">
                    
                    <!-- Dòng 1: Hai form tạo mới nằm ngang nhau -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; flex-wrap: wrap;">
                        
                        <!-- Form 1: Tạo Quyền Hạn mới -->
                        <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 24px;">
                            <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Tạo Quyền Hạn (Permission) Mới</h3>
                            <form method="POST" action="admin.php">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                <input type="hidden" name="action" value="create_permission">
                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Tên quyền hạn *</label>
                                    <input type="text" name="perm_name" class="form-control" placeholder="VD: order.view" required <?php echo !hasPermission($_SESSION['user_id'], 'permission.create') ? 'disabled' : ''; ?>>
                                </div>
                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Mô tả quyền hạn</label>
                                    <input type="text" name="perm_desc" class="form-control" placeholder="VD: Quyền xem danh sách hóa đơn đơn hàng" <?php echo !hasPermission($_SESSION['user_id'], 'permission.create') ? 'disabled' : ''; ?>>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 12px; font-size: 0.9rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'permission.create') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Tạo Quyền Hạn</button>
                            </form>
                        </div>

                        <!-- Form 2: Tạo Vai Trò mới -->
                        <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 24px;">
                            <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Tạo Vai Trò (Role) Mới</h3>
                            <form method="POST" action="admin.php">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                <input type="hidden" name="action" value="create_role">
                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Tên vai trò *</label>
                                    <input type="text" name="role_name" class="form-control" placeholder="VD: MODERATOR" style="text-transform: uppercase;" required <?php echo !hasPermission($_SESSION['user_id'], 'role.create') ? 'disabled' : ''; ?>>
                                </div>
                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Mô tả vai trò</label>
                                    <input type="text" name="role_desc" class="form-control" placeholder="VD: Kiểm duyệt viên sản phẩm" <?php echo !hasPermission($_SESSION['user_id'], 'role.create') ? 'disabled' : ''; ?>>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 12px; font-size: 0.9rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'role.create') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Tạo Vai Trò</button>
                            </form>
                        </div>
                    </div>

                    <!-- Dòng 2: Ma Trận Vai Trò & Quyền Hạn -->
                    <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 24px;">
                        <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 8px; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Ma Trận Vai Trò & Quyền Hạn</h3>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 20px;">Tích chọn quyền hạn cho từng vai trò và bấm lưu lại.</p>
                        
                        <form method="POST" action="admin.php" onsubmit="return confirm('Bạn có chắc chắn muốn lưu các thay đổi phân quyền trên ma trận này không?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="update_permissions_matrix">
                            <div class="admin-table-card" style="border: none; box-shadow: none; padding: 0; background: transparent; overflow-x: auto; max-height: 480px;">
                                <table class="admin-table" style="font-size: 0.85rem; width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8fafc;">
                                            <th style="padding: 12px; border-bottom: 2px solid #e2e8f0; text-align: left;">Vai Trò</th>
                                            <?php foreach ($all_permissions as $perm): ?>
                                                <th style="padding: 12px; border-bottom: 2px solid #e2e8f0; text-align: center; white-space: nowrap;">
                                                    <span style="font-weight: 700;" title="<?php echo htmlspecialchars($perm['MoTa'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($perm['TenQuyen']); ?>
                                                    </span>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_roles as $role): ?>
                                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                                <td style="padding: 12px;">
                                                    <strong style="color: var(--primary);"><?php echo htmlspecialchars($role['TenVaiTro']); ?></strong>
                                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($role['MoTa'] ?? ''); ?></div>
                                                </td>
                                                <?php foreach ($all_permissions as $perm): ?>
                                                    <?php 
                                                        $is_checked = in_array($perm['TenQuyen'], $role['Permissions'] ?? []);
                                                    ?>
                                                    <td style="padding: 12px; text-align: center;">
                                                        <input type="checkbox" name="matrix_perms[<?php echo $role['MaVaiTro']; ?>][]" value="<?php echo $perm['MaQuyen']; ?>" <?php echo $is_checked ? 'checked' : ''; ?> style="width: 18px; height: 18px; cursor: pointer;">
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top: 20px; width: 100%; padding: 12px; border-radius: 12px; font-size: 0.9rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'role.permission.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Lưu Ma Trận Quyền Hạn</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab 3: Quản lý danh mục -->
            <div id="categories-tab" class="tab-content">
                <!-- Form thêm danh mục mới -->
                <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 24px; margin-bottom: 24px;">
                    <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; color: var(--text-main);">Thêm Danh Mục Sản Phẩm Mới</h3>
                    <form method="POST" action="admin.php" style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 16px; align-items: flex-end;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
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
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
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
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
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

            <!-- Modal Phân Vai Trò Người Dùng -->
            <div id="assignRolesModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 500px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 8px; font-size: 1.2rem; font-family: 'Be Vietnam Pro', sans-serif;">Phân Vai Trò Tài Khoản</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">Tài khoản: <strong id="assign_user_name" style="color: var(--text-main);"></strong></p>
                    
                    <form method="POST" action="admin.php" onsubmit="return confirm('Bạn có chắc chắn muốn cập nhật vai trò cho người dùng này không?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="assign_user_roles">
                        <input type="hidden" name="user_id" id="assign_user_id">
                        
                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 12px;">Chọn Vai Trò (Có thể chọn nhiều):</label>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <?php foreach ($all_roles as $role): ?>
                                    <label style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; cursor: pointer; color: var(--text-main);">
                                        <input type="checkbox" name="user_roles[]" value="<?php echo $role['MaVaiTro']; ?>" id="role_checkbox_<?php echo htmlspecialchars($role['TenVaiTro']); ?>" style="width: 18px; height: 18px; cursor: pointer;">
                                        <span>
                                            <strong><?php echo htmlspecialchars($role['TenVaiTro']); ?></strong>
                                            <span style="font-size: 0.75rem; color: var(--text-muted); display: block;"><?php echo htmlspecialchars($role['MoTa'] ?? 'Không có mô tả'); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeAssignRolesModal()" style="width: auto; padding: 10px 20px;">Hủy</button>
                            <button type="submit" class="btn btn-primary" style="width: auto; padding: 10px 24px; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'role.assign') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Lưu Thay Đổi</button>
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
                                <form method="POST" action="admin.php" style="flex: 1;" onsubmit="handleModalProductActionAjax(event, this, 'approve')">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="action" value="approve_product">
                                    <input type="hidden" name="product_id" id="admin_modal_pid_approve">
                                    <button type="submit" class="btn" style="background: #16a34a; color: #fff; width: 100%; border-radius: 12px; padding: 10px; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'product.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>✓ Duyệt Cho Bán</button>
                                </form>
                                <form method="POST" action="admin.php" style="flex: 1;" onsubmit="handleModalProductActionAjax(event, this, 'ban')">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="action" value="ban_product">
                                    <input type="hidden" name="product_id" id="admin_modal_pid_ban">
                                    <button type="submit" class="btn" style="background: #dc2626; color: #fff; width: 100%; border-radius: 12px; padding: 10px; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'product.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>🚫 Cấm Bài Đăng</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </main>
        </div>
    </div>

    <!-- Script chuyển đổi tab & dropdown & modal -->
    <script>
        // Toggle Sidebar trên thiết bị di động
        const toggleBtn = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.dashboard-sidebar');
        if (toggleBtn && sidebar) {
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                sidebar.classList.toggle('show');
            });
            document.addEventListener('click', () => {
                sidebar.classList.remove('show');
            });
            sidebar.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }

        function switchTab(tabId, btn, title) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.menu-item').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
            if (title) {
                document.getElementById('pageTitle').textContent = title;
            }
            if (sidebar) {
                sidebar.classList.remove('show');
            }
            // Lưu tab hoạt động vào sessionStorage để tránh bị reset về đầu khi reload trang (CRUD)
            sessionStorage.setItem('admin_active_tab', tabId);
            sessionStorage.setItem('admin_active_tab_title', title || '');
        }

        function switchProductSubTab(subTabId, btn) {
            document.querySelectorAll('.product-sub-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.sub-tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(subTabId).classList.add('active');
            btn.classList.add('active');
            // Lưu sub-tab hoạt động vào sessionStorage
            sessionStorage.setItem('admin_product_sub_tab', subTabId);
        }

        function switchUserSubTab(subTabId, btn) {
            document.querySelectorAll('.user-sub-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.user-sub-tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(subTabId).classList.add('active');
            btn.classList.add('active');
            // Lưu sub-tab hoạt động vào sessionStorage
            sessionStorage.setItem('admin_user_sub_tab', subTabId);
        }

        function filterAdminProducts() {
            const searchVal = document.getElementById('adminProductSearch').value.toLowerCase().trim();
            const catVal = document.getElementById('adminProductCategory').value;

            document.querySelectorAll('.product-row').forEach(row => {
                const title = row.getAttribute('data-title') || '';
                const seller = row.getAttribute('data-seller') || '';
                const category = row.getAttribute('data-category') || '';

                const matchesSearch = title.includes(searchVal) || seller.includes(searchVal);
                const matchesCategory = catVal === '' || category === catVal;

                if (matchesSearch && matchesCategory) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            updateProductSubTabCounts();
        }

        function updateProductSubTabCounts() {
            const pendingCount = document.querySelectorAll('#product-sub-pending .product-row:not([style*="display: none"])').length;
            const sellingCount = document.querySelectorAll('#product-sub-selling .product-row:not([style*="display: none"])').length;
            const bannedCount = document.querySelectorAll('#product-sub-banned .product-row:not([style*="display: none"])').length;

            const subBtns = document.querySelectorAll('.sub-tab-btn');
            if (subBtns.length >= 3) {
                subBtns[0].textContent = `Chờ duyệt (${pendingCount})`;
                subBtns[1].textContent = `Đang bán (${sellingCount})`;
                subBtns[2].textContent = `Đã cấm (${bannedCount})`;
            }
        }

        function changeUserStatusFilter(status) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('tab', 'users');
            urlParams.set('user_status', status);
            urlParams.set('user_page', '1');
            // Lấy từ ô input tìm kiếm user_search nếu có
            const searchInput = document.getElementsByName('user_search')[0];
            if (searchInput && searchInput.value.trim() !== '') {
                urlParams.set('user_search', searchInput.value.trim());
            } else {
                urlParams.delete('user_search');
            }
            window.location.search = urlParams.toString();
        }

        function handleUserQuickLimit(val) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('tab', 'users');
            urlParams.set('user_limit', val);
            urlParams.set('user_page', '1');
            window.location.search = urlParams.toString();
        }

        function applyCustomLimit() {
            const inputVal = document.getElementById('customLimitInput').value.trim();
            if (inputVal === '' || isNaN(inputVal) || parseInt(inputVal) <= 0) {
                window.location.href = 'admin.php?limit=all&page=1';
            } else {
                window.location.href = 'admin.php?limit=' + parseInt(inputVal) + '&page=1';
            }
        }

        function handleQuickLimit(val) {
            if (val === 'all') {
                document.getElementById('customLimitInput').value = '';
                window.location.href = 'admin.php?limit=all&page=1';
            } else {
                document.getElementById('customLimitInput').value = val;
                window.location.href = 'admin.php?limit=' + val + '&page=1';
            }
        }

        function handleProductActionAjax(event, form, productId, actionType) {
            event.preventDefault();
            const formData = new FormData(form);
            
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const row = document.getElementById(`product-row-${productId}`);
                    if (!row) return;

                    const badgeCell = row.querySelector('td:nth-child(6)'); // Trạng thái duyệt
                    const approveForm = row.querySelector('.approve-form');
                    const banForm = row.querySelector('.ban-form');

                    if (actionType === 'approve') {
                        // 1. Cập nhật badge thành đã duyệt màu xanh lá
                        badgeCell.innerHTML = '<span class="badge badge-success">✓ Đã duyệt</span>';
                        // 2. Ẩn nút duyệt
                        approveForm.style.setProperty('display', 'none', 'important');
                        // 3. Hiện nút cấm
                        banForm.style.setProperty('display', 'inline-block', 'important');
                        
                        // 4. Di chuyển dòng sang bảng Đang bán
                        const targetTbody = document.querySelector('#product-sub-selling tbody');
                        const emptyRow = targetTbody.querySelector('tr td[colspan]');
                        if (emptyRow) {
                            emptyRow.parentElement.remove();
                        }
                        targetTbody.appendChild(row);

                        // 5. Chuyển sang tab Đang bán
                        const sellingBtn = document.querySelector('.sub-tab-btn[onclick*="product-sub-selling"]');
                        if (sellingBtn) {
                            switchProductSubTab('product-sub-selling', sellingBtn);
                        }
                    } else if (actionType === 'ban') {
                        // 1. Cập nhật badge thành đã cấm màu đỏ
                        badgeCell.innerHTML = '<span class="badge badge-danger">🚫 Đã cấm</span>';
                        // 2. Ẩn nút cấm
                        banForm.style.setProperty('display', 'none', 'important');
                        // 3. Hiện nút duyệt
                        approveForm.style.setProperty('display', 'inline-block', 'important');
                        
                        // 4. Di chuyển dòng sang bảng Đã cấm
                        const targetTbody = document.querySelector('#product-sub-banned tbody');
                        const emptyRow = targetTbody.querySelector('tr td[colspan]');
                        if (emptyRow) {
                            emptyRow.parentElement.remove();
                        }
                        targetTbody.appendChild(row);

                        // 5. Chuyển sang tab Đã cấm
                        const bannedBtn = document.querySelector('.sub-tab-btn[onclick*="product-sub-banned"]');
                        if (bannedBtn) {
                            switchProductSubTab('product-sub-banned', bannedBtn);
                        }
                    } else if (actionType === 'delete') {
                        row.remove();
                    }

                    // Cập nhật lại số đếm trên các sub-tab
                    updateProductSubTabCounts();
                } else {
                    alert('Lỗi thao tác: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('Có lỗi mạng xảy ra.');
            });
        }

        function handleModalProductActionAjax(event, form, actionType) {
            event.preventDefault();
            const productId = form.querySelector('input[name="product_id"]').value;
            const formData = new FormData(form);
            
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const rowForm = document.querySelector(`#product-row-${productId} .${actionType}-form`);
                    if (rowForm) {
                        // Kích hoạt giả lập gửi form bằng AJAX trên dòng đó để cập nhật UI đồng bộ
                        rowForm.dispatchEvent(new Event('submit', { cancelable: true }));
                    } else {
                        window.location.reload();
                    }
                    closeAdminProductModal();
                } else {
                    alert('Lỗi: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                window.location.reload();
            });
        }

        // Khôi phục tab và sub-tab hoạt động khi tải lại trang
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const urlTab = urlParams.get('tab');
            if (urlTab === 'users') {
                const targetBtn = Array.from(document.querySelectorAll('.menu-item')).find(btn => {
                    const onclickAttr = btn.getAttribute('onclick') || '';
                    return onclickAttr.includes('users-tab');
                });
                if (targetBtn) {
                    switchTab('users-tab', targetBtn, 'Quản lý tài khoản và quyền');
                }
            } else {
                const activeTabId = sessionStorage.getItem('admin_active_tab');
                const activeTabTitle = sessionStorage.getItem('admin_active_tab_title');
                if (activeTabId && document.getElementById(activeTabId)) {
                    let targetBtn = null;
                    document.querySelectorAll('.menu-item').forEach(btn => {
                        const onclickAttr = btn.getAttribute('onclick') || '';
                        if (onclickAttr.includes(activeTabId)) {
                            targetBtn = btn;
                        }
                    });
                    if (targetBtn) {
                        switchTab(activeTabId, targetBtn, activeTabTitle);
                    }
                }
            }

            // Khôi phục sub-tab sản phẩm
            const activeSubTabId = sessionStorage.getItem('admin_product_sub_tab');
            if (activeSubTabId && document.getElementById(activeSubTabId)) {
                let targetSubBtn = null;
                document.querySelectorAll('.sub-tab-btn').forEach(btn => {
                    const onclickAttr = btn.getAttribute('onclick') || '';
                    if (onclickAttr.includes(activeSubTabId)) {
                        targetSubBtn = btn;
                    }
                });
            }

            // Khôi phục sub-tab người dùng
            const activeUserSubTabId = sessionStorage.getItem('admin_user_sub_tab');
            if (activeUserSubTabId && document.getElementById(activeUserSubTabId)) {
                let targetUserSubBtn = null;
                document.querySelectorAll('.user-sub-tab-btn').forEach(btn => {
                    const onclickAttr = btn.getAttribute('onclick') || '';
                    if (onclickAttr.includes(activeUserSubTabId)) {
                        targetUserSubBtn = btn;
                    }
                });
                if (targetUserSubBtn) {
                    switchUserSubTab(activeUserSubTabId, targetUserSubBtn);
                }
            }
        });

        function openAssignRolesModal(userId, userName, rolesString) {
            document.getElementById('assign_user_id').value = userId;
            document.getElementById('assign_user_name').textContent = userName;
            
            const activeRoles = rolesString.split(',').map(r => r.trim());
            
            const checkboxes = document.querySelectorAll('#assignRolesModal input[type="checkbox"]');
            checkboxes.forEach(cb => {
                const roleName = cb.id.replace('role_checkbox_', '');
                cb.checked = activeRoles.includes(roleName);
            });
            
            document.getElementById('assignRolesModal').style.display = 'flex';
        }

        function closeAssignRolesModal() {
            document.getElementById('assignRolesModal').style.display = 'none';
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
