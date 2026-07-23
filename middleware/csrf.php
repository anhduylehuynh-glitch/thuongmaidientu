<?php
// ============================================================================
// MIDDLEWARE CHỐNG TẤN CÔNG CSRF
// ============================================================================

// 1. Tự động khởi tạo CSRF Token cho phiên làm việc nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Lấy CSRF token hiện tại
 */
function getCsrfToken() {
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * So sánh token nhận được với token trong session bằng hash_equals chống timing attack
 */
function verifyCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Kiểm tra và bắt buộc CSRF cho các request thay đổi trạng thái dữ liệu (POST, PUT, PATCH, DELETE)
 */
function enforceCsrf() {
    $methods = ['POST', 'PUT', 'PATCH', 'DELETE'];
    if (isset($_SERVER['REQUEST_METHOD']) && in_array($_SERVER['REQUEST_METHOD'], $methods)) {
        $token = $_POST['csrf_token'] ?? '';

        // Đọc từ header X-CSRF-TOKEN (cho các request fetch/ajax)
        if (empty($token) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        // Đọc từ JSON input (nếu client gửi payload dạng JSON)
        if (empty($token)) {
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['csrf_token'])) {
                $token = $input['csrf_token'];
            }
        }

        if (!verifyCsrfToken($token)) {
            if (function_exists('writeSecurityLog')) {
                writeSecurityLog("CSRF Validation Failed for method: " . $_SERVER['REQUEST_METHOD']);
            }

            http_response_code(419); // 419 Authentication Timeout (thường dùng cho lỗi CSRF)
            
            if (function_exists('isAjaxRequest') && isAjaxRequest()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Mã bảo mật CSRF không hợp lệ hoặc đã hết hạn. Vui lòng thử lại.']);
                exit;
            } else {
                die("
                <!DOCTYPE html>
                <html lang='vi'>
                <head>
                    <meta charset='UTF-8'>
                    <title>Yêu Cầu Hết Hạn (CSRF) - Chợ Đồ Cũ</title>
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
                            <div class='error-icon'>🔄</div>
                            <h2>Trang Web Đã Hết Hạn</h2>
                            <p style='margin-top: 15px;'>Mã bảo mật CSRF không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thực hiện lại.</p>
                            <a href='index.php' class='btn btn-primary'>Quay Lại Trang Chủ</a>
                        </div>
                    </div>
                </body>
                </html>
                ");
            }
        }
    }
}
