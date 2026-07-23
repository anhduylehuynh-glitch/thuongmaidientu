<?php
require_once 'config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập tài khoản.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    try {
        $type = $_POST['type'] ?? 'image'; // 'image' or 'video'

        if ($type === 'image') {
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Lỗi khi tải file ảnh lên máy chủ.");
            }

            $orig_name = $_FILES['file']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                throw new Exception("Chỉ hỗ trợ file ảnh định dạng JPG, PNG, WEBP, GIF.");
            }

            $dir = __DIR__ . '/uploads/images/';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $filename = 'img_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $filename)) {
                echo json_encode([
                    'success' => true, 
                    'file_path' => 'uploads/images/' . $filename,
                    'message' => 'Tải ảnh lên thư mục thành công.'
                ]);
                exit;
            } else {
                throw new Exception("Không thể lưu file ảnh vào thư mục server.");
            }
        } elseif ($type === 'video') {
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Lỗi khi tải file video lên máy chủ.");
            }
            if ($_FILES['file']['size'] > 100 * 1024 * 1024) {
                throw new Exception("Kích thước video tải lên vượt quá giới hạn 100MB.");
            }

            $orig_name = $_FILES['file']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'ogg'])) {
                throw new Exception("Định dạng video không được hỗ trợ. Vui lòng chọn MP4, WebM hoặc MOV.");
            }

            $dir = __DIR__ . '/uploads/videos/';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $filename = 'vid_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $filename)) {
                echo json_encode([
                    'success' => true, 
                    'file_path' => 'uploads/videos/' . $filename,
                    'message' => 'Tải video lên thư mục thành công.'
                ]);
                exit;
            } else {
                throw new Exception("Không thể lưu file video vào thư mục server.");
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Yêu cầu tải file không hợp lệ.']);
