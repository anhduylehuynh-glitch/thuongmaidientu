<?php
require_once 'config/config.php';

$db = getDBConnection();
$rev_today = $db->query("SELECT COALESCE(SUM(TongTienThanhToan), 0) FROM DonHang WHERE DATE(NgayTao) = CURRENT_DATE()")->fetchColumn();
$rev_month = $db->query("SELECT COALESCE(SUM(TongTienThanhToan), 0) FROM DonHang WHERE MONTH(NgayTao) = MONTH(CURRENT_DATE()) AND YEAR(NgayTao) = YEAR(CURRENT_DATE())")->fetchColumn();
$rev_total = $db->query("SELECT COALESCE(SUM(TongTienThanhToan), 0) FROM DonHang")->fetchColumn();

echo "Doanh Thu Hôm Nay: " . number_format($rev_today, 0, ',', '.') . " đ\n";
echo "Doanh Thu Tháng Này: " . number_format($rev_month, 0, ',', '.') . " đ\n";
echo "Tổng Doanh Thu Sàn: " . number_format($rev_total, 0, ',', '.') . " đ\n";
echo "Phí Sàn 5%: " . number_format($rev_total * 0.05, 0, ',', '.') . " đ\n";
