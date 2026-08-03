<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;
$user_id = 0;

if (isset($_SESSION['user_id'])) {
    try {
        $db = getDBConnection();
        $user_id = $_SESSION['user_id'];
        $stmt = $db->prepare("SELECT * FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
        $stmt->execute(['id' => $user_id]);
        $user_data = $stmt->fetch();
        if ($user_data) {
            $is_logged_in = true;
        }
    } catch (Exception $e) {
        $is_logged_in = false;
    }
}

// Redirect to login if not logged in
if (!$is_logged_in) {
    header("Location: login_page.php");
    exit;
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $oid = (int)($_POST['order_id'] ?? 0);

    // 1. Hủy đơn hàng (Chỉ dành cho đơn Chờ Xác Nhận st=0)
    if ($action === 'cancel_order' && $oid > 0) {
        $ly_do_option = trim($_POST['ly_do_option'] ?? '');
        $ly_do_other = trim($_POST['ly_do_other'] ?? '');
        $ly_do_final = ($ly_do_option === 'Khác' && !empty($ly_do_other)) ? $ly_do_other : $ly_do_option;
        if (empty($ly_do_final)) {
            $ly_do_final = 'Người mua hủy đơn hàng';
        }

        try {
            $db = getDBConnection();
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM DonHang WHERE MaDonHang = :oid AND MaNguoiMua = :uid FOR UPDATE");
            $stmt->execute(['oid' => $oid, 'uid' => $user_id]);
            $ord_item = $stmt->fetch();

            if ($ord_item) {
                $st_val = is_numeric($ord_item['TrangThaiDonHang']) ? (int)$ord_item['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$ord_item['TrangThaiDonHang'])));
                if ($st_val === 0) { // Chờ xác nhận
                    // Cập nhật trạng thái đơn thành Đã Hủy (b'110' / 6) kèm Lý Do Hủy
                    $upd = $db->prepare("UPDATE DonHang SET TrangThaiDonHang = b'110', LyDoHuy = :lydo WHERE MaDonHang = :oid");
                    $upd->execute(['lydo' => $ly_do_final, 'oid' => $oid]);

                    // Hoàn tiền vào ví điện tử nếu đã thanh toán bằng ví
                    if ($ord_item['PhuongThucThanhToan'] === 'Ví điện tử') {
                        $pay_st = is_numeric($ord_item['TrangThaiThanhToan']) ? (int)$ord_item['TrangThaiThanhToan'] : (int)bindec(decbin(ord((string)$ord_item['TrangThaiThanhToan'])));
                        if ($pay_st === 1) { // Đã thanh toán
                            $w_chk = $db->prepare("SELECT MaVi FROM ViDienTu WHERE MaNguoiDung = :uid");
                            $w_chk->execute(['uid' => $user_id]);
                            $w_id = $w_chk->fetchColumn();
                            if ($w_id) {
                                $refund_amt = (float)$ord_item['TongTienThanhToan'];
                                $upd_w = $db->prepare("UPDATE ViDienTu SET SoDu = SoDu + :amt WHERE MaVi = :mavi");
                                $upd_w->execute(['amt' => $refund_amt, 'mavi' => $w_id]);

                                $upd_pay = $db->prepare("UPDATE DonHang SET TrangThaiThanhToan = b'100' WHERE MaDonHang = :oid");
                                $upd_pay->execute(['oid' => $oid]);

                                $ins_tx = $db->prepare("
                                    INSERT INTO LichSuGiaoDichVi (MaViNguon, MaViDich, SoTien, LoaiGiaoDich, TrangThai, MoTa, MaDonHang, NgayTao)
                                    VALUES (NULL, :mavi, :amt, 'HOAN_TIEN_HUY_DON', b'01', :mota, :oid, NOW())
                                ");
                                $ins_tx->execute([
                                    'mavi' => $w_id,
                                    'amt' => $refund_amt,
                                    'mota' => "Hoàn tiền đơn hàng #DH-" . sprintf('%05d', $oid) . " (Lý do hủy: {$ly_do_final})",
                                    'oid' => $oid
                                ]);
                            }
                        }
                    }

                    // Hoàn trả lại số lượng tồn kho sản phẩm
                    $items_stmt = $db->prepare("SELECT MaSanPham, SoLuong FROM ChiTietDonHang WHERE MaDonHang = :oid");
                    $items_stmt->execute(['oid' => $oid]);
                    $items = $items_stmt->fetchAll();
                    $upd_stock = $db->prepare("UPDATE SanPham SET SoLuongTon = SoLuongTon + :qty WHERE MaSanPham = :pid");
                    foreach ($items as $it) {
                        $upd_stock->execute(['qty' => $it['SoLuong'], 'pid' => $it['MaSanPham']]);
                    }

                    $_SESSION['flash_success'] = "Đã hủy đơn hàng #DH-" . sprintf('%05d', $oid) . " thành công!";
                } else {
                    $_SESSION['flash_error'] = "Đơn hàng đã được xử lý hoặc đang giao, không thể tự hủy. Vui lòng liên hệ người bán!";
                }
            }
            $db->commit();
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = "Lỗi khi hủy đơn hàng: " . $e->getMessage();
        }
        header("Location: cart.php");
        exit;
    }

    // 2. Xác nhận đã nhận hàng (st=5 Hoàn tất)
    if ($action === 'confirm_received' && $oid > 0) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT * FROM DonHang WHERE MaDonHang = :oid AND MaNguoiMua = :uid");
            $stmt->execute(['oid' => $oid, 'uid' => $user_id]);
            $ord_item = $stmt->fetch();

            if ($ord_item) {
                // Cập nhật trạng thái đơn thành Hoàn Tất / Đã Nhận (b'101' / 5)
                $upd = $db->prepare("UPDATE DonHang SET TrangThaiDonHang = b'101' WHERE MaDonHang = :oid");
                $upd->execute(['oid' => $oid]);

                $_SESSION['flash_success'] = "Cảm ơn bạn! Đã xác nhận nhận đơn hàng #DH-" . sprintf('%05d', $oid) . " thành công!";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Lỗi khi xác nhận nhận hàng: " . $e->getMessage();
        }
        header("Location: orders.php?status=received");
        exit;
    }

    // 3. Yêu cầu trả hàng / hoàn tiền (st=4 Trả hàng)
    if ($action === 'request_return' && $oid > 0) {
        $ly_do_tra = trim($_POST['ly_do_tra_hang'] ?? '');
        $bang_chung = trim($_POST['hinh_anh_bang_chung'] ?? '');

        if (empty($ly_do_tra)) {
            $_SESSION['flash_error'] = "Vui lòng điền lý do yêu cầu trả hàng / hoàn tiền!";
        } else {
            try {
                $db = getDBConnection();
                $db->beginTransaction();

                $stmt = $db->prepare("
                    SELECT dh.*, ct.MaSanPham, sp.MaNguoiBan 
                    FROM DonHang dh 
                    JOIN ChiTietDonHang ct ON dh.MaDonHang = ct.MaDonHang 
                    JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham 
                    WHERE dh.MaDonHang = :oid AND dh.MaNguoiMua = :uid 
                    LIMIT 1
                ");
                $stmt->execute(['oid' => $oid, 'uid' => $user_id]);
                $ord_item = $stmt->fetch();

                if ($ord_item) {
                    // Thêm vào bảng TraHangHoanTien
                    $ins_ret = $db->prepare("
                        INSERT INTO TraHangHoanTien (MaDonHang, MaSanPham, MaNguoiMua, MaNguoiBan, LyDoTraHang, HinhAnhBangChung, SoTienHoan, TrangThai, NgayTao)
                        VALUES (:oid, :pid, :uid, :seller_id, :reason, :proof, :amt, 'CHO_XU_LY', NOW())
                    ");
                    $ins_ret->execute([
                        'oid' => $oid,
                        'pid' => $ord_item['MaSanPham'],
                        'uid' => $user_id,
                        'seller_id' => $ord_item['MaNguoiBan'],
                        'reason' => $ly_do_tra,
                        'proof' => $bang_chung,
                        'amt' => $ord_item['TongTienThanhToan']
                    ]);

                    // Cập nhật trạng thái đơn thành Trả Hàng (b'100' / 4)
                    $upd = $db->prepare("UPDATE DonHang SET TrangThaiDonHang = b'100' WHERE MaDonHang = :oid");
                    $upd->execute(['oid' => $oid]);

                    $_SESSION['flash_success'] = "Đã gửi yêu cầu trả hàng / hoàn tiền cho đơn #DH-" . sprintf('%05d', $oid) . "! Người bán sẽ xem xét trong 24h-48h.";
                }
                $db->commit();
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) $db->rollBack();
                $_SESSION['flash_error'] = "Lỗi khi gửi yêu cầu trả hàng: " . $e->getMessage();
            }
        }
        header("Location: orders.php?status=returns");
        exit;
    }
}

// Helper to format order status
function formatOrderStatus($status_val) {
    $val = is_numeric($status_val) ? (int)$status_val : (int)bindec(decbin(ord((string)$status_val)));
    switch ($val) {
        case 0: return ['text' => 'Chờ xác nhận', 'color' => '#d97706', 'bg' => '#fef3c7'];
        case 1: return ['text' => 'Đang xử lý', 'color' => '#2563eb', 'bg' => '#dbeafe'];
        case 2: return ['text' => 'Đang chuẩn bị', 'color' => '#0284c7', 'bg' => '#e0f2fe'];
        case 3: return ['text' => 'Đang giao', 'color' => '#0284c7', 'bg' => '#e0f2fe'];
        case 4: return ['text' => 'Trả hàng / Hoàn tiền', 'color' => '#dc2626', 'bg' => '#fee2e2'];
        case 5: return ['text' => 'Hoàn tất / Đã nhận', 'color' => '#16a34a', 'bg' => '#dcfce7'];
        case 6: return ['text' => 'Đã hủy', 'color' => '#6b7280', 'bg' => '#f3f4f6'];
        default: return ['text' => 'Chờ xác nhận', 'color' => '#d97706', 'bg' => '#fef3c7'];
    }
}

// Helper to format payment status
function formatPaymentStatus($status_val) {
    $val = is_numeric($status_val) ? (int)$status_val : (int)bindec(decbin(ord((string)$status_val)));
    switch ($val) {
        case 0: return 'Chưa thanh toán';
        case 1: return 'Đã thanh toán';
        case 2: return 'Tạm giữ (Escrow)';
        case 3: return 'Đã giải ngân';
        case 4: return 'Đã hoàn tiền';
        default: return 'Chưa thanh toán';
    }
}

// Fetch user's orders
$all_orders = [];
try {
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT dh.*, sdc.DiaChiChiTiet
        FROM DonHang dh
        LEFT JOIN SoDiaChi sdc ON dh.MaDiaChiGiao = sdc.MaDiaChi
        WHERE dh.MaNguoiMua = :uid
        ORDER BY dh.NgayTao DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    $all_orders = $stmt->fetchAll();

    foreach ($all_orders as &$ord) {
        $item_stmt = $db->prepare("
            SELECT ct.*, sp.TenSanPham, sp.TinhTrang, nd.HoTen as TenNguoiBan,
                   (SELECT DuongDanAnh FROM HinhAnhSP WHERE MaSanPham = sp.MaSanPham ORDER BY AnhChinh DESC, MaHinhAnh ASC LIMIT 1) as DuongDanAnh
            FROM ChiTietDonHang ct
            JOIN SanPham sp ON ct.MaSanPham = sp.MaSanPham
            JOIN NguoiDung nd ON sp.MaNguoiBan = nd.MaNguoiDung
            WHERE ct.MaDonHang = :oid
        ");
        $item_stmt->execute(['oid' => $ord['MaDonHang']]);
        $ord['Items'] = $item_stmt->fetchAll();
    }
    unset($ord);
} catch (Exception $e) {
    $all_orders = [];
}

// Calculate status counts
$status_filter = $_GET['status'] ?? 'all';
$counts = [
    'all' => count($all_orders),
    'pending' => 0,
    'delivering' => 0,
    'delivered' => 0,
    'received' => 0,
    'returns' => 0,
    'cancelled' => 0
];

foreach ($all_orders as $o) {
    $st = is_numeric($o['TrangThaiDonHang']) ? (int)$o['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$o['TrangThaiDonHang'])));
    if ($st === 0) $counts['pending']++;
    elseif ($st === 1 || $st === 2) $counts['delivering']++;
    elseif ($st === 3) $counts['delivered']++;
    elseif ($st === 5) $counts['received']++;
    elseif ($st === 4) $counts['returns']++;
    elseif ($st === 6) $counts['cancelled']++;
}

// Filter orders based on selected status tab
$orders = array_filter($all_orders, function($o) use ($status_filter) {
    if ($status_filter === 'all') return true;
    $st = is_numeric($o['TrangThaiDonHang']) ? (int)$o['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$o['TrangThaiDonHang'])));
    if ($status_filter === 'pending') return $st === 0;
    if ($status_filter === 'delivering') return ($st === 1 || $st === 2);
    if ($status_filter === 'delivered') return $st === 3;
    if ($status_filter === 'received') return $st === 5;
    if ($status_filter === 'returns') return $st === 4;
    if ($status_filter === 'cancelled') return $st === 6;
    return true;
});

$cart_count = getCartItemCount();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn Hàng Của Tôi - Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .orders-container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .page-top-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
        }
        .page-tab-item {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            padding: 10px 22px;
            border-radius: 12px;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
        }
        .page-tab-item:hover {
            color: var(--primary);
            background: rgba(2, 132, 199, 0.06);
        }
        .page-tab-item.active {
            color: var(--primary);
            background: #e0f2fe;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.15);
        }
        .order-status-tabs {
            display: flex;
            gap: 8px;
            background: #ffffff;
            padding: 8px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            margin-bottom: 28px;
            overflow-x: auto;
            border: 1px solid #f1f5f9;
        }
        .status-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .status-tab:hover {
            color: var(--text-main);
            background: #f8fafc;
        }
        .status-tab.active {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.3);
        }
        .status-tab .tab-count {
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 50px;
        }
        .status-tab.active .tab-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }
        .order-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 24px;
            border: 1px solid rgba(0,0,0,0.04);
            transition: transform 0.2s ease;
        }
        .order-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .order-status-badge {
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-block;
        }
        .order-item-row {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 14px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .order-item-row:last-child { border-bottom: none; }
        .order-item-img {
            width: 75px;
            height: 75px;
            border-radius: 12px;
            object-fit: cover;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .order-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 18px;
            border-top: 1px solid #f1f5f9;
            margin-top: 16px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .btn-cancel-order {
            background: #fee2e2;
            color: #dc2626;
            border: none;
            padding: 9px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-cancel-order:hover {
            background: #fca5a5;
            color: #991b1b;
        }
        .btn-confirm-received {
            background: #dcfce7;
            color: #166534;
            border: none;
            padding: 9px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-confirm-received:hover {
            background: #bbf7d0;
            color: #14532d;
        }
        .btn-request-return {
            background: #fee2e2;
            color: #dc2626;
            border: none;
            padding: 9px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-request-return:hover {
            background: #fca5a5;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="background-decor"></div>
    <div class="site-wrapper">
        <header class="site-header">
            <div class="nav-container">
                <a href="index.php" class="brand-logo">Chợ Đồ Cũ</a>
                <nav class="nav-menu">
                    <a href="index.php" class="nav-link">Trang Chủ</a>
                    <a href="cart.php" class="nav-link" style="display: flex; align-items: center; gap: 6px; position: relative;">
                        Giỏ Hàng
                        <?php if ($cart_count > 0): ?>
                            <span style="background: var(--primary); color: white; border-radius: 50px; padding: 2px 8px; font-size: 0.75rem; font-weight: 700;">
                                <?php echo $cart_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="post_product.php" class="nav-link" style="color: var(--primary); font-weight: 700;">Đăng Bán</a>
                    <a href="profile.php" class="nav-link">Hồ Sơ</a>
                </nav>
            </div>
        </header>

        <div class="orders-container">
            <!-- Navigation Tabs Giỏ Hàng vs Đơn Hàng -->
            <div class="page-top-tabs">
                <a href="cart.php" class="page-tab-item">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 4px;"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg> Giỏ Hàng Của Tôi <?php echo $cart_count > 0 ? "({$cart_count})" : ''; ?>
                </a>
                <a href="orders.php" class="page-tab-item active">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 4px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg> Đơn Hàng Của Tôi
                </a>
            </div>

            <!-- Sub-Tabs Lọc Trạng Thái Đơn Hàng -->
            <div class="order-status-tabs">
                <a href="orders.php?status=all" class="status-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                    Tất cả <span class="tab-count"><?php echo $counts['all']; ?></span>
                </a>
                <a href="orders.php?status=pending" class="status-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                    Chờ xác nhận <span class="tab-count"><?php echo $counts['pending']; ?></span>
                </a>
                <a href="orders.php?status=delivering" class="status-tab <?php echo $status_filter === 'delivering' ? 'active' : ''; ?>">
                    Đang giao <span class="tab-count"><?php echo $counts['delivering']; ?></span>
                </a>
                <a href="orders.php?status=delivered" class="status-tab <?php echo $status_filter === 'delivered' ? 'active' : ''; ?>">
                    Đã giao <span class="tab-count"><?php echo $counts['delivered']; ?></span>
                </a>
                <a href="orders.php?status=received" class="status-tab <?php echo $status_filter === 'received' ? 'active' : ''; ?>">
                    Đã nhận <span class="tab-count"><?php echo $counts['received']; ?></span>
                </a>
                <a href="orders.php?status=returns" class="status-tab <?php echo $status_filter === 'returns' ? 'active' : ''; ?>">
                    Trả hàng <span class="tab-count"><?php echo $counts['returns']; ?></span>
                </a>
                <a href="orders.php?status=cancelled" class="status-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
                    Đã hủy <span class="tab-count"><?php echo $counts['cancelled']; ?></span>
                </a>
            </div>

            <?php if (!empty($flash_success)): ?>
                <div style="background: #dcfce7; color: #166534; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">
                    ✓ <?php echo htmlspecialchars($flash_success); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($flash_error)): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">
                    ✕ <?php echo htmlspecialchars($flash_error); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($orders)): ?>
                <div class="order-card" style="text-align: center; padding: 60px 20px;">
                    <h2 style="font-size: 1.3rem; margin-bottom: 8px; color: var(--text-main);">Không tìm thấy đơn hàng nào</h2>
                    <p style="color: var(--text-muted); margin-bottom: 24px;">Không có đơn hàng nào khớp với danh mục trạng thái hiện tại.</p>
                    <a href="index.php" class="btn btn-primary" style="border-radius: 50px; padding: 12px 30px; text-decoration: none;">Khám Phá Sản Phẩm</a>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $ord): ?>
                    <?php 
                        $st_val = is_numeric($ord['TrangThaiDonHang']) ? (int)$ord['TrangThaiDonHang'] : (int)bindec(decbin(ord((string)$ord['TrangThaiDonHang'])));
                        $status_info = formatOrderStatus($ord['TrangThaiDonHang']);
                        $payment_text = formatPaymentStatus($ord['TrangThaiThanhToan']);
                    ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div>
                                <strong style="font-size: 1.1rem; color: var(--text-main);">
                                    Đơn hàng #DH-<?php echo sprintf('%05d', $ord['MaDonHang']); ?>
                                </strong>
                                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                                    Ngày đặt: <?php echo date('d/m/Y H:i', strtotime($ord['NgayTao'])); ?>
                                </div>
                            </div>
                            <div>
                                <span class="order-status-badge" style="background: <?php echo $status_info['bg']; ?>; color: <?php echo $status_info['color']; ?>;">
                                    <?php echo $status_info['text']; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Items List -->
                        <div style="margin-bottom: 16px;">
                            <?php foreach ($ord['Items'] as $item): ?>
                                <div class="order-item-row">
                                    <img src="<?php echo htmlspecialchars(!empty($item['DuongDanAnh']) ? $item['DuongDanAnh'] : 'assets/images/no-image.png'); ?>" alt="Thumb" class="order-item-img">
                                    <div style="flex: 1;">
                                        <a href="product_detail.php?id=<?php echo $item['MaSanPham']; ?>" style="font-weight: 700; color: var(--text-main); text-decoration: none; font-size: 1rem; display: block; margin-bottom: 4px;">
                                            <?php echo htmlspecialchars($item['TenSanPham']); ?>
                                        </a>
                                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                                            Người bán: <strong><?php echo htmlspecialchars($item['TenNguoiBan']); ?></strong> • Tình trạng: <?php echo htmlspecialchars($item['TinhTrang']); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 2px;">
                                            Số lượng: <?php echo $item['SoLuong']; ?> x <?php echo number_format($item['GiaChotMua'], 0, ',', '.'); ?> đ
                                        </div>
                                    </div>
                                    <div style="font-weight: 800; color: var(--primary); font-size: 1.05rem;">
                                        <?php echo number_format($item['GiaChotMua'] * $item['SoLuong'], 0, ',', '.'); ?> đ
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Order Footer -->
                        <div class="order-footer">
                            <div style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.6;">
                                <div>Địa chỉ giao: <strong><?php echo htmlspecialchars($ord['DiaChiChiTiet'] ?? 'Chưa cập nhật'); ?></strong></div>
                                <div>Thanh toán: <strong><?php echo htmlspecialchars($ord['PhuongThucThanhToan']); ?></strong> (<?php echo $payment_text; ?>)</div>
                                <?php if (!empty($ord['MaVanDon'])): ?>
                                    <div style="font-size: 0.85rem; color: #0284c7; margin-top: 2px;">
                                        Mã vận đơn: <strong><?php echo htmlspecialchars($ord['MaVanDon']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($ord['LyDoHuy'])): ?>
                                    <div style="font-size: 0.85rem; color: #dc2626; margin-top: 4px; background: #fee2e2; padding: 4px 10px; border-radius: 8px; display: inline-block;">
                                        Lý do hủy: <strong><?php echo htmlspecialchars($ord['LyDoHuy']); ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                                <div>
                                    <span style="font-size: 0.9rem; color: var(--text-muted);">Tổng thanh toán: </span>
                                    <strong style="font-size: 1.25rem; color: var(--primary);"><?php echo number_format($ord['TongTienThanhToan'], 0, ',', '.'); ?> đ</strong>
                                </div>

                                <?php if ($st_val === 0): ?>
                                    <button type="button" class="btn-cancel-order" onclick="openCancelModal(<?php echo $ord['MaDonHang']; ?>, 'DH-<?php echo sprintf('%05d', $ord['MaDonHang']); ?>')">
                                        Hủy Đơn Hàng
                                    </button>
                                <?php elseif ($st_val === 1 || $st_val === 2 || $st_val === 3): ?>
                                    <form method="POST" action="orders.php?status=received" style="display: inline;" onsubmit="return confirm('Bạn có chắc chắn đã nhận được hàng và hài lòng với sản phẩm không?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                        <input type="hidden" name="action" value="confirm_received">
                                        <input type="hidden" name="order_id" value="<?php echo $ord['MaDonHang']; ?>">
                                        <button type="submit" class="btn-confirm-received">✓ Xác Nhận Đã Nhận</button>
                                    </form>
                                    <button type="button" class="btn-request-return" onclick="openReturnModal(<?php echo $ord['MaDonHang']; ?>, 'DH-<?php echo sprintf('%05d', $ord['MaDonHang']); ?>')">
                                        🔄 Hoàn Đơn / Trả Hàng
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Modal Form Hủy Đơn Hàng -->
        <div id="cancelOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 24px; padding: 28px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); position: relative;">
                <button type="button" onclick="closeCancelModal()" style="position: absolute; top: 20px; right: 20px; background: #f1f5f9; border: none; font-size: 1.1rem; cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">✕</button>

                <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-main); margin-bottom: 6px;">Hủy Đơn Hàng</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;" id="cancel_order_code_display"></p>

                <form method="POST" action="orders.php?status=<?php echo urlencode($status_filter); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="hidden" name="action" value="cancel_order">
                    <input type="hidden" name="order_id" id="modal_cancel_order_id">

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 8px;">Vui lòng chọn lý do hủy *</label>
                        <select name="ly_do_option" id="ly_do_select" class="form-control" style="width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid #cbd5e1; font-size: 0.9rem;" onchange="toggleCancelOtherReason(this.value)">
                            <option value="Tôi muốn thay đổi địa chỉ nhận hàng">Tôi muốn thay đổi địa chỉ nhận hàng</option>
                            <option value="Tôi muốn thay đổi sản phẩm / số lượng khác">Tôi muốn thay đổi sản phẩm / số lượng khác</option>
                            <option value="Tôi tìm thấy sản phẩm giá tốt hơn ở nơi khác">Tôi tìm thấy sản phẩm giá tốt hơn ở nơi khác</option>
                            <option value="Đổi ý, không muốn mua nữa">Đổi ý, không muốn mua nữa</option>
                            <option value="Khác">Lý do khác (Nhập chi tiết)</option>
                        </select>
                    </div>

                    <div id="cancel_other_box" style="display: none; margin-bottom: 20px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 6px;">Chi tiết lý do khác *</label>
                        <textarea name="ly_do_other" class="form-control" style="width: 100%; padding: 10px; border-radius: 12px; border: 1px solid #cbd5e1; font-size: 0.9rem; resize: vertical; min-height: 80px;" placeholder="Nhập lý do cụ thể..."></textarea>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 24px;">
                        <button type="button" onclick="closeCancelModal()" style="flex: 1; padding: 12px; border-radius: 50px; border: 1px solid #cbd5e1; background: #ffffff; color: var(--text-main); font-weight: 700; cursor: pointer;">Trở Lại</button>
                        <button type="submit" style="flex: 1; padding: 12px; border-radius: 50px; border: none; background: #dc2626; color: #ffffff; font-weight: 700; cursor: pointer;">Xác Nhận Hủy</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Form Yêu Cầu Trả Hàng / Hoàn Tiền -->
        <div id="returnOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 24px; padding: 28px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); position: relative;">
                <button type="button" onclick="closeReturnModal()" style="position: absolute; top: 20px; right: 20px; background: #f1f5f9; border: none; font-size: 1.1rem; cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">✕</button>

                <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-main); margin-bottom: 6px;">Yêu Cầu Hoàn Đơn / Trả Hàng</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;" id="return_order_code_display"></p>

                <form method="POST" action="orders.php?status=returns">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="hidden" name="action" value="request_return">
                    <input type="hidden" name="order_id" id="modal_return_order_id">

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 8px;">Lý do trả hàng / hoàn tiền *</label>
                        <textarea name="ly_do_tra_hang" class="form-control" style="width: 100%; padding: 10px; border-radius: 12px; border: 1px solid #cbd5e1; font-size: 0.9rem; resize: vertical; min-height: 90px;" placeholder="Mô tả cụ thể lý do (sản phẩm lỗi, vỡ, không đúng như hình ảnh...)" required></textarea>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 6px;">Link/Đường dẫn hình ảnh bằng chứng (nếu có)</label>
                        <input type="text" name="hinh_anh_bang_chung" class="form-control" style="width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid #cbd5e1; font-size: 0.9rem;" placeholder="https://...">
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 24px;">
                        <button type="button" onclick="closeReturnModal()" style="flex: 1; padding: 12px; border-radius: 50px; border: 1px solid #cbd5e1; background: #ffffff; color: var(--text-main); font-weight: 700; cursor: pointer;">Trở Lại</button>
                        <button type="submit" style="flex: 1; padding: 12px; border-radius: 50px; border: none; background: #dc2626; color: #ffffff; font-weight: 700; cursor: pointer;">Gửi Yêu Cầu</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openCancelModal(orderId, orderCode) {
                document.getElementById('modal_cancel_order_id').value = orderId;
                document.getElementById('cancel_order_code_display').textContent = 'Xác nhận hủy đơn hàng ' + orderCode;
                document.getElementById('cancelOrderModal').style.display = 'flex';
            }
            function closeCancelModal() {
                document.getElementById('cancelOrderModal').style.display = 'none';
            }
            function toggleCancelOtherReason(val) {
                document.getElementById('cancel_other_box').style.display = (val === 'Khác') ? 'block' : 'none';
            }

            function openReturnModal(orderId, orderCode) {
                document.getElementById('modal_return_order_id').value = orderId;
                document.getElementById('return_order_code_display').textContent = 'Yêu cầu trả hàng cho ' + orderCode;
                document.getElementById('returnOrderModal').style.display = 'flex';
            }
            function closeReturnModal() {
                document.getElementById('returnOrderModal').style.display = 'none';
            }
        </script>

        <footer class="site-footer">
            <div class="footer-content">
                <div class="footer-brand">Chợ Đồ Cũ</div>
                <p class="footer-text">&copy; 2026 Chợ Đồ Cũ Inc. Bảo lưu mọi quyền.</p>
            </div>
        </footer>
    </div>
</body>
</html>
