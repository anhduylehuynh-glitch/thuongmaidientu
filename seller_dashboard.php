<?php
require_once 'config/config.php';

// Bắt buộc đăng nhập
requireLogin();
$user_id = $_SESSION['user_id'];
$db = getDBConnection();

// Tự động tạo các bảng phụ trợ cho Shop nếu chưa tồn tại
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `CaiDatCuaHang` (
            `MaCuaHang` INT AUTO_INCREMENT PRIMARY KEY,
            `MaNguoiBan` INT NOT NULL UNIQUE,
            `TenCuaHang` VARCHAR(150) NOT NULL,
            `MoTaCuaHang` TEXT NULL,
            `EmailLienHe` VARCHAR(100) NULL,
            `SdtLienHe` VARCHAR(20) NULL,
            `DiaChiLayHang` TEXT NULL,
            `DiaChiHoanHang` TEXT NULL,
            `ThoiGianChuanBi` VARCHAR(50) DEFAULT '24h',
            `TrangThaiHoatDong` BIT(1) DEFAULT b'1',
            `NgayTao` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS `LichSuKho` (
            `MaLichSu` INT AUTO_INCREMENT PRIMARY KEY,
            `MaSanPham` INT NOT NULL,
            `NguoiThucHien` INT NOT NULL,
            `SoLuongCu` INT DEFAULT 0,
            `SoLuongMoi` INT DEFAULT 0,
            `LoaiDieuChinh` VARCHAR(50) DEFAULT 'DIEU_CHINH',
            `LyDo` TEXT NULL,
            `NgayTao` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS `Voucher` (
            `MaVoucher` INT AUTO_INCREMENT PRIMARY KEY,
            `MaNguoiBan` INT NOT NULL,
            `MaCode` VARCHAR(50) NOT NULL,
            `LoaiGiamGia` VARCHAR(20) DEFAULT 'TIEN',
            `GiaTriGiam` DECIMAL(15,2) DEFAULT 0,
            `GiaTriDonToiThieu` DECIMAL(15,2) DEFAULT 0,
            `GiamToiDa` DECIMAL(15,2) DEFAULT 0,
            `SoLuong` INT DEFAULT 100,
            `DaSuDung` INT DEFAULT 0,
            `NgayBatDau` DATETIME NULL,
            `NgayKetThuc` DATETIME NULL,
            `TrangThai` BIT(1) DEFAULT b'1',
            `NgayTao` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");
} catch (Exception $e) {
    // Bỏ qua nếu đã tồn tại
}

// Lấy thông tin tài khoản
$stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
$stmt->execute(['id' => $user_id]);
$current_user = $stmt->fetch();

if (!$current_user) {
    header("Location: login_page.php");
    exit;
}

// Khởi tạo cài đặt cửa hàng nếu chưa có
$shop_stmt = $db->prepare("SELECT * FROM `CaiDatCuaHang` WHERE `MaNguoiBan` = :uid");
$shop_stmt->execute(['uid' => $user_id]);
$shop_info = $shop_stmt->fetch();

if (!$shop_info) {
    $ins_shop = $db->prepare("
        INSERT INTO `CaiDatCuaHang` 
        (`MaNguoiBan`, `TenCuaHang`, `MoTaCuaHang`, `EmailLienHe`, `SdtLienHe`, `DiaChiLayHang`, `DiaChiHoanHang`, `ThoiGianChuanBi`, `TrangThaiHoatDong`)
        VALUES (:uid, :name, 'Cửa hàng thanh lý đồ cũ uy tín', :email, :phone, 'Địa chỉ shop', 'Địa chỉ shop', '24h', b'1')
    ");
    $ins_shop->execute([
        'uid' => $user_id,
        'name' => 'Shop ' . ($current_user['HoTen'] ?? 'Thành Viên'),
        'email' => $current_user['Email'] ?? '',
        'phone' => $current_user['SoDienThoai'] ?? ''
    ]);
    $shop_stmt->execute(['uid' => $user_id]);
    $shop_info = $shop_stmt->fetch();
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Tab đang chọn
$tab = $_GET['tab'] ?? 'dashboard';

// ============================================================================
// HÀM XỬ LÝ ACTION POST (SẢN PHẨM, ĐƠN HÀNG, KHO, VOUCHER, TRẢ HÀNG, DÀNH CHO SHOP)
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Thêm / Sửa sản phẩm của Shop
    if ($action === 'save_product') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['ten_san_pham'] ?? '');
        $cat_id = (int)($_POST['ma_danh_muc'] ?? 0);
        $brand = trim($_POST['thuong_hieu'] ?? '');
        $price = (float)($_POST['gia_ban'] ?? 0);
        $promo_price = (float)($_POST['gia_khuyen_mai'] ?? 0);
        $stock = (int)($_POST['so_luong_ton'] ?? 0);
        $condition = trim($_POST['tinh_trang'] ?? 'Mới 99%');
        $weight = (float)($_POST['khoi_luong'] ?? 0.5);
        $desc = trim($_POST['mo_ta'] ?? '');
        $video = trim($_POST['video_url'] ?? '');

        if (empty($name) || $cat_id <= 0 || $price <= 0) {
            $_SESSION['flash_error'] = "Vui lòng nhập đầy đủ tên sản phẩm, danh mục và giá bán hợp lệ!";
        } else {
            try {
                if ($pid > 0) {
                    // Sửa sản phẩm (chỉ sửa sản phẩm thuộc shop mình)
                    $chk_p = $db->prepare("SELECT MaSanPham FROM SanPham WHERE MaSanPham = :pid AND MaNguoiBan = :uid");
                    $chk_p->execute(['pid' => $pid, 'uid' => $user_id]);
                    if ($chk_p->fetch()) {
                        $upd_p = $db->prepare("
                            UPDATE SanPham 
                            SET TenSanPham = :name, MaDanhMuc = :cat, ThuongHieu = :brand, GiaBan = :price, 
                                GiaKhuyenMai = :promo, SoLuongTon = :stock, TinhTrang = :cond, KhoiLuong_Kg = :weight, 
                                MoTaChiTiet = :desc, VideoThucTe = :video
                            WHERE MaSanPham = :pid AND MaNguoiBan = :uid
                        ");
                        $upd_p->execute([
                            'name' => $name, 'cat' => $cat_id, 'brand' => $brand, 'price' => $price,
                            'promo' => $promo_price, 'stock' => $stock, 'cond' => $condition, 'weight' => $weight,
                            'desc' => $desc, 'video' => $video, 'pid' => $pid, 'uid' => $user_id
                        ]);
                        $_SESSION['flash_success'] = "Đã cập nhật sản phẩm #{$pid} thành công!";
                    } else {
                        $_SESSION['flash_error'] = "Bạn không có quyền chỉnh sửa sản phẩm này!";
                    }
                } else {
                    // Thêm mới sản phẩm
                    $ins_p = $db->prepare("
                        INSERT INTO SanPham 
                        (MaNguoiBan, MaDanhMuc, TenSanPham, ThuongHieu, GiaBan, GiaKhuyenMai, SoLuongTon, TinhTrang, KhoiLuong_Kg, MoTaChiTiet, VideoThucTe, TrangThaiDuyet, TrangThaiBan)
                        VALUES (:uid, :cat, :name, :brand, :price, :promo, :stock, :cond, :weight, :desc, :video, b'01', b'00')
                    ");
                    $ins_p->execute([
                        'uid' => $user_id, 'cat' => $cat_id, 'name' => $name, 'brand' => $brand,
                        'price' => $price, 'promo' => $promo_price, 'stock' => $stock, 'cond' => $condition,
                        'weight' => $weight, 'desc' => $desc, 'video' => $video
                    ]);
                    $new_pid = $db->lastInsertId();

                    // Thêm ảnh mẫu mặc định nếu chưa có
                    $ins_img = $db->prepare("INSERT INTO HinhAnhSP (MaSanPham, DuongDanAnh, AnhChinh) VALUES (:pid, 'assets/images/no-image.png', 1)");
                    $ins_img->execute(['pid' => $new_pid]);

                    $_SESSION['flash_success'] = "Đã đăng bán sản phẩm mới thành công!";
                }
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Lỗi khi lưu sản phẩm: " . $e->getMessage();
            }
        }
        header("Location: seller_dashboard.php?tab=products");
        exit;
    }

    // 2. Chuyển trạng thái ẩn / ngừng bán sản phẩm
    if ($action === 'toggle_product_status') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $status_code = trim($_POST['status_code'] ?? '00'); // '00': Sẵn sàng, '11': Ẩn, '10': Ngừng bán

        $chk_p = $db->prepare("SELECT MaSanPham FROM SanPham WHERE MaSanPham = :pid AND MaNguoiBan = :uid");
        $chk_p->execute(['pid' => $pid, 'uid' => $user_id]);
        if ($chk_p->fetch()) {
            $bit_val = "b'00'";
            if ($status_code === '11') $bit_val = "b'11'";
            if ($status_code === '10') $bit_val = "b'10'";

            $db->exec("UPDATE SanPham SET TrangThaiBan = {$bit_val} WHERE MaSanPham = {$pid} AND MaNguoiBan = {$user_id}");
            $_SESSION['flash_success'] = "Đã cập nhật trạng thái sản phẩm #{$pid}!";
        } else {
            $_SESSION['flash_error'] = "Bạn không có quyền thao tác trên sản phẩm này!";
        }
        header("Location: seller_dashboard.php?tab=products");
        exit;
    }

    // 3. Quản lý Biến thể Sản phẩm (Add/Edit Variant)
    if ($action === 'save_variant') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $sku = trim($_POST['sku'] ?? '');
        $color = trim($_POST['mau_sac'] ?? '');
        $size = trim($_POST['kich_thuoc'] ?? '');
        $cap = trim($_POST['dung_luong'] ?? '');
        $v_price = (float)($_POST['gia_ban'] ?? 0);
        $v_stock = (int)($_POST['so_luong_ton'] ?? 0);

        $chk_p = $db->prepare("SELECT MaSanPham FROM SanPham WHERE MaSanPham = :pid AND MaNguoiBan = :uid");
        $chk_p->execute(['pid' => $pid, 'uid' => $user_id]);
        if ($chk_p->fetch()) {
            $ins_v = $db->prepare("
                INSERT INTO BienTheSanPham (MaSanPham, SKU, MauSac, KichThuoc, DungLuong, GiaBan, SoLuongTon)
                VALUES (:pid, :sku, :color, :size, :cap, :price, :stock)
            ");
            $ins_v->execute([
                'pid' => $pid, 'sku' => $sku, 'color' => $color, 'size' => $size,
                'cap' => $cap, 'price' => $v_price, 'stock' => $v_stock
            ]);
            $_SESSION['flash_success'] = "Đã thêm biến thể mới cho sản phẩm #{$pid}!";
        }
        header("Location: seller_dashboard.php?tab=products");
        exit;
    }

    // 4. Luồng Chuyển Trạng Thái Đơn Hàng Chuẩn Nghiêm Ngặt
    // Chờ xác nhận (0) -> Đã xác nhận (1) -> Đang chuẩn bị (2) -> Đang giao (3) -> Hoàn thành (5)
    if ($action === 'update_order_status') {
        $oid = (int)($_POST['order_id'] ?? 0);
        $target_st = (int)($_POST['target_status'] ?? 0);
        $tracking_code = trim($_POST['ma_van_don'] ?? '');

        // Kiểm tra đơn hàng có chứa sản phẩm của shop này không
        $chk_o = $db->prepare("
            SELECT dh.* 
            FROM DonHang dh
            JOIN ChiTietDonHang ct ON dh.MaDonHang = ct.MaDonHang
            JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
            WHERE dh.MaDonHang = :oid AND sp.MaNguoiBan = :uid
            LIMIT 1
        ");
        $chk_o->execute(['oid' => $oid, 'uid' => $user_id]);
        $ord_data = $chk_o->fetch();

        if ($ord_data) {
            $curr_st = is_numeric($ord_data['TrangThaiDonHang']) ? (int)$ord_data['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$ord_data['TrangThaiDonHang'])));

            // Kiểm tra luồng chuyển tiếp nghiêm ngặt
            $valid_transition = false;
            if ($curr_st === 0 && $target_st === 1) $valid_transition = true; // Chờ xác nhận -> Đã xác nhận
            if ($curr_st === 1 && $target_st === 2) $valid_transition = true; // Đã xác nhận -> Đang chuẩn bị
            if ($curr_st === 2 && $target_st === 3) $valid_transition = true; // Đang chuẩn bị -> Đang giao (Cần mã vận đơn)
            if ($curr_st === 3 && $target_st === 5) $valid_transition = true; // Đang giao -> Hoàn thành

            if ($valid_transition) {
                $bit_target = "b'00" . $target_st . "'";
                if ($target_st === 1) $bit_target = "b'001'";
                if ($target_st === 2) $bit_target = "b'010'";
                if ($target_st === 3) $bit_target = "b'011'";
                if ($target_st === 5) $bit_target = "b'101'";

                $sql_extra = "";
                if (!empty($tracking_code)) {
                    $sql_extra = ", MaVanDon = " . $db->quote($tracking_code);
                }

                $db->exec("UPDATE DonHang SET TrangThaiDonHang = {$bit_target} {$sql_extra} WHERE MaDonHang = {$oid}");
                $_SESSION['flash_success'] = "Đã cập nhật trạng thái đơn hàng #DH-" . sprintf('%05d', $oid) . "!";
            } else {
                $_SESSION['flash_error'] = "Không thể chuyển trạng thái sai thứ tự quy trình (Chờ xác nhận → Đã xác nhận → Đang chuẩn bị → Đang giao → Hoàn thành)!";
            }
        } else {
            $_SESSION['flash_error'] = "Bạn không có quyền xử lý đơn hàng này!";
        }
        header("Location: seller_dashboard.php?tab=orders&order_status=" . urlencode($_POST['order_status'] ?? ($_GET['order_status'] ?? 'all')));
        exit;
    }

    // 5. Điều Chỉnh Tồn Kho & Ghi Lịch Sử Kho
    if ($action === 'adjust_stock') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $new_stock = max(0, (int)($_POST['new_stock'] ?? 0));
        $reason = trim($_POST['reason'] ?? 'Điều chỉnh kiểm kê kho');

        $chk_p = $db->prepare("SELECT MaSanPham, SoLuongTon FROM SanPham WHERE MaSanPham = :pid AND MaNguoiBan = :uid");
        $chk_p->execute(['pid' => $pid, 'uid' => $user_id]);
        $prod = $chk_p->fetch();

        if ($prod) {
            $old_stock = (int)$prod['SoLuongTon'];
            $upd_s = $db->prepare("UPDATE SanPham SET SoLuongTon = :st WHERE MaSanPham = :pid");
            $upd_s->execute(['st' => $new_stock, 'pid' => $pid]);

            // Ghi lịch sử kho
            $ins_log = $db->prepare("
                INSERT INTO LichSuKho (MaSanPham, NguoiThucHien, SoLuongCu, SoLuongMoi, LoaiDieuChinh, LyDo)
                VALUES (:pid, :uid, :old, :new, 'DIEU_CHINH', :reason)
            ");
            $ins_log->execute([
                'pid' => $pid, 'uid' => $user_id, 'old' => $old_stock,
                'new' => $new_stock, 'reason' => $reason
            ]);

            $_SESSION['flash_success'] = "Đã cập nhật kho sản phẩm #{$pid} (Từ {$old_stock} → {$new_stock})!";
        }
        header("Location: seller_dashboard.php?tab=inventory");
        exit;
    }

    // 6. Quản Lý Mã Giảm Giá (Shop Voucher)
    if ($action === 'create_voucher') {
        $code = strtoupper(trim($_POST['ma_code'] ?? ''));
        $type = trim($_POST['loai_giam'] ?? 'PERCENT');
        $val = (float)($_POST['gia_tri_giam'] ?? 0);
        $max_discount = (float)($_POST['giam_toi_da'] ?? 0);
        $min_order = (float)($_POST['don_toi_thieu'] ?? 0);
        $total_usage = (int)($_POST['tong_luot_dung'] ?? 100);
        $start_date = trim($_POST['ngay_bat_dau'] ?? date('Y-m-d H:i:s'));
        $end_date = trim($_POST['ngay_ket_thuc'] ?? date('Y-m-d H:i:s', strtotime('+30 days')));

        if (!empty($code) && $val > 0) {
            try {
                $ins_v = $db->prepare("
                    INSERT INTO MaGiamGia (MaNguoiBan, MaCode, LoaiGiam, GiaTriGiam, GiamToiDa, DonToiThieu, TongLuotDung, NgayBatDau, NgayKetThuc, TrangThai)
                    VALUES (:uid, :code, :type, :val, :max_d, :min_o, :total_u, :sdate, :edate, b'1')
                ");
                $ins_v->execute([
                    'uid' => $user_id, 'code' => $code, 'type' => $type, 'val' => $val,
                    'max_d' => $max_discount, 'min_o' => $min_order, 'total_u' => $total_usage,
                    'sdate' => $start_date, 'edate' => $end_date
                ]);
                $_SESSION['flash_success'] = "Đã tạo mã giảm giá {$code} thành công!";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Mã voucher đã tồn tại hoặc không hợp lệ!";
            }
        }
        header("Location: seller_dashboard.php?tab=vouchers");
        exit;
    }

    // 7. Xử Lý Trả Hàng Hoàn Tiền (Accept/Reject Return)
    if ($action === 'process_return') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $status = trim($_POST['status'] ?? ''); // 'CHAP_NHAN' hoặc 'TU_CHOI'
        $response_reason = trim($_POST['ly_do_phan_hoi'] ?? '');

        $chk_r = $db->prepare("SELECT * FROM TraHangHoanTien WHERE MaYeuCau = :rid AND MaNguoiBan = :uid");
        $chk_r->execute(['rid' => $req_id, 'uid' => $user_id]);
        $ret = $chk_r->fetch();

        if ($ret) {
            $upd_r = $db->prepare("UPDATE TraHangHoanTien SET TrangThai = :st, LyDoPhanHoi = :reason, NgayXuLy = NOW() WHERE MaYeuCau = :rid");
            $upd_r->execute(['st' => $status, 'reason' => $response_reason, 'rid' => $req_id]);

            if ($status === 'CHAP_NHAN') {
                // Cập nhật trạng thái đơn hàng sang Trả Hàng (b'100' / 4)
                $db->exec("UPDATE DonHang SET TrangThaiDonHang = b'100' WHERE MaDonHang = {$ret['MaDonHang']}");
            }

            $_SESSION['flash_success'] = "Đã phản hồi yêu cầu trả hàng #{$req_id}!";
        }
        header("Location: seller_dashboard.php?tab=returns");
        exit;
    }

    // 8. Phản Hồi Đánh Giá Của Khách
    if ($action === 'reply_review') {
        $review_id = (int)($_POST['review_id'] ?? 0);
        $reply_text = trim($_POST['noi_dung_phan_hoi'] ?? '');

        if ($review_id > 0 && !empty($reply_text)) {
            $ins_rep = $db->prepare("
                INSERT INTO PhanHoiDanhGia (MaDanhGia, MaNguoiBan, NoiDungPhanHoi)
                VALUES (:rid, :uid, :reply)
                ON DUPLICATE KEY UPDATE NoiDungPhanHoi = :reply2, NgayPhanHoi = NOW()
            ");
            $ins_rep->execute(['rid' => $review_id, 'uid' => $user_id, 'reply' => $reply_text, 'reply2' => $reply_text]);
            $_SESSION['flash_success'] = "Đã gửi phản hồi cho đánh giá!";
        }
        header("Location: seller_dashboard.php?tab=reviews");
        exit;
    }

    // 9. Cập Nhật Cài Đặt Cửa Hàng
    if ($action === 'update_settings') {
        $shop_name = trim($_POST['ten_cua_hang'] ?? '');
        $shop_desc = trim($_POST['mo_ta_cua_hang'] ?? '');
        $email = trim($_POST['email_lien_he'] ?? '');
        $phone = trim($_POST['sdt_lien_he'] ?? '');
        $addr_pickup = trim($_POST['dia_chi_lay_hang'] ?? '');
        $addr_return = trim($_POST['dia_chi_hoan_hang'] ?? '');
        $prep_time = trim($_POST['thoi_gian_chuan_bi'] ?? '24h');
        $bank_name = trim($_POST['ten_ngan_hang'] ?? '');
        $bank_acc = trim($_POST['so_tai_khoan'] ?? '');
        $bank_user = trim($_POST['ten_chu_tai_khoan'] ?? '');

        $upd_s = $db->prepare("
            UPDATE CaiDatCuaHang 
            SET TenCuaHang = :name, MoTaCuaHang = :desc, EmailLienHe = :email, SdtLienHe = :phone,
                DiaChiLayHang = :pickup, DiaChiHoanHang = :return_addr, ThoiGianChuanBi = :prep,
                TenNganHang = :bname, SoTaiKhoan = :bacc, TenChuTaiKhoan = :buser
            WHERE MaNguoiBan = :uid
        ");
        $upd_s->execute([
            'name' => $shop_name, 'desc' => $shop_desc, 'email' => $email, 'phone' => $phone,
            'pickup' => $addr_pickup, 'return_addr' => $addr_return, 'prep' => $prep_time,
            'bname' => $bank_name, 'bacc' => $bank_acc, 'buser' => $bank_user, 'uid' => $user_id
        ]);
        $_SESSION['flash_success'] = "Đã cập nhật thông tin cài đặt cửa hàng!";
        header("Location: seller_dashboard.php?tab=settings");
        exit;
    }
}

// ============================================================================
// LẤY DỮ LIỆU THỐNG KÊ & DANH SÁCH CHO 8 PHÂN HỆ
// ============================================================================

// 1. Thống Kê Tổng Quan (Dashboard)
$today = date('Y-m-d');
$first_day_month = date('Y-m-01 00:00:00');

$revenue_today = (float)($db->query("
    SELECT SUM(dh.TongTienThanhToan) 
    FROM DonHang dh
    JOIN ChiTietDonHang ct ON dh.MaDonHang = ct.MaDonHang
    JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
    WHERE sp.MaNguoiBan = {$user_id} AND DATE(dh.NgayTao) = '{$today}' AND dh.TrangThaiDonHang IN (b'001', b'010', b'011', b'101')
")->fetchColumn() ?: 0);

$revenue_month = (float)($db->query("
    SELECT SUM(dh.TongTienThanhToan) 
    FROM DonHang dh
    JOIN ChiTietDonHang ct ON dh.MaDonHang = ct.MaDonHang
    JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
    WHERE sp.MaNguoiBan = {$user_id} AND dh.NgayTao >= '{$first_day_month}' AND dh.TrangThaiDonHang IN (b'001', b'010', b'011', b'101')
")->fetchColumn() ?: 0);

$pending_orders_count = (int)($db->query("
    SELECT COUNT(DISTINCT dh.MaDonHang) 
    FROM DonHang dh
    JOIN ChiTietDonHang ct ON dh.MaDonHang = ct.MaDonHang
    JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
    WHERE sp.MaNguoiBan = {$user_id} AND dh.TrangThaiDonHang = b'000'
")->fetchColumn() ?: 0);

$delivering_orders_count = (int)($db->query("
    SELECT COUNT(DISTINCT dh.MaDonHang) 
    FROM DonHang dh
    JOIN ChiTietDonHang ct ON dh.MaDonHang = ct.MaDonHang
    JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
    WHERE sp.MaNguoiBan = {$user_id} AND dh.TrangThaiDonHang IN (b'010', b'011')
")->fetchColumn() ?: 0);

$active_products_count = (int)($db->query("
    SELECT COUNT(*) FROM SanPham WHERE MaNguoiBan = {$user_id} AND TrangThaiBan = b'00'
")->fetchColumn() ?: 0);

$low_stock_count = (int)($db->query("
    SELECT COUNT(*) FROM SanPham WHERE MaNguoiBan = {$user_id} AND SoLuongTon <= 5
")->fetchColumn() ?: 0);

$new_returns_count = (int)($db->query("
    SELECT COUNT(*) FROM TraHangHoanTien WHERE MaNguoiBan = {$user_id} AND TrangThai = 'CHO_XU_LY'
")->fetchColumn() ?: 0);

$new_reviews_count = (int)($db->query("
    SELECT COUNT(*) 
    FROM DonDanhGiaSanPham dg
    JOIN SanPham sp ON dg.MaSanPham = sp.MaSanPham
    WHERE sp.MaNguoiBan = {$user_id}
")->fetchColumn() ?: 0);

// Danh mục sản phẩm dùng cho Form
$categories = $db->query("SELECT * FROM DanhMuc ORDER BY TenDanhMuc ASC")->fetchAll();

// Fetch Sản Phẩm thuộc Shop
$products = $db->query("
    SELECT sp.*, dm.TenDanhMuc,
           (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC LIMIT 1) as DuongDanAnh
    FROM SanPham sp
    JOIN DanhMuc dm ON sp.MaDanhMuc = dm.MaDanhMuc
    WHERE sp.MaNguoiBan = {$user_id}
    ORDER BY sp.MaSanPham DESC
")->fetchAll();

// Fetch Đơn Hàng thuộc Shop
$orders_sql = "
    SELECT DISTINCT dh.*, nd.HoTen as TenKhachHang, nd.SoDienThoai as SdtKhachHang, sdc.DiaChiChiTiet
    FROM DonHang dh
    JOIN ChiTietDonHang ct ON dh.MaDonHang = ct.MaDonHang
    JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
    JOIN NguoiDung nd ON dh.MaNguoiMua = nd.MaNguoiDung
    LEFT JOIN SoDiaChi sdc ON dh.MaDiaChiGiao = sdc.MaDiaChi
    WHERE sp.MaNguoiBan = {$user_id}
    ORDER BY dh.NgayTao DESC
";
$orders = $db->query($orders_sql)->fetchAll();

foreach ($orders as &$ord) {
    $item_st = $db->prepare("
        SELECT ct.*, sp.TenSanPham, sp.ThuongHieu,
               (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC LIMIT 1) as DuongDanAnh
        FROM ChiTietDonHang ct
        JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
        WHERE ct.MaDonHang = :oid AND sp.MaNguoiBan = :uid
    ");
    $item_st->execute(['oid' => $ord['MaDonHang'], 'uid' => $user_id]);
    $ord['Items'] = $item_st->fetchAll();
}
unset($ord);

// Phân loại trạng thái đơn hàng thành từng Tab riêng cho Seller
$seller_order_status = $_GET['order_status'] ?? 'all';
$order_counts = [
    'all' => count($orders),
    'pending' => 0,    // 0: Chờ xác nhận
    'confirmed' => 0,  // 1: Đã xác nhận
    'preparing' => 0,  // 2: Đang chuẩn bị
    'delivering' => 0, // 3: Đang giao
    'completed' => 0,  // 5: Hoàn thành
    'cancelled' => 0,  // 6: Đã hủy
    'returns' => 0     // 4: Trả hàng - Hoàn tiền
];

foreach ($orders as $o) {
    $st = is_numeric($o['TrangThaiDonHang']) ? (int)$o['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$o['TrangThaiDonHang'])));
    if ($st === 0) $order_counts['pending']++;
    elseif ($st === 1) $order_counts['confirmed']++;
    elseif ($st === 2) $order_counts['preparing']++;
    elseif ($st === 3) $order_counts['delivering']++;
    elseif ($st === 5) $order_counts['completed']++;
    elseif ($st === 6) $order_counts['cancelled']++;
    elseif ($st === 4) $order_counts['returns']++;
}

$filtered_orders = array_filter($orders, function($o) use ($seller_order_status) {
    if ($seller_order_status === 'all') return true;
    $st = is_numeric($o['TrangThaiDonHang']) ? (int)$o['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$o['TrangThaiDonHang'])));
    if ($seller_order_status === 'pending') return $st === 0;
    if ($seller_order_status === 'confirmed') return $st === 1;
    if ($seller_order_status === 'preparing') return $st === 2;
    if ($seller_order_status === 'delivering') return $st === 3;
    if ($seller_order_status === 'completed') return $st === 5;
    if ($seller_order_status === 'cancelled') return $st === 6;
    if ($seller_order_status === 'returns') return $st === 4;
    return true;
});

// Fetch Kho & Lịch sử tồn kho
$inventory_logs = $db->query("
    SELECT lsk.*, sp.TenSanPham, nd.HoTen as TenNguoiThucHien
    FROM LichSuKho lsk
    JOIN SanPham sp ON lsk.MaSanPham = sp.MaSanPham
    JOIN NguoiDung nd ON lsk.NguoiThucHien = nd.MaNguoiDung
    WHERE sp.MaNguoiBan = {$user_id}
    ORDER BY lsk.MaLichSu DESC LIMIT 50
")->fetchAll();

// Fetch Mã Giảm Giá Shop
$vouchers = $db->query("SELECT * FROM MaGiamGia WHERE MaNguoiBan = {$user_id} ORDER BY MaVoucher DESC")->fetchAll();

// Fetch Yêu Cầu Trả Hàng
$return_requests = $db->query("
    SELECT th.*, sp.TenSanPham, nd.HoTen as TenNguoiMua
    FROM TraHangHoanTien th
    JOIN SanPham sp ON th.MaSanPham = sp.MaSanPham
    JOIN NguoiDung nd ON th.MaNguoiMua = nd.MaNguoiDung
    WHERE th.MaNguoiBan = {$user_id}
    ORDER BY th.MaYeuCau DESC
")->fetchAll();

// Fetch Đánh Giá Sản Phẩm
$reviews = $db->query("
    SELECT dg.*, sp.TenSanPham, nd.HoTen as TenNguoiDanhGia, ph.NoiDungPhanHoi, ph.NgayPhanHoi
    FROM DonDanhGiaSanPham dg
    JOIN SanPham sp ON dg.MaSanPham = sp.MaSanPham
    JOIN NguoiDung nd ON dg.MaNguoiDanhGia = nd.MaNguoiDung
    LEFT JOIN PhanHoiDanhGia ph ON dg.MaDanhGia = ph.MaDanhGia
    WHERE sp.MaNguoiBan = {$user_id}
    ORDER BY dg.MaDanhGia DESC
")->fetchAll();

// Điểm đánh giá TB của shop
$avg_rating = (float)($db->query("
    SELECT AVG(dg.SoSao) 
    FROM DonDanhGiaSanPham dg
    JOIN SanPham sp ON dg.MaSanPham = sp.MaSanPham
    WHERE sp.MaNguoiBan = {$user_id}
")->fetchColumn() ?: 5.0);

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kênh Người Bán - <?php echo htmlspecialchars($shop_info['TenCuaHang']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/seller.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="seller-body">
    <div class="seller-layout">
        <!-- Sidebar Navigation -->
        <aside class="seller-sidebar">
            <div>
                <div class="seller-brand">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 6px;"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg> Kênh Người Bán
                </div>
                <ul class="seller-menu">
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=dashboard" class="seller-menu-link <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg> Trang Tổng Quan
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="chat.php" class="seller-menu-link" style="color: #4f46e5; font-weight: 600;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg> Tin Nhắn Khách Hàng
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=products" class="seller-menu-link <?php echo $tab === 'products' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg> Quản Lý Sản Phẩm
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=orders" class="seller-menu-link <?php echo $tab === 'orders' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg> Quản Lý Đơn Hàng
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=inventory" class="seller-menu-link <?php echo $tab === 'inventory' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path><path d="M9 9h6"></path><path d="M9 13h6"></path><path d="M9 17h6"></path></svg> Quản Lý Tồn Kho
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=vouchers" class="seller-menu-link <?php echo $tab === 'vouchers' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg> Mã Giảm Giá
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=returns" class="seller-menu-link <?php echo $tab === 'returns' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><polyline points="23 20 23 14 17 14"></polyline><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"></path></svg> Trả Hàng & Hoàn Tiền
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=reviews" class="seller-menu-link <?php echo $tab === 'reviews' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg> Đánh Giá Shop
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=finance" class="seller-menu-link <?php echo $tab === 'finance' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg> Tài Chính & Báo Cáo
                        </a>
                    </li>
                    <li class="seller-menu-item">
                        <a href="seller_dashboard.php?tab=settings" class="seller-menu-link <?php echo $tab === 'settings' ? 'active' : ''; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg> Cài Đặt Cửa Hàng
                        </a>
                    </li>
                </ul>
            </div>
            <div>
                <a href="index.php" style="color: #94a3b8; text-decoration: none; font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Quay lại Chợ Đồ Cũ
                </a>
            </div>
        </aside>

        <!-- Main Content Body -->
        <main class="seller-content">
            <div class="seller-header">
                <div>
                    <h1 style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin: 0;"><?php echo htmlspecialchars($shop_info['TenCuaHang']); ?></h1>
                    <span style="font-size: 0.85rem; color: #64748b;">Kênh quản trị dành riêng cho chủ cửa hàng • Điểm Uy Tín: <strong><?php echo (int)($current_user['DiemUyTin'] ?? 100); ?></strong>/100</span>
                </div>
                <div>
                    <a href="seller.php?id=<?php echo $user_id; ?>" target="_blank" class="btn-seller btn-seller-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 4px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg> Xem Cửa Hàng Công Khai
                    </a>
                </div>
            </div>

            <?php if (!empty($flash_success)): ?>
                <div style="background: #dcfce7; color: #166534; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">✓ <?php echo htmlspecialchars($flash_success); ?></div>
            <?php endif; ?>
            <?php if (!empty($flash_error)): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">✕ <?php echo htmlspecialchars($flash_error); ?></div>
            <?php endif; ?>

            <!-- ===================================================================
                 MODULE 1: TRANG TỔNG QUAN (DASHBOARD)
                 =================================================================== -->
            <?php if ($tab === 'dashboard'): ?>
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-title">Doanh Thu Hôm Nay</div>
                        <div class="stat-value" style="color: #0284c7;"><?php echo number_format($revenue_today, 0, ',', '.'); ?> đ</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Doanh Thu Tháng Này</div>
                        <div class="stat-value" style="color: #16a34a;"><?php echo number_format($revenue_month, 0, ',', '.'); ?> đ</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Đơn Chờ Xác Nhận</div>
                        <div class="stat-value" style="color: #d97706;"><?php echo $pending_orders_count; ?> đơn</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Đơn Đang Giao</div>
                        <div class="stat-value" style="color: #2563eb;"><?php echo $delivering_orders_count; ?> đơn</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Sản Phẩm Đang Bán</div>
                        <div class="stat-value"><?php echo $active_products_count; ?> SP</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Tồn Kho Cảnh Báo (≤ 5)</div>
                        <div class="stat-value" style="color: #dc2626;"><?php echo $low_stock_count; ?> SP</div>
                    </div>
                </div>

                <!-- Biểu đồ Doanh Thu & Đơn Hàng -->
                <div class="seller-card-box">
                    <h3 style="font-size: 1.1rem; margin-bottom: 20px;">Biểu Đồ Doanh Thu & Đơn Hàng</h3>
                    <canvas id="sellerRevenueChart" style="max-height: 320px;"></canvas>
                </div>

                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        const ctx = document.getElementById('sellerRevenueChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8'],
                                datasets: [{
                                    label: 'Doanh thu (VNĐ)',
                                    data: [1500000, 3200000, 4800000, 2900000, 6100000, 7500000, 8900000, <?php echo $revenue_month; ?>],
                                    borderColor: '#0284c7',
                                    backgroundColor: 'rgba(2, 132, 199, 0.1)',
                                    fill: true,
                                    tension: 0.3
                                }]
                            }
                        });
                    });
                </script>

            <!-- ===================================================================
                 MODULE 2: QUẢN LÝ SẢN PHẨM & BIẾN THỂ
                 =================================================================== -->
            <?php elseif ($tab === 'products'): ?>
                <div class="seller-card-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0;">Danh Sách Sản Phẩm Cửa Hàng</h3>
                        <button onclick="openProductModal()" class="btn-seller btn-seller-primary">+ Thêm Sản Phẩm Mới</button>
                    </div>

                    <table class="seller-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sản Phẩm</th>
                                <th>Danh Mục</th>
                                <th>Giá Bán</th>
                                <th>Giá KM</th>
                                <th>Tồn Kho</th>
                                <th>Trạng Thái</th>
                                <th>Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td>#<?php echo $p['MaSanPham']; ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <img src="<?php echo htmlspecialchars($p['DuongDanAnh'] ?? 'assets/images/no-image.png'); ?>" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
                                            <div>
                                                <strong><?php echo htmlspecialchars($p['TenSanPham']); ?></strong>
                                                <div style="font-size: 0.75rem; color: #64748b;"><?php echo htmlspecialchars($p['ThuongHieu'] ?? 'Không thương hiệu'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($p['TenDanhMuc']); ?></td>
                                    <td><strong><?php echo number_format($p['GiaBan'], 0, ',', '.'); ?> đ</strong></td>
                                    <td><?php echo $p['GiaKhuyenMai'] > 0 ? number_format($p['GiaKhuyenMai'], 0, ',', '.') . ' đ' : '-'; ?></td>
                                    <td>
                                        <span style="font-weight: 700; color: <?php echo $p['SoLuongTon'] <= 5 ? '#dc2626' : '#16a34a'; ?>;">
                                            <?php echo $p['SoLuongTon']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($p['TrangThaiBan'] == b'00'): ?>
                                            <span style="color: #16a34a; font-weight: 700;">Đang bán</span>
                                        <?php elseif ($p['TrangThaiBan'] == b'11'): ?>
                                            <span style="color: #64748b;">Tạm ẩn</span>
                                        <?php else: ?>
                                            <span style="color: #dc2626;">Ngừng bán</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Bạn có chắc chắn muốn thay đổi trạng thái sản phẩm này không?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                            <input type="hidden" name="action" value="toggle_product_status">
                                            <input type="hidden" name="product_id" value="<?php echo $p['MaSanPham']; ?>">
                                            <input type="hidden" name="status_code" value="<?php echo $p['TrangThaiBan'] == b'00' ? '11' : '00'; ?>">
                                            <button type="submit" class="btn-seller" style="background: #f1f5f9; color: #475569;">
                                                <?php echo $p['TrangThaiBan'] == b'00' ? 'Ẩn' : 'Hiện'; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ===================================================================
                 MODULE 3: QUẢN LÝ ĐƠN HÀNG (STRICT FLOW WITH SUB-TABS)
                 =================================================================== -->
            <?php elseif ($tab === 'orders'): ?>
                <div class="seller-card-box">
                    <h3>Quản Lý Đơn Hàng Cửa Hàng</h3>
                    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 20px;">Quy trình xử lý chuẩn: Chờ xác nhận → Đã xác nhận → Đang chuẩn bị → Đang giao → Hoàn thành</p>

                    <!-- Sub-Tabs phân chia từng hành động trong luồng xử lý đơn hàng -->
                    <div class="order-status-tabs" style="display: flex; gap: 8px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 8px; border-bottom: 2px solid #f1f5f9;">
                        <a href="seller_dashboard.php?tab=orders&order_status=all" class="status-tab <?php echo $seller_order_status === 'all' ? 'active' : ''; ?>">
                            Tất cả <span class="tab-count"><?php echo $order_counts['all']; ?></span>
                        </a>
                        <a href="seller_dashboard.php?tab=orders&order_status=pending" class="status-tab <?php echo $seller_order_status === 'pending' ? 'active' : ''; ?>">
                            Chờ xác nhận <span class="tab-count"><?php echo $order_counts['pending']; ?></span>
                        </a>
                        <a href="seller_dashboard.php?tab=orders&order_status=confirmed" class="status-tab <?php echo $seller_order_status === 'confirmed' ? 'active' : ''; ?>">
                            Đã xác nhận <span class="tab-count"><?php echo $order_counts['confirmed']; ?></span>
                        </a>
                        <a href="seller_dashboard.php?tab=orders&order_status=preparing" class="status-tab <?php echo $seller_order_status === 'preparing' ? 'active' : ''; ?>">
                            Đang chuẩn bị <span class="tab-count"><?php echo $order_counts['preparing']; ?></span>
                        </a>
                        <a href="seller_dashboard.php?tab=orders&order_status=delivering" class="status-tab <?php echo $seller_order_status === 'delivering' ? 'active' : ''; ?>">
                            Đang giao <span class="tab-count"><?php echo $order_counts['delivering']; ?></span>
                        </a>
                        <a href="seller_dashboard.php?tab=orders&order_status=completed" class="status-tab <?php echo $seller_order_status === 'completed' ? 'active' : ''; ?>">
                            Hoàn thành <span class="tab-count"><?php echo $order_counts['completed']; ?></span>
                        </a>
                        <a href="seller_dashboard.php?tab=orders&order_status=cancelled" class="status-tab <?php echo $seller_order_status === 'cancelled' ? 'active' : ''; ?>">
                            Đã hủy <span class="tab-count"><?php echo $order_counts['cancelled']; ?></span>
                        </a>
                        <a href="seller_dashboard.php?tab=orders&order_status=returns" class="status-tab <?php echo $seller_order_status === 'returns' ? 'active' : ''; ?>">
                            Trả hàng - Hoàn tiền <span class="tab-count"><?php echo $order_counts['returns']; ?></span>
                        </a>
                    </div>

                    <?php if (empty($filtered_orders)): ?>
                        <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                            Không có đơn hàng nào ở trạng thái này.
                        </div>
                    <?php else: ?>
                        <?php foreach ($filtered_orders as $ord): ?>
                            <?php 
                                $st = is_numeric($ord['TrangThaiDonHang']) ? (int)$ord['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$ord['TrangThaiDonHang'])));
                            ?>
                            <div style="border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; margin-bottom: 16px; background: #fff;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                                    <strong>Đơn hàng #DH-<?php echo sprintf('%05d', $ord['MaDonHang']); ?> • Khách: <?php echo htmlspecialchars($ord['TenKhachHang']); ?> (<?php echo htmlspecialchars($ord['SdtKhachHang']); ?>)</strong>
                                    <span style="font-weight: 700; color: #0284c7;">Tổng: <?php echo number_format($ord['TongTienThanhToan'], 0, ',', '.'); ?> đ</span>
                                </div>

                                <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 12px;">
                                    Địa chỉ giao: <?php echo htmlspecialchars($ord['DiaChiChiTiet'] ?? 'Chưa cập nhật'); ?> • PTTT: <?php echo htmlspecialchars($ord['PhuongThucThanhToan']); ?>
                                    <?php if (!empty($ord['MaVanDon'])): ?>
                                        • Mã vận đơn: <strong style="color: #0284c7;"><?php echo htmlspecialchars($ord['MaVanDon']); ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($ord['LyDoHuy'])): ?>
                                        • Lý do hủy: <strong style="color: #dc2626;"><?php echo htmlspecialchars($ord['LyDoHuy']); ?></strong>
                                    <?php endif; ?>
                                </div>

                                <!-- Buttons Chuyển Trạng Thái Theo Quy Trình Chuẩn -->
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <?php if ($st === 0): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['MaDonHang']; ?>">
                                            <input type="hidden" name="target_status" value="1">
                                            <input type="hidden" name="order_status" value="<?php echo htmlspecialchars($seller_order_status); ?>">
                                            <button type="submit" class="btn-seller btn-seller-success">✓ Xác Nhận Đơn Hàng</button>
                                        </form>
                                    <?php elseif ($st === 1): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['MaDonHang']; ?>">
                                            <input type="hidden" name="target_status" value="2">
                                            <input type="hidden" name="order_status" value="<?php echo htmlspecialchars($seller_order_status); ?>">
                                            <button type="submit" class="btn-seller btn-seller-primary">📦 Chuẩn Bị Hàng</button>
                                        </form>
                                    <?php elseif ($st === 2): ?>
                                        <form method="POST" style="display: flex; gap: 8px; align-items: center;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['MaDonHang']; ?>">
                                            <input type="hidden" name="target_status" value="3">
                                            <input type="hidden" name="order_status" value="<?php echo htmlspecialchars($seller_order_status); ?>">
                                            <input type="text" name="ma_van_don" placeholder="Nhập mã vận đơn..." required style="padding: 6px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem;">
                                            <button type="submit" class="btn-seller btn-seller-primary">🚚 Bàn Giao Vận Chuyển</button>
                                        </form>
                                    <?php elseif ($st === 3): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['MaDonHang']; ?>">
                                            <input type="hidden" name="target_status" value="5">
                                            <input type="hidden" name="order_status" value="<?php echo htmlspecialchars($seller_order_status); ?>">
                                            <button type="submit" class="btn-seller btn-seller-success">✓ Xác Nhận Đã Giao Thành Công</button>
                                        </form>
                                    <?php elseif ($st === 5): ?>
                                        <span style="color: #16a34a; font-weight: 700;">✓ Hoàn Thành</span>
                                    <?php elseif ($st === 6): ?>
                                        <span style="color: #dc2626; font-weight: 700;">✕ Đã Hủy</span>
                                    <?php elseif ($st === 4): ?>
                                        <span style="color: #dc2626; font-weight: 700;">🔄 Trả Hàng - Hoàn Tiền</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <!-- ===================================================================
                 MODULE 4: QUẢN LÝ KHO & TỒN KHO
                 =================================================================== -->
            <?php elseif ($tab === 'inventory'): ?>
                <div class="seller-card-box">
                    <h3>Quản Lý Kho & Tồn Kho Sản Phẩm</h3>
                    <table class="seller-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sản Phẩm</th>
                                <th>Tồn Kho Hiện Tại</th>
                                <th>Thao Tác Điều Chỉnh Kho</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td>#<?php echo $p['MaSanPham']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($p['TenSanPham']); ?></strong></td>
                                    <td><strong style="color: <?php echo $p['SoLuongTon'] <= 5 ? '#dc2626' : '#16a34a'; ?>;"><?php echo $p['SoLuongTon']; ?></strong></td>
                                    <td>
                                        <form method="POST" style="display: flex; gap: 8px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                            <input type="hidden" name="action" value="adjust_stock">
                                            <input type="hidden" name="product_id" value="<?php echo $p['MaSanPham']; ?>">
                                            <input type="number" name="new_stock" value="<?php echo $p['SoLuongTon']; ?>" min="0" required style="width: 70px; padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                            <input type="text" name="reason" placeholder="Lý do điều chỉnh" required style="padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.85rem;">
                                            <button type="submit" class="btn-seller btn-seller-primary">Lưu Tồn Kho</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Lịch Sử Kho -->
                <div class="seller-card-box">
                    <h3>Lịch Sử Nhập / Điều Chỉnh Kho</h3>
                    <table class="seller-table">
                        <thead>
                            <tr>
                                <th>Mã LS</th>
                                <th>Sản Phẩm</th>
                                <th>Số Lượng Cũ → Mới</th>
                                <th>Lý Do</th>
                                <th>Người Thực Hiện</th>
                                <th>Thời Gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory_logs as $log): ?>
                                <tr>
                                    <td>#<?php echo $log['MaLichSu']; ?></td>
                                    <td><?php echo htmlspecialchars($log['TenSanPham']); ?></td>
                                    <td><?php echo $log['SoLuongCu']; ?> → <strong><?php echo $log['SoLuongMoi']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($log['LyDo']); ?></td>
                                    <td><?php echo htmlspecialchars($log['TenNguoiThucHien']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($log['NgayTao'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ===================================================================
                 MODULE 5: KHUYẾN MÃI (VOUCHERS)
                 =================================================================== -->
            <?php elseif ($tab === 'vouchers'): ?>
                <div class="seller-card-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0;">Mã Giảm Giá Của Cửa Hàng</h3>
                        <button onclick="openVoucherModal()" class="btn-seller btn-seller-primary">+ Tạo Voucher Mới</button>
                    </div>

                    <table class="seller-table">
                        <thead>
                            <tr>
                                <th>Mã Code</th>
                                <th>Loại Giảm</th>
                                <th>Giá Trị</th>
                                <th>Đơn Tối Thiểu</th>
                                <th>Lượt Dùng</th>
                                <th>Thời Hạn</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vouchers as $v): ?>
                                <tr>
                                    <td><strong style="color: #0284c7;"><?php echo htmlspecialchars($v['MaCode']); ?></strong></td>
                                    <td><?php echo $v['LoaiGiam'] === 'PERCENT' ? 'Theo %' : 'Số tiền cố định'; ?></td>
                                    <td><?php echo $v['LoaiGiam'] === 'PERCENT' ? (float)$v['GiaTriGiam'] . '%' : number_format($v['GiaTriGiam'], 0, ',', '.') . ' đ'; ?></td>
                                    <td><?php echo number_format($v['DonToiThieu'], 0, ',', '.'); ?> đ</td>
                                    <td><?php echo $v['TongLuotDung']; ?> lượt</td>
                                    <td><?php echo date('d/m/Y', strtotime($v['NgayBatDau'])) . ' - ' . date('d/m/Y', strtotime($v['NgayKetThuc'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ===================================================================
                 MODULE 6: TRẢ HÀNG VÀ HOÀN TIỀN
                 =================================================================== -->
            <?php elseif ($tab === 'returns'): ?>
                <div class="seller-card-box">
                    <h3>Yêu Cầu Trả Hàng & Hoàn Tiền</h3>
                    <?php if (empty($return_requests)): ?>
                        <p style="color: #64748b;">Chưa có yêu cầu trả hàng nào.</p>
                    <?php else: ?>
                        <?php foreach ($return_requests as $ret): ?>
                            <div style="border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; margin-bottom: 16px;">
                                <div><strong>Yêu cầu #<?php echo $ret['MaYeuCau']; ?> • Khách: <?php echo htmlspecialchars($ret['TenNguoiMua']); ?></strong></div>
                                <div style="margin-top: 6px;">Sản phẩm: <?php echo htmlspecialchars($ret['TenSanPham']); ?> • Số tiền hoàn: <strong><?php echo number_format($ret['SoTienHoan'], 0, ',', '.'); ?> đ</strong></div>
                                <div style="margin-top: 6px; font-size: 0.85rem; color: #64748b;">Lý do trả hàng: <?php echo htmlspecialchars($ret['LyDoTraHang']); ?></div>

                                <?php if ($ret['TrangThai'] === 'CHO_XU_LY'): ?>
                                    <form method="POST" style="margin-top: 14px; display: flex; gap: 10px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                        <input type="hidden" name="action" value="process_return">
                                        <input type="hidden" name="request_id" value="<?php echo $ret['MaYeuCau']; ?>">
                                        <input type="text" name="ly_do_phan_hoi" placeholder="Lý do chấp nhận/từ chối" required style="padding: 6px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem;">
                                        <button type="submit" name="status" value="CHAP_NHAN" class="btn-seller btn-seller-success">✓ Chấp Nhận</button>
                                        <button type="submit" name="status" value="TU_CHOI" class="btn-seller btn-seller-danger">✕ Từ Chối</button>
                                    </form>
                                <?php else: ?>
                                    <div style="margin-top: 10px; font-weight: 700; color: #0284c7;">Trạng thái: <?php echo htmlspecialchars($ret['TrangThai']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <!-- ===================================================================
                 MODULE 7: ĐÁNH GIÁ SẢN PHẨM
                 =================================================================== -->
            <?php elseif ($tab === 'reviews'): ?>
                <div class="seller-card-box">
                    <h3>Đánh Giá Của Khách Hàng (Điểm TB: <?php echo number_format($avg_rating, 1); ?> ⭐)</h3>
                    <?php foreach ($reviews as $rev): ?>
                        <div style="border-bottom: 1px solid #f1f5f9; padding: 16px 0;">
                            <div><strong><?php echo htmlspecialchars($rev['TenNguoiDanhGia']); ?></strong> • <span style="color: #d97706; font-weight: 700;"><?php echo $rev['SoSao']; ?> ⭐</span></div>
                            <div style="font-size: 0.85rem; color: #64748b; margin-top: 4px;">Sản phẩm: <?php echo htmlspecialchars($rev['TenSanPham']); ?></div>
                            <p style="margin: 8px 0; font-size: 0.9rem;"><?php echo htmlspecialchars($rev['NhanXet'] ?? 'Không có nhận xét'); ?></p>

                            <?php if (!empty($rev['NoiDungPhanHoi'])): ?>
                                <div style="background: #f8fafc; padding: 10px 14px; border-radius: 10px; font-size: 0.85rem; color: #0284c7;">
                                    <strong>Phản hồi của Shop:</strong> <?php echo htmlspecialchars($rev['NoiDungPhanHoi']); ?>
                                </div>
                            <?php else: ?>
                                <form method="POST" style="display: flex; gap: 8px; margin-top: 8px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="action" value="reply_review">
                                    <input type="hidden" name="review_id" value="<?php echo $rev['MaDanhGia']; ?>">
                                    <input type="text" name="noi_dung_phan_hoi" placeholder="Nhập phản hồi cho khách..." required style="flex: 1; padding: 6px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem;">
                                    <button type="submit" class="btn-seller btn-seller-primary">Gửi Phản Hồi</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- ===================================================================
                 MODULE 8: TÀI CHÍNH & CÀI ĐẶT CỬA HÀNG
                 =================================================================== -->
            <?php elseif ($tab === 'finance'): ?>
                <div class="seller-card-box">
                    <h3>Báo Cáo Tài Chính Cửa Hàng</h3>
                    <div class="stat-grid">
                        <div class="stat-card">
                            <div class="stat-title">Doanh Thu Thực Nhận</div>
                            <div class="stat-value" style="color: #16a34a;"><?php echo number_format($revenue_month * 0.95, 0, ',', '.'); ?> đ</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-title">Phí Nền Tảng (5%)</div>
                            <div class="stat-value" style="color: #dc2626;"><?php echo number_format($revenue_month * 0.05, 0, ',', '.'); ?> đ</div>
                        </div>
                    </div>
                </div>

            <?php elseif ($tab === 'settings'): ?>
                <div class="seller-card-box">
                    <h3>Thiết Lập Thông Tin Cửa Hàng</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="update_settings">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px;">Tên Cửa Hàng *</label>
                            <input type="text" name="ten_cua_hang" value="<?php echo htmlspecialchars($shop_info['TenCuaHang']); ?>" required class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1;">
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px;">Mô Tả Cửa Hàng</label>
                            <textarea name="mo_ta_cua_hang" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1;"><?php echo htmlspecialchars($shop_info['MoTaCuaHang'] ?? ''); ?></textarea>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px;">Tên Ngân Hàng Rút Tiền</label>
                                <input type="text" name="ten_ngan_hang" value="<?php echo htmlspecialchars($shop_info['TenNganHang'] ?? ''); ?>" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px;">Số Tài Khoản</label>
                                <input type="text" name="so_tai_khoan" value="<?php echo htmlspecialchars($shop_info['SoTaiKhoan'] ?? ''); ?>" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1;">
                            </div>
                        </div>
                        <button type="submit" class="btn-seller btn-seller-primary">Lưu Thay Đổi Cài Đặt</button>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modal Thêm Sản Phẩm -->
    <div id="productModal" class="modal-seller">
        <div class="modal-seller-card">
            <h3 style="margin-top: 0;">Đăng Bán Sản Phẩm Mới</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                <input type="hidden" name="action" value="save_product">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-weight: 700; font-size: 0.85rem;">Tên Sản Phẩm *</label>
                    <input type="text" name="ten_san_pham" required class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 0.85rem;">Danh Mục *</label>
                        <select name="ma_danh_muc" required class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['MaDanhMuc']; ?>"><?php echo htmlspecialchars($c['TenDanhMuc']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 0.85rem;">Giá Bán (VNĐ) *</label>
                        <input type="number" name="gia_ban" required min="1000" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 0.85rem;">Tồn Kho *</label>
                        <input type="number" name="so_luong_ton" value="1" min="1" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 0.85rem;">Tình Trạng *</label>
                        <input type="text" name="tinh_trang" value="Mới 99%" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 700; font-size: 0.85rem;">Mô Tả Chi Tiết</label>
                    <textarea name="mo_ta" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; min-height: 80px;"></textarea>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeProductModal()" class="btn-seller" style="background: #cbd5e1; color: #0f172a;">Hủy</button>
                    <button type="submit" class="btn-seller btn-seller-primary">Đăng Bán Ngay</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Thêm Mã Giảm Giá -->
    <div id="voucherModal" class="modal-seller">
        <div class="modal-seller-card">
            <h3 style="margin-top: 0;">Tạo Mã Giảm Giá Cửa Hàng</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                <input type="hidden" name="action" value="create_voucher">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-weight: 700; font-size: 0.85rem;">Mã Code (Ví dụ: SHOP50K) *</label>
                    <input type="text" name="ma_code" required class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 0.85rem;">Loại Giảm *</label>
                        <select name="loai_giam" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                            <option value="PERCENT">Giảm Theo %</option>
                            <option value="FIXED">Giảm Tiền Cố Định</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 0.85rem;">Giá Trị Giảm *</label>
                        <input type="number" name="gia_tri_giam" required min="1" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="closeVoucherModal()" class="btn-seller" style="background: #cbd5e1; color: #0f172a;">Hủy</button>
                    <button type="submit" class="btn-seller btn-seller-primary">Lưu Voucher</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openProductModal() { document.getElementById('productModal').style.display = 'flex'; }
        function closeProductModal() { document.getElementById('productModal').style.display = 'none'; }
        function openVoucherModal() { document.getElementById('voucherModal').style.display = 'flex'; }
        function closeVoucherModal() { document.getElementById('voucherModal').style.display = 'none'; }
    </script>
    <?php include_once __DIR__ . '/includes/chatbot.php'; ?>
</body>
</html>
