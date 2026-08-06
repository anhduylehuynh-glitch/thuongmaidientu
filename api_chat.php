<?php
require_once 'config/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập để sử dụng tính năng chat.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = getDBConnection();

    switch ($action) {
        case 'start':
            $partner_id = (int)($_POST['partner_id'] ?? $_GET['partner_id'] ?? 0);
            $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : (isset($_GET['product_id']) ? (int)$_GET['product_id'] : null);

            if ($partner_id <= 0) {
                throw new Exception('ID người nhận không hợp lệ.');
            }

            if ($partner_id === $user_id) {
                throw new Exception('Bạn không thể gửi tin nhắn cho chính mình.');
            }

            // Kiểm tra xem đối phương có tồn tại không
            $partner_stmt = $db->prepare("SELECT MaNguoiDung, HoTen FROM NguoiDung WHERE MaNguoiDung = :pid");
            $partner_stmt->execute(['pid' => $partner_id]);
            $partner = $partner_stmt->fetch();
            if (!$partner) {
                throw new Exception('Tài khoản đối phương không tồn tại.');
            }

            // Tìm cuộc hội thoại đã có giữa 2 người
            $stmt = $db->prepare("SELECT MaCuocHoiThoai FROM CuocHoiThoai 
                                  WHERE (MaNguoiMua = :uid1 AND MaNguoiBan = :uid2) 
                                     OR (MaNguoiMua = :uid3 AND MaNguoiBan = :uid4) 
                                  LIMIT 1");
            $stmt->execute([
                'uid1' => $user_id, 
                'uid2' => $partner_id,
                'uid3' => $partner_id,
                'uid4' => $user_id
            ]);
            $conv = $stmt->fetch();

            if ($conv) {
                $conversation_id = (int)$conv['MaCuocHoiThoai'];
                if ($product_id && $product_id > 0) {
                    // Cập nhật sản phẩm liên quan nếu có
                    $upd = $db->prepare("UPDATE CuocHoiThoai SET MaSanPham = :pid WHERE MaCuocHoiThoai = :cid");
                    $upd->execute(['pid' => $product_id, 'cid' => $conversation_id]);
                }
            } else {
                // Tạo cuộc hội thoại mới
                $ins = $db->prepare("INSERT INTO CuocHoiThoai (MaNguoiMua, MaNguoiBan, MaSanPham) VALUES (:mua, :ban, :sp)");
                $ins->execute([
                    'mua' => $user_id,
                    'ban' => $partner_id,
                    'sp'  => ($product_id && $product_id > 0) ? $product_id : null
                ]);
                $conversation_id = (int)$db->lastInsertId();
            }

            echo json_encode([
                'success' => true,
                'conversation_id' => $conversation_id,
                'partner_name' => $partner['HoTen']
            ]);
            break;

        case 'list_conversations':
            $query = "
                SELECT 
                    c.MaCuocHoiThoai,
                    c.MaNguoiMua,
                    c.MaNguoiBan,
                    c.MaSanPham,
                    c.NgayTao,
                    IF(c.MaNguoiMua = :uid1, u_ban.MaNguoiDung, u_mua.MaNguoiDung) AS PartnerId,
                    IF(c.MaNguoiMua = :uid2, u_ban.HoTen, u_mua.HoTen) AS PartnerName,
                    IF(c.MaNguoiMua = :uid3, u_ban.google_picture, u_mua.google_picture) AS PartnerAvatar,
                    sp.TenSanPham,
                    sp.GiaBan,
                    (SELECT DuongDanAnh FROM HinhAnhSP h WHERE h.MaSanPham = sp.MaSanPham LIMIT 1) AS SanPhamAnh,
                    tn.NoiDung AS LastMessage,
                    tn.DuongDanHinhAnh AS LastImage,
                    tn.NgayGui AS LastTime,
                    tn.MaNguoiGui AS LastSenderId
                FROM CuocHoiThoai c
                JOIN NguoiDung u_mua ON c.MaNguoiMua = u_mua.MaNguoiDung
                JOIN NguoiDung u_ban ON c.MaNguoiBan = u_ban.MaNguoiDung
                LEFT JOIN SanPham sp ON c.MaSanPham = sp.MaSanPham
                LEFT JOIN TinNhanChat tn ON tn.MaTinNhan = (
                    SELECT MaTinNhan FROM TinNhanChat 
                    WHERE MaCuocHoiThoai = c.MaCuocHoiThoai 
                    ORDER BY MaTinNhan DESC LIMIT 1
                )
                WHERE c.MaNguoiMua = :uid4 OR c.MaNguoiBan = :uid5
                ORDER BY COALESCE(tn.NgayGui, c.NgayTao) DESC
            ";
            $stmt = $db->prepare($query);
            $stmt->execute([
                'uid1' => $user_id,
                'uid2' => $user_id,
                'uid3' => $user_id,
                'uid4' => $user_id,
                'uid5' => $user_id
            ]);
            $conversations = $stmt->fetchAll();

            // Format bớt thông tin
            $data = array_map(function($conv) use ($user_id) {
                return [
                    'id' => (int)$conv['MaCuocHoiThoai'],
                    'partner_id' => (int)$conv['PartnerId'],
                    'partner_name' => $conv['PartnerName'],
                    'partner_avatar' => $conv['PartnerAvatar'] ?: 'assets/images/default-avatar.png',
                    'product_id' => $conv['MaSanPham'] ? (int)$conv['MaSanPham'] : null,
                    'product_name' => $conv['TenSanPham'] ?? null,
                    'product_price' => $conv['GiaBan'] ? number_format($conv['GiaBan'], 0, ',', '.') . ' đ' : null,
                    'product_image' => $conv['SanPhamAnh'] ?? null,
                    'last_message' => $conv['LastMessage'] ?: ($conv['LastImage'] ? '[Hình ảnh]' : 'Bắt đầu trò chuyện'),
                    'last_time' => $conv['LastTime'] ? date('H:i d/m', strtotime($conv['LastTime'])) : date('H:i d/m', strtotime($conv['NgayTao'])),
                    'is_last_me' => ((int)$conv['LastSenderId'] === $user_id)
                ];
            }, $conversations);

            echo json_encode(['success' => true, 'conversations' => $data]);
            break;

        case 'get_messages':
            $conversation_id = (int)($_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
            $after_id = (int)($_GET['after_id'] ?? $_POST['after_id'] ?? 0);

            if ($conversation_id <= 0) {
                throw new Exception('ID cuộc hội thoại không hợp lệ.');
            }

            // Kiểm tra quyền truy cập cuộc hội thoại
            $check = $db->prepare("SELECT MaCuocHoiThoai FROM CuocHoiThoai WHERE MaCuocHoiThoai = :cid AND (MaNguoiMua = :uid1 OR MaNguoiBan = :uid2)");
            $check->execute(['cid' => $conversation_id, 'uid1' => $user_id, 'uid2' => $user_id]);
            if (!$check->fetch()) {
                throw new Exception('Bạn không có quyền xem cuộc hội thoại này.');
            }

            $sql = "SELECT MaTinNhan, MaNguoiGui, NoiDung, DuongDanHinhAnh, NgayGui 
                    FROM TinNhanChat 
                    WHERE MaCuocHoiThoai = :cid";
            $params = ['cid' => $conversation_id];

            if ($after_id > 0) {
                $sql .= " AND MaTinNhan > :after_id";
                $params['after_id'] = $after_id;
            }

            $sql .= " ORDER BY MaTinNhan ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $raw_messages = $stmt->fetchAll();

            $messages = array_map(function($msg) use ($user_id) {
                return [
                    'id' => (int)$msg['MaTinNhan'],
                    'sender_id' => (int)$msg['MaNguoiGui'],
                    'is_me' => ((int)$msg['MaNguoiGui'] === $user_id),
                    'content' => htmlspecialchars($msg['NoiDung'] ?? ''),
                    'image' => $msg['DuongDanHinhAnh'] ?? null,
                    'time' => date('H:i', strtotime($msg['NgayGui'])),
                    'date' => date('d/m/Y', strtotime($msg['NgayGui']))
                ];
            }, $raw_messages);

            echo json_encode(['success' => true, 'messages' => $messages]);
            break;

        case 'send_message':
            $conversation_id = (int)($_POST['conversation_id'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            $image_path = null;

            if ($conversation_id <= 0) {
                throw new Exception('ID cuộc hội thoại không hợp lệ.');
            }

            // Kiểm tra quyền
            $check = $db->prepare("SELECT MaCuocHoiThoai FROM CuocHoiThoai WHERE MaCuocHoiThoai = :cid AND (MaNguoiMua = :uid1 OR MaNguoiBan = :uid2)");
            $check->execute(['cid' => $conversation_id, 'uid1' => $user_id, 'uid2' => $user_id]);
            if (!$check->fetch()) {
                throw new Exception('Bạn không thuộc cuộc hội thoại này.');
            }

            // Xử lý upload ảnh đính kèm nếu có
            if (isset($_FILES['chat_image']) && $_FILES['chat_image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['chat_image']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    throw new Exception('Định dạng ảnh không được hỗ trợ.');
                }
                $dir = __DIR__ . '/uploads/chat/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $filename = 'chat_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['chat_image']['tmp_name'], $dir . $filename)) {
                    $image_path = 'uploads/chat/' . $filename;
                } else {
                    throw new Exception('Lỗi khi lưu ảnh tải lên.');
                }
            }

            if (empty($content) && empty($image_path)) {
                throw new Exception('Nội dung tin nhắn hoặc ảnh không được để trống.');
            }

            $ins = $db->prepare("INSERT INTO TinNhanChat (MaCuocHoiThoai, MaNguoiGui, NoiDung, DuongDanHinhAnh) VALUES (:cid, :uid, :content, :img)");
            $ins->execute([
                'cid' => $conversation_id,
                'uid' => $user_id,
                'content' => $content,
                'img' => $image_path
            ]);

            $msg_id = (int)$db->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => [
                    'id' => $msg_id,
                    'sender_id' => $user_id,
                    'is_me' => true,
                    'content' => htmlspecialchars($content),
                    'image' => $image_path,
                    'time' => date('H:i')
                ]
            ]);
            break;

        default:
            throw new Exception('Hành động (action) không hợp lệ.');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
