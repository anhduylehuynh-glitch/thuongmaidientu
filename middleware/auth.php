<?php
// ============================================================================
// MIDDLEWARE XÁC THỰC VÀ PHÂN QUYỀN (RBAC)
// ============================================================================

/**
 * Kiểm tra xem người dùng hiện tại có quyền hạn cụ thể hay không
 */
function hasPermission($user_id, $permission) {
    try {
        $db = getDBConnection();

        // Kiểm tra quyền chi tiết của vai trò người dùng trong database
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM `NguoiDung_VaiTro` ndvt
            JOIN `VaiTro_Quyen` vtq ON ndvt.MaVaiTro = vtq.MaVaiTro
            JOIN `Quyen` q ON vtq.MaQuyen = q.MaQuyen
            WHERE ndvt.MaNguoiDung = :uid AND q.TenQuyen = :perm
        ");
        $stmt->execute([
            'uid'  => $user_id,
            'perm' => $permission
        ]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        if (function_exists('writeSecurityLog')) {
            writeSecurityLog("Error checking permission: " . $e->getMessage());
        }
        return false;
    }
}

/**
 * Yêu cầu người dùng phải đăng nhập. Nếu không sẽ chặn lại
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        if (isAjaxRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập tài khoản.']);
            exit;
        } else {
            header("Location: login_page.php");
            exit;
        }
    }

    // Kiểm tra xem tài khoản có bị khóa trong database không
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT `TrangThaiTaiKhoan` FROM `NguoiDung` WHERE `MaNguoiDung` = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $status_val = $stmt->fetchColumn();

        if ($status_val !== false) {
            $is_active = false;
            if (is_null($status_val)) {
                $is_active = true;
            } elseif (is_int($status_val)) {
                $is_active = $status_val === 1;
            } elseif (is_string($status_val)) {
                if (strlen($status_val) === 1) {
                    $is_active = (ord($status_val) === 1 || $status_val === '1');
                } else {
                    $is_active = ($status_val === '1');
                }
            } else {
                $is_active = (bool)$status_val;
            }

            if (!$is_active) {
                // Tài khoản bị khóa, xóa session và đăng xuất
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();

                if (isAjaxRequest()) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Tài khoản của bạn đã bị khóa bởi quản trị viên.']);
                    exit;
                } else {
                    header("Location: login_page.php?error=" . urlencode("Tài khoản của bạn đã bị khóa bởi quản trị viên."));
                    exit;
                }
            }
        }
    } catch (Exception $e) {
        // Bỏ qua lỗi DB
    }
}

/**
 * Yêu cầu người dùng phải có quyền hạn cụ thể. Nếu không sẽ chặn và trả về HTTP 403
 */
function requirePermission($permission) {
    // 1. Kiểm tra đăng nhập trước
    requireLogin();

    $user_id = $_SESSION['user_id'];

    // 2. Kiểm tra quyền hạn
    if (!hasPermission($user_id, $permission)) {
        if (function_exists('writeSecurityLog')) {
            writeSecurityLog("Access Denied: User ID $user_id attempted to access '$permission'");
        }

        http_response_code(403);
        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này.']);
            exit;
        } else {
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
                        <p style='margin-top: 15px;'>Tài khoản của bạn không được cấp quyền thực hiện chức năng này ($permission).</p>
                        <a href='index.php' class='btn btn-primary'>Quay Lại Trang Chủ</a>
                    </div>
                </div>
            </body>
            </html>
            ");
        }
    }
}

/**
 * Kiểm tra xem người dùng hiện tại có phải chủ sở hữu của sản phẩm hay không
 */
function checkProductOwnership($product_id, $user_id) {
    try {
        $db = getDBConnection();

        // 1. Nếu là ADMIN, luôn cho phép
        $admin_stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM `NguoiDung_VaiTro` ndvt
            JOIN `VaiTro` vt ON ndvt.MaVaiTro = vt.MaVaiTro
            WHERE ndvt.MaNguoiDung = :uid AND vt.TenVaiTro = 'ADMIN'
        ");
        $admin_stmt->execute(['uid' => $user_id]);
        if ((int)$admin_stmt->fetchColumn() > 0) {
            return true;
        }

        // 2. Kiểm tra cột MaNguoiBan trong bảng SanPham
        $stmt = $db->prepare("SELECT `MaNguoiBan` FROM `SanPham` WHERE `MaSanPham` = :pid");
        $stmt->execute(['pid' => $product_id]);
        $owner_id = $stmt->fetchColumn();

        return $owner_id !== false && (int)$owner_id === (int)$user_id;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Hàm phụ trợ xác định request có phải AJAX hoặc mong đợi JSON không
 */
function isAjaxRequest() {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

/**
 * Lấy IP thực của client
 */
function getClientIp() {
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Kiểm tra xem tài khoản và IP hiện tại có bị khóa brute-force không (5 lần trong 15 phút)
 */
function checkBruteForce($username) {
    $ip = getClientIp();
    $identifier = hash('sha256', $username . '|' . $ip);
    
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT Attempts, UNIX_TIMESTAMP(LastAttempt) as LastAttemptTime FROM FailedLogins WHERE Identifier = :id");
        $stmt->execute(['id' => $identifier]);
        $row = $stmt->fetch();
        
        if ($row) {
            $attempts = (int)$row['Attempts'];
            $lastTime = (int)$row['LastAttemptTime'];
            
            // Nếu đã qua 15 phút, tự động mở khóa
            if (time() - $lastTime > 900) {
                $del = $db->prepare("DELETE FROM FailedLogins WHERE Identifier = :id");
                $del->execute(['id' => $identifier]);
                return true;
            }
            
            if ($attempts >= 5) {
                return false; // Bị khóa
            }
        }
    } catch (Exception $e) {
        // Bỏ qua lỗi DB
    }
    return true;
}

/**
 * Ghi nhận một lần đăng nhập thất bại
 */
function recordFailedLogin($username) {
    $ip = getClientIp();
    $identifier = hash('sha256', $username . '|' . $ip);
    
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            INSERT INTO FailedLogins (Identifier, Attempts, LastAttempt) 
            VALUES (:id, 1, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE Attempts = Attempts + 1, LastAttempt = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['id' => $identifier]);
    } catch (Exception $e) {
        // Bỏ qua
    }
}

/**
 * Xóa lịch sử đăng nhập sai khi đăng nhập thành công
 */
function clearFailedLogins($username) {
    $ip = getClientIp();
    $identifier = hash('sha256', $username . '|' . $ip);
    
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("DELETE FROM FailedLogins WHERE Identifier = :id");
        $stmt->execute(['id' => $identifier]);
    } catch (Exception $e) {
        // Bỏ qua
    }
}
