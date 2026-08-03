<?php
require_once 'config/config.php';

$db = getDBConnection();
$m = 8;
$y = 2026;

$stmt_rev_month = $db->prepare("
    SELECT COALESCE(SUM(TongTienThanhToan), 0) 
    FROM `DonHang` 
    WHERE MONTH(NgayTao) = :m 
      AND YEAR(NgayTao) = :y
      AND (TrangThaiThanhToan IN (b'001', b'010', b'011', 1, 2, 3) OR TrangThaiDonHang NOT IN (b'110', 6))
");
$stmt_rev_month->execute(['m' => $m, 'y' => $y]);
$revenue = $stmt_rev_month->fetchColumn();

$stmt_orders = $db->prepare("
    SELECT COUNT(*) 
    FROM `DonHang` 
    WHERE MONTH(NgayTao) = :m 
      AND YEAR(NgayTao) = :y
      AND (TrangThaiThanhToan IN (b'001', b'010', b'011', 1, 2, 3) OR TrangThaiDonHang NOT IN (b'110', 6))
");
$stmt_orders->execute(['m' => $m, 'y' => $y]);
$orders_count = $stmt_orders->fetchColumn();

echo "Thống kê Tháng $m/$y:\n";
echo "- Doanh Thu Tháng $m/$y: " . number_format($revenue, 0, ',', '.') . " đ\n";
echo "- Phí Sàn 5%: " . number_format($revenue * 0.05, 0, ',', '.') . " đ\n";
echo "- Số Đơn Hàng: " . number_format($orders_count) . " đơn\n";
