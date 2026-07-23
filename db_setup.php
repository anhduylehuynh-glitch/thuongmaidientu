<?php
require_once 'config/config.php';

$message = '';
$status = 'info'; // info, success, error

if (isset($_POST['setup'])) {
    try {
        // Step 1: Kết nối MySQL server không chọn database để tạo database nếu chưa có
        $dsn_no_db = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        $pdo_init = new PDO($dsn_no_db, DB_USER, DB_PASS);
        $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Tạo Database
        $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $message .= "• Đã tạo hoặc kiểm tra sự tồn tại của database `" . DB_NAME . "` thành công.<br>";

        // Step 2: Kết nối trực tiếp vào DB vừa tạo
        $pdo = getDBConnection();

        // Kiểm tra xem bảng NguoiDung đã tồn tại chưa
        $table_check = $pdo->query("SHOW TABLES LIKE 'NguoiDung'");
        $table_exists = $table_check->rowCount() > 0;

        if (!$table_exists) {
            // Đọc file schema
            $schema_file = __DIR__ . '/database_schema.sql';
            if (file_exists($schema_file)) {
                $sql = file_get_contents($schema_file);
                // Thực thi schema khởi tạo
                $pdo->exec($sql);
                $message .= "• Khởi tạo các bảng từ file schema `database_schema.sql` thành công.<br>";
            } else {
                throw new Exception("Không tìm thấy file schema `database_schema.sql` tại thư mục dự án.");
            }
        } else {
            $message .= "• Bảng `NguoiDung` đã tồn tại, bỏ qua bước chạy schema ban đầu.<br>";
        }

        // Step 3: Kiểm tra và thêm cột hỗ trợ Google OAuth vào bảng NguoiDung
        // Kiểm tra xem cột google_id đã tồn tại chưa
        $column_check = $pdo->query("SHOW COLUMNS FROM `NguoiDung` LIKE 'google_id'");
        if ($column_check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `NguoiDung` ADD COLUMN `google_id` VARCHAR(255) NULL UNIQUE AFTER `Email`;");
            $message .= "• Thêm cột `google_id` vào bảng `NguoiDung` thành công.<br>";
        } else {
            $message .= "• Cột `google_id` đã tồn tại.<br>";
        }

        // Kiểm tra xem cột google_picture đã tồn tại chưa
        $column_check_pic = $pdo->query("SHOW COLUMNS FROM `NguoiDung` LIKE 'google_picture'");
        if ($column_check_pic->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `NguoiDung` ADD COLUMN `google_picture` VARCHAR(255) NULL AFTER `google_id`;");
            $message .= "• Thêm cột `google_picture` vào bảng `NguoiDung` thành công.<br>";
        } else {
            $message .= "• Cột `google_picture` đã tồn tại.<br>";
        }

        // Đổi cột MatKhau thành NULLable để người dùng OAuth không cần nhập mật khẩu
        $pdo->exec("ALTER TABLE `NguoiDung` MODIFY `MatKhau` VARCHAR(255) NULL;");
        $message .= "• Cấu hình cột `MatKhau` thành có thể chứa giá trị NULL thành công.<br>";

        // Thiết lập một vài VaiTro và Quyen cơ bản để demo nếu chưa có dữ liệu
        $role_check = $pdo->query("SELECT COUNT(*) FROM `VaiTro` WHERE `TenVaiTro` = 'BUYER'");
        if ($role_check->fetchColumn() == 0) {
            $pdo->exec("INSERT INTO `VaiTro` (`TenVaiTro`, `MoTa`) VALUES 
                ('ADMIN', 'Quản trị viên hệ thống'),
                ('BUYER', 'Người mua hàng'),
                ('SELLER', 'Người bán hàng');
            ");
            $message .= "• Đã nạp danh sách vai trò mẫu (ADMIN, BUYER, SELLER) thành công.<br>";
        }

        // Thiết lập danh mục mặc định nếu chưa có
        $default_cats = [
            ['Ten' => 'Điện thoại', 'MoTa' => 'Điện thoại thông minh, máy đọc sách, phụ kiện điện thoại'],
            ['Ten' => 'Máy tính', 'MoTa' => 'Laptop, máy tính để bàn, phụ kiện máy tính'],
            ['Ten' => 'Đồ gia dụng', 'MoTa' => 'Thiết bị điện gia dụng, đồ dùng nhà bếp, máy hút bụi']
        ];
        foreach ($default_cats as $cat) {
            $cat_chk = $pdo->prepare("SELECT COUNT(*) FROM `DanhMuc` WHERE `TenDanhMuc` = :t");
            $cat_chk->execute(['t' => $cat['Ten']]);
            if ($cat_chk->fetchColumn() == 0) {
                $ins_cat = $pdo->prepare("INSERT INTO `DanhMuc` (`TenDanhMuc`, `MoTa`) VALUES (:t, :m)");
                $ins_cat->execute(['t' => $cat['Ten'], 'm' => $cat['MoTa']]);
                $message .= "• Đã tạo danh mục mặc định: `" . htmlspecialchars($cat['Ten']) . "`<br>";
            }
        }

        // Tự động cấp quyền ADMIN cho các email quản trị viên chỉ định
        $admin_emails = ['anhduylehuynh@gmail.com', 'tienle6040@gmail.com', 'anhduylehuynh@gmail'];
        $admin_role_stmt = $pdo->query("SELECT `MaVaiTro` FROM `VaiTro` WHERE `TenVaiTro` = 'ADMIN'");
        $admin_role_id = $admin_role_stmt->fetchColumn();

        if ($admin_role_id) {
            foreach ($admin_emails as $e) {
                $user_stmt = $pdo->prepare("SELECT `MaNguoiDung`, `Email` FROM `NguoiDung` WHERE `Email` = :e OR `Email` LIKE :e_like");
                $user_stmt->execute(['e' => $e, 'e_like' => $e . '%']);
                $found_users = $user_stmt->fetchAll();
                foreach ($found_users as $fu) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM `NguoiDung_VaiTro` WHERE `MaNguoiDung` = :uid AND `MaVaiTro` = :rid");
                    $chk->execute(['uid' => $fu['MaNguoiDung'], 'rid' => $admin_role_id]);
                    if ($chk->fetchColumn() == 0) {
                        $ins = $pdo->prepare("INSERT INTO `NguoiDung_VaiTro` (`MaNguoiDung`, `MaVaiTro`) VALUES (:uid, :rid)");
                        $ins->execute(['uid' => $fu['MaNguoiDung'], 'rid' => $admin_role_id]);
                        $message .= "• Đã cấp quyền ADMIN cho tài khoản: `" . htmlspecialchars($fu['Email']) . "`<br>";
                    }
                }
            }
        }

        // Chạy file migration_security.sql nếu tồn tại
        $migration_file = __DIR__ . '/migration_security.sql';
        if (file_exists($migration_file)) {
            $migration_sql = file_get_contents($migration_file);
            $pdo->exec($migration_sql);
            $message .= "• Thực thi file migration bảo mật `migration_security.sql` thành công.<br>";
        }

        // Tạo các chỉ mục phụ (Secondary Indexes) nếu chưa tồn tại
        $queries = [
            'idx_sp_duyet_ban_ngaydang' => "CREATE INDEX idx_sp_duyet_ban_ngaydang ON SanPham(TrangThaiDuyet, TrangThaiBan, NgayDang)",
            'idx_sp_danhmuc_trangthai' => "CREATE INDEX idx_sp_danhmuc_trangthai ON SanPham(MaDanhMuc, TrangThaiDuyet, TrangThaiBan)",
            'idx_sp_nguoiban_ngaydang' => "CREATE INDEX idx_sp_nguoiban_ngaydang ON SanPham(MaNguoiBan, NgayDang)"
        ];

        foreach ($queries as $name => $sql) {
            $check = $pdo->prepare("
                SELECT COUNT(*) 
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'SanPham' 
                  AND INDEX_NAME = :name
            ");
            $check->execute(['name' => $name]);
            if ($check->fetchColumn() == 0) {
                $pdo->exec($sql);
                $message .= "• Tạo chỉ mục phụ `$name` tối ưu truy vấn thành công.<br>";
            } else {
                $message .= "• Chỉ mục phụ `$name` đã tồn tại sẵn.<br>";
            }
        }

        $status = 'success';
        $message = "<strong>Thiết lập cơ sở dữ liệu hoàn tất!</strong><br><br>" . $message;
    } catch (Exception $e) {
        $status = 'error';
        $message = "<strong>Đã xảy ra lỗi trong quá trình thiết lập:</strong><br>" . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài Đặt Database - Google Login Demo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .container {
            max-width: 600px;
            margin: 80px auto;
            padding: 40px;
        }
        .db-status-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        .btn-action {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            margin: 20px 0;
            width: 100%;
        }
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        .alert-box {
            text-align: left;
            padding: 15px 20px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 14px;
            line-height: 1.6;
        }
        .alert-info {
            background: rgba(102, 126, 234, 0.15);
            border: 1px solid rgba(102, 126, 234, 0.3);
            color: #a3b8cc;
        }
        .alert-success {
            background: rgba(72, 187, 120, 0.15);
            border: 1px solid rgba(72, 187, 120, 0.3);
            color: #9ae6b4;
        }
        .alert-error {
            background: rgba(245, 101, 101, 0.15);
            border: 1px solid rgba(245, 101, 101, 0.3);
            color: #feb2b2;
        }
        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: #a0aec0;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="background-decor"></div>
    <div class="container">
        <div class="db-status-card">
            <h2>Thiết Lập Cơ Sở Dữ Liệu</h2>
            <p style="color: #a0aec0; font-size: 14px; margin-top: 10px;">
                Công cụ tự động cấu hình Database MySQL (Port: <b><?php echo DB_PORT; ?></b>) cho chức năng Đăng nhập Google
            </p>

            <form method="POST">
                <button type="submit" name="setup" class="btn-action">Bắt Đầu Cấu Hình DB</button>
            </form>

            <?php if (!empty($message)): ?>
                <div class="alert-box alert-<?php echo $status; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <a href="index.php" class="back-link">← Quay lại Trang Chủ</a>
        </div>
    </div>
</body>
</html>
