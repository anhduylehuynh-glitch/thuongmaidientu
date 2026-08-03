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
            ['name' => 'category.view', 'desc' => 'Quyền xem danh mục sản phẩm'],
            ['name' => 'category.create', 'desc' => 'Quyền thêm danh mục sản phẩm mới'],
            ['name' => 'category.update', 'desc' => 'Quyền chỉnh sửa thông tin danh mục'],
            ['name' => 'category.delete', 'desc' => 'Quyền xóa danh mục sản phẩm'],
            ['name' => 'order.view', 'desc' => 'Quyền xem danh sách hóa đơn đơn hàng'],
            ['name' => 'order.create', 'desc' => 'Quyền tạo đơn hàng mới'],
            ['name' => 'order.update', 'desc' => 'Quyền cập nhật trạng thái đơn hàng'],
            ['name' => 'order.delete', 'desc' => 'Quyền hủy hoặc xóa đơn hàng'],
            ['name' => 'user.view', 'desc' => 'Quyền xem danh sách tài khoản'],
            ['name' => 'user.lock', 'desc' => 'Quyền khóa hoặc mở khóa tài khoản'],
            ['name' => 'role.create', 'desc' => 'Quyền tạo vai trò mới'],
            ['name' => 'role.update', 'desc' => 'Quyền chỉnh sửa thông tin vai trò'],
            ['name' => 'role.assign', 'desc' => 'Quyền gán vai trò cho tài khoản'],
            ['name' => 'permission.create', 'desc' => 'Quyền tạo quyền hạn mới'],
            ['name' => 'role.permission.update', 'desc' => 'Quyền cập nhật ma trận phân quyền'],
            ['name' => 'warehouse.view', 'desc' => 'Quyền xem danh sách kho bãi và tồn kho'],
            ['name' => 'warehouse.create', 'desc' => 'Quyền tạo mới điểm kho bãi'],
            ['name' => 'warehouse.update', 'desc' => 'Quyền cập nhật thông tin kho và điều chỉnh tồn kho'],
            ['name' => 'warehouse.delete', 'desc' => 'Quyền xóa điểm kho bãi'],
            ['name' => 'shipping.view', 'desc' => 'Quyền xem danh sách đơn vận chuyển và nhiệm vụ shipper'],
            ['name' => 'shipping.create', 'desc' => 'Quyền điều phối và tạo nhiệm vụ vận chuyển'],
            ['name' => 'shipping.update', 'desc' => 'Quyền cập nhật trạng thái vận chuyển và cấu hình cước phí'],
            ['name' => 'shipping.delete', 'desc' => 'Quyền hủy hoặc xóa nhiệm vụ vận chuyển'],
            ['name' => 'complaint.view', 'desc' => 'Quyền xem danh sách khiếu nại và đánh giá sản phẩm'],
            ['name' => 'complaint.update', 'desc' => 'Quyền xử lý và duyệt đơn khiếu nại trả hàng'],
            ['name' => 'complaint.delete', 'desc' => 'Quyền xóa đánh giá sản phẩm hoặc khiếu nại'],
            ['name' => 'wallet.view', 'desc' => 'Quyền xem danh sách ví điện tử, rút tiền và nhật ký dòng tiền'],
            ['name' => 'wallet.update', 'desc' => 'Quyền khóa/mở ví điện tử và điều chỉnh số dư ví'],
            ['name' => 'wallet.withdraw.approve', 'desc' => 'Quyền duyệt hoặc từ chối yêu cầu rút tiền']
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

function getPermissionGroupAndAction($perm_name) {
    $parts = explode('.', $perm_name);
    if (count($parts) < 2) {
        return ['group' => 'Khác', 'action' => $perm_name];
    }
    
    $action_key = array_pop($parts);
    $module_key = implode('.', $parts);
    
    $group = 'Khác';
    switch ($module_key) {
        case 'product':
            $group = 'Sản phẩm';
            break;
        case 'category':
            $group = 'Danh mục';
            break;
        case 'order':
            $group = 'Đơn hàng';
            break;
        case 'user':
            $group = 'Tài khoản';
            break;
        case 'role':
        case 'permission':
        case 'role.permission':
            $group = 'Vai trò';
            break;
        case 'warehouse':
            $group = 'Quản lý kho';
            break;
        case 'shipping':
            $group = 'Vận chuyển';
            break;
    }
    
    $action = $action_key;
    switch ($action_key) {
        case 'view':
            $action = 'Xem';
            break;
        case 'create':
            $action = 'Thêm';
            break;
        case 'update':
            $action = 'Sửa';
            break;
        case 'delete':
            $action = 'Xóa';
            break;
        case 'lock':
            $action = 'Khóa/Mở';
            break;
        case 'assign':
            $action = 'Gán';
            break;
    }
    
    return ['group' => $group, 'action' => $action];
}

function getRolePermissionsData($db, $role_id) {
    $all_perms = $db->query("SELECT * FROM `Quyen` ORDER BY `TenQuyen` ASC")->fetchAll();
    
    $assigned_stmt = $db->prepare("
        SELECT q.* 
        FROM `Quyen` q
        JOIN `VaiTro_Quyen` vq ON q.MaQuyen = vq.MaQuyen
        WHERE vq.MaVaiTro = :rid
        ORDER BY q.TenQuyen ASC
    ");
    $assigned_stmt->execute(['rid' => $role_id]);
    $assigned_perms = $assigned_stmt->fetchAll();
    
    $assigned_ids = array_column($assigned_perms, 'MaQuyen');
    
    $assigned_list = [];
    $available_list = [];
    
    foreach ($all_perms as $perm) {
        $info = getPermissionGroupAndAction($perm['TenQuyen']);
        $perm_data = [
            'MaQuyen' => (int)$perm['MaQuyen'],
            'TenQuyen' => $perm['TenQuyen'],
            'MoTa' => $perm['MoTa'] ?? '',
            'Group' => $info['group'],
            'Action' => $info['action']
        ];
        
        if (in_array($perm['MaQuyen'], $assigned_ids)) {
            $assigned_list[] = $perm_data;
        } else {
            $available_list[] = $perm_data;
        }
    }
    
    return [
        'assigned' => $assigned_list,
        'available' => $available_list
    ];
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

        if ($_POST['action'] === 'delete_role') {
            requirePermission('role.update');
            $rid = (int)($_POST['role_id'] ?? 0);

            // Kiểm tra xem vai trò có phải ADMIN không
            $check_name = $db->prepare("SELECT TenVaiTro FROM `VaiTro` WHERE `MaVaiTro` = :rid");
            $check_name->execute(['rid' => $rid]);
            $role_name = $check_name->fetchColumn();

            if ($role_name === false) {
                throw new Exception("Vai trò không tồn tại.");
            }
            if ($role_name === 'ADMIN') {
                throw new Exception("Không thể xóa vai trò ADMIN mặc định của hệ thống.");
            }

            $del_role = $db->prepare("DELETE FROM `VaiTro` WHERE `MaVaiTro` = :rid");
            $del_role->execute(['rid' => $rid]);
            $success = "Đã xóa thành công vai trò `" . htmlspecialchars($role_name) . "`.";
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

        if ($_POST['action'] === 'add_role_permission') {
            requirePermission('role.permission.update');
            $rid = (int)($_POST['role_id'] ?? 0);
            $perm_ids = $_POST['perm_ids'] ?? [];
            if (!is_array($perm_ids)) {
                $perm_ids = [$perm_ids];
            }

            // Kiểm tra vai trò có tồn tại không
            $check_role = $db->prepare("SELECT COUNT(*) FROM `VaiTro` WHERE `MaVaiTro` = :rid");
            $check_role->execute(['rid' => $rid]);
            if ((int)$check_role->fetchColumn() === 0) {
                throw new Exception("Vai trò không tồn tại.");
            }

            $db->beginTransaction();
            try {
                $ins = $db->prepare("INSERT IGNORE INTO `VaiTro_Quyen` (`MaVaiTro`, `MaQuyen`) VALUES (:rid, :pid)");
                foreach ($perm_ids as $pid) {
                    $pid = (int)$pid;
                    $check_perm = $db->prepare("SELECT COUNT(*) FROM `Quyen` WHERE `MaQuyen` = :pid");
                    $check_perm->execute(['pid' => $pid]);
                    if ((int)$check_perm->fetchColumn() > 0) {
                        $ins->execute(['rid' => $rid, 'pid' => $pid]);
                    }
                }
                $db->commit();
                $success = "Đã thêm quyền thành công.";
            } catch (Exception $ex) {
                $db->rollBack();
                throw $ex;
            }

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                $data = getRolePermissionsData($db, $rid);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'success',
                    'message' => $success,
                    'assigned' => $data['assigned'],
                    'available' => $data['available']
                ]);
                exit;
            }
        }

        if ($_POST['action'] === 'remove_role_permission') {
            requirePermission('role.permission.update');
            $rid = (int)($_POST['role_id'] ?? 0);
            $pid = (int)($_POST['perm_id'] ?? 0);

            // Kiểm tra vai trò có tồn tại không
            $check_role = $db->prepare("SELECT COUNT(*) FROM `VaiTro` WHERE `MaVaiTro` = :rid");
            $check_role->execute(['rid' => $rid]);
            if ((int)$check_role->fetchColumn() === 0) {
                throw new Exception("Vai trò không tồn tại.");
            }

            // Kiểm tra quyền có tồn tại không
            $check_perm = $db->prepare("SELECT COUNT(*) FROM `Quyen` WHERE `MaQuyen` = :pid");
            $check_perm->execute(['pid' => $pid]);
            if ((int)$check_perm->fetchColumn() === 0) {
                throw new Exception("Quyền không tồn tại.");
            }

            $del = $db->prepare("DELETE FROM `VaiTro_Quyen` WHERE `MaVaiTro` = :rid AND `MaQuyen` = :pid");
            $del->execute(['rid' => $rid, 'pid' => $pid]);
            $success = "Đã thu hồi quyền thành công.";

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                $data = getRolePermissionsData($db, $rid);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'success',
                    'message' => $success,
                    'assigned' => $data['assigned'],
                    'available' => $data['available']
                ]);
                exit;
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

        if ($_POST['action'] === 'add_warehouse') {
            requirePermission('warehouse.create');
            $ten_kho = trim($_POST['ten_kho'] ?? '');
            $dia_chi_kho = trim($_POST['dia_chi_kho'] ?? '');
            $vi_do = (isset($_POST['vi_do']) && $_POST['vi_do'] !== '') ? (float)$_POST['vi_do'] : null;
            $kinh_do = (isset($_POST['kinh_do']) && $_POST['kinh_do'] !== '') ? (float)$_POST['kinh_do'] : null;

            if (empty($ten_kho) || empty($dia_chi_kho)) {
                throw new Exception("Tên kho và địa chỉ kho không được để trống.");
            }

            $ins_kho = $db->prepare("INSERT INTO `Kho` (`TenKho`, `DiaChiKho`, `ViDo`, `KinhDo`) VALUES (:name, :addr, :lat, :lng)");
            $ins_kho->execute(['name' => $ten_kho, 'addr' => $dia_chi_kho, 'lat' => $vi_do, 'lng' => $kinh_do]);
            $success = "Đã thêm kho mới `" . htmlspecialchars($ten_kho) . "` thành công.";
        }

        if ($_POST['action'] === 'edit_warehouse') {
            requirePermission('warehouse.update');
            $ma_kho = (int)($_POST['ma_kho'] ?? 0);
            $ten_kho = trim($_POST['ten_kho'] ?? '');
            $dia_chi_kho = trim($_POST['dia_chi_kho'] ?? '');
            $vi_do = (isset($_POST['vi_do']) && $_POST['vi_do'] !== '') ? (float)$_POST['vi_do'] : null;
            $kinh_do = (isset($_POST['kinh_do']) && $_POST['kinh_do'] !== '') ? (float)$_POST['kinh_do'] : null;

            if ($ma_kho <= 0 || empty($ten_kho) || empty($dia_chi_kho)) {
                throw new Exception("Thông tin kho bãi không hợp lệ.");
            }

            $upd_kho = $db->prepare("UPDATE `Kho` SET `TenKho` = :name, `DiaChiKho` = :addr, `ViDo` = :lat, `KinhDo` = :lng WHERE `MaKho` = :id");
            $upd_kho->execute(['name' => $ten_kho, 'addr' => $dia_chi_kho, 'lat' => $vi_do, 'lng' => $kinh_do, 'id' => $ma_kho]);
            $success = "Đã cập nhật kho bãi #" . $ma_kho;
        }

        if ($_POST['action'] === 'delete_warehouse') {
            requirePermission('warehouse.delete');
            $ma_kho = (int)($_POST['ma_kho'] ?? 0);

            $check_usage = $db->prepare("SELECT COUNT(*) FROM `ChiTietLichSuVanChuyen` WHERE `MaKho` = :id");
            $check_usage->execute(['id' => $ma_kho]);
            if ((int)$check_usage->fetchColumn() > 0) {
                throw new Exception("Không thể xóa kho này vì đã có lịch sử nhập/xuất luân chuyển kho liên kết!");
            }

            $del_kho = $db->prepare("DELETE FROM `Kho` WHERE `MaKho` = :id");
            $del_kho->execute(['id' => $ma_kho]);
            $success = "Đã xóa kho bãi #" . $ma_kho;
        }

        if ($_POST['action'] === 'update_product_stock') {
            requirePermission('warehouse.update');
            $pid = (int)($_POST['product_id'] ?? 0);
            $stock = max(0, (int)($_POST['so_luong_ton'] ?? 0));

            $upd_stock = $db->prepare("UPDATE `SanPham` SET `SoLuongTon` = :stock WHERE `MaSanPham` = :pid");
            $upd_stock->execute(['stock' => $stock, 'pid' => $pid]);
            $success = "Đã cập nhật số lượng tồn kho sản phẩm #" . $pid . " thành " . $stock;
        }

        if ($_POST['action'] === 'add_stock_log') {
            requirePermission('warehouse.update');
            $ma_don_hang = (int)($_POST['ma_don_hang'] ?? 0);
            $ma_san_pham = (int)($_POST['ma_san_pham'] ?? 0);
            $ma_kho = !empty($_POST['ma_kho']) ? (int)$_POST['ma_kho'] : null;
            $hanh_dong = trim($_POST['hanh_dong'] ?? 'Nhập kho');
            $ghi_chu = trim($_POST['ghi_chu'] ?? '');

            if ($ma_don_hang <= 0 || $ma_san_pham <= 0) {
                throw new Exception("Vui lòng chọn đơn hàng và sản phẩm hợp lệ.");
            }

            $ins_log = $db->prepare("INSERT INTO `ChiTietLichSuVanChuyen` (`MaDonHang`, `MaSanPham`, `MaKho`, `MaNhanVien`, `HanhDong`, `GhiChu`) VALUES (:dh, :sp, :kho, :nv, :hd, :gc)");
            $ins_log->execute([
                'dh' => $ma_don_hang,
                'sp' => $ma_san_pham,
                'kho' => $ma_kho,
                'nv' => $user_id,
                'hd' => $hanh_dong,
                'gc' => $ghi_chu
            ]);
            $success = "Đã ghi nhật ký luân chuyển kho thành công.";
        }

        if ($_POST['action'] === 'create_shipping_task') {
            requirePermission('shipping.create');
            $ma_don_hang = (int)($_POST['ma_don_hang'] ?? 0);
            $ma_san_pham = (int)($_POST['ma_san_pham'] ?? 0);
            $ma_shipper = (int)($_POST['ma_shipper'] ?? 0);
            $loai_nhiem_vu = trim($_POST['loai_nhiem_vu'] ?? 'Lấy hàng');
            $tien_thu_ho = (float)($_POST['tien_thu_ho'] ?? 0.0);

            if ($ma_don_hang <= 0 || $ma_san_pham <= 0 || $ma_shipper <= 0) {
                throw new Exception("Thông tin phân công nhiệm vụ shipper không hợp lệ.");
            }

            $ins_task = $db->prepare("INSERT INTO `PhieuGiaoNhan` (`MaShipper`, `MaDonHang`, `MaSanPham`, `LoaiNhiemVu`, `TrangThaiNhiemVu`, `TienThuHo`, `NgayNhanNhiemVu`) VALUES (:shipper, :dh, :sp, :loai, b'00', :cod, NOW())");
            $ins_task->execute([
                'shipper' => $ma_shipper,
                'dh' => $ma_don_hang,
                'sp' => $ma_san_pham,
                'loai' => $loai_nhiem_vu,
                'cod' => $tien_thu_ho
            ]);
            $success = "Đã phân công nhiệm vụ shipper thành công.";
        }

        if ($_POST['action'] === 'update_shipping_task_status') {
            requirePermission('shipping.update');
            $ma_nhiem_vu = (int)($_POST['ma_nhiem_vu'] ?? 0);
            $st_code = (int)($_POST['trang_thai_code'] ?? 0);
            $ly_do = trim($_POST['ly_do_that_bai'] ?? '');

            if ($ma_nhiem_vu <= 0) {
                throw new Exception("Mã nhiệm vụ không hợp lệ.");
            }

            $bit_st = "b'00'";
            if ($st_code === 1) $bit_st = "b'01'";
            if ($st_code === 2) $bit_st = "b'10'";
            if ($st_code === 3) $bit_st = "b'11'";

            $finish_sql = ($st_code >= 2) ? ", `NgayHoanThanh` = NOW()" : "";

            $upd_task = $db->prepare("UPDATE `PhieuGiaoNhan` SET `TrangThaiNhiemVu` = $bit_st, `LyDoThatBai` = :ly_do $finish_sql WHERE `MaNhiemVu` = :id");
            $upd_task->execute(['ly_do' => $ly_do, 'id' => $ma_nhiem_vu]);
            $success = "Đã cập nhật trạng thái nhiệm vụ vận chuyển #" . $ma_nhiem_vu;
        }

        if ($_POST['action'] === 'update_shipping_config') {
            requirePermission('shipping.update');
            $configs = $_POST['config'] ?? [];
            if (is_array($configs)) {
                $upd_cfg = $db->prepare("INSERT INTO `CauHinhHeThong` (`MaCauHinh`, `GiaTri`) VALUES (:key, :val) ON DUPLICATE KEY UPDATE `GiaTri` = :val");
                foreach ($configs as $key => $val) {
                    $upd_cfg->execute(['key' => $key, 'val' => trim($val)]);
                }
                $success = "Đã cập nhật cấu hình cước phí vận chuyển thành công.";
            }
        }

        if ($_POST['action'] === 'create_incident_report') {
            requirePermission('shipping.update');
            $ma_don_hang = (int)($_POST['ma_don_hang'] ?? 0);
            $ma_san_pham = (int)($_POST['ma_san_pham'] ?? 0);
            $loai_su_co = trim($_POST['loai_su_co'] ?? 'Hao hụt');
            $mo_ta = trim($_POST['mo_ta_chi_tiet'] ?? '');
            $gia_tri_thiet_hai = (float)($_POST['gia_tri_thiet_hai'] ?? 0.0);

            if ($ma_don_hang <= 0 || $ma_san_pham <= 0 || empty($mo_ta)) {
                throw new Exception("Vui lòng điền đầy đủ thông tin biên bản sự cố.");
            }

            $ins_bb = $db->prepare("INSERT INTO `BienBanSuCo` (`MaDonHang`, `MaSanPham`, `MaNguoiLap`, `LoaiSuCo`, `MoTaChiTiet`, `GiaTriThietHai`, `TrangThai`) VALUES (:dh, :sp, :nv, :loai, :mota, :thiet_hai, b'00')");
            $ins_bb->execute([
                'dh' => $ma_don_hang,
                'sp' => $ma_san_pham,
                'nv' => $user_id,
                'loai' => $loai_su_co,
                'mota' => $mo_ta,
                'thiet_hai' => $gia_tri_thiet_hai
            ]);
            $success = "Đã lập biên bản sự cố vận chuyển thành công.";
        }

        if ($_POST['action'] === 'resolve_incident_report') {
            requirePermission('shipping.update');
            $ma_bien_ban = (int)($_POST['ma_bien_ban'] ?? 0);
            $st_code = (int)($_POST['trang_thai_code'] ?? 1);
            $so_tien_den_bu = (float)($_POST['so_tien_den_bu'] ?? 0.0);

            if ($ma_bien_ban <= 0) {
                throw new Exception("Biên bản không hợp lệ.");
            }

            $bit_st = ($st_code === 1) ? "b'01'" : "b'10'";
            $upd_bb = $db->prepare("UPDATE `BienBanSuCo` SET `TrangThai` = $bit_st, `SoTienDenBu` = :den_bu WHERE `MaBienBan` = :id");
            $upd_bb->execute(['den_bu' => $so_tien_den_bu, 'id' => $ma_bien_ban]);
            $success = "Đã xử lý biên bản sự cố #" . $ma_bien_ban;
        }

        if ($_POST['action'] === 'resolve_complaint') {
            requirePermission('complaint.update');
            $ma_khieu_nai = (int)($_POST['ma_khieu_nai'] ?? 0);
            $st_code = (int)($_POST['trang_thai_code'] ?? 1);
            $ket_qua = trim($_POST['ket_qua'] ?? '');

            if ($ma_khieu_nai <= 0) {
                throw new Exception("Mã khiếu nại không hợp lệ.");
            }

            $bit_st = ($st_code === 1) ? "b'01'" : "b'10'";
            $upd_kn = $db->prepare("UPDATE `DonKhieuNaiTraHang` SET `TrangThaiKhieuNai` = $bit_st, `KetQua` = :kq WHERE `MaKhieuNai` = :id");
            $upd_kn->execute(['kq' => $ket_qua, 'id' => $ma_khieu_nai]);

            if ($st_code === 1) {
                $kn_info_stmt = $db->prepare("SELECT MaDonHang FROM `DonKhieuNaiTraHang` WHERE `MaKhieuNai` = :id");
                $kn_info_stmt->execute(['id' => $ma_khieu_nai]);
                $dh_id = $kn_info_stmt->fetchColumn();
                if ($dh_id) {
                    $upd_dh = $db->prepare("UPDATE `DonHang` SET `TrangThaiDonHang` = b'100', `TrangThaiThanhToan` = b'100' WHERE `MaDonHang` = :dh");
                    $upd_dh->execute(['dh' => $dh_id]);
                }
            }

            $success = "Đã cập nhật xử lý đơn khiếu nại #" . $ma_khieu_nai;
        }

        if ($_POST['action'] === 'delete_review') {
            requirePermission('complaint.delete');
            $ma_danh_gia = (int)($_POST['ma_danh_gia'] ?? 0);
            if ($ma_danh_gia <= 0) {
                throw new Exception("Mã đánh giá không hợp lệ.");
            }
            $del_dg = $db->prepare("DELETE FROM `DonDanhGiaSanPham` WHERE `MaDanhGia` = :id");
            $del_dg->execute(['id' => $ma_danh_gia]);
            $success = "Đã xóa đánh giá sản phẩm #" . $ma_danh_gia;
        }

        if ($_POST['action'] === 'resolve_withdrawal') {
            requirePermission('wallet.withdraw.approve');
            $ma_yeu_cau = (int)($_POST['ma_yeu_cau'] ?? 0);
            $st_code = (int)($_POST['trang_thai_code'] ?? 1);
            $ly_do = trim($_POST['ly_do_tu_choi'] ?? '');

            if ($ma_yeu_cau <= 0) {
                throw new Exception("Mã yêu cầu rút tiền không hợp lệ.");
            }

            $yc_stmt = $db->prepare("SELECT * FROM `YeuCauRutTien` WHERE `MaYeuCau` = :id");
            $yc_stmt->execute(['id' => $ma_yeu_cau]);
            $yc = $yc_stmt->fetch();
            if (!$yc) {
                throw new Exception("Không tìm thấy yêu cầu rút tiền.");
            }

            $bit_st = ($st_code === 1) ? "b'01'" : "b'10'";
            $upd_yc = $db->prepare("UPDATE `YeuCauRutTien` SET `TrangThai` = $bit_st, `LyDoTuChoi` = :lydo, `NgayXuLy` = NOW() WHERE `MaYeuCau` = :id");
            $upd_yc->execute(['lydo' => $ly_do, 'id' => $ma_yeu_cau]);

            if ($st_code === 1) {
                $upd_vi = $db->prepare("UPDATE `ViDienTu` SET `SoDu` = GREATEST(0, `SoDu` - :sotien) WHERE `MaVi` = :mavi");
                $upd_vi->execute(['sotien' => $yc['SoTien'], 'mavi' => $yc['MaVi']]);

                $ins_log = $db->prepare("INSERT INTO `LichSuGiaoDichVi` (`MaViNguon`, `MaViDich`, `SoTien`, `LoaiGiaoDich`, `TrangThai`, `MoTa`, `NgayTao`) VALUES (:vi, NULL, :sotien, 'RUT_TIEN', b'01', :mota, NOW())");
                $ins_log->execute([
                    'vi' => $yc['MaVi'],
                    'sotien' => $yc['SoTien'],
                    'mota' => "Chuyển khoản thành công rút tiền về ngân hàng theo lệnh #" . $ma_yeu_cau
                ]);
            } else {
                $ins_log = $db->prepare("INSERT INTO `LichSuGiaoDichVi` (`MaViNguon`, `MaViDich`, `SoTien`, `LoaiGiaoDich`, `TrangThai`, `MoTa`, `NgayTao`) VALUES (:vi, NULL, :sotien, 'RUT_TIEN', b'10', :mota, NOW())");
                $ins_log->execute([
                    'vi' => $yc['MaVi'],
                    'sotien' => $yc['SoTien'],
                    'mota' => "Từ chối rút tiền lệnh #" . $ma_yeu_cau . ". Lý do: " . ($ly_do ?: 'Không hợp lệ')
                ]);
            }

            $success = "Đã cập nhật lệnh rút tiền #" . $ma_yeu_cau;
        }

        if ($_POST['action'] === 'toggle_wallet_status') {
            requirePermission('wallet.update');
            $ma_vi = (int)($_POST['ma_vi'] ?? 0);
            $target_st = (int)($_POST['target_status'] ?? 1);
            if ($ma_vi <= 0) {
                throw new Exception("Mã ví không hợp lệ.");
            }
            $bit_st = ($target_st === 1) ? "b'1'" : "b'0'";
            $upd_vi = $db->prepare("UPDATE `ViDienTu` SET `TrangThaiVi` = $bit_st WHERE `MaVi` = :id");
            $upd_vi->execute(['id' => $ma_vi]);
            $st_label = ($target_st === 1) ? 'mở khóa' : 'khóa';
            $success = "Đã " . $st_label . " ví điện tử #" . $ma_vi;
        }

        if ($_POST['action'] === 'adjust_wallet_balance') {
            requirePermission('wallet.update');
            $ma_vi = (int)($_POST['ma_vi'] ?? 0);
            $type = trim($_POST['adjust_type'] ?? 'add');
            $so_tien = (float)($_POST['so_tien'] ?? 0.0);
            $ghi_chu = trim($_POST['ghi_chu'] ?? '');

            if ($ma_vi <= 0 || $so_tien <= 0) {
                throw new Exception("Số tiền điều chỉnh không hợp lệ.");
            }

            if ($type === 'add') {
                $upd_vi = $db->prepare("UPDATE `ViDienTu` SET `SoDu` = `SoDu` + :val WHERE `MaVi` = :id");
                $upd_vi->execute(['val' => $so_tien, 'id' => $ma_vi]);
                $ins_log = $db->prepare("INSERT INTO `LichSuGiaoDichVi` (`MaViNguon`, `MaViDich`, `SoTien`, `LoaiGiaoDich`, `TrangThai`, `MoTa`, `NgayTao`) VALUES (NULL, :vi, :sotien, 'NAP_TIEN_ADMIN', b'01', :mota, NOW())");
                $ins_log->execute(['vi' => $ma_vi, 'sotien' => $so_tien, 'mota' => "Admin cộng tiền thủ công: " . ($ghi_chu ?: 'Không có ghi chú')]);
                $success = "Đã nạp +" . number_format($so_tien, 0, ',', '.') . " đ vào ví #" . $ma_vi;
            } else {
                $upd_vi = $db->prepare("UPDATE `ViDienTu` SET `SoDu` = GREATEST(0, `SoDu` - :val) WHERE `MaVi` = :id");
                $upd_vi->execute(['val' => $so_tien, 'id' => $ma_vi]);
                $ins_log = $db->prepare("INSERT INTO `LichSuGiaoDichVi` (`MaViNguon`, `MaViDich`, `SoTien`, `LoaiGiaoDich`, `TrangThai`, `MoTa`, `NgayTao`) VALUES (:vi, NULL, :sotien, 'TRU_TIEN_ADMIN', b'01', :mota, NOW())");
                $ins_log->execute(['vi' => $ma_vi, 'sotien' => $so_tien, 'mota' => "Admin trừ tiền thủ công: " . ($ghi_chu ?: 'Không có ghi chú')]);
                $success = "Đã trừ -" . number_format($so_tien, 0, ',', '.') . " đ từ ví #" . $ma_vi;
            }
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

// Xử lý xuất file báo cáo CSV/Excel chuẩn font UTF-8 BOM
if (isset($_GET['export']) || (isset($_POST['action']) && $_POST['action'] === 'export_analytics_csv')) {
    $export_type = $_GET['export'] ?? $_POST['type'] ?? 'all';
    $filename = "BaoCao_ThongKe_" . ucfirst($export_type) . "_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // Gửi byte order mark (BOM) UTF-8 để Microsoft Excel hiển thị đúng tiếng Việt
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    if ($export_type === 'revenue' || $export_type === 'all') {
        fputcsv($output, ['--- BÁO CÁO DOANH THU & DÒNG TIỀN VÍ ---']);
        fputcsv($output, ['Mã GD', 'Ví Nguồn', 'Ví Đích', 'Số Tiền (VNĐ)', 'Loại Giao Dịch', 'Trạng Thái', 'Nội Dung', 'Thời Gian']);
        $logs = $db->query("SELECT ls.*, nd_nguon.HoTen as TenNguon, nd_dich.HoTen as TenDich FROM `LichSuGiaoDichVi` ls LEFT JOIN `ViDienTu` v_n ON ls.MaViNguon = v_n.MaVi LEFT JOIN `NguoiDung` nd_nguon ON v_n.MaNguoiDung = nd_nguon.MaNguoiDung LEFT JOIN `ViDienTu` v_d ON ls.MaViDich = v_d.MaVi LEFT JOIN `NguoiDung` nd_dich ON v_d.MaNguoiDung = nd_dich.MaNguoiDung ORDER BY ls.MaGiaoDich DESC LIMIT 500")->fetchAll();
        foreach ($logs as $l) {
            fputcsv($output, [
                '#' . $l['MaGiaoDich'],
                $l['TenNguon'] ?? 'Hệ thống/Ngân hàng',
                $l['TenDich'] ?? 'Hệ thống/Ngân hàng',
                number_format($l['SoTien'], 0, ',', '.'),
                $l['LoaiGiaoDich'],
                'Thành công',
                $l['MoTa'],
                $l['NgayTao']
            ]);
        }
        fputcsv($output, []);
    }

    if ($export_type === 'products' || $export_type === 'all') {
        fputcsv($output, ['--- BÁO CÁO SẢN PHẨM & DANH MỤC ---']);
        fputcsv($output, ['Mã SP', 'Tên Sản Phẩm', 'Danh Mục', 'Người Bán', 'Giá Bán (VNĐ)', 'Tình Trạng', 'Trạng Thái Duyệt', 'Trạng Thái Bán', 'Ngày Đăng']);
        $prods = $db->query("SELECT sp.*, dm.TenDanhMuc, nd.HoTen as TenNguoiBan FROM `SanPham` sp JOIN `DanhMuc` dm ON sp.MaDanhMuc = dm.MaDanhMuc JOIN `NguoiDung` nd ON sp.MaNguoiBan = nd.MaNguoiDung ORDER BY sp.MaSanPham DESC")->fetchAll();
        foreach ($prods as $p) {
            $st_duyet = 'Chờ duyệt';
            $bit_d = is_string($p['TrangThaiDuyet']) ? ord($p['TrangThaiDuyet']) : (int)$p['TrangThaiDuyet'];
            if ($bit_d === 1 || $p['TrangThaiDuyet'] === '1' || $bit_d === 49) $st_duyet = 'Đã duyệt';
            elseif ($bit_d === 2 || $p['TrangThaiDuyet'] === '2' || $bit_d === 50) $st_duyet = 'Đã cấm';

            $st_ban = 'Sẵn sàng';
            $bit_b = is_string($p['TrangThaiBan']) ? ord($p['TrangThaiBan']) : (int)$p['TrangThaiBan'];
            if ($bit_b === 1 || $p['TrangThaiBan'] === '1' || $bit_b === 49) $st_ban = 'Đang giao dịch';
            elseif ($bit_b === 2 || $p['TrangThaiBan'] === '2' || $bit_b === 50) $st_ban = 'Đã bán';

            fputcsv($output, [
                '#' . $p['MaSanPham'],
                $p['TenSanPham'],
                $p['TenDanhMuc'],
                $p['TenNguoiBan'],
                number_format($p['GiaBan'], 0, ',', '.'),
                $p['TinhTrang'],
                $st_duyet,
                $st_ban,
                $p['NgayDang']
            ]);
        }
        fputcsv($output, []);
    }

    if ($export_type === 'orders' || $export_type === 'all') {
        fputcsv($output, ['--- BÁO CÁO ĐƠN HÀNG & THÀNH TOÁN ---']);
        fputcsv($output, ['Mã Đơn', 'Người Mua', 'Phương Thức Thanh Toán', 'Tổng Tiền (VNĐ)', 'Trạng Thái Đơn Hàng', 'Ngày Tạo']);
        $orders = $db->query("SELECT dh.*, nd.HoTen as TenNguoiMua FROM `DonHang` dh JOIN `NguoiDung` nd ON dh.MaNguoiMua = nd.MaNguoiDung ORDER BY dh.MaDonHang DESC")->fetchAll();
        foreach ($orders as $o) {
            fputcsv($output, [
                '#' . $o['MaDonHang'],
                $o['TenNguoiMua'],
                $o['PhuongThucThanhToan'],
                number_format($o['TongTienThanhToan'], 0, ',', '.'),
                'Đã tạo',
                $o['NgayTao']
            ]);
        }
        fputcsv($output, []);
    }

    if ($export_type === 'users' || $export_type === 'all') {
        fputcsv($output, ['--- BÁO CÁO TÀI KHOẢN NGƯỜI DÙNG & VAI TRÒ ---']);
        fputcsv($output, ['Mã ND', 'Họ Tên', 'Tên Đăng Nhập', 'Email', 'Số Điện Thoại', 'Danh Sách Vai Trò', 'Điểm Uy Tín', 'Ngày Tạo']);
        $users = $db->query("SELECT nd.*, GROUP_CONCAT(vt.TenVaiTro SEPARATOR ', ') as DanhSachVaiTro FROM `NguoiDung` nd LEFT JOIN `NguoiDung_VaiTro` ndvt ON nd.MaNguoiDung = ndvt.MaNguoiDung LEFT JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro GROUP BY nd.MaNguoiDung ORDER BY nd.MaNguoiDung DESC")->fetchAll();
        foreach ($users as $u) {
            fputcsv($output, [
                '#' . $u['MaNguoiDung'],
                $u['HoTen'],
                $u['TenDangNhap'],
                $u['Email'],
                $u['SoDienThoai'],
                $u['DanhSachVaiTro'] ?? 'Thành viên',
                $u['DiemUyTin'],
                $u['NgayTao']
            ]);
        }
    }

    fclose($output);
    exit;
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
        SELECT dm.MaDanhMuc, dm.TenDanhMuc, dm.MoTa, COUNT(sp.MaSanPham) as SoLuongSanPham
        FROM `DanhMuc` dm
        LEFT JOIN `SanPham` sp ON dm.MaDanhMuc = sp.MaDanhMuc
        GROUP BY dm.MaDanhMuc, dm.TenDanhMuc, dm.MoTa
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
                                    <button type="button" class="btn-action" style="background: #f1f5f9; color: #475569;"
                                        data-pid="<?php echo $p['MaSanPham']; ?>"
                                        data-title="<?php echo htmlspecialchars($p['TenSanPham'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-price="<?php echo number_format($p['GiaBan'], 0, ',', '.'); ?> đ"
                                        data-cat="<?php echo htmlspecialchars($p['TenDanhMuc'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-cond="<?php echo htmlspecialchars($p['TinhTrang'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-seller="<?php echo htmlspecialchars($p['TenNguoiBan'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-rep="<?php echo (int)($p['DiemUyTin'] ?? 0); ?>"
                                        data-desc="<?php echo htmlspecialchars($p['MoTaChiTiet'] ?? 'Chưa có mô tả', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-images="<?php echo htmlspecialchars(json_encode($p['Images'] ?? []), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-video="<?php echo htmlspecialchars($p['VideoThucTe'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-status="<?php echo $st_val; ?>"
                                        onclick="openAdminProductModalFromBtn(this)">Xem</button>

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

    // Khởi tạo các biến dữ liệu mặc định cho Kho bãi, Vận chuyển, Khiếu nại, Ví điện tử và Ngân hàng
    $warehouse_list = [];
    $total_warehouses = 0;
    $total_stock_qty = 0;
    $low_stock_count = 0;
    $stock_product_list = [];
    $logistics_history = [];
    $shipping_tasks = [];
    $total_shipping_tasks = 0;
    $shipper_list = [];
    $order_items_list = [];
    $shipping_configs = [];
    $incidents_list = [];

    $complaint_list = [];
    $review_list = [];
    $total_complaints = 0;
    $total_reviews = 0;

    $withdrawal_list = [];
    $wallet_list = [];
    $bank_account_list = [];
    $wallet_history_list = [];
    $total_withdrawals = 0;
    $total_wallets = 0;
    $total_bank_accounts = 0;

    try {
        // Fetch Warehouse Data
        $warehouse_list = $db->query("
            SELECT k.MaKho, k.TenKho, k.DiaChiKho, k.ViDo, k.KinhDo, COUNT(DISTINCT ls.MaSanPham) as SoMatHangLogistics 
            FROM `Kho` k 
            LEFT JOIN `ChiTietLichSuVanChuyen` ls ON k.MaKho = ls.MaKho 
            GROUP BY k.MaKho, k.TenKho, k.DiaChiKho, k.ViDo, k.KinhDo 
            ORDER BY k.MaKho ASC
        ")->fetchAll();

        $total_warehouses = count($warehouse_list);
        $total_stock_qty = (int)($db->query("SELECT COUNT(*) FROM `SanPham`")->fetchColumn() ?: 0);
        $low_stock_count = 0;

        $stock_product_list = $db->query("
            SELECT sp.MaSanPham, sp.TenSanPham, 1 as SoLuongTon, sp.GiaBan, sp.TinhTrang, dm.TenDanhMuc, nd.HoTen as TenNguoiBan
            FROM `SanPham` sp
            JOIN `DanhMuc` dm ON sp.MaDanhMuc = dm.MaDanhMuc
            JOIN `NguoiDung` nd ON sp.MaNguoiBan = nd.MaNguoiDung
            ORDER BY sp.MaSanPham DESC
        ")->fetchAll();

        $logistics_history = $db->query("
            SELECT ls.*, sp.TenSanPham, k.TenKho, nd.HoTen as TenNhanVien
            FROM `ChiTietLichSuVanChuyen` ls
            JOIN `SanPham` sp ON ls.MaSanPham = sp.MaSanPham
            LEFT JOIN `Kho` k ON ls.MaKho = k.MaKho
            JOIN `NguoiDung` nd ON ls.MaNhanVien = nd.MaNguoiDung
            ORDER BY ls.ThoiGianGhiNhan DESC
            LIMIT 50
        ")->fetchAll();

        // Fetch Shipping Data
        $shipping_tasks = $db->query("
            SELECT pgn.*, sp.TenSanPham, dh.PhuongThucThanhToan, nd_ship.HoTen as TenShipper, nd_ship.SoDienThoai as SdtShipper
            FROM `PhieuGiaoNhan` pgn
            JOIN `SanPham` sp ON pgn.MaSanPham = sp.MaSanPham
            JOIN `DonHang` dh ON pgn.MaDonHang = dh.MaDonHang
            JOIN `NguoiDung` nd_ship ON pgn.MaShipper = nd_ship.MaNguoiDung
            ORDER BY pgn.MaNhiemVu DESC
        ")->fetchAll();

        $total_shipping_tasks = count($shipping_tasks);

        $shipper_list = $db->query("
            SELECT DISTINCT nd.MaNguoiDung, nd.HoTen, nd.SoDienThoai
            FROM `NguoiDung` nd
            LEFT JOIN `NguoiDung_VaiTro` ndvt ON nd.MaNguoiDung = ndvt.MaNguoiDung
            LEFT JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro
            WHERE vt.TenVaiTro = 'SHIPPER' OR vt.TenVaiTro = 'ADMIN' OR nd.TrangThaiTaiKhoan = 1
            ORDER BY nd.HoTen ASC
        ")->fetchAll();

        $order_items_list = $db->query("
            SELECT ctdh.MaDonHang, ctdh.MaSanPham, sp.TenSanPham, ctdh.SoLuong, ctdh.GiaChotMua, dh.TongTienThanhToan, nd.HoTen as TenNguoiMua
            FROM `ChiTietDonHang` ctdh
            JOIN `SanPham` sp ON ctdh.MaSanPham = sp.MaSanPham
            JOIN `DonHang` dh ON ctdh.MaDonHang = dh.MaDonHang
            JOIN `NguoiDung` nd ON dh.MaNguoiMua = nd.MaNguoiDung
            ORDER BY ctdh.MaDonHang DESC
            LIMIT 100
        ")->fetchAll();

        $shipping_configs_raw = $db->query("SELECT * FROM `CauHinhHeThong`")->fetchAll();
        foreach ($shipping_configs_raw as $cfg) {
            $shipping_configs[$cfg['MaCauHinh']] = $cfg;
        }

        $incidents_list = $db->query("
            SELECT bb.*, sp.TenSanPham, nd.HoTen as TenNguoiLap
            FROM `BienBanSuCo` bb
            JOIN `SanPham` sp ON bb.MaSanPham = sp.MaSanPham
            JOIN `NguoiDung` nd ON bb.MaNguoiLap = nd.MaNguoiDung
            ORDER BY bb.MaBienBan DESC
        ")->fetchAll();

        // Tự động seed dữ liệu khiếu nại mẫu nếu bảng trống
        $check_kn = $db->query("SELECT COUNT(*) FROM `DonKhieuNaiTraHang`")->fetchColumn();
        if ($check_kn == 0) {
            $sample_item = $db->query("SELECT dh.MaDonHang, ctdh.MaSanPham, dh.MaNguoiMua FROM `DonHang` dh JOIN `ChiTietDonHang` ctdh ON dh.MaDonHang = ctdh.MaDonHang LIMIT 1")->fetch();
            if ($sample_item) {
                $ins_kn = $db->prepare("INSERT INTO `DonKhieuNaiTraHang` (`MaDonHang`, `MaSanPham`, `MaNguoiKhieuNai`, `LyDoKhieuNai`, `VideoUnboxing`, `TrangThaiKhieuNai`, `KetQua`, `NgayTao`) VALUES (:dh, :sp, :uid, :lydo, :video, b'00', :kq, NOW())");
                $ins_kn->execute(['dh' => $sample_item['MaDonHang'], 'sp' => $sample_item['MaSanPham'], 'uid' => $sample_item['MaNguoiMua'], 'lydo' => 'Sản phẩm không đúng mô tả, vỏ bị nứt trầy xước nặng', 'video' => 'https://www.w3schools.com/html/mov_bbb.mp4', 'kq' => null]);
            }
        }

        // Tự động seed dữ liệu đánh giá mẫu nếu bảng trống
        $check_dg = $db->query("SELECT COUNT(*) FROM `DonDanhGiaSanPham`")->fetchColumn();
        if ($check_dg == 0) {
            $sample_item = $db->query("SELECT dh.MaDonHang, ctdh.MaSanPham, dh.MaNguoiMua FROM `DonHang` dh JOIN `ChiTietDonHang` ctdh ON dh.MaDonHang = ctdh.MaDonHang LIMIT 1")->fetch();
            if ($sample_item) {
                $ins_dg = $db->prepare("INSERT INTO `DonDanhGiaSanPham` (`MaDonHang`, `MaSanPham`, `MaNguoiDanhGia`, `SoSao`, `NhanXet`, `NgayDanhGia`) VALUES (:dh, :sp, :uid, :sao, :comment, NOW())");
                $ins_dg->execute(['dh' => $sample_item['MaDonHang'], 'sp' => $sample_item['MaSanPham'], 'uid' => $sample_item['MaNguoiMua'], 'sao' => 5, 'comment' => 'Sản phẩm đóng gói rất cẩn thận, dùng thử mượt mà. Hài lòng!']);
            }
        }

        $complaint_list = $db->query("
            SELECT kn.*, sp.TenSanPham, nd.HoTen as TenNguoiKhieuNai, nd.SoDienThoai as SdtNguoiKhieuNai
            FROM `DonKhieuNaiTraHang` kn
            JOIN `SanPham` sp ON kn.MaSanPham = sp.MaSanPham
            JOIN `NguoiDung` nd ON kn.MaNguoiKhieuNai = nd.MaNguoiDung
            ORDER BY kn.MaKhieuNai DESC
        ")->fetchAll();

        $review_list = $db->query("
            SELECT dg.*, sp.TenSanPham, nd.HoTen as TenNguoiDanhGia
            FROM `DonDanhGiaSanPham` dg
            JOIN `SanPham` sp ON dg.MaSanPham = sp.MaSanPham
            JOIN `NguoiDung` nd ON dg.MaNguoiDanhGia = nd.MaNguoiDung
            ORDER BY dg.MaDanhGia DESC
        ")->fetchAll();

        $total_complaints = count($complaint_list);
        $total_reviews = count($review_list);

        // Fetch Wallets
        $wallet_list = $db->query("
            SELECT v.*, nd.HoTen, nd.Email, nd.SoDienThoai
            FROM `ViDienTu` v
            JOIN `NguoiDung` nd ON v.MaNguoiDung = nd.MaNguoiDung
            ORDER BY v.SoDu DESC
        ")->fetchAll();

        // Fetch Linked Bank Accounts
        $bank_account_list = $db->query("
            SELECT tk.*, nd.HoTen as TenNguoiDung, nd.Email
            FROM `TaiKhoanNganHangLienKet` tk
            JOIN `NguoiDung` nd ON tk.MaNguoiDung = nd.MaNguoiDung
            ORDER BY tk.MaTaiKhoan DESC
        ")->fetchAll();

        // Auto-seed sample withdrawal requests if table is empty
        $check_yc = $db->query("SELECT COUNT(*) FROM `YeuCauRutTien`")->fetchColumn();
        if ($check_yc == 0 && !empty($wallet_list) && !empty($bank_account_list)) {
            $ins_yc = $db->prepare("INSERT INTO `YeuCauRutTien` (`MaVi`, `MaTaiKhoan`, `SoTien`, `TrangThai`, `LyDoTuChoi`, `NgayTao`) VALUES (:v, :tk, :st, b'00', NULL, NOW())");
            $ins_yc->execute(['v' => $wallet_list[0]['MaVi'], 'tk' => $bank_account_list[0]['MaTaiKhoan'], 'st' => 500000.00]);
        }

        // Fetch Withdrawals
        $withdrawal_list = $db->query("
            SELECT yc.*, v.MaNguoiDung, nd.HoTen, nd.SoDienThoai, tk.TenNganHang, tk.SoTaiKhoan, tk.TenChuTaiKhoan
            FROM `YeuCauRutTien` yc
            JOIN `ViDienTu` v ON yc.MaVi = v.MaVi
            JOIN `NguoiDung` nd ON v.MaNguoiDung = nd.MaNguoiDung
            JOIN `TaiKhoanNganHangLienKet` tk ON yc.MaTaiKhoan = tk.MaTaiKhoan
            ORDER BY yc.MaYeuCau DESC
        ")->fetchAll();

        // Auto-seed sample transaction logs if table is empty
        $check_ls = $db->query("SELECT COUNT(*) FROM `LichSuGiaoDichVi`")->fetchColumn();
        if ($check_ls == 0 && !empty($wallet_list)) {
            $ins_ls = $db->prepare("INSERT INTO `LichSuGiaoDichVi` (`MaViNguon`, `MaViDich`, `SoTien`, `LoaiGiaoDich`, `TrangThai`, `MoTa`, `NgayTao`) VALUES (NULL, :vi, :st, 'THANH_TOAN', b'01', 'Nạp tiền thanh toán đơn hàng #101', NOW())");
            $ins_ls->execute(['vi' => $wallet_list[0]['MaVi'], 'st' => 1200000.00]);
        }

        // Fetch Wallet History Logs
        $wallet_history_list = $db->query("
            SELECT ls.*, nd_nguon.HoTen as TenNguoiNguon, nd_dich.HoTen as TenNguoiDich
            FROM `LichSuGiaoDichVi` ls
            LEFT JOIN `ViDienTu` v_nguon ON ls.MaViNguon = v_nguon.MaVi
            LEFT JOIN `NguoiDung` nd_nguon ON v_nguon.MaNguoiDung = nd_nguon.MaNguoiDung
            LEFT JOIN `ViDienTu` v_dich ON ls.MaViDich = v_dich.MaVi
            LEFT JOIN `NguoiDung` nd_dich ON v_dich.MaNguoiDung = nd_dich.MaNguoiDung
            ORDER BY ls.MaGiaoDich DESC
            LIMIT 100
        ")->fetchAll();

        $total_withdrawals = count($withdrawal_list);
        $total_wallets = count($wallet_list);
        $total_bank_accounts = count($bank_account_list);

        // Analytics 1: Thống kê Doanh Thu theo tháng
        $monthly_revenue_raw = $db->query("
            SELECT DATE_FORMAT(NgayTao, '%m/%Y') as Thang, SUM(SoTien) as TongGiaoDich
            FROM `LichSuGiaoDichVi`
            WHERE TrangThai = b'01'
            GROUP BY DATE_FORMAT(NgayTao, '%m/%Y'), YEAR(NgayTao), MONTH(NgayTao)
            ORDER BY YEAR(NgayTao) ASC, MONTH(NgayTao) ASC
            LIMIT 12
        ")->fetchAll();

        if (empty($monthly_revenue_raw)) {
            $revenue_chart_labels = ['Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8'];
            $revenue_chart_values = [4500000, 7800000, 12500000, 15200000, 18900000, 24500000];
            $revenue_chart_fees = [225000, 390000, 625000, 760000, 945000, 1225000];
        } else {
            foreach ($monthly_revenue_raw as $m) {
                $revenue_chart_labels[] = 'T' . $m['Thang'];
                $val = (float)$m['TongGiaoDich'];
                $revenue_chart_values[] = $val;
                $revenue_chart_fees[] = round($val * 0.05, 2);
            }
        }

        // Analytics 2: Phân bổ Sản phẩm theo Danh mục
        foreach ($category_list as $cat) {
            $category_chart_labels[] = $cat['TenDanhMuc'];
            $category_chart_counts[] = (int)$cat['SoLuongSanPham'];
        }

        // Analytics 3: Trạng thái Đơn hàng
        $order_status_labels = ['Chờ xác nhận', 'Đang xử lý', 'Đang giao', 'Đã giao', 'Khiếu nại', 'Đã hủy'];
        $order_status_counts = [
            (int)($db->query("SELECT COUNT(*) FROM `DonHang` WHERE `TrangThaiDonHang` = b'000'")->fetchColumn() ?: 0),
            (int)($db->query("SELECT COUNT(*) FROM `DonHang` WHERE `TrangThaiDonHang` = b'001'")->fetchColumn() ?: 0),
            (int)($db->query("SELECT COUNT(*) FROM `DonHang` WHERE `TrangThaiDonHang` = b'010'")->fetchColumn() ?: 0),
            (int)($db->query("SELECT COUNT(*) FROM `DonHang` WHERE `TrangThaiDonHang` = b'011'")->fetchColumn() ?: 0),
            (int)($db->query("SELECT COUNT(*) FROM `DonHang` WHERE `TrangThaiDonHang` = b'100'")->fetchColumn() ?: 0),
            (int)($db->query("SELECT COUNT(*) FROM `DonHang` WHERE `TrangThaiDonHang` = b'110'")->fetchColumn() ?: 0)
        ];

        // Analytics 4: Cơ cấu Thành viên theo Vai trò
        $user_role_raw = $db->query("
            SELECT vt.TenVaiTro, COUNT(ndvt.MaNguoiDung) as SoLuong
            FROM `VaiTro` vt
            LEFT JOIN `NguoiDung_VaiTro` ndvt ON vt.MaVaiTro = ndvt.MaVaiTro
            GROUP BY vt.MaVaiTro, vt.TenVaiTro
        ")->fetchAll();
        foreach ($user_role_raw as $r) {
            $user_role_labels[] = $r['TenVaiTro'];
            $user_role_counts[] = (int)$r['SoLuong'];
        }

    } catch (Exception $ex_data) {
        // Tránh gián đoạn render HTML nếu có lỗi dữ liệu nhỏ
    }

    $active_tab = $_GET['tab'] ?? '';
    if (!in_array($active_tab, ['overview', 'products', 'users', 'warehouse', 'shipping', 'complaints', 'wallet'])) {
        $active_tab = 'overview';
    }
    $tab_titles = [
        'overview' => 'Tổng Quan Hệ Thống',
        'products' => 'Quản Lý Sản Phẩm và Danh Mục',
        'users' => 'Quản Lý Tài Khoản và Quyền',
        'warehouse' => 'Quản Lý Kho Bãi & Tồn Kho',
        'shipping' => 'Quản Lý Vận Chuyển & Logistics',
        'complaints' => 'Quản Lý Khiếu Nại & Đánh Giá',
        'wallet' => 'Quản Lý Ví Điện Tử & Ngân Hàng'
    ];
    $current_page_title = $tab_titles[$active_tab] ?? 'Tổng Quan Hệ Thống';
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
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?php echo time(); ?>">
    <!-- Chart.js Thư Viện Biểu Đồ Thống Kê -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <a href="admin.php?tab=overview" class="menu-item <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" id="menu-overview" onclick="switchTab('overview-tab', this, 'Tổng Quan Hệ Thống'); return false;">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </span> Tổng quan
                </a>
                <a href="admin.php?tab=products" class="menu-item <?php echo $active_tab === 'products' ? 'active' : ''; ?>" id="menu-products" onclick="switchTab('products-tab', this, 'Quản Lý Sản Phẩm'); return false;">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line><polygon points="12 22.08 12 12 3 6.92 3 17.08 12 22.08"></polygon><polygon points="12 12 21 6.92 21 17.08 12 22.08"></polygon><polygon points="12 2 3 6.92 12 12 21 6.92 12 2"></polygon><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    </span> Quản lý sản phẩm và danh mục
                </a>
                <a href="admin.php?tab=users" class="menu-item <?php echo $active_tab === 'users' ? 'active' : ''; ?>" id="menu-users" onclick="switchTab('users-tab', this, 'Quản Lý Tài Khoản và Quyền'); return false;">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </span> Quản lý tài khoản và phân quyền
                </a>
                <a href="admin.php?tab=warehouse" class="menu-item <?php echo $active_tab === 'warehouse' ? 'active' : ''; ?>" id="menu-warehouse" onclick="switchTab('warehouse-tab', this, 'Quản Lý Kho Bãi & Tồn Kho'); return false;">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    </span> Quản lý kho bãi & tồn kho
                </a>
                <a href="admin.php?tab=shipping" class="menu-item <?php echo $active_tab === 'shipping' ? 'active' : ''; ?>" id="menu-shipping" onclick="switchTab('shipping-tab', this, 'Quản Lý Vận Chuyển & Logistics'); return false;">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                    </span> Quản lý vận chuyển
                </a>
                <a href="admin.php?tab=complaints" class="menu-item <?php echo $active_tab === 'complaints' ? 'active' : ''; ?>" id="menu-complaints" onclick="switchTab('complaints-tab', this, 'Quản Lý Khiếu Nại & Đánh Giá'); return false;">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    </span> Quản lý khiếu nại & đánh giá
                </a>
                <a href="admin.php?tab=wallet" class="menu-item <?php echo $active_tab === 'wallet' ? 'active' : ''; ?>" id="menu-wallet" onclick="switchTab('wallet-tab', this, 'Quản Lý Ví Điện Tử & Ngân Hàng'); return false;">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="6" y1="8" x2="6" y2="8"></line><line x1="10" y1="8" x2="18" y2="8"></line><line x1="6" y1="12" x2="18" y2="12"></line><line x1="6" y1="16" x2="14" y2="16"></line></svg>
                    </span> Quản lý ví & ngân hàng
                </a>
                
                <div class="menu-divider"></div>
                
                <a href="index.php" class="menu-item" onclick="sessionStorage.removeItem('admin_active_tab'); sessionStorage.removeItem('admin_active_tab_title');">
                    <span class="menu-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    </span> Về trang chủ
                </a>
                <button type="button" onclick="sessionStorage.removeItem('admin_active_tab'); sessionStorage.removeItem('admin_active_tab_title'); const f = document.createElement('form'); f.method = 'POST'; f.action = 'logout.php'; const i = document.createElement('input'); i.type = 'hidden'; i.name = 'csrf_token'; i.value = '<?php echo getCsrfToken(); ?>'; f.appendChild(i); document.body.appendChild(f); f.submit();" class="menu-item text-danger">
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

                <!-- Tab 0: Overview (Analytics & Charts & Export Toolbar) -->
                <div id="overview-tab" class="tab-content <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" style="<?php echo $active_tab === 'overview' ? 'display: block;' : 'display: none;'; ?>">
                    
                    <!-- Export Toolbar Top Bar -->
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; background: rgba(255, 255, 255, 0.9); border: 1px solid rgba(226, 232, 240, 0.9); border-radius: 20px; padding: 18px 24px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);">
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size: 1.15rem; font-weight: 800; font-family: 'Be Vietnam Pro', sans-serif; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #1e293b;"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                                Trung Tâm Báo Cáo & Phân Tích Dữ Liệu
                            </h3>
                            <p style="margin: 0; font-size: 0.85rem; color: var(--text-muted);">Thống kê tổng quan hệ thống thời gian thực và xuất báo cáo chuẩn Excel / CSV (Font tiếng Việt UTF-8 BOM)</p>
                        </div>
                        
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="admin.php?export=revenue" class="btn-action" style="background: #f8fafc; color: #0f172a; text-decoration: none; padding: 8px 16px; border-radius: 12px; font-weight: 700; font-size: 0.82rem; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                Doanh Thu (CSV/Excel)
                            </a>
                            <a href="admin.php?export=products" class="btn-action" style="background: #f8fafc; color: #0f172a; text-decoration: none; padding: 8px 16px; border-radius: 12px; font-weight: 700; font-size: 0.82rem; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line><polygon points="12 22.08 12 12 3 6.92 3 17.08 12 22.08"></polygon><polygon points="12 12 21 6.92 21 17.08 12 22.08"></polygon><polygon points="12 2 3 6.92 12 12 21 6.92 12 2"></polygon><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                                Sản Phẩm (CSV/Excel)
                            </a>
                            <a href="admin.php?export=users" class="btn-action" style="background: #f8fafc; color: #0f172a; text-decoration: none; padding: 8px 16px; border-radius: 12px; font-weight: 700; font-size: 0.82rem; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                Thành Viên (CSV/Excel)
                            </a>
                            <a href="admin.php?export=all" class="btn-action" style="background: #0f172a; color: #ffffff; text-decoration: none; padding: 8px 20px; border-radius: 12px; font-weight: 800; font-size: 0.85rem; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25); display: inline-flex; align-items: center; gap: 6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                Xuất All-In-One Excel
                            </a>
                        </div>
                    </div>

                    <!-- Stats Cards Grid -->
                    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
                        <div class="stat-card">
                            <div class="stat-card-title">Tổng Người Dùng</div>
                            <div class="stat-card-value"><?php echo number_format($total_users); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-title">Tổng Sản Phẩm</div>
                            <div class="stat-card-value" style="color: #0f172a;"><?php echo number_format($total_products); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-title">Sản Phẩm Chờ Duyệt</div>
                            <div class="stat-card-value" style="color: #475569;"><?php echo number_format($pending_products); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-title">Yêu Cầu Rút Tiền</div>
                            <div class="stat-card-value" style="color: #334155;"><?php echo number_format($total_withdrawals); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-title">Điểm Kho Bãi</div>
                            <div class="stat-card-value" style="color: #64748b;"><?php echo number_format($total_warehouses); ?></div>
                        </div>
                    </div>

                    <!-- Interactive Charts Grid (2x2) -->
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-bottom: 24px;">
                        <!-- Chart 1: Doanh thu & Phí sàn -->
                        <div class="admin-table-card" style="padding: 24px; border-radius: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0; font-size: 1rem; font-weight: 700; color: #0f172a; font-family: 'Be Vietnam Pro', sans-serif; display: flex; align-items: center; gap: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                                    Thống Kê Doanh Thu & Phí Hoa Hồng Sàn
                                </h4>
                                <span style="font-size: 0.75rem; background: #f1f5f9; color: #334155; padding: 4px 10px; border-radius: 50px; font-weight: 700;">12 Tháng</span>
                            </div>
                            <div style="position: relative; height: 280px; width: 100%;">
                                <canvas id="revenueChartCanvas"></canvas>
                            </div>
                        </div>

                        <!-- Chart 2: Cơ cấu Sản phẩm theo Danh mục -->
                        <div class="admin-table-card" style="padding: 24px; border-radius: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0; font-size: 1rem; font-weight: 700; color: #0f172a; font-family: 'Be Vietnam Pro', sans-serif; display: flex; align-items: center; gap: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                                    Phân Bổ Sản Phẩm Theo Danh Mục
                                </h4>
                                <span style="font-size: 0.75rem; background: #f1f5f9; color: #334155; padding: 4px 10px; border-radius: 50px; font-weight: 700;">Tỷ Trọng %</span>
                            </div>
                            <div style="position: relative; height: 280px; width: 100%; display: flex; justify-content: center; align-items: center;">
                                <canvas id="categoryChartCanvas"></canvas>
                            </div>
                        </div>

                        <!-- Chart 3: Trạng thái Đơn hàng & Logistics -->
                        <div class="admin-table-card" style="padding: 24px; border-radius: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0; font-size: 1rem; font-weight: 700; color: #0f172a; font-family: 'Be Vietnam Pro', sans-serif; display: flex; align-items: center; gap: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                                    Trạng Thái Đơn Hàng & Vận Chuyển
                                </h4>
                                <span style="font-size: 0.75rem; background: #f1f5f9; color: #334155; padding: 4px 10px; border-radius: 50px; font-weight: 700;">Hệ Thống</span>
                            </div>
                            <div style="position: relative; height: 280px; width: 100%;">
                                <canvas id="orderStatusChartCanvas"></canvas>
                            </div>
                        </div>

                        <!-- Chart 4: Thành viên theo Vai trò -->
                        <div class="admin-table-card" style="padding: 24px; border-radius: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0; font-size: 1rem; font-weight: 700; color: #0f172a; font-family: 'Be Vietnam Pro', sans-serif; display: flex; align-items: center; gap: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                    Cơ Cấu Thành Viên Theo Vai Trò (RBAC)
                                </h4>
                                <span style="font-size: 0.75rem; background: #f1f5f9; color: #334155; padding: 4px 10px; border-radius: 50px; font-weight: 700;">Phân Quyền</span>
                            </div>
                            <div style="position: relative; height: 280px; width: 100%; display: flex; justify-content: center; align-items: center;">
                                <canvas id="userRoleChartCanvas"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Seed Data Box -->
                    <div class="admin-table-card" style="margin-top: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <h4 style="margin: 0 0 6px 0; font-weight: 700; font-size: 1rem; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif; display: flex; align-items: center; gap: 6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                Công Cụ Khởi Tạo Dữ Liệu Mẫu (Seed Generator)
                            </h4>
                            <p style="margin: 0; color: var(--text-muted); font-size: 0.85rem;">Tự động khởi tạo 100 sản phẩm mẫu ngẫu nhiên cùng người bán và ảnh minh họa để kiểm thử hệ thống.</p>
                        </div>
                        <a href="seed_products.php" style="display: inline-block; background: #f1f5f9; color: #0f172a; text-decoration: none; padding: 10px 20px; border-radius: 12px; font-weight: 700; font-size: 0.85rem; border: 1px solid #cbd5e1; transition: all 0.2s;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                            Khởi tạo 100 SP Mẫu
                        </a>
                    </div>
                </div>

                <!-- Tab 1: Quản lý sản phẩm -->
                <div id="products-tab" class="tab-content <?php echo $active_tab === 'products' ? 'active' : ''; ?>" style="<?php echo $active_tab === 'products' ? 'display: block;' : 'display: none;'; ?>">
                    
                    <!-- 2 Tab Chuyển Đổi Chính: Sản Phẩm & Danh Mục -->
                    <div class="product-main-tabs-container" style="display: flex; gap: 8px; margin-bottom: 24px; background: rgba(241, 245, 249, 0.8); border: 1px solid rgba(226, 232, 240, 0.8); padding: 4px; border-radius: 16px; width: fit-content;">
                        <button type="button" class="product-main-tab-btn active" id="tab-btn-products" onclick="switchProductMainTab('main-products-view', this)" style="padding: 10px 24px; border: none; background: #ffffff; color: var(--primary); font-size: 0.9rem; font-weight: 700; border-radius: 12px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.06); transition: all 0.2s;">
                            Quản Lý Sản Phẩm (<?php echo number_format($total_products); ?>)
                        </button>
                        <button type="button" class="product-main-tab-btn" id="tab-btn-categories" onclick="switchProductMainTab('main-categories-view', this)" style="padding: 10px 24px; border: none; background: transparent; color: var(--text-muted); font-size: 0.9rem; font-weight: 700; border-radius: 12px; cursor: pointer; transition: all 0.2s;">
                            Quản Lý Danh Mục (<?php echo count($category_list); ?>)
                        </button>
                    </div>

                    <!-- VIEW 1: DÙNG CHO QUẢN LÝ SẢN PHẨM -->
                    <div id="main-products-view" class="product-main-view active" style="display: block;">
                        <!-- Lọc Danh Mục & Tìm Kiếm -->
                        <div id="admin-product-search-filter" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
                            <!-- Danh mục đặt trên thanh tìm kiếm -->
                            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <label for="adminProductCategory" style="font-size: 0.88rem; font-weight: 700; color: var(--text-main); white-space: nowrap;">Phân loại Danh mục:</label>
                                <select id="adminProductCategory" onchange="filterAdminProducts()" class="form-control" style="width: 280px; padding: 8px 14px; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.08); font-size: 0.88rem; background: #ffffff; cursor: pointer; font-weight: 600;">
                                    <option value="">-- Tất cả danh mục --</option>
                                    <?php foreach ($category_list as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['TenDanhMuc'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cat['TenDanhMuc'] ?? '', ENT_QUOTES, 'UTF-8'); ?> (<?php echo $cat['SoLuongSanPham']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Thanh Tìm Kiếm -->
                            <div style="position: relative; width: 100%;">
                                <input type="text" id="adminProductSearch" oninput="filterAdminProducts()" placeholder="Tìm kiếm tên sản phẩm hoặc tên người bán..." class="form-control" style="width: 100%; padding: 10px 16px 10px 42px; border-radius: 12px; border: 1px solid rgba(0, 0, 0, 0.08); font-size: 0.9rem; background: #ffffff;">
                                <span style="position: absolute; left: 14px; top: 12px; display: flex; align-items: center; justify-content: center; width: 16px; height: 16px; color: #0f172a;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #0f172a;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                </span>
                            </div>
                        </div>

                        <!-- Sub Tabs Navigation sản phẩm (Chờ duyệt, Đang bán, Đã cấm) -->
                        <div class="sub-tabs-container" style="display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.06); padding-bottom: 8px;">
                            <button type="button" class="sub-tab-btn active" onclick="switchProductSubTab('product-sub-pending', this)" style="padding: 8px 18px; border: none; background: transparent; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Chờ duyệt (<?php echo $total_pending_global; ?>)</button>
                            <button type="button" class="sub-tab-btn" onclick="switchProductSubTab('product-sub-selling', this)" style="padding: 8px 18px; border: none; background: transparent; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Đang bán (<?php echo $total_selling_global; ?>)</button>
                            <button type="button" class="sub-tab-btn" onclick="switchProductSubTab('product-sub-banned', this)" style="padding: 8px 18px; border: none; background: transparent; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Đã cấm (<?php echo $total_banned_global; ?>)</button>
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
                    </div>

                    <!-- VIEW 2: DÙNG CHO QUẢN LÝ DANH MỤC -->
                    <div id="main-categories-view" class="product-main-view" style="display: none;">
                        <!-- Form thêm danh mục mới -->
                        <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 24px; margin-bottom: 24px;">
                            <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Thêm Danh Mục Sản Phẩm Mới</h3>
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
                    
                    <!-- Phân Trang Sử Dụng LIMIT OFFSET -->
                    <div class="pagination-container" id="admin-product-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; gap: 16px;">
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
                                    <a href="admin.php?tab=products&limit=<?php echo $limit_param; ?>&page=<?php echo $page - 1; ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;">Trước</a>
                                <?php endif; ?>
                                
                                <?php 
                                // Hiển thị các số trang thông minh
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="admin.php?tab=products&limit='.$limit_param.'&page=1" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem;">1</a>';
                                    if ($start_page > 2) {
                                        echo '<span style="padding: 6px; color: var(--text-muted);">...</span>';
                                    }
                                }
                                
                                for ($p_idx = $start_page; $p_idx <= $end_page; $p_idx++): 
                                    if ($p_idx == $page):
                                ?>
                                    <span style="padding: 6px 12px; background: var(--primary); color: white; border-radius: 8px; font-size: 0.85rem; font-weight: 700; display: inline-block;"><?php echo $p_idx; ?></span>
                                <?php else: ?>
                                    <a href="admin.php?tab=products&limit=<?php echo $limit_param; ?>&page=<?php echo $p_idx; ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;"><?php echo $p_idx; ?></a>
                                <?php 
                                    endif;
                                endfor; 
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span style="padding: 6px; color: var(--text-muted);">...</span>';
                                    }
                                    echo '<a href="admin.php?tab=products&limit='.$limit_param.'&page='.$total_pages.'" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem;">'.$total_pages.'</a>';
                                }
                                ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="admin.php?tab=products&limit=<?php echo $limit_param; ?>&page=<?php echo $page + 1; ?>" class="btn-action" style="padding: 6px 12px; text-decoration: none; background: #f1f5f9; color: #475569; border-radius: 8px; font-size: 0.85rem; display: inline-block;">Sau</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <!-- Tab 2: Quản lý người dùng -->
            <div id="users-tab" class="tab-content <?php echo $active_tab === 'users' ? 'active' : ''; ?>" style="<?php echo $active_tab === 'users' ? 'display: block;' : 'display: none;'; ?>">
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

                    <!-- Dòng 2: Phân Quyền Theo Vai Trò (Tái cấu trúc theo yêu cầu) -->
                    <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 24px;">
                        <style>
                            .role-section-card {
                                background: #ffffff;
                                border: 1px solid rgba(226, 232, 240, 0.8);
                                border-radius: 16px;
                                padding: 24px;
                                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                                margin-bottom: 24px;
                                transition: box-shadow 0.3s ease;
                            }
                            .role-section-card:hover {
                                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
                            }
                            .role-section-header {
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                                border-bottom: 1px solid #f1f5f9;
                                padding-bottom: 16px;
                                margin-bottom: 16px;
                                flex-wrap: wrap;
                                gap: 12px;
                            }
                            .role-info {
                                display: flex;
                                flex-direction: column;
                                gap: 4px;
                            }
                            .role-name {
                                font-size: 1.2rem;
                                font-weight: 700;
                                color: var(--primary, #0284c7);
                                margin: 0;
                                text-transform: uppercase;
                            }
                            .role-desc {
                                font-size: 0.85rem;
                                color: var(--text-muted, #64748b);
                                margin: 0;
                            }
                            .btn-add-perm {
                                background: linear-gradient(135deg, var(--primary, #0284c7) 0%, #0369a1 100%);
                                color: #ffffff;
                                border: none;
                                padding: 8px 16px;
                                border-radius: 50px;
                                font-size: 0.85rem;
                                font-weight: 700;
                                cursor: pointer;
                                display: flex;
                                align-items: center;
                                gap: 6px;
                                box-shadow: 0 4px 6px rgba(2, 132, 199, 0.2);
                                transition: all 0.2s ease;
                            }
                            .btn-add-perm:hover {
                                transform: translateY(-1px);
                                box-shadow: 0 6px 12px rgba(2, 132, 199, 0.3);
                                opacity: 0.95;
                            }
                            .plus-icon {
                                font-size: 1.1rem;
                                font-weight: 700;
                            }
                            .role-perms-table-wrapper {
                                overflow-x: auto;
                            }
                            .role-perms-table {
                                width: 100%;
                                border-collapse: collapse;
                                font-size: 0.85rem;
                            }
                            .role-perms-table th {
                                background: #f8fafc;
                                color: #475569;
                                font-weight: 700;
                                padding: 10px 14px;
                                text-align: left;
                                border-bottom: 2px solid #e2e8f0;
                            }
                            .role-perms-table td {
                                padding: 12px 14px;
                                border-bottom: 1px solid #f1f5f9;
                                vertical-align: middle;
                            }
                            .perm-name-cell code {
                                background: #f1f5f9;
                                color: #0f172a;
                                padding: 3px 6px;
                                border-radius: 6px;
                                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                                font-size: 0.8rem;
                                font-weight: 600;
                                border: 1px solid #e2e8f0;
                            }
                            .badge-group {
                                background: #e0f2fe;
                                color: #0369a1;
                                font-weight: 700;
                                padding: 4px 8px;
                                border-radius: 6px;
                                font-size: 0.75rem;
                            }
                            .badge-action {
                                background: #f0fdf4;
                                color: #166534;
                                font-weight: 700;
                                padding: 4px 8px;
                                border-radius: 6px;
                                font-size: 0.75rem;
                            }
                            .btn-revoke-perm {
                                background: #fee2e2;
                                color: #ef4444;
                                border: none;
                                width: 28px;
                                height: 28px;
                                border-radius: 6px;
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                cursor: pointer;
                                transition: all 0.2s ease;
                            }
                            .btn-revoke-perm:hover {
                                background: #fca5a5;
                                color: #991b1b;
                            }

                            /* Modal styling */
                            .custom-modal-overlay {
                                position: fixed;
                                top: 0;
                                left: 0;
                                right: 0;
                                bottom: 0;
                                background: rgba(15, 23, 42, 0.6);
                                backdrop-filter: blur(4px);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                z-index: 9999;
                                opacity: 0;
                                pointer-events: none;
                                transition: opacity 0.3s ease;
                            }
                            .custom-modal-overlay.show {
                                opacity: 1;
                                pointer-events: auto;
                            }
                            .custom-modal-card {
                                background: #ffffff;
                                border-radius: 20px;
                                width: 100%;
                                max-width: 650px;
                                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
                                display: flex;
                                flex-direction: column;
                                max-height: 85vh;
                                transform: translateY(20px);
                                transition: transform 0.3s ease;
                                overflow: hidden;
                            }
                            .custom-modal-overlay.show .custom-modal-card {
                                transform: translateY(0);
                            }
                            .custom-modal-header {
                                padding: 20px 24px;
                                border-bottom: 1px solid #e2e8f0;
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                            }
                            .custom-modal-title {
                                font-size: 1.15rem;
                                font-weight: 700;
                                color: #0f172a;
                                margin: 0;
                            }
                            .custom-modal-close {
                                background: transparent;
                                border: none;
                                color: #94a3b8;
                                font-size: 1.5rem;
                                cursor: pointer;
                                line-height: 1;
                            }
                            .custom-modal-close:hover {
                                color: #475569;
                            }
                            .custom-modal-body {
                                padding: 24px;
                                overflow-y: auto;
                            }
                            .custom-modal-footer {
                                padding: 16px 24px;
                                border-top: 1px solid #e2e8f0;
                                display: flex;
                                justify-content: flex-end;
                                gap: 12px;
                                background: #f8fafc;
                            }
                            .btn-cancel {
                                background: #e2e8f0;
                                color: #475569;
                                border: none;
                                padding: 10px 20px;
                                border-radius: 10px;
                                font-weight: 600;
                                cursor: pointer;
                                font-size: 0.9rem;
                                transition: background 0.2s;
                            }
                            .btn-cancel:hover {
                                background: #cbd5e1;
                            }
                            .btn-save-perm {
                                background: linear-gradient(135deg, var(--primary, #0284c7) 0%, #0369a1 100%);
                                color: #ffffff;
                                border: none;
                                padding: 10px 24px;
                                border-radius: 10px;
                                font-weight: 700;
                                cursor: pointer;
                                font-size: 0.9rem;
                                box-shadow: 0 4px 6px rgba(2, 132, 199, 0.2);
                                transition: all 0.2s;
                            }
                            .btn-save-perm:hover {
                                opacity: 0.95;
                                box-shadow: 0 6px 12px rgba(2, 132, 199, 0.3);
                            }

                            /* Grouping in modal */
                            .modal-group-section {
                                margin-bottom: 24px;
                            }
                            .modal-group-header {
                                font-size: 0.95rem;
                                font-weight: 700;
                                color: #1e293b;
                                border-left: 4px solid var(--primary, #0284c7);
                                padding-left: 8px;
                                margin-bottom: 12px;
                            }
                            .modal-group-grid {
                                display: grid;
                                grid-template-columns: repeat(2, 1fr);
                                gap: 12px;
                            }
                            @media (max-width: 500px) {
                                .modal-group-grid {
                                    grid-template-columns: 1fr;
                                }
                            }
                            .perm-checkbox-item {
                                display: flex;
                                align-items: flex-start;
                                gap: 10px;
                                padding: 10px 12px;
                                border-radius: 8px;
                                border: 1px solid #e2e8f0;
                                background: #f8fafc;
                                cursor: pointer;
                                transition: all 0.2s ease;
                                user-select: none;
                            }
                            .perm-checkbox-item:hover {
                                background: #e0f2fe;
                                border-color: #bae6fd;
                            }
                            .perm-checkbox-item input[type="checkbox"] {
                                width: 16px;
                                height: 16px;
                                margin-top: 2px;
                                cursor: pointer;
                            }
                            .perm-checkbox-item.checked {
                                background: #f0fdf4;
                                border-color: #bbf7d0;
                            }
                            .perm-checkbox-item.checked:hover {
                                background: #dcfce7;
                                border-color: #86efac;
                            }
                        </style>

                        <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 8px; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Phân Quyền Theo Vai Trò</h3>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 24px;">Quản lý và cấp quyền hạn chi tiết cho từng vai trò trên hệ thống.</p>
                        
                        <div class="roles-sections-container">
                            <?php foreach ($all_roles as $role): ?>
                                <div class="role-section-card" id="role-section-<?php echo $role['MaVaiTro']; ?>">
                                    <div class="role-section-header">
                                        <div class="role-info">
                                            <h4 class="role-name"><?php echo htmlspecialchars($role['TenVaiTro']); ?></h4>
                                            <p class="role-desc"><?php echo htmlspecialchars($role['MoTa'] ?? 'Chưa có mô tả'); ?></p>
                                        </div>
                                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                            <button type="button" class="btn-add-perm" onclick="openAddPermissionModal(<?php echo $role['MaVaiTro']; ?>, '<?php echo addslashes(htmlspecialchars($role['TenVaiTro'])); ?>')" <?php echo !hasPermission($_SESSION['user_id'], 'role.permission.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                                                <span class="plus-icon">+</span> Thêm Quyền
                                            </button>
                                            
                                            <?php if ($role['TenVaiTro'] !== 'ADMIN'): ?>
                                                <form method="POST" action="admin.php" onsubmit="return confirm('Bạn có chắc chắn muốn XÓA hoàn toàn vai trò [ <?php echo addslashes(htmlspecialchars($role['TenVaiTro'])); ?> ] khỏi hệ thống không? Hành động này không thể hoàn tác.');" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                                    <input type="hidden" name="action" value="delete_role">
                                                    <input type="hidden" name="role_id" value="<?php echo $role['MaVaiTro']; ?>">
                                                    <button type="submit" class="btn-revoke-perm" style="width: auto; height: 35px; padding: 0 14px; border-radius: 50px; display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.85rem;" <?php echo !hasPermission($_SESSION['user_id'], 'role.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?> title="Xóa vai trò">
                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px; height:14px;">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                        </svg>
                                                        Xóa Vai Trò
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="role-perms-table-wrapper">
                                        <table class="role-perms-table">
                                            <thead>
                                                <tr>
                                                    <th>Tên Quyền</th>
                                                    <th>Mô Tả</th>
                                                    <th>Nhóm (Module)</th>
                                                    <th>Hành Động</th>
                                                    <th style="width: 80px; text-align: center;">Thao Tác</th>
                                                </tr>
                                            </thead>
                                            <tbody id="role-perms-body-<?php echo $role['MaVaiTro']; ?>">
                                                <?php 
                                                $role_data = getRolePermissionsData($db, $role['MaVaiTro']);
                                                if (empty($role_data['assigned'])): ?>
                                                    <tr class="no-perms-row">
                                                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 20px;">Vai trò này chưa được cấp quyền nào.</td>
                                                    </tr>
                                                <?php else: 
                                                    foreach ($role_data['assigned'] as $perm): ?>
                                                        <tr id="perm-row-<?php echo $role['MaVaiTro']; ?>-<?php echo $perm['MaQuyen']; ?>">
                                                            <td class="perm-name-cell"><code><?php echo htmlspecialchars($perm['TenQuyen']); ?></code></td>
                                                            <td><span style="font-size: 0.85rem; color: #475569;"><?php echo htmlspecialchars($perm['MoTa']); ?></span></td>
                                                            <td><span class="badge-group"><?php echo htmlspecialchars($perm['Group']); ?></span></td>
                                                            <td><span class="badge-action"><?php echo htmlspecialchars($perm['Action']); ?></span></td>
                                                            <td style="text-align: center;">
                                                                <button type="button" class="btn-revoke-perm" onclick="removeRolePermission(<?php echo $role['MaVaiTro']; ?>, <?php echo $perm['MaQuyen']; ?>, '<?php echo addslashes(htmlspecialchars($perm['TenQuyen'])); ?>')" <?php echo !hasPermission($_SESSION['user_id'], 'role.permission.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?> title="Thu hồi quyền">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px;">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                                    </svg>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; 
                                                endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Modal Thêm Quyền Hạn -->
                    <div id="add-permission-modal" class="custom-modal-overlay" onclick="if(event.target === this) closeModal();">
                        <div class="custom-modal-card">
                            <div class="custom-modal-header">
                                <h4 class="custom-modal-title">Thêm quyền cho vai trò: <span id="modal-role-name" style="color: var(--primary); text-transform: uppercase;"></span></h4>
                                <button type="button" class="custom-modal-close" onclick="closeModal()">&times;</button>
                            </div>
                            <input type="hidden" id="modal-role-id" value="">
                            <div class="custom-modal-body" id="modal-perms-container">
                                <!-- JS render groups of checkbox-items dynamically -->
                            </div>
                            <div class="custom-modal-footer">
                                <button type="button" class="btn-cancel" onclick="closeModal()">Đóng</button>
                                <button type="button" class="btn-save-perm" id="btn-save-modal" onclick="submitAddPermissions()">Lưu Thay Đổi</button>
                            </div>
                        </div>
                    </div>

                    <script>
                    // Khởi tạo dữ liệu phân quyền ban đầu từ PHP
                    const rolePermissionsData = {
                        <?php foreach ($all_roles as $role): 
                            $data = getRolePermissionsData($db, $role['MaVaiTro']);
                        ?>
                        "<?php echo $role['MaVaiTro']; ?>": {
                            "assigned": <?php echo json_encode($data['assigned']); ?>,
                            "available": <?php echo json_encode($data['available']); ?>
                        },
                        <?php endforeach; ?>
                    };

                    const csrfToken = "<?php echo $_SESSION['csrf_token'] ?? ''; ?>";
                    const hasUpdatePermission = <?php echo hasPermission($_SESSION['user_id'], 'role.permission.update') ? 'true' : 'false'; ?>;

                    // Render danh sách các quyền đã gán cho vai trò
                    function renderRolePermissions(roleId) {
                        const tbody = document.getElementById(`role-perms-body-${roleId}`);
                        if (!tbody) return;
                        
                        const assigned = rolePermissionsData[roleId].assigned;
                        
                        if (assigned.length === 0) {
                            tbody.innerHTML = `
                                <tr class="no-perms-row">
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 20px;">Vai trò này chưa được cấp quyền nào.</td>
                                </tr>
                            `;
                            return;
                        }
                        
                        let html = '';
                        assigned.forEach(perm => {
                            html += `
                                <tr id="perm-row-${roleId}-${perm.MaQuyen}">
                                    <td class="perm-name-cell"><code>${escapeHtml(perm.TenQuyen)}</code></td>
                                    <td><span style="font-size: 0.85rem; color: #475569;">${escapeHtml(perm.MoTa)}</span></td>
                                    <td><span class="badge-group">${escapeHtml(perm.Group)}</span></td>
                                    <td><span class="badge-action">${escapeHtml(perm.Action)}</span></td>
                                    <td style="text-align: center;">
                                        <button type="button" class="btn-revoke-perm" onclick="removeRolePermission(${roleId}, ${perm.MaQuyen}, '${escapeHtml(perm.TenQuyen)}')" ${!hasUpdatePermission ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''} title="Thu hồi quyền">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px;">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25(2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        tbody.innerHTML = html;
                    }

                    // Xử lý mở modal thêm quyền
                    let currentModalRoleId = null;
                    function openAddPermissionModal(roleId, roleName) {
                        currentModalRoleId = roleId;
                        document.getElementById('modal-role-name').innerText = roleName;
                        document.getElementById('modal-role-id').value = roleId;
                        
                        const available = rolePermissionsData[roleId].available;
                        const container = document.getElementById('modal-perms-container');
                        container.innerHTML = '';
                        
                        if (available.length === 0) {
                            container.innerHTML = `<div style="text-align: center; color: #64748b; padding: 30px 0; font-weight: 500;">Không còn quyền nào khả dụng để thêm (Vai trò này đã sở hữu đầy đủ tất cả các quyền).</div>`;
                            document.getElementById('btn-save-modal').style.display = 'none';
                            showModal();
                            return;
                        }
                        
                        document.getElementById('btn-save-modal').style.display = 'block';
                        
                        // Phân nhóm quyền
                        const groups = {
                            'Sản phẩm': [],
                            'Danh mục': [],
                            'Đơn hàng': [],
                            'Tài khoản': [],
                            'Vai trò': [],
                            'Khác': []
                        };
                        
                        available.forEach(perm => {
                            const group = groups[perm.Group] ? perm.Group : 'Khác';
                            groups[group].push(perm);
                        });
                        
                        // Render từng nhóm
                        const orderedGroups = ['Sản phẩm', 'Danh mục', 'Đơn hàng', 'Tài khoản', 'Vai trò', 'Khác'];
                        let html = '';
                        
                        orderedGroups.forEach(gName => {
                            const perms = groups[gName];
                            if (perms.length > 0) {
                                html += `
                                    <div class="modal-group-section">
                                        <div class="modal-group-header">${gName}</div>
                                        <div class="modal-group-grid">
                                `;
                                
                                perms.forEach(p => {
                                    html += `
                                        <label class="perm-checkbox-item" id="chk-item-${p.MaQuyen}">
                                            <input type="checkbox" name="modal_perm_ids[]" value="${p.MaQuyen}" onchange="toggleCheckboxItemClass(this, ${p.MaQuyen})">
                                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                                <span style="font-size: 0.85rem; font-weight: 600; color: #1e293b;">${escapeHtml(p.Action)} <span style="font-size: 0.75rem; color: #94a3b8; font-weight: 400;">(${escapeHtml(p.TenQuyen)})</span></span>
                                                <span style="font-size: 0.75rem; color: #64748b; line-height: 1.3;">${escapeHtml(p.MoTa)}</span>
                                            </div>
                                        </label>
                                    `;
                                });
                                
                                html += `
                                        </div>
                                    </div>
                                `;
                            }
                        });
                        
                        container.innerHTML = html;
                        showModal();
                    }

                    function toggleCheckboxItemClass(checkbox, permId) {
                        const label = document.getElementById(`chk-item-${permId}`);
                        if (label) {
                            if (checkbox.checked) {
                                label.classList.add('checked');
                            } else {
                                label.classList.remove('checked');
                            }
                        }
                    }

                    function showModal() {
                        const modal = document.getElementById('add-permission-modal');
                        modal.classList.add('show');
                    }

                    function closeModal() {
                        const modal = document.getElementById('add-permission-modal');
                        modal.classList.remove('show');
                        currentModalRoleId = null;
                    }

                    // Xử lý gửi AJAX thêm quyền
                    function submitAddPermissions() {
                        if (!currentModalRoleId) return;
                        
                        const checkedBoxes = document.querySelectorAll('input[name="modal_perm_ids[]"]:checked');
                        if (checkedBoxes.length === 0) {
                            alert('Vui lòng chọn ít nhất một quyền hạn để thêm.');
                            return;
                        }
                        
                        const permIds = Array.from(checkedBoxes).map(cb => cb.value);
                        
                        const formData = new FormData();
                        formData.append('action', 'add_role_permission');
                        formData.append('role_id', currentModalRoleId);
                        formData.append('csrf_token', csrfToken);
                        permIds.forEach(id => formData.append('perm_ids[]', id));
                        
                        const saveBtn = document.getElementById('btn-save-modal');
                        const originalText = saveBtn.innerText;
                        saveBtn.innerText = 'Đang lưu...';
                        saveBtn.disabled = true;
                        
                        fetch('admin.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        })
                        .then(response => response.json())
                        .then(res => {
                            saveBtn.innerText = originalText;
                            saveBtn.disabled = false;
                            
                            if (res.status === 'success') {
                                // Cập nhật local data
                                rolePermissionsData[currentModalRoleId].assigned = res.assigned;
                                rolePermissionsData[currentModalRoleId].available = res.available;
                                
                                // Vẽ lại UI
                                renderRolePermissions(currentModalRoleId);
                                
                                // Đóng modal
                                closeModal();
                                
                                // Hiển thị toast
                                showAdminToast(res.message, 'success');
                            } else {
                                alert('Lỗi: ' + res.message);
                            }
                        })
                        .catch(err => {
                            saveBtn.innerText = originalText;
                            saveBtn.disabled = false;
                            console.error(err);
                            alert('Lỗi kết nối hệ thống. Vui lòng thử lại.');
                        });
                    }

                    // Xử lý gửi AJAX thu hồi quyền
                    function removeRolePermission(roleId, permId, permName) {
                        if (!confirm(`Bạn có chắc chắn muốn thu hồi quyền [ ${permName} ] khỏi vai trò này không?`)) {
                            return;
                        }
                        
                        const formData = new FormData();
                        formData.append('action', 'remove_role_permission');
                        formData.append('role_id', roleId);
                        formData.append('perm_id', permId);
                        formData.append('csrf_token', csrfToken);
                        
                        fetch('admin.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        })
                        .then(response => response.json())
                        .then(res => {
                            if (res.status === 'success') {
                                // Cập nhật local data
                                rolePermissionsData[roleId].assigned = res.assigned;
                                rolePermissionsData[roleId].available = res.available;
                                
                                // Vẽ lại UI
                                renderRolePermissions(roleId);
                                
                                // Hiển thị toast
                                showAdminToast(res.message, 'success');
                            } else {
                                alert('Lỗi: ' + res.message);
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            alert('Lỗi kết nối hệ thống. Vui lòng thử lại.');
                        });
                    }

                    // Helper escape HTML bảo mật chống XSS
                    function escapeHtml(string) {
                        const matchHtmlRegExp = /["'&<>]/;
                        const str = '' + string;
                        const match = matchHtmlRegExp.exec(str);

                        if (!match) {
                            return str;
                        }

                        let escape;
                        let html = '';
                        let index = 0;
                        let lastIndex = 0;

                        for (index = match.index; index < str.length; index++) {
                            switch (str.charCodeAt(index)) {
                                case 34: // "
                                    escape = '&quot;';
                                    break;
                                case 38: // &
                                    escape = '&amp;';
                                    break;
                                case 39: // '
                                    escape = '&#39;';
                                    break;
                                case 60: // <
                                    escape = '&lt;';
                                    break;
                                case 62: // >
                                    escape = '&gt;';
                                    break;
                                default:
                                    continue;
                            }

                            if (lastIndex !== index) {
                                html += str.substring(lastIndex, index);
                            }

                            lastIndex = index + 1;
                            html += escape;
                        }

                        return lastIndex !== index
                            ? html + str.substring(lastIndex, index)
                            : html;
                    }

                    // Hàm hiện thông báo toast nhẹ nhàng
                    function showAdminToast(message, type = 'success') {
                        let container = document.getElementById('toast-container');
                        if (!container) {
                            container = document.createElement('div');
                            container.id = 'toast-container';
                            container.style.position = 'fixed';
                            container.style.bottom = '24px';
                            container.style.right = '24px';
                            container.style.zIndex = '99999';
                            container.style.display = 'flex';
                            container.style.flexDirection = 'column';
                            container.style.gap = '8px';
                            document.body.appendChild(container);
                        }
                        
                        const toast = document.createElement('div');
                        toast.style.background = type === 'success' ? '#10b981' : '#ef4444';
                        toast.style.color = '#ffffff';
                        toast.style.padding = '12px 24px';
                        toast.style.borderRadius = '10px';
                        toast.style.fontSize = '0.9rem';
                        toast.style.fontWeight = '600';
                        toast.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1)';
                        toast.style.transform = 'translateY(20px)';
                        toast.style.opacity = '0';
                        toast.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
                        toast.innerText = message;
                        
                        container.appendChild(toast);
                        
                        setTimeout(() => {
                            toast.style.transform = 'translateY(0)';
                            toast.style.opacity = '1';
                        }, 10);
                        
                        setTimeout(() => {
                            toast.style.transform = 'translateY(-20px)';
                            toast.style.opacity = '0';
                            setTimeout(() => {
                                toast.remove();
                            }, 300);
                        }, 3000);
                    }
                    </script>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 3: Quản lý Kho bãi & Tồn kho -->
        <div id="warehouse-tab" class="tab-content <?php echo $active_tab === 'warehouse' ? 'active' : ''; ?>" style="<?php echo $active_tab === 'warehouse' ? 'display: block;' : 'display: none;'; ?>">
            <?php if (!hasPermission($_SESSION['user_id'], 'warehouse.view')): ?>
                <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 40px; text-align: center; color: var(--text-muted); font-size: 1.1rem;">
                    🚫 Bạn không có quyền xem thông tin quản lý kho bãi và tồn kho.
                </div>
            <?php else: ?>
                <!-- Stats Overview for Warehouse -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-title">Tổng Điểm Kho Bãi</div>
                        <div class="stat-card-value"><?php echo number_format($total_warehouses); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-title">Tổng Số Lượng Tồn Kho</div>
                        <div class="stat-card-value" style="color: var(--primary);"><?php echo number_format($total_stock_qty); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-title">Sản Phẩm Sắp Hết Hàng (≤ 5)</div>
                        <div class="stat-card-value" style="color: #d97706;"><?php echo number_format($low_stock_count); ?></div>
                    </div>
                </div>

                <!-- Sub Tabs Navigation for Warehouse -->
                <div class="sub-tabs-container" style="display: flex; gap: 12px; margin-top: 24px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.06); padding-bottom: 8px;">
                    <button type="button" class="warehouse-sub-tab-btn active" onclick="switchWarehouseSubTab('warehouse-sub-hubs', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Danh Sách Kho Bãi (<?php echo $total_warehouses; ?>)</button>
                    <button type="button" class="warehouse-sub-tab-btn" onclick="switchWarehouseSubTab('warehouse-sub-stock', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Quản Lý Tồn Kho (<?php echo count($stock_product_list); ?>)</button>
                    <button type="button" class="warehouse-sub-tab-btn" onclick="switchWarehouseSubTab('warehouse-sub-logs', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Nhật Ký Luân Chuyển (<?php echo count($logistics_history); ?>)</button>
                </div>

                <!-- Sub Content 1: Danh sách Kho Bãi -->
                <div id="warehouse-sub-hubs" class="warehouse-sub-content active">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif; margin: 0;">Danh Sách Các Điểm Kho Trung Chuyển (Hubs)</h3>
                        <button type="button" class="btn btn-primary" onclick="openAddWarehouseModal()" style="padding: 10px 20px; border-radius: 12px; font-size: 0.85rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'warehouse.create') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>+ Thêm Kho Mới</button>
                    </div>
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã Kho</th>
                                    <th>Tên Kho</th>
                                    <th>Địa Chỉ Chi Tiết</th>
                                    <th>Tọa Độ (Vĩ độ / Kinh độ)</th>
                                    <th>Số Mặt Hàng Đã Qua Kho</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($warehouse_list)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có điểm kho trung chuyển nào trong hệ thống.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($warehouse_list as $wh): ?>
                                        <tr>
                                            <td><strong>#<?php echo $wh['MaKho']; ?></strong></td>
                                            <td><strong style="color: var(--primary);"><?php echo htmlspecialchars($wh['TenKho']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($wh['DiaChiKho']); ?></td>
                                            <td>
                                                <?php if (!empty($wh['ViDo']) && !empty($wh['KinhDo'])): ?>
                                                    <span style="font-size: 0.8rem; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; font-family: monospace;"><?php echo $wh['ViDo']; ?>, <?php echo $wh['KinhDo']; ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted); font-size: 0.8rem;">Chưa định vị</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700;"><?php echo number_format($wh['SoMatHangLogistics']); ?> lượt</span></td>
                                            <td>
                                                <div style="display: flex; gap: 8px;">
                                                    <button type="button" class="btn-action" style="background: #e0e7ff; color: #4338ca;" onclick="openEditWarehouseModal(<?php echo $wh['MaKho']; ?>, '<?php echo addslashes(htmlspecialchars($wh['TenKho'])); ?>', '<?php echo addslashes(htmlspecialchars($wh['DiaChiKho'])); ?>', '<?php echo $wh['ViDo'] ?? ''; ?>', '<?php echo $wh['KinhDo'] ?? ''; ?>')" <?php echo !hasPermission($_SESSION['user_id'], 'warehouse.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Sửa</button>

                                                    <form method="POST" action="admin.php" style="display: inline;" onsubmit="return confirm('Bạn có chắc muốn xóa điểm kho này?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                                        <input type="hidden" name="action" value="delete_warehouse">
                                                        <input type="hidden" name="ma_kho" value="<?php echo $wh['MaKho']; ?>">
                                                        <button type="submit" class="btn-action" style="background: #fee2e2; color: #b91c1c;" <?php echo !hasPermission($_SESSION['user_id'], 'warehouse.delete') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Xóa</button>
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

                <!-- Sub Content 2: Quản lý Tồn kho -->
                <div id="warehouse-sub-stock" class="warehouse-sub-content">
                    <div style="display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 260px;">
                            <input type="text" id="adminStockSearch" oninput="filterAdminStock()" placeholder="Tìm kiếm tên sản phẩm hoặc người bán..." class="form-control" style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08); font-size: 0.9rem; background: #fff;">
                        </div>
                        <div style="width: 200px;">
                            <select id="adminStockFilter" onchange="filterAdminStock()" class="form-control" style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08); font-size: 0.9rem; background: #fff; cursor: pointer;">
                                <option value="all">Tất cả số lượng</option>
                                <option value="low">Sắp hết (≤ 5 sản phẩm)</option>
                                <option value="out">Hết hàng (= 0)</option>
                            </select>
                        </div>
                    </div>

                    <div class="admin-table-card">
                        <table class="admin-table" id="stockTable">
                            <thead>
                                <tr>
                                    <th>Mã SP</th>
                                    <th>Tên Sản Phẩm</th>
                                    <th>Danh Mục</th>
                                    <th>Giá Bán</th>
                                    <th>Người Bán</th>
                                    <th>Số Lượng Tồn Kho</th>
                                    <th>Trạng Thái Kho</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock_product_list)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">Không có dữ liệu sản phẩm trong kho.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stock_product_list as $sp): ?>
                                        <?php 
                                            $qty = (int)$sp['SoLuongTon']; 
                                            $st_badge = '<span class="badge badge-success">✓ Đủ hàng</span>';
                                            if ($qty === 0) {
                                                $st_badge = '<span class="badge badge-danger">🚫 Hết hàng</span>';
                                            } elseif ($qty <= 5) {
                                                $st_badge = '<span class="badge badge-warning">⚠️ Sắp hết hàng</span>';
                                            }
                                        ?>
                                        <tr class="stock-row" data-name="<?php echo htmlspecialchars(mb_strtolower($sp['TenSanPham'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-seller="<?php echo htmlspecialchars(mb_strtolower($sp['TenNguoiBan'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" data-qty="<?php echo $qty; ?>">
                                            <td>#<?php echo $sp['MaSanPham']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($sp['TenSanPham']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($sp['TenDanhMuc']); ?></td>
                                            <td><strong style="color: var(--primary);"><?php echo number_format($sp['GiaBan'], 0, ',', '.'); ?> đ</strong></td>
                                            <td><?php echo htmlspecialchars($sp['TenNguoiBan']); ?></td>
                                            <td><strong style="font-size: 1rem; color: #0f172a;"><?php echo $qty; ?></strong></td>
                                            <td><?php echo $st_badge; ?></td>
                                            <td>
                                                <button type="button" class="btn-action" style="background: #e0f2fe; color: #0369a1;" onclick="openUpdateStockModal(<?php echo $sp['MaSanPham']; ?>, '<?php echo addslashes(htmlspecialchars($sp['TenSanPham'])); ?>', <?php echo $qty; ?>)" <?php echo !hasPermission($_SESSION['user_id'], 'warehouse.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Cập Nhật Tồn</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sub Content 3: Nhật ký Luân chuyển -->
                <div id="warehouse-sub-logs" class="warehouse-sub-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif; margin: 0;">Lịch Sử Luân Chuyển Hàng Hóa & Quét Barcode Kho</h3>
                        <button type="button" class="btn btn-primary" onclick="openAddStockLogModal()" style="padding: 10px 20px; border-radius: 12px; font-size: 0.85rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'warehouse.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>+ Ghi Log Luân Chuyển Thủ Công</button>
                    </div>
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã LS</th>
                                    <th>Mã Đơn / SP</th>
                                    <th>Tên Sản Phẩm</th>
                                    <th>Điểm Kho</th>
                                    <th>Nhân Viên Thực Hiện</th>
                                    <th>Hành Động Logistics</th>
                                    <th>Ghi Chú Chi Tiết</th>
                                    <th>Thời Gian Ghi Nhận</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logistics_history)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có nhật ký luân chuyển kho nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logistics_history as $log): ?>
                                        <tr>
                                            <td>#<?php echo $log['MaLichSu']; ?></td>
                                            <td>Đơn #<?php echo $log['MaDonHang']; ?> / SP #<?php echo $log['MaSanPham']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($log['TenSanPham']); ?></strong></td>
                                            <td><span style="font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($log['TenKho'] ?? 'Đang luân chuyển trên đường'); ?></span></td>
                                            <td><?php echo htmlspecialchars($log['TenNhanVien']); ?></td>
                                            <td><span class="badge" style="background: #f0fdf4; color: #166534; font-weight: 700;"><?php echo htmlspecialchars($log['HanhDong']); ?></span></td>
                                            <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($log['GhiChu'] ?? 'N/A'); ?></span></td>
                                            <td><span style="font-size: 0.8rem; color: #64748b;"><?php echo date('H:i d/m/Y', strtotime($log['ThoiGianGhiNhan'])); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 4: Quản lý Vận chuyển & Logistics -->
        <div id="shipping-tab" class="tab-content <?php echo $active_tab === 'shipping' ? 'active' : ''; ?>" style="<?php echo $active_tab === 'shipping' ? 'display: block;' : 'display: none;'; ?>">
            <?php if (!hasPermission($_SESSION['user_id'], 'shipping.view')): ?>
                <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 40px; text-align: center; color: var(--text-muted); font-size: 1.1rem;">
                    🚫 Bạn không có quyền xem thông tin quản lý vận chuyển và shipper.
                </div>
            <?php else: ?>
                <!-- Stats Overview for Shipping -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-title">Tổng Phiếu Nhiệm Vụ</div>
                        <div class="stat-card-value"><?php echo number_format($total_shipping_tasks); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-title">Đội Nguồn Shipper Sẵn Sàng</div>
                        <div class="stat-card-value" style="color: var(--primary);"><?php echo count($shipper_list); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-title">Biên Bản Sự Cố Đang Theo Dõi</div>
                        <div class="stat-card-value" style="color: #dc2626;"><?php echo count($incidents_list); ?></div>
                    </div>
                </div>

                <!-- Sub Tabs Navigation for Shipping -->
                <div class="sub-tabs-container" style="display: flex; gap: 12px; margin-top: 24px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.06); padding-bottom: 8px;">
                    <button type="button" class="shipping-sub-tab-btn active" onclick="switchShippingSubTab('shipping-sub-tasks', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Nhiệm Vụ Shipper (<?php echo $total_shipping_tasks; ?>)</button>
                    <button type="button" class="shipping-sub-tab-btn" onclick="switchShippingSubTab('shipping-sub-config', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Cấu Hình Cước Phí Ship</button>
                    <button type="button" class="shipping-sub-tab-btn" onclick="switchShippingSubTab('shipping-sub-incidents', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Biên Bản Sự Cố (<?php echo count($incidents_list); ?>)</button>
                </div>

                <!-- Sub Content 1: Nhiệm vụ Shipper -->
                <div id="shipping-sub-tasks" class="shipping-sub-content active">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif; margin: 0;">Danh Sách Phiếu Giao Nhận & Điều Phối Shipper</h3>
                        <button type="button" class="btn btn-primary" onclick="openCreateShippingTaskModal()" style="padding: 10px 20px; border-radius: 12px; font-size: 0.85rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'shipping.create') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>+ Phân Công Nhiệm Vụ Mới</button>
                    </div>
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã NV</th>
                                    <th>Đơn Hàng / Sản Phẩm</th>
                                    <th>Shipper Phụ Trách</th>
                                    <th>Loại Nhiệm Vụ</th>
                                    <th>Tiền COD Thu Hộ</th>
                                    <th>Trạng Thái Nhiệm Vụ</th>
                                    <th>Thời Gian Nhận / Hoàn Thành</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($shipping_tasks)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">Chưa có phiếu giao nhận shipper nào được tạo.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($shipping_tasks as $stask): ?>
                                        <?php 
                                            $st_code = decodeBitVal($stask['TrangThaiNhiemVu'] ?? null);
                                            $st_badge = '<span class="badge badge-warning">Chờ tiếp nhận</span>';
                                            if ($st_code === 1) $st_badge = '<span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">Đang thực hiện</span>';
                                            if ($st_code === 2) $st_badge = '<span class="badge badge-success">✓ Thành công</span>';
                                            if ($st_code === 3) $st_badge = '<span class="badge badge-danger">🚫 Thất bại</span>';
                                        ?>
                                        <tr>
                                            <td><strong>#<?php echo $stask['MaNhiemVu']; ?></strong></td>
                                            <td>
                                                <div>Đơn #<?php echo $stask['MaDonHang']; ?> - SP #<?php echo $stask['MaSanPham']; ?></div>
                                                <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($stask['TenSanPham']); ?></div>
                                            </td>
                                            <td>
                                                <strong style="color: var(--primary);"><?php echo htmlspecialchars($stask['TenShipper']); ?></strong>
                                                <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars($stask['SdtShipper'] ?? 'Chưa có SĐT'); ?></div>
                                            </td>
                                            <td><span class="badge" style="background: #f1f5f9; color: #475569; font-weight: 700;"><?php echo htmlspecialchars($stask['LoaiNhiemVu']); ?></span></td>
                                            <td><strong style="color: #d97706;"><?php echo number_format($stask['TienThuHo'], 0, ',', '.'); ?> đ</strong></td>
                                            <td><?php echo $st_badge; ?></td>
                                            <td>
                                                <div style="font-size: 0.8rem;">Nhận: <?php echo date('H:i d/m', strtotime($stask['NgayNhanNhiemVu'])); ?></div>
                                                <?php if (!empty($stask['NgayHoanThanh'])): ?>
                                                    <div style="font-size: 0.8rem; color: #16a34a;">Xong: <?php echo date('H:i d/m', strtotime($stask['NgayHoanThanh'])); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn-action" style="background: #e0f2fe; color: #0369a1;" onclick="openUpdateTaskStatusModal(<?php echo $stask['MaNhiemVu']; ?>, <?php echo $st_code; ?>, '<?php echo addslashes(htmlspecialchars($stask['LyDoThatBai'] ?? '')); ?>')" <?php echo !hasPermission($_SESSION['user_id'], 'shipping.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Cập Nhật</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sub Content 2: Cấu hình Cước Phí -->
                <div id="shipping-sub-config" class="shipping-sub-content">
                    <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 28px; max-width: 800px; margin: 0 auto;">
                        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 20px; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif;">Cấu Hình Cước Phí Vận Chuyển & Chiết Khấu Thành Viên</h3>
                        <form method="POST" action="admin.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="update_shipping_config">

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Phí mở cửa (2km đầu tiên - VNĐ) *</label>
                                    <input type="number" name="config[SHIP_OPENING_FEE]" value="<?php echo htmlspecialchars($shipping_configs['SHIP_OPENING_FEE']['GiaTri'] ?? '15000'); ?>" class="form-control" required>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Đơn giá km tiếp theo (VNĐ/km) *</label>
                                    <input type="number" name="config[SHIP_PER_KM_FEE]" value="<?php echo htmlspecialchars($shipping_configs['SHIP_PER_KM_FEE']['GiaTri'] ?? '5000'); ?>" class="form-control" required>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Hạn mức khối lượng hàng nhẹ (kg) *</label>
                                    <input type="number" step="0.1" name="config[SHIP_MAX_LIGHT_WEIGHT]" value="<?php echo htmlspecialchars($shipping_configs['SHIP_MAX_LIGHT_WEIGHT']['GiaTri'] ?? '2'); ?>" class="form-control" required>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Phụ phí cồng kềnh (VNĐ/kg vượt hạn mức) *</label>
                                    <input type="number" name="config[SHIP_HEAVY_SURCHARGE_FEE]" value="<?php echo htmlspecialchars($shipping_configs['SHIP_HEAVY_SURCHARGE_FEE']['GiaTri'] ?? '5000'); ?>" class="form-control" required>
                                </div>
                            </div>

                            <h4 style="font-size: 1rem; font-weight: 700; margin-bottom: 16px; color: var(--primary);">Giảm Giá Phí Ship Theo Hạng Thành Viên (Tỷ lệ 0.05 = 5%)</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Hạng Đồng (Bronze)</label>
                                    <input type="number" step="0.01" name="config[MEMBER_BRONZE_DISCOUNT]" value="<?php echo htmlspecialchars($shipping_configs['MEMBER_BRONZE_DISCOUNT']['GiaTri'] ?? '0.00'); ?>" class="form-control">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Hạng Bạc (Silver)</label>
                                    <input type="number" step="0.01" name="config[MEMBER_SILVER_DISCOUNT]" value="<?php echo htmlspecialchars($shipping_configs['MEMBER_SILVER_DISCOUNT']['GiaTri'] ?? '0.01'); ?>" class="form-control">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Hạng Vàng (Gold)</label>
                                    <input type="number" step="0.01" name="config[MEMBER_GOLD_DISCOUNT]" value="<?php echo htmlspecialchars($shipping_configs['MEMBER_GOLD_DISCOUNT']['GiaTri'] ?? '0.03'); ?>" class="form-control">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Hạng Kim Cương (Diamond)</label>
                                    <input type="number" step="0.01" name="config[MEMBER_DIAMOND_DISCOUNT]" value="<?php echo htmlspecialchars($shipping_configs['MEMBER_DIAMOND_DISCOUNT']['GiaTri'] ?? '0.05'); ?>" class="form-control">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; border-radius: 12px; font-size: 0.95rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'shipping.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Lưu Cấu Hình Cước Phí Vận Chuyển</button>
                        </form>
                    </div>
                </div>

                <!-- Sub Content 3: Biên bản Sự cố -->
                <div id="shipping-sub-incidents" class="shipping-sub-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); font-family: 'Be Vietnam Pro', sans-serif; margin: 0;">Quản Lý Biên Bản Sự Cố Vận Chuyển & Đền Bù Hàng Hóa</h3>
                        <button type="button" class="btn btn-primary" onclick="openCreateIncidentModal()" style="padding: 10px 20px; border-radius: 12px; font-size: 0.85rem; font-weight: 700;" <?php echo !hasPermission($_SESSION['user_id'], 'shipping.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>+ Lập Biên Bản Sự Cố Mới</button>
                    </div>
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã BB</th>
                                    <th>Đơn Hàng / Sản Phẩm</th>
                                    <th>Người Lập Biên Bản</th>
                                    <th>Loại Sự Cố</th>
                                    <th>Mô Tả Thiệt hại</th>
                                    <th>Giá Trị Thiệt Hại</th>
                                    <th>Số Tiền Đền Bù</th>
                                    <th>Trạng Thái Giải Quyết</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($incidents_list)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 30px;">Không có biên bản sự cố vận chuyển nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($incidents_list as $inc): ?>
                                        <?php 
                                            $inc_st = decodeBitVal($inc['TrangThai'] ?? null);
                                            $inc_badge = '<span class="badge badge-warning">Chờ xử lý</span>';
                                            if ($inc_st === 1) $inc_badge = '<span class="badge badge-success">✓ Đã đền bù</span>';
                                            if ($inc_st === 2) $inc_badge = '<span class="badge badge-danger">🚫 Từ chối</span>';
                                        ?>
                                        <tr>
                                            <td><strong>#<?php echo $inc['MaBienBan']; ?></strong></td>
                                            <td>Đơn #<?php echo $inc['MaDonHang']; ?> / SP #<?php echo $inc['MaSanPham']; ?><br><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($inc['TenSanPham']); ?></span></td>
                                            <td><?php echo htmlspecialchars($inc['TenNguoiLap']); ?></td>
                                            <td><span class="badge" style="background: #fee2e2; color: #b91c1c; font-weight: 700;"><?php echo htmlspecialchars($inc['LoaiSuCo']); ?></span></td>
                                            <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($inc['MoTaChiTiet']); ?></span></td>
                                            <td><strong style="color: #dc2626;"><?php echo number_format($inc['GiaTriThietHai'], 0, ',', '.'); ?> đ</strong></td>
                                            <td><strong style="color: #16a34a;"><?php echo number_format($inc['SoTienDenBu'], 0, ',', '.'); ?> đ</strong></td>
                                            <td><?php echo $inc_badge; ?></td>
                                            <td>
                                                <button type="button" class="btn-action" style="background: #e0e7ff; color: #4338ca;" onclick="openResolveIncidentModal(<?php echo $inc['MaBienBan']; ?>, <?php echo $inc_st; ?>, '<?php echo $inc['SoTienDenBu']; ?>')" <?php echo !hasPermission($_SESSION['user_id'], 'shipping.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Xử Lý</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 5: Quản lý Khiếu nại & Đánh giá -->
        <div id="complaints-tab" class="tab-content <?php echo $active_tab === 'complaints' ? 'active' : ''; ?>" style="<?php echo $active_tab === 'complaints' ? 'display: block;' : 'display: none;'; ?>">
            <?php if (!hasPermission($_SESSION['user_id'], 'complaint.view')): ?>
                <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 40px; text-align: center; color: var(--text-muted); font-size: 1.1rem;">
                    🚫 Bạn không có quyền xem danh sách khiếu nại và đánh giá.
                </div>
            <?php else: ?>
                <!-- Stats Overview for Complaints & Reviews -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-title">Tổng Đơn Khiếu Nại</div>
                        <div class="stat-card-value" style="color: #dc2626;"><?php echo number_format($total_complaints); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-title">Tổng Đánh Giá Sản Phẩm</div>
                        <div class="stat-card-value" style="color: #0284c7;"><?php echo number_format($total_reviews); ?></div>
                    </div>
                </div>

                <!-- Sub Tabs Navigation for Complaints -->
                <div class="sub-tabs-container" style="display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.06); padding-bottom: 8px; margin-top: 24px;">
                    <button type="button" class="complaint-sub-tab-btn active" onclick="switchComplaintsSubTab('complaint-sub-tickets', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Khiếu Nại & Trả Hàng (<?php echo $total_complaints; ?>)</button>
                    <button type="button" class="complaint-sub-tab-btn" onclick="switchComplaintsSubTab('complaint-sub-reviews', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Đánh Giá & Bình Luận (<?php echo $total_reviews; ?>)</button>
                </div>

                <!-- Sub Content 1: Đơn khiếu nại trả hàng -->
                <div id="complaint-sub-tickets" class="complaint-sub-content active" style="display: block;">
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã KN</th>
                                    <th>Đơn Hàng / Sản Phẩm</th>
                                    <th>Người Khiếu Nại</th>
                                    <th>Lý Do Khiếu Nại</th>
                                    <th>Video Unboxing</th>
                                    <th>Trạng Thái</th>
                                    <th>Kết Quả Xử Lý</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($complaint_list)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 24px;">Chưa có đơn khiếu nại trả hàng nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($complaint_list as $kn): 
                                        $kn_st = 0;
                                        if (is_string($kn['TrangThaiKhieuNai'])) {
                                            $kn_st = ord($kn['TrangThaiKhieuNai']);
                                            if ($kn_st === 49 || $kn['TrangThaiKhieuNai'] === '1') $kn_st = 1;
                                            elseif ($kn_st === 50 || $kn['TrangThaiKhieuNai'] === '2') $kn_st = 2;
                                            elseif ($kn_st === 48 || $kn['TrangThaiKhieuNai'] === '0') $kn_st = 0;
                                        } else {
                                            $kn_st = (int)$kn['TrangThaiKhieuNai'];
                                        }

                                        $kn_badge = '<span class="badge" style="background: #fef3c7; color: #d97706; font-weight: 700;">Chờ xử lý</span>';
                                        if ($kn_st === 1) {
                                            $kn_badge = '<span class="badge" style="background: #dcfce7; color: #15803d; font-weight: 700;">Đã chấp nhận</span>';
                                        } elseif ($kn_st === 2) {
                                            $kn_badge = '<span class="badge" style="background: #fee2e2; color: #b91c1c; font-weight: 700;">Đã từ chối</span>';
                                        }
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $kn['MaKhieuNai']; ?></strong></td>
                                            <td>Đơn #<?php echo $kn['MaDonHang']; ?><br><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($kn['TenSanPham']); ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($kn['TenNguoiKhieuNai']); ?></strong><br><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($kn['SdtNguoiKhieuNai'] ?? ''); ?></span></td>
                                            <td><span style="font-size: 0.85rem; color: var(--text-main); font-weight: 500;"><?php echo htmlspecialchars($kn['LyDoKhieuNai']); ?></span></td>
                                            <td>
                                                <?php if (!empty($kn['VideoUnboxing'])): ?>
                                                    <a href="<?php echo htmlspecialchars($kn['VideoUnboxing']); ?>" target="_blank" class="btn-action" style="background: #e0f2fe; color: #0369a1; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-size: 0.8rem; font-weight: 700; padding: 4px 10px; border-radius: 8px;">
                                                        🎥 Xem Video
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted); font-size: 0.8rem;">Không có</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $kn_badge; ?></td>
                                            <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($kn['KetQua'] ?? 'Chưa cập nhật'); ?></span></td>
                                            <td>
                                                <button type="button" class="btn-action" style="background: #e0e7ff; color: #4338ca;" onclick="openResolveComplaintModal(<?php echo $kn['MaKhieuNai']; ?>, <?php echo $kn_st; ?>, '<?php echo addslashes(htmlspecialchars($kn['KetQua'] ?? '')); ?>')" <?php echo !hasPermission($_SESSION['user_id'], 'complaint.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Xử Lý</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sub Content 2: Đánh giá & Phản hồi -->
                <div id="complaint-sub-reviews" class="complaint-sub-content" style="display: none;">
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã ĐG</th>
                                    <th>Sản Phẩm</th>
                                    <th>Người Đánh Giá</th>
                                    <th>Số Sao</th>
                                    <th>Nhận Xét / Bình Luận</th>
                                    <th>Thời Gian</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($review_list)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">Chưa có đánh giá sản phẩm nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($review_list as $dg): 
                                        $stars_html = str_repeat('⭐', (int)$dg['SoSao']);
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $dg['MaDanhGia']; ?></strong></td>
                                            <td>Đơn #<?php echo $dg['MaDonHang']; ?><br><span style="font-size: 0.85rem; font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($dg['TenSanPham']); ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($dg['TenNguoiDanhGia']); ?></strong></td>
                                            <td><span style="font-size: 1rem;"><?php echo $stars_html; ?></span> <strong style="font-size: 0.85rem; color: #d97706;"><?php echo $dg['SoSao']; ?>/5</strong></td>
                                            <td><span style="font-size: 0.88rem; color: var(--text-main); font-weight: 500;"><?php echo htmlspecialchars($dg['NhanXet'] ?? 'Không có nhận xét'); ?></span></td>
                                            <td><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($dg['NgayDanhGia'])); ?></span></td>
                                            <td>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc muốn xóa đánh giá này?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                                    <input type="hidden" name="action" value="delete_review">
                                                    <input type="hidden" name="ma_danh_gia" value="<?php echo $dg['MaDanhGia']; ?>">
                                                    <button type="submit" class="btn-action" style="background: #fee2e2; color: #b91c1c;" <?php echo !hasPermission($_SESSION['user_id'], 'complaint.delete') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Xóa</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 6: Quản lý Ví Điện Tử & Ngân Hàng -->
        <div id="wallet-tab" class="tab-content <?php echo $active_tab === 'wallet' ? 'active' : ''; ?>" style="<?php echo $active_tab === 'wallet' ? 'display: block;' : 'display: none;'; ?>">
            <?php if (!hasPermission($_SESSION['user_id'], 'wallet.view')): ?>
                <div style="background: rgba(255,255,255,0.8); border: 1px solid rgba(226,232,240,0.8); border-radius: 16px; padding: 40px; text-align: center; color: var(--text-muted); font-size: 1.1rem;">
                    🚫 Bạn không có quyền xem danh sách ví điện tử và ngân hàng.
                </div>
            <?php else: ?>
                <!-- Stats Overview for Wallet & Banking -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-title">Yêu Cầu Rút Tiền</div>
                        <div class="stat-card-value" style="color: #d97706;"><?php echo number_format($total_withdrawals); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-title">Ví Điện Tử Thành Viên</div>
                        <div class="stat-card-value" style="color: #10b981;"><?php echo number_format($total_wallets); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-title">Ngân Hàng Liên Kết</div>
                        <div class="stat-card-value" style="color: #6366f1;"><?php echo number_format($total_bank_accounts); ?></div>
                    </div>
                </div>

                <!-- Sub Tabs Navigation for Wallet -->
                <div class="sub-tabs-container" style="display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.06); padding-bottom: 8px; margin-top: 24px;">
                    <button type="button" class="wallet-sub-tab-btn active" onclick="switchWalletSubTab('wallet-sub-withdrawals', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Yêu Cầu Rút Tiền (<?php echo $total_withdrawals; ?>)</button>
                    <button type="button" class="wallet-sub-tab-btn" onclick="switchWalletSubTab('wallet-sub-wallets', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Ví Điện Tử (<?php echo $total_wallets; ?>)</button>
                    <button type="button" class="wallet-sub-tab-btn" onclick="switchWalletSubTab('wallet-sub-banks', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Ngân Hàng Liên Kết (<?php echo $total_bank_accounts; ?>)</button>
                    <button type="button" class="wallet-sub-tab-btn" onclick="switchWalletSubTab('wallet-sub-history', this)" style="padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border-radius: 50px; transition: all 0.2s;">Nhật Ký Dòng Tiền & Escrow</button>
                </div>

                <!-- Sub Content 1: Yêu cầu rút tiền -->
                <div id="wallet-sub-withdrawals" class="wallet-sub-content active" style="display: block;">
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã YC</th>
                                    <th>Người Rút</th>
                                    <th>Số Tiền</th>
                                    <th>Ngân Hàng Thụ Hưởng</th>
                                    <th>Số Tài Khoản</th>
                                    <th>Chủ Tài Khoản</th>
                                    <th>Trạng Thái</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($withdrawal_list)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 24px;">Chưa có yêu cầu rút tiền nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($withdrawal_list as $yc): 
                                        $yc_st = 0;
                                        if (is_string($yc['TrangThai'])) {
                                            $yc_st = ord($yc['TrangThai']);
                                            if ($yc_st === 49 || $yc['TrangThai'] === '1') $yc_st = 1;
                                            elseif ($yc_st === 50 || $yc['TrangThai'] === '2') $yc_st = 2;
                                            elseif ($yc_st === 48 || $yc['TrangThai'] === '0') $yc_st = 0;
                                        } else {
                                            $yc_st = (int)$yc['TrangThai'];
                                        }

                                        $yc_badge = '<span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 700;">Chờ chuyển khoản</span>';
                                        if ($yc_st === 1) {
                                            $yc_badge = '<span class="badge" style="background: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1; font-weight: 700;">Đã chuyển khoản</span>';
                                        } elseif ($yc_st === 2) {
                                            $yc_badge = '<span class="badge" style="background: #fee2e2; color: #b91c1c; font-weight: 700;">Đã từ chối</span>';
                                        }
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $yc['MaYeuCau']; ?></strong></td>
                                            <td><strong><?php echo htmlspecialchars($yc['HoTen']); ?></strong><br><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($yc['SoDienThoai'] ?? ''); ?></span></td>
                                            <td><strong style="color: #0284c7; font-size: 1.05rem;"><?php echo number_format($yc['SoTien'], 0, ',', '.'); ?> đ</strong></td>
                                            <td><span style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($yc['TenNganHang']); ?></span></td>
                                            <td><strong style="letter-spacing: 0.5px;"><?php echo htmlspecialchars($yc['SoTaiKhoan']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($yc['TenChuTaiKhoan']); ?></td>
                                            <td><?php echo $yc_badge; ?></td>
                                            <td>
                                                <button type="button" class="btn-action" style="background: #e0e7ff; color: #4338ca;" onclick="openResolveWithdrawalModal(<?php echo $yc['MaYeuCau']; ?>, <?php echo $yc_st; ?>, '<?php echo addslashes(htmlspecialchars($yc['LyDoTuChoi'] ?? '')); ?>')" <?php echo !hasPermission($_SESSION['user_id'], 'wallet.withdraw.approve') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Xử Lý</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sub Content 2: Danh sách Ví Điện Tử -->
                <div id="wallet-sub-wallets" class="wallet-sub-content" style="display: none;">
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã Ví</th>
                                    <th>Chủ Sở Hữu</th>
                                    <th>Email / SĐT</th>
                                    <th>Số Dư Ví</th>
                                    <th>Trạng Thái Ví</th>
                                    <th>Cập Nhật Lần Cuối</th>
                                    <th>Hành Động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($wallet_list)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">Chưa có dữ liệu ví điện tử.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($wallet_list as $w): 
                                        $w_st = 1;
                                        if (is_string($w['TrangThaiVi'])) {
                                            $w_st = (ord($w['TrangThaiVi']) === 1 || $w['TrangThaiVi'] === '1') ? 1 : 0;
                                        } else {
                                            $w_st = (int)$w['TrangThaiVi'];
                                        }
                                        $w_badge = ($w_st === 1) ? '<span class="badge badge-success" style="background: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1; font-weight: 700;">Hoạt động</span>' : '<span class="badge badge-danger" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 700;">Bị khóa</span>';
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $w['MaVi']; ?></strong></td>
                                            <td><strong><?php echo htmlspecialchars($w['HoTen']); ?></strong></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($w['Email'] ?? 'N/A'); ?></div>
                                                <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($w['SoDienThoai'] ?? ''); ?></div>
                                            </td>
                                            <td><strong style="color: #16a34a; font-size: 1.05rem;"><?php echo number_format($w['SoDu'], 0, ',', '.'); ?> đ</strong></td>
                                            <td><?php echo $w_badge; ?></td>
                                            <td><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($w['NgayCapNhat'])); ?></span></td>
                                            <td>
                                                <div style="display: flex; gap: 6px;">
                                                    <button type="button" class="btn-action" style="background: #e0f2fe; color: #0369a1;" onclick="openAdjustWalletModal(<?php echo $w['MaVi']; ?>, '<?php echo addslashes(htmlspecialchars($w['HoTen'])); ?>', <?php echo $w['SoDu']; ?>)" <?php echo !hasPermission($_SESSION['user_id'], 'wallet.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>Nạp/Trừ</button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc muốn đổi trạng thái ví này?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                                        <input type="hidden" name="action" value="toggle_wallet_status">
                                                        <input type="hidden" name="ma_vi" value="<?php echo $w['MaVi']; ?>">
                                                        <input type="hidden" name="target_status" value="<?php echo $w_st === 1 ? 0 : 1; ?>">
                                                        <button type="submit" class="btn-action" style="<?php echo $w_st === 1 ? 'background: #fee2e2; color: #b91c1c;' : 'background: #dcfce7; color: #15803d;'; ?>" <?php echo !hasPermission($_SESSION['user_id'], 'wallet.update') ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                                                            <?php echo $w_st === 1 ? 'Khóa Ví' : 'Mở Khóa'; ?>
                                                        </button>
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

                <!-- Sub Content 3: Tài Khoản Ngân Hàng Liên Kết -->
                <div id="wallet-sub-banks" class="wallet-sub-content" style="display: none;">
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã TK</th>
                                    <th>Thành Viên</th>
                                    <th>Tên Ngân Hàng</th>
                                    <th>Chi Nhánh</th>
                                    <th>Số Tài Khoản</th>
                                    <th>Tên Chủ Tài Khoản</th>
                                    <th>Ngày Liên Kết</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bank_account_list)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">Chưa có tài khoản ngân hàng liên kết nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($bank_account_list as $bk): ?>
                                        <tr>
                                            <td><strong>#<?php echo $bk['MaTaiKhoan']; ?></strong></td>
                                            <td><strong><?php echo htmlspecialchars($bk['TenNguoiDung']); ?></strong><br><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($bk['Email'] ?? ''); ?></span></td>
                                            <td><span style="font-weight: 700; color: #0284c7;"><?php echo htmlspecialchars($bk['TenNganHang']); ?></span></td>
                                            <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($bk['ChiNhanh'] ?? 'Trụ sở chính'); ?></span></td>
                                            <td><strong style="letter-spacing: 0.5px;"><?php echo htmlspecialchars($bk['SoTaiKhoan']); ?></strong></td>
                                            <td><strong><?php echo htmlspecialchars($bk['TenChuTaiKhoan']); ?></strong></td>
                                            <td><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($bk['NgayLienKet'])); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sub Content 4: Nhật ký dòng tiền & Escrow -->
                <div id="wallet-sub-history" class="wallet-sub-content" style="display: none;">
                    <div class="admin-table-card">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Mã GD</th>
                                    <th>Ví Nguồn / Từ</th>
                                    <th>Ví Đích / Đến</th>
                                    <th>Số Tiền</th>
                                    <th>Loại Giao Dịch</th>
                                    <th>Diễn Giải / Nội Dung</th>
                                    <th>Thời Gian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($wallet_history_list)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">Chưa có lịch sử giao dịch ví.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($wallet_history_list as $log): ?>
                                        <tr>
                                            <td><strong>#<?php echo $log['MaGiaoDich']; ?></strong></td>
                                            <td><?php echo !empty($log['TenNguoiNguon']) ? '<strong>' . htmlspecialchars($log['TenNguoiNguon']) . '</strong> (Ví #' . $log['MaViNguon'] . ')' : '<span style="color: var(--text-muted);">Hệ thống / Ngân hàng</span>'; ?></td>
                                            <td><?php echo !empty($log['TenNguoiDich']) ? '<strong>' . htmlspecialchars($log['TenNguoiDich']) . '</strong> (Ví #' . $log['MaViDich'] . ')' : '<span style="color: var(--text-muted);">Hệ thống / Ngân hàng</span>'; ?></td>
                                            <td><strong style="color: #2563eb; font-size: 0.95rem;"><?php echo number_format($log['SoTien'], 0, ',', '.'); ?> đ</strong></td>
                                            <td><span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700;"><?php echo htmlspecialchars($log['LoaiGiaoDich']); ?></span></td>
                                            <td><span style="font-size: 0.85rem; color: var(--text-main);"><?php echo htmlspecialchars($log['MoTa'] ?? ''); ?></span></td>
                                            <td><span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($log['NgayTao'])); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
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

                            <!-- Nút Thao Tác Duyệt / Cấm / Xem Trang Web -->
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <a id="admin_modal_web_link" href="#" target="_blank" class="btn" style="background: #e0e7ff; color: #4338ca; border-radius: 12px; padding: 10px 14px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">🔗 Trang Sản Phẩm</a>
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

            <!-- Modal Thêm Kho Mới -->
            <div id="addWarehouseModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 500px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Thêm Điểm Kho Trung Chuyển Mới</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="add_warehouse">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Tên Kho *</label>
                            <input type="text" name="ten_kho" class="form-control" placeholder="VD: Kho Trung Chuyển Cầu Giấy" required>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Địa Chỉ Chi Tiết *</label>
                            <input type="text" name="dia_chi_kho" class="form-control" placeholder="VD: Số 100 Đường Xuân Thủy, Cầu Giấy, Hà Nội" required>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Vĩ Độ (Latitude)</label>
                                <input type="number" step="any" name="vi_do" class="form-control" placeholder="21.0367">
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Kinh Độ (Longitude)</label>
                                <input type="number" step="any" name="kinh_do" class="form-control" placeholder="105.7825">
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeAddWarehouseModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Thêm Kho</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Sửa Kho -->
            <div id="editWarehouseModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 500px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Chỉnh Sửa Kho Bãi</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="edit_warehouse">
                        <input type="hidden" name="ma_kho" id="edit_wh_id">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Tên Kho *</label>
                            <input type="text" name="ten_kho" id="edit_wh_name" class="form-control" required>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Địa Chỉ Chi Tiết *</label>
                            <input type="text" name="dia_chi_kho" id="edit_wh_addr" class="form-control" required>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Vĩ Độ (Latitude)</label>
                                <input type="number" step="any" name="vi_do" id="edit_wh_lat" class="form-control">
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Kinh Độ (Longitude)</label>
                                <input type="number" step="any" name="kinh_do" id="edit_wh_lng" class="form-control">
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeEditWarehouseModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Lưu Thay Đổi</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Cập Nhật Số Lượng Tồn Kho -->
            <div id="updateStockModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 450px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 12px; font-size: 1.2rem;">Cập Nhật Số Lượng Tồn Kho</h3>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 16px;" id="update_stock_sp_name"></p>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="update_product_stock">
                        <input type="hidden" name="product_id" id="update_stock_pid">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Số Lượng Tồn Kho *</label>
                            <input type="number" name="so_luong_ton" id="update_stock_qty" min="0" class="form-control" required>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeUpdateStockModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Cập Nhật</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Ghi Log Luân Chuyển Kho -->
            <div id="addStockLogModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 550px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Ghi Log Luân Chuyển Kho</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="add_stock_log">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Chọn Sản Phẩm / Đơn Hàng *</label>
                            <select name="ma_don_hang_san_pham" onchange="const parts = this.value.split('_'); document.getElementById('log_ma_dh').value = parts[0]; document.getElementById('log_ma_sp').value = parts[1];" class="form-control" required style="cursor: pointer;">
                                <option value="">-- Chọn Đơn Hàng & Sản Phẩm --</option>
                                <?php foreach ($order_items_list as $oi): ?>
                                    <option value="<?php echo $oi['MaDonHang']; ?>_<?php echo $oi['MaSanPham']; ?>">Đơn #<?php echo $oi['MaDonHang']; ?> - SP #<?php echo $oi['MaSanPham']; ?>: <?php echo htmlspecialchars($oi['TenSanPham']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="ma_don_hang" id="log_ma_dh">
                            <input type="hidden" name="ma_san_pham" id="log_ma_sp">
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Chọn Kho Trung Chuyển (nếu có)</label>
                            <select name="ma_kho" class="form-control" style="cursor: pointer;">
                                <option value="">-- Không qua kho (Đang vận chuyển trên đường) --</option>
                                <?php foreach ($warehouse_list as $wh): ?>
                                    <option value="<?php echo $wh['MaKho']; ?>"><?php echo htmlspecialchars($wh['TenKho']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Hành Động Logistics *</label>
                            <select name="hanh_dong" class="form-control" style="cursor: pointer;">
                                <option value="Nhập kho">Nhập kho</option>
                                <option value="Xuất kho">Xuất kho</option>
                                <option value="Phân loại">Phân loại</option>
                                <option value="Đang vận chuyển">Đang vận chuyển</option>
                                <option value="Lưu kho hoàn trả">Lưu kho hoàn trả</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Ghi Chú Chi Tiết</label>
                            <input type="text" name="ghi_chu" class="form-control" placeholder="VD: Kiểm tra gói hàng nguyên vẹn, đã phân loại sang khu vực Giao Cầu Giấy">
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeAddStockLogModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Ghi Log</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Phân Công Nhiệm Vụ Shipper -->
            <div id="createShippingTaskModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 550px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Phân Công Nhiệm Vụ Shipper</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="create_shipping_task">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Chọn Đơn Hàng & Sản Phẩm *</label>
                            <select name="ma_don_hang_san_pham" onchange="const parts = this.value.split('_'); document.getElementById('ship_ma_dh').value = parts[0]; document.getElementById('ship_ma_sp').value = parts[1];" class="form-control" required style="cursor: pointer;">
                                <option value="">-- Chọn Đơn Hàng & Sản Phẩm --</option>
                                <?php foreach ($order_items_list as $oi): ?>
                                    <option value="<?php echo $oi['MaDonHang']; ?>_<?php echo $oi['MaSanPham']; ?>">Đơn #<?php echo $oi['MaDonHang']; ?> - SP #<?php echo $oi['MaSanPham']; ?>: <?php echo htmlspecialchars($oi['TenSanPham']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="ma_don_hang" id="ship_ma_dh">
                            <input type="hidden" name="ma_san_pham" id="ship_ma_sp">
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Chọn Shipper Phụ Trách *</label>
                            <select name="ma_shipper" class="form-control" required style="cursor: pointer;">
                                <option value="">-- Chọn Shipper --</option>
                                <?php foreach ($shipper_list as $shp): ?>
                                    <option value="<?php echo $shp['MaNguoiDung']; ?>"><?php echo htmlspecialchars($shp['HoTen']); ?> (<?php echo htmlspecialchars($shp['SoDienThoai'] ?? 'Không SĐT'); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Loại Nhiệm Vụ *</label>
                            <select name="loai_nhiem_vu" class="form-control" style="cursor: pointer;">
                                <option value="Lấy hàng">Lấy hàng (Từ Seller về Kho/Shipper)</option>
                                <option value="Giao hàng">Giao hàng (Đến Buyer)</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Số Tiền COD Thu Hộ (VNĐ)</label>
                            <input type="number" name="tien_thu_ho" class="form-control" placeholder="0" value="0">
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeCreateShippingTaskModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Phân Công</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Cập Nhật Trạng Thái Nhiệm Vụ Shipper -->
            <div id="updateTaskStatusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 450px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Cập Nhật Trạng Thái Nhiệm Vụ</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="update_shipping_task_status">
                        <input type="hidden" name="ma_nhiem_vu" id="task_st_id">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Trạng Thái Mới *</label>
                            <select name="trang_thai_code" id="task_st_code" class="form-control" style="cursor: pointer;" onchange="if(this.value === '3') { document.getElementById('task_reason_div').style.display='block'; } else { document.getElementById('task_reason_div').style.display='none'; }">
                                <option value="0">Chờ tiếp nhận</option>
                                <option value="1">Đang thực hiện</option>
                                <option value="2">Thành công</option>
                                <option value="3">Thất bại</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px; display: none;" id="task_reason_div">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Lý Do Thất Bại</label>
                            <input type="text" name="ly_do_that_bai" id="task_st_reason" class="form-control" placeholder="VD: Khách hàng hẹn lại sang ngày mai / Không nghe máy">
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeUpdateTaskStatusModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Lưu Trạng Thái</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Lập Biên Bản Sự Cố -->
            <div id="createIncidentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 550px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Lập Biên Bản Sự Cố Vận Chuyển</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="create_incident_report">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Chọn Đơn Hàng & Sản Phẩm *</label>
                            <select name="ma_don_hang_san_pham" onchange="const parts = this.value.split('_'); document.getElementById('inc_ma_dh').value = parts[0]; document.getElementById('inc_ma_sp').value = parts[1];" class="form-control" required style="cursor: pointer;">
                                <option value="">-- Chọn Đơn Hàng & Sản Phẩm --</option>
                                <?php foreach ($order_items_list as $oi): ?>
                                    <option value="<?php echo $oi['MaDonHang']; ?>_<?php echo $oi['MaSanPham']; ?>">Đơn #<?php echo $oi['MaDonHang']; ?> - SP #<?php echo $oi['MaSanPham']; ?>: <?php echo htmlspecialchars($oi['TenSanPham']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="ma_don_hang" id="inc_ma_dh">
                            <input type="hidden" name="ma_san_pham" id="inc_ma_sp">
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Loại Sự Cố *</label>
                            <select name="loai_su_co" class="form-control" style="cursor: pointer;">
                                <option value="Hao hụt">Hao hụt</option>
                                <option value="Hư hỏng sản phẩm">Hư hỏng sản phẩm</option>
                                <option value="Mất gói hàng">Mất gói hàng</option>
                                <option value="Khách trả hàng vỡ">Khách trả hàng vỡ</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Giá Trị Thiệt hại (VNĐ) *</label>
                            <input type="number" name="gia_tri_thiet_hai" class="form-control" placeholder="VD: 500000" required>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Mô Tả Chi Tiết Sự Cố *</label>
                            <textarea name="mo_ta_chi_tiet" class="form-control" rows="3" placeholder="VD: Gói hàng bị móp vỡ trong quá trình vận chuyển từ Kho đến tay người mua..." required></textarea>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeCreateIncidentModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Lập Biên Bản</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Xử Lý Biên Bản Sự Cố -->
            <div id="resolveIncidentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 450px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem;">Xử Lý Đền Bù Sự Cố</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="resolve_incident_report">
                        <input type="hidden" name="ma_bien_ban" id="res_inc_id">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Trạng Thái Xử Lý *</label>
                            <select name="trang_thai_code" id="res_inc_st" class="form-control" style="cursor: pointer;">
                                <option value="1">Đã chấp nhận đền bù</option>
                                <option value="2">Từ chối đền bù</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Số Tiền Đền Bù (VNĐ)</label>
                            <input type="number" name="so_tien_den_bu" id="res_inc_amount" class="form-control" placeholder="0">
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" class="btn btn-outline" onclick="closeResolveIncidentModal()">Hủy</button>
                            <button type="submit" class="btn btn-primary">Xác Nhận Xử Lý</button>
            <!-- Modal Xử Lý Khiếu Nại -->
            <div id="resolveComplaintModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 520px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem; font-family: 'Be Vietnam Pro', sans-serif;">Xử Lý Đơn Khiếu Nại Trả Hàng</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="resolve_complaint">
                        <input type="hidden" name="ma_khieu_nai" id="resolve_kn_id">
                        
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Quyết Định Xử Lý *</label>
                            <select name="trang_thai_code" id="resolve_kn_status" class="form-control" style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08); background: #ffffff;">
                                <option value="1">✅ Chấp nhận trả hàng & Hoàn tiền (Đã duyệt)</option>
                                <option value="2">❌ Từ chối khiếu nại (Không hợp lệ)</option>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Ý kiến giải quyết của Quản trị viên / Kết quả *</label>
                            <textarea name="ket_qua" id="resolve_kn_result" class="form-control" rows="3" placeholder="Nhập lý do hoặc thông báo kết quả cho người mua & người bán..." style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08); font-family: inherit;" required></textarea>
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" onclick="closeResolveComplaintModal()" class="btn btn-outline" style="padding: 10px 20px; border-radius: 12px;">Hủy</button>
                            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; border-radius: 12px; font-weight: 700;">Lưu Kết Quả</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Xử Lý Rút Tiền -->
            <div id="resolveWithdrawalModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 520px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem; font-family: 'Be Vietnam Pro', sans-serif;">Xử Lý Lệnh Rút Tiền Ngân Hàng</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="resolve_withdrawal">
                        <input type="hidden" name="ma_yeu_cau" id="resolve_yc_id">
                        
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Trạng Thái Chuyển Khoản *</label>
                            <select name="trang_thai_code" id="resolve_yc_status" class="form-control" style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08); background: #ffffff;">
                                <option value="1">✅ Đã chuyển khoản thành công (Duyệt)</option>
                                <option value="2">❌ Từ chối rút tiền (Không hợp lệ)</option>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Lý Do Từ Chối / Ghi Chú</label>
                            <input type="text" name="ly_do_tu_choi" id="resolve_yc_reason" class="form-control" placeholder="Nhập lý do nếu từ chối (VD: Số tài khoản ngân hàng sai)..." style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08);">
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" onclick="closeResolveWithdrawalModal()" class="btn btn-outline" style="padding: 10px 20px; border-radius: 12px;">Hủy</button>
                            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; border-radius: 12px; font-weight: 700;">Lưu Kết Quả</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Điều Chỉnh Số Dư Ví -->
            <div id="adjustWalletModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 20px; max-width: 500px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                    <h3 style="margin-bottom: 16px; font-size: 1.2rem; font-family: 'Be Vietnam Pro', sans-serif;">Điều Chỉnh Số Dư Ví Điện Tử</h3>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="adjust_wallet_balance">
                        <input type="hidden" name="ma_vi" id="adjust_w_id">
                        
                        <div style="margin-bottom: 12px; font-size: 0.9rem; color: var(--text-muted);">
                            Chủ ví: <strong id="adjust_w_name" style="color: #1e293b;"></strong><br>
                            Số dư hiện tại: <strong id="adjust_w_current" style="color: #16a34a;"></strong>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Loại Thao Tác *</label>
                            <select name="adjust_type" class="form-control" style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08); background: #ffffff;">
                                <option value="add">➕ Nạp (Cộng tiền vào ví)</option>
                                <option value="deduct">➖ Trừ (Trừ tiền khỏi ví)</option>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Số Tiền Điều Chỉnh (VNĐ) *</label>
                            <input type="number" name="so_tien" class="form-control" placeholder="100000" min="1000" step="1000" required style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08);">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">Ghi Chú Diễn Giải</label>
                            <input type="text" name="ghi_chu" class="form-control" placeholder="VD: Nạp khuyến mãi, Đền bù khiếu nại đơn #102..." style="width: 100%; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.08);">
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <button type="button" onclick="closeAdjustWalletModal()" class="btn btn-outline" style="padding: 10px 20px; border-radius: 12px;">Hủy</button>
                            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; border-radius: 12px; font-weight: 700;">Xác Nhận</button>
                        </div>
                    </form>
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
            document.querySelectorAll('.tab-content').forEach(el => {
                el.classList.remove('active');
                el.style.display = 'none';
            });
            document.querySelectorAll('.menu-item').forEach(el => el.classList.remove('active'));
            
            const targetEl = document.getElementById(tabId);
            if (targetEl) {
                targetEl.classList.add('active');
                targetEl.style.display = 'block';
            }
            
            if (btn) {
                btn.classList.add('active');
            } else {
                const foundBtn = document.querySelector(`.menu-item[onclick*="${tabId}"]`) || document.getElementById('menu-' + tabId.replace('-tab', ''));
                if (foundBtn) foundBtn.classList.add('active');
            }

            if (title) {
                const pageTitleEl = document.getElementById('pageTitle');
                if (pageTitleEl) pageTitleEl.textContent = title;
            }

            const sb = document.querySelector('.dashboard-sidebar');
            if (sb) {
                sb.classList.remove('show');
            }

            // Lưu tab hoạt động vào sessionStorage
            sessionStorage.setItem('admin_active_tab', tabId);
            sessionStorage.setItem('admin_active_tab_title', title || '');

            // Đồng bộ tham số tab vào URL mà không làm tải lại trang
            try {
                const shortTab = tabId.replace('-tab', '');
                const url = new URL(window.location.href);
                url.searchParams.set('tab', shortTab);
                window.history.replaceState({}, '', url.toString());
            } catch(e) {}
        }

        function switchProductMainTab(viewId, btn) {
            document.querySelectorAll('.product-main-view').forEach(el => {
                el.style.display = 'none';
                el.classList.remove('active');
            });
            document.querySelectorAll('.product-main-tab-btn').forEach(el => {
                el.classList.remove('active');
                el.style.background = 'transparent';
                el.style.color = 'var(--text-muted)';
                el.style.boxShadow = 'none';
            });

            const target = document.getElementById(viewId);
            if (target) {
                target.style.display = 'block';
                target.classList.add('active');
            }
            if (btn) {
                btn.classList.add('active');
                btn.style.background = '#ffffff';
                btn.style.color = 'var(--primary)';
                btn.style.boxShadow = '0 2px 8px rgba(0,0,0,0.06)';
            }
            sessionStorage.setItem('admin_product_main_view', viewId);
        }

        function switchProductSubTab(subTabId, btn) {
            document.querySelectorAll('.product-sub-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.sub-tab-btn').forEach(el => el.classList.remove('active'));
            const targetEl = document.getElementById(subTabId);
            if (targetEl) targetEl.classList.add('active');
            if (btn) btn.classList.add('active');
            // Lưu sub-tab hoạt động vào sessionStorage
            sessionStorage.setItem('admin_product_sub_tab', subTabId);
            
            // Ẩn/hiện bộ tìm kiếm và phân trang của sản phẩm khi chuyển sang sub-tab danh mục
            const searchFilter = document.getElementById('admin-product-search-filter');
            const pagination = document.getElementById('admin-product-pagination');
            if (subTabId === 'product-sub-categories') {
                if (searchFilter) searchFilter.style.display = 'none';
                if (pagination) pagination.style.display = 'none';
            } else {
                if (searchFilter) searchFilter.style.display = 'flex';
                if (pagination) pagination.style.display = 'flex';
            }
        }

        function switchUserSubTab(subTabId, btn) {
            document.querySelectorAll('.user-sub-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.user-sub-tab-btn').forEach(el => el.classList.remove('active'));
            const targetEl = document.getElementById(subTabId);
            if (targetEl) targetEl.classList.add('active');
            if (btn) btn.classList.add('active');
            // Lưu sub-tab hoạt động vào sessionStorage
            sessionStorage.setItem('admin_user_sub_tab', subTabId);
        }

        function switchWarehouseSubTab(subTabId, btn) {
            document.querySelectorAll('.warehouse-sub-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.warehouse-sub-tab-btn').forEach(el => el.classList.remove('active'));
            const targetEl = document.getElementById(subTabId);
            if (targetEl) targetEl.classList.add('active');
            if (btn) btn.classList.add('active');
            sessionStorage.setItem('admin_warehouse_sub_tab', subTabId);
        }

        function switchShippingSubTab(subTabId, btn) {
            document.querySelectorAll('.shipping-sub-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.shipping-sub-tab-btn').forEach(el => el.classList.remove('active'));
            const targetEl = document.getElementById(subTabId);
            if (targetEl) targetEl.classList.add('active');
            if (btn) btn.classList.add('active');
            sessionStorage.setItem('admin_shipping_sub_tab', subTabId);
        }

        function switchComplaintsSubTab(subTabId, btn) {
            document.querySelectorAll('.complaint-sub-content').forEach(el => {
                el.style.display = 'none';
                el.classList.remove('active');
            });
            document.querySelectorAll('.complaint-sub-tab-btn').forEach(el => el.classList.remove('active'));
            const targetEl = document.getElementById(subTabId);
            if (targetEl) {
                targetEl.style.display = 'block';
                targetEl.classList.add('active');
            }
            if (btn) btn.classList.add('active');
            sessionStorage.setItem('admin_complaint_sub_tab', subTabId);
        }

        function switchWalletSubTab(subTabId, btn) {
            document.querySelectorAll('.wallet-sub-content').forEach(el => {
                el.style.display = 'none';
                el.classList.remove('active');
            });
            document.querySelectorAll('.wallet-sub-tab-btn').forEach(el => el.classList.remove('active'));
            const targetEl = document.getElementById(subTabId);
            if (targetEl) {
                targetEl.style.display = 'block';
                targetEl.classList.add('active');
            }
            if (btn) btn.classList.add('active');
            sessionStorage.setItem('admin_wallet_sub_tab', subTabId);
        }

        function openResolveWithdrawalModal(ycId, stCode, reasonText) {
            document.getElementById('resolve_yc_id').value = ycId;
            document.getElementById('resolve_yc_status').value = stCode > 0 ? stCode : 1;
            document.getElementById('resolve_yc_reason').value = reasonText || '';
            const modal = document.getElementById('resolveWithdrawalModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeResolveWithdrawalModal() {
            const modal = document.getElementById('resolveWithdrawalModal');
            if (modal) modal.style.display = 'none';
        }

        function openAdjustWalletModal(wId, wName, currentBalance) {
            document.getElementById('adjust_w_id').value = wId;
            document.getElementById('adjust_w_name').textContent = wName;
            document.getElementById('adjust_w_current').textContent = new Intl.NumberFormat('vi-VN').format(currentBalance) + ' đ';
            const modal = document.getElementById('adjustWalletModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeAdjustWalletModal() {
            const modal = document.getElementById('adjustWalletModal');
            if (modal) modal.style.display = 'none';
        }

        function openResolveComplaintModal(knId, stCode, resultText) {
            document.getElementById('resolve_kn_id').value = knId;
            document.getElementById('resolve_kn_status').value = stCode > 0 ? stCode : 1;
            document.getElementById('resolve_kn_result').value = resultText || '';
            const modal = document.getElementById('resolveComplaintModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeResolveComplaintModal() {
            const modal = document.getElementById('resolveComplaintModal');
            if (modal) modal.style.display = 'none';
        }

        function filterAdminStock() {
            const searchVal = document.getElementById('adminStockSearch').value.toLowerCase().trim();
            const filterVal = document.getElementById('adminStockFilter').value;

            document.querySelectorAll('.stock-row').forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const seller = row.getAttribute('data-seller') || '';
                const qty = parseInt(row.getAttribute('data-qty') || '0');

                const matchesSearch = name.includes(searchVal) || seller.includes(searchVal);
                let matchesFilter = true;
                if (filterVal === 'low') matchesFilter = (qty <= 5);
                if (filterVal === 'out') matchesFilter = (qty === 0);

                if (matchesSearch && matchesFilter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
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
            // Gắn sự kiện click trực tiếp cho tất cả các nút menu sidebar làm dự phòng
            document.querySelectorAll('.sidebar-menu .menu-item').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    const onclickAttr = this.getAttribute('onclick') || '';
                    const match = onclickAttr.match(/switchTab\('([^']+)',\s*this,\s*'([^']+)'\)/);
                    if (match) {
                        const tabId = match[1];
                        const title = match[2];
                        switchTab(tabId, this, title);
                    }
                });
            });

            const tabMap = {
                'overview': ['overview-tab', 'Tổng Quan Hệ Thống'],
                'products': ['products-tab', 'Quản Lý Sản Phẩm và Danh Mục'],
                'users': ['users-tab', 'Quản Lý Tài Khoản và Quyền'],
                'warehouse': ['warehouse-tab', 'Quản Lý Kho Bãi & Tồn Kho'],
                'shipping': ['shipping-tab', 'Quản Lý Vận Chuyển & Logistics'],
                'complaints': ['complaints-tab', 'Quản Lý Khiếu Nại & Đánh Giá'],
                'wallet': ['wallet-tab', 'Quản Lý Ví Điện Tử & Ngân Hàng']
            };

            const urlParams = new URLSearchParams(window.location.search);
            const urlTab = urlParams.get('tab');

            let targetTabId = null;
            let targetTitle = null;

            if (urlTab && tabMap[urlTab]) {
                [targetTabId, targetTitle] = tabMap[urlTab];
            } else {
                const activeTabId = sessionStorage.getItem('admin_active_tab');
                const activeTabTitle = sessionStorage.getItem('admin_active_tab_title');
                if (activeTabId && document.getElementById(activeTabId)) {
                    targetTabId = activeTabId;
                    targetTitle = activeTabTitle;
                } else {
                    targetTabId = 'overview-tab';
                    targetTitle = 'Tổng Quan Hệ Thống';
                }
            }

            const targetBtn = document.querySelector(`.menu-item[onclick*="${targetTabId}"]`) || document.getElementById('menu-' + targetTabId.replace('-tab', ''));
            switchTab(targetTabId, targetBtn, targetTitle);

            // Khôi phục view chính sản phẩm / danh mục
            const activeProductMainView = sessionStorage.getItem('admin_product_main_view');
            if (activeProductMainView && document.getElementById(activeProductMainView)) {
                let targetMainBtn = document.querySelector(`.product-main-tab-btn[onclick*="${activeProductMainView}"]`);
                switchProductMainTab(activeProductMainView, targetMainBtn);
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
                if (targetSubBtn) {
                    switchProductSubTab(activeSubTabId, targetSubBtn);
                }
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

            // Khôi phục sub-tab kho bãi
            const activeWhSubTabId = sessionStorage.getItem('admin_warehouse_sub_tab');
            if (activeWhSubTabId && document.getElementById(activeWhSubTabId)) {
                let targetWhSubBtn = null;
                document.querySelectorAll('.warehouse-sub-tab-btn').forEach(btn => {
                    const onclickAttr = btn.getAttribute('onclick') || '';
                    if (onclickAttr.includes(activeWhSubTabId)) {
                        targetWhSubBtn = btn;
                    }
                });
                if (targetWhSubBtn) {
                    switchWarehouseSubTab(activeWhSubTabId, targetWhSubBtn);
                }
            }

            // Khôi phục sub-tab vận chuyển
            const activeShipSubTabId = sessionStorage.getItem('admin_shipping_sub_tab');
            if (activeShipSubTabId && document.getElementById(activeShipSubTabId)) {
                let targetShipSubBtn = null;
                document.querySelectorAll('.shipping-sub-tab-btn').forEach(btn => {
                    const onclickAttr = btn.getAttribute('onclick') || '';
                    if (onclickAttr.includes(activeShipSubTabId)) {
                        targetShipSubBtn = btn;
                    }
                });
                if (targetShipSubBtn) {
                    switchShippingSubTab(activeShipSubTabId, targetShipSubBtn);
                }
            }

            // Khôi phục sub-tab khiếu nại & đánh giá
            const activeComplaintSubTabId = sessionStorage.getItem('admin_complaint_sub_tab');
            if (activeComplaintSubTabId && document.getElementById(activeComplaintSubTabId)) {
                let targetComplaintSubBtn = null;
                document.querySelectorAll('.complaint-sub-tab-btn').forEach(btn => {
                    const onclickAttr = btn.getAttribute('onclick') || '';
                    if (onclickAttr.includes(activeComplaintSubTabId)) {
                        targetComplaintSubBtn = btn;
                    }
                });
                if (targetComplaintSubBtn) {
                    switchComplaintsSubTab(activeComplaintSubTabId, targetComplaintSubBtn);
                }
            }

            // Khôi phục sub-tab ví điện tử & ngân hàng
            const activeWalletSubTabId = sessionStorage.getItem('admin_wallet_sub_tab');
            if (activeWalletSubTabId && document.getElementById(activeWalletSubTabId)) {
                let targetWalletSubBtn = null;
                document.querySelectorAll('.wallet-sub-tab-btn').forEach(btn => {
                    const onclickAttr = btn.getAttribute('onclick') || '';
                    if (onclickAttr.includes(activeWalletSubTabId)) {
                        targetWalletSubBtn = btn;
                    }
                });
                if (targetWalletSubBtn) {
                    switchWalletSubTab(activeWalletSubTabId, targetWalletSubBtn);
                }
            }

            // Khởi tạo Chart.js Biểu Đồ Thống Kê
            if (typeof Chart !== 'undefined') {
                // Chart 1: Revenue Line/Bar Chart
                const ctxRevenue = document.getElementById('revenueChartCanvas');
                if (ctxRevenue) {
                    new Chart(ctxRevenue, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($revenue_chart_labels); ?>,
                            datasets: [
                                {
                                    label: 'Tổng Dòng Tiền (đ)',
                                    data: <?php echo json_encode($revenue_chart_values); ?>,
                                    backgroundColor: 'rgba(2, 132, 199, 0.75)',
                                    borderColor: '#0284c7',
                                    borderWidth: 1.5,
                                    borderRadius: 8
                                },
                                {
                                    label: 'Phí Sàn 5% (đ)',
                                    data: <?php echo json_encode($revenue_chart_fees); ?>,
                                    backgroundColor: 'rgba(16, 185, 129, 0.75)',
                                    borderColor: '#10b981',
                                    borderWidth: 1.5,
                                    borderRadius: 8
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'top' },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) label += ': ';
                                            if (context.parsed.y !== null) {
                                                label += new Intl.NumberFormat('vi-VN').format(context.parsed.y) + ' đ';
                                            }
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    ticks: {
                                        callback: function(val) {
                                            return val >= 1000000 ? (val / 1000000) + ' Tr' : val;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Chart 2: Categories Doughnut Chart
                const ctxCat = document.getElementById('categoryChartCanvas');
                if (ctxCat) {
                    new Chart(ctxCat, {
                        type: 'doughnut',
                        data: {
                            labels: <?php echo json_encode($category_chart_labels); ?>,
                            datasets: [{
                                data: <?php echo json_encode($category_chart_counts); ?>,
                                backgroundColor: [
                                    '#0284c7', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#6366f1', '#14b8a6', '#f97316'
                                ],
                                borderWidth: 2,
                                borderColor: '#ffffff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
                            }
                        }
                    });
                }

                // Chart 3: Order Status Bar Chart
                const ctxOrder = document.getElementById('orderStatusChartCanvas');
                if (ctxOrder) {
                    new Chart(ctxOrder, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($order_status_labels); ?>,
                            datasets: [{
                                label: 'Số Lượng Đơn',
                                data: <?php echo json_encode($order_status_counts); ?>,
                                backgroundColor: [
                                    'rgba(245, 158, 11, 0.8)',
                                    'rgba(59, 130, 246, 0.8)',
                                    'rgba(14, 165, 233, 0.8)',
                                    'rgba(16, 185, 129, 0.8)',
                                    'rgba(239, 68, 68, 0.8)',
                                    'rgba(107, 114, 128, 0.8)'
                                ],
                                borderRadius: 8
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } }
                        }
                    });
                }

                // Chart 4: User Roles Polar/Pie Chart
                const ctxRole = document.getElementById('userRoleChartCanvas');
                if (ctxRole) {
                    new Chart(ctxRole, {
                        type: 'polarArea',
                        data: {
                            labels: <?php echo json_encode($user_role_labels); ?>,
                            datasets: [{
                                data: <?php echo json_encode($user_role_counts); ?>,
                                backgroundColor: [
                                    'rgba(239, 68, 68, 0.7)',
                                    'rgba(14, 165, 233, 0.7)',
                                    'rgba(16, 185, 129, 0.7)',
                                    'rgba(139, 92, 246, 0.7)',
                                    'rgba(245, 158, 11, 0.7)'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
                            }
                        }
                    });
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

        function openAdminProductModalFromBtn(btn) {
            const pid = btn.getAttribute('data-pid');
            const title = btn.getAttribute('data-title');
            const price = btn.getAttribute('data-price');
            const cat = btn.getAttribute('data-cat');
            const cond = btn.getAttribute('data-cond');
            const seller = btn.getAttribute('data-seller');
            const rep = btn.getAttribute('data-rep');
            const desc = btn.getAttribute('data-desc');
            const imagesJson = btn.getAttribute('data-images') || '[]';
            const videoPath = btn.getAttribute('data-video');
            const stVal = parseInt(btn.getAttribute('data-status') || '0', 10);

            openAdminProductModal(pid, title, price, cat, cond, seller, rep, desc, imagesJson, videoPath, stVal);
        }

        function openAdminProductModal(pid, title, price, cat, cond, seller, rep, desc, imagesJson, videoPath, stVal) {
            document.getElementById('admin_modal_pid_approve').value = pid;
            document.getElementById('admin_modal_pid_ban').value = pid;
            const webLink = document.getElementById('admin_modal_web_link');
            if (webLink) webLink.href = 'product_detail.php?id=' + pid;
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

        // Hàm điều khiển Modal Quản Lý Kho & Vận Chuyển
        function openAddWarehouseModal() { document.getElementById('addWarehouseModal').style.display = 'flex'; }
        function closeAddWarehouseModal() { document.getElementById('addWarehouseModal').style.display = 'none'; }

        function openEditWarehouseModal(id, name, addr, lat, lng) {
            document.getElementById('edit_wh_id').value = id;
            document.getElementById('edit_wh_name').value = name;
            document.getElementById('edit_wh_addr').value = addr;
            document.getElementById('edit_wh_lat').value = lat;
            document.getElementById('edit_wh_lng').value = lng;
            document.getElementById('editWarehouseModal').style.display = 'flex';
        }
        function closeEditWarehouseModal() { document.getElementById('editWarehouseModal').style.display = 'none'; }

        function openUpdateStockModal(pid, name, qty) {
            document.getElementById('update_stock_pid').value = pid;
            document.getElementById('update_stock_sp_name').textContent = 'Sản phẩm: #' + pid + ' - ' + name;
            document.getElementById('update_stock_qty').value = qty;
            document.getElementById('updateStockModal').style.display = 'flex';
        }
        function closeUpdateStockModal() { document.getElementById('updateStockModal').style.display = 'none'; }

        function openAddStockLogModal() { document.getElementById('addStockLogModal').style.display = 'flex'; }
        function closeAddStockLogModal() { document.getElementById('addStockLogModal').style.display = 'none'; }

        function openCreateShippingTaskModal() { document.getElementById('createShippingTaskModal').style.display = 'flex'; }
        function closeCreateShippingTaskModal() { document.getElementById('createShippingTaskModal').style.display = 'none'; }

        function openUpdateTaskStatusModal(id, code, reason) {
            document.getElementById('task_st_id').value = id;
            document.getElementById('task_st_code').value = code;
            document.getElementById('task_st_reason').value = reason;
            document.getElementById('task_reason_div').style.display = (code === 3) ? 'block' : 'none';
            document.getElementById('updateTaskStatusModal').style.display = 'flex';
        }
        function closeUpdateTaskStatusModal() { document.getElementById('updateTaskStatusModal').style.display = 'none'; }

        function openCreateIncidentModal() { document.getElementById('createIncidentModal').style.display = 'flex'; }
        function closeCreateIncidentModal() { document.getElementById('createIncidentModal').style.display = 'none'; }

        function openResolveIncidentModal(id, code, amount) {
            document.getElementById('res_inc_id').value = id;
            document.getElementById('res_inc_st').value = code > 0 ? code : 1;
            document.getElementById('res_inc_amount').value = amount;
            document.getElementById('resolveIncidentModal').style.display = 'flex';
        }
        function closeResolveIncidentModal() { document.getElementById('resolveIncidentModal').style.display = 'none'; }
    </script>
</body>

</html>
