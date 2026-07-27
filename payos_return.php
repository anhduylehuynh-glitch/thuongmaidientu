<?php
require_once 'config/config.php';
requireLogin();

$orderCode = isset($_GET['orderCode']) ? (int)$_GET['orderCode'] : 0;
$payment_id = isset($_GET['id']) ? trim($_GET['id']) : '';

$status_code = 'processing';
$message = 'Đang xác thực giao dịch nạp tiền của bạn...';
$success_amount = 0;

if ($orderCode <= 0) {
    $status_code = 'error';
    $message = 'Yêu cầu không hợp lệ. Không tìm thấy mã giao dịch.';
} else {
    try {
        $db = getDBConnection();
        
        // Lấy thông tin ví của người dùng hiện tại
        $wallet_stmt = $db->prepare("SELECT * FROM `ViDienTu` WHERE `MaNguoiDung` = :uid");
        $wallet_stmt->execute(['uid' => $_SESSION['user']['MaNguoiDung']]);
        $wallet = $wallet_stmt->fetch();
        
        if (!$wallet) {
            $status_code = 'error';
            $message = 'Không tìm thấy ví điện tử của bạn.';
        } else {
            // Lấy thông tin giao dịch ví
            $tx_stmt = $db->prepare("SELECT * FROM `LichSuGiaoDichVi` WHERE `MaGiaoDich` = :txid");
            $tx_stmt->execute(['txid' => $orderCode]);
            $transaction = $tx_stmt->fetch();
            
            if (!$transaction) {
                $status_code = 'error';
                $message = 'Không tìm thấy giao dịch tương ứng trong hệ thống.';
            } else if ($transaction['MaViDich'] != $wallet['MaVi']) {
                $status_code = 'error';
                $message = 'Giao dịch này không thuộc sở hữu của tài khoản của bạn.';
            } else {
                // Giải mã trạng thái
                $local_status = 0;
                $raw_status = $transaction['TrangThai'];
                if (!is_null($raw_status)) {
                    if (is_int($raw_status)) $local_status = $raw_status;
                    else if (is_string($raw_status) && strlen($raw_status) === 1) {
                        $o = ord($raw_status);
                        if ($o === 1 || $raw_status === '1') $local_status = 1;
                        elseif ($o === 2 || $raw_status === '2') $local_status = 2;
                    }
                }
                
                if ($local_status === 1) {
                    // Giao dịch đã thành công trước đó
                    $status_code = 'success';
                    $success_amount = $transaction['SoTien'];
                    $message = 'Giao dịch nạp tiền đã hoàn tất thành công từ trước.';
                } else if ($local_status === 2) {
                    // Giao dịch đã thất bại trước đó
                    $status_code = 'error';
                    $message = 'Giao dịch này đã thất bại hoặc bị hủy bỏ trước đó.';
                } else {
                    // Trạng thái đang xử lý (Pending), tiến hành gọi PayOS API để cập nhật trạng thái mới nhất
                    $ch = curl_init('https://api-merchant.payos.vn/v2/payment-requests/' . $orderCode);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'x-client-id: ' . PAYOS_CLIENT_ID,
                        'x-api-key: ' . PAYOS_API_KEY
                    ]);
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    $res_data = json_decode($response, true);
                    
                    if ($http_code === 200 && isset($res_data['code']) && $res_data['code'] === '00') {
                        $payos_status = $res_data['data']['status'];
                        $amount = $res_data['data']['amount'];
                        
                        if ($payos_status === 'PAID') {
                            // Thực hiện cộng tiền vào ví và cập nhật trạng thái giao dịch
                            $db->beginTransaction();
                            
                            // Cập nhật trạng thái giao dịch ví thành thành công b'01'
                            $upd_tx = $db->prepare("UPDATE `LichSuGiaoDichVi` SET `TrangThai` = b'01', `MoTa` = :mota WHERE `MaGiaoDich` = :txid");
                            $upd_tx->execute([
                                'mota' => 'Nạp tiền ví thành công qua PayOS (Mã GD: ' . $orderCode . ')',
                                'txid' => $orderCode
                            ]);
                            
                            // Cộng tiền vào ví điện tử
                            $upd_wallet = $db->prepare("UPDATE `ViDienTu` SET `SoDu` = `SoDu` + :amount WHERE `MaVi` = :mavi");
                            $upd_wallet->execute([
                                'amount' => $amount,
                                'mavi' => $wallet['MaVi']
                            ]);
                            
                            $db->commit();
                            
                            $status_code = 'success';
                            $success_amount = $amount;
                            $message = 'Nạp tiền vào ví điện tử thành công!';
                        } else if ($payos_status === 'CANCELLED') {
                            // Cập nhật trạng thái giao dịch ví thành thất bại b'10'
                            $upd_tx = $db->prepare("UPDATE `LichSuGiaoDichVi` SET `TrangThai` = b'10', `MoTa` = :mota WHERE `MaGiaoDich` = :txid");
                            $upd_tx->execute([
                                'mota' => 'Giao dịch nạp tiền bị hủy bỏ trên cổng thanh toán',
                                'txid' => $orderCode
                            ]);
                            
                            $status_code = 'error';
                            $message = 'Giao dịch nạp tiền của bạn đã bị hủy bỏ.';
                        } else {
                            $status_code = 'pending';
                            $message = 'Giao dịch đang được xử lý hoặc chưa hoàn tất thanh toán.';
                        }
                    } else {
                        $status_code = 'error';
                        $message = 'Không thể xác thực thông tin giao dịch với cổng thanh toán PayOS.';
                    }
                }
            }
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $status_code = 'error';
        $message = 'Lỗi hệ thống khi xử lý giao dịch: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kết Quả Thanh Toán - Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .result-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 80vh;
            padding: 24px;
        }
        .result-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 24px;
            padding: 40px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 10px 10px -5px rgba(0, 0, 0, 0.02);
            animation: cardAppear 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .icon-wrapper {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px auto;
        }
        .icon-success {
            background: #dcfce7;
            color: #15803d;
            animation: bounceIn 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .icon-error {
            background: #fee2e2;
            color: #b91c1c;
            animation: bounceIn 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .icon-pending {
            background: #fef3c7;
            color: #b45309;
            animation: pulse 2s infinite ease-in-out;
        }
        @keyframes bounceIn {
            from { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.1); opacity: 0.8; }
            to { transform: scale(1); opacity: 1; }
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
        .status-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 12px;
            font-family: 'Outfit', sans-serif;
        }
        .status-desc {
            font-size: 0.95rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .amount-display {
            font-size: 1.8rem;
            font-weight: 800;
            color: #16a34a;
            margin: 16px 0;
            font-family: 'Outfit', sans-serif;
        }
        .countdown-text {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 16px;
        }
        .btn-redirect {
            width: 100%;
            border-radius: 50px;
            padding: 12px 24px;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }
    </style>
</head>
<body>
    <div class="background-decor"></div>
    <div class="site-wrapper">
        <main class="result-container">
            <div class="result-card">
                <?php if ($status_code === 'success'): ?>
                    <div class="icon-wrapper icon-success">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                    <h2 class="status-title">Nạp Tiền Thành Công</h2>
                    <p class="status-desc"><?php echo htmlspecialchars($message); ?></p>
                    <div class="amount-display">+<?php echo number_format($success_amount, 0, ',', '.'); ?> đ</div>
                    <a href="profile.php" class="btn btn-primary btn-redirect">Quay lại Hồ sơ cá nhân</a>
                <?php elseif ($status_code === 'pending'): ?>
                    <div class="icon-wrapper icon-pending">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <h2 class="status-title">Giao Dịch Đang Chờ</h2>
                    <p class="status-desc"><?php echo htmlspecialchars($message); ?></p>
                    <a href="profile.php" class="btn btn-primary btn-redirect" style="background: #d97706;">Quay lại Hồ sơ cá nhân</a>
                <?php else: ?>
                    <div class="icon-wrapper icon-error">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </div>
                    <h2 class="status-title">Giao Dịch Thất Bại</h2>
                    <p class="status-desc"><?php echo htmlspecialchars($message); ?></p>
                    <a href="profile.php" class="btn btn-primary btn-redirect" style="background: #dc2626;">Quay lại Hồ sơ cá nhân</a>
                <?php endif; ?>

                <p class="countdown-text">Tự động chuyển hướng sau <span id="countdown">5</span> giây...</p>
            </div>
        </main>
    </div>

    <script>
        let seconds = 5;
        const countdownEl = document.getElementById('countdown');
        const interval = setInterval(() => {
            seconds--;
            countdownEl.innerText = seconds;
            if (seconds <= 0) {
                clearInterval(interval);
                window.location.href = 'profile.php';
            }
        }, 1000);
    </script>
</body>
</html>
