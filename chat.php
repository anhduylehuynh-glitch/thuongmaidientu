<?php
require_once 'config/config.php';

$is_logged_in = false;
$user_data = null;
$user_id = 0;

if (isset($_SESSION['user_id'])) {
    try {
        $db = getDBConnection();
        $user_id = (int)$_SESSION['user_id'];
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

if (!$is_logged_in) {
    header("Location: login_page.php");
    exit;
}

$cart_count = getCartItemCount();
$init_partner_id = (int)($_GET['partner_id'] ?? 0);
$init_product_id = (int)($_GET['product_id'] ?? 0);
$csrf_token = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tin Nhắn Trực Tiếp - Sàn Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --chat-sidebar-width: 320px;
        }

        body {
            background-color: var(--bg-light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Layout Chat Container */
        .chat-page-container {
            max-width: 1280px;
            width: 100%;
            margin: 20px auto;
            padding: 0 15px;
            flex: 1;
            display: flex;
            height: calc(100vh - 120px);
            min-height: 550px;
        }

        .chat-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            display: flex;
            width: 100%;
            height: 100%;
            overflow: hidden;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        /* Sidebar Danh Sách Cuộc Hội Thoại */
        .chat-sidebar {
            width: var(--chat-sidebar-width);
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #f8fafc;
        }

        .chat-sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
        }

        .chat-sidebar-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chat-search-input {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            font-size: 0.88rem;
            background: #f1f5f9;
            transition: all 0.2s;
        }

        .chat-search-input:focus {
            background: #ffffff;
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.2s;
        }

        .conversation-item:hover {
            background: #f1f5f9;
        }

        .conversation-item.active {
            background: #e0e7ff;
            border-left: 4px solid var(--primary);
        }

        .conv-avatar-wrap {
            position: relative;
            width: 48px;
            height: 48px;
            flex-shrink: 0;
        }

        .conv-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #ffffff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        .conv-details {
            flex: 1;
            min-width: 0;
        }

        .conv-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .conv-name {
            font-weight: 600;
            font-size: 0.92rem;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conv-time {
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .conv-preview {
            font-size: 0.82rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conv-product-badge {
            display: inline-block;
            font-size: 0.72rem;
            background: #f1f5f9;
            color: var(--primary);
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 4px;
            font-weight: 500;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Khung Nội Dung Chat */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            position: relative;
        }

        .chat-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
        }

        .chat-header-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-header-user h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .chat-product-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            padding: 10px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .chat-product-img {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .chat-product-info {
            flex: 1;
            min-width: 0;
        }

        #chatProductLink {
            width: auto !important;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .chat-product-title {
            font-size: 0.88rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-product-price {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--primary);
            display: block;
        }

        /* Message Feed */
        .messages-feed {
            flex: 1;
            padding: 20px 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 14px;
            background: #f8fafc;
        }

        .message-row {
            display: flex;
            flex-direction: column;
            max-width: 70%;
        }

        .message-row.me {
            align-self: flex-end;
            align-items: flex-end;
        }

        .message-row.them {
            align-self: flex-start;
            align-items: flex-start;
        }

        .message-bubble {
            padding: 12px 16px;
            border-radius: 18px;
            font-size: 0.92rem;
            line-height: 1.45;
            word-break: break-word;
            position: relative;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }

        .message-row.me .message-bubble {
            background: linear-gradient(135deg, var(--primary) 0%, #4338ca 100%);
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }

        .message-row.them .message-bubble {
            background: #ffffff;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 4px;
        }

        .message-img {
            max-width: 240px;
            max-height: 240px;
            border-radius: 12px;
            margin-top: 6px;
            cursor: pointer;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .message-time {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 4px;
            padding: 0 4px;
        }

        /* Chat Input Footer */
        .chat-footer {
            padding: 14px 20px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
        }

        .chat-form {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-input-wrap {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
        }

        .chat-input {
            width: 100%;
            padding: 12px 16px;
            padding-right: 48px;
            border-radius: 25px;
            border: 1px solid #cbd5e1;
            font-size: 0.92rem;
            background: #f8fafc;
            transition: all 0.2s;
        }

        .chat-input:focus {
            background: #ffffff;
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .btn-attach-img {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #64748b;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 4px;
            transition: color 0.2s;
        }

        .btn-attach-img:hover {
            color: var(--primary);
        }

        .btn-send {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--primary);
            color: #ffffff;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .btn-send:hover {
            transform: scale(1.05);
            background: #4338ca;
        }

        /* Image Preview Badge */
        .image-preview-container {
            display: none;
            padding: 8px 12px;
            background: #f1f5f9;
            border-radius: 12px;
            margin-bottom: 8px;
            align-items: center;
            gap: 10px;
        }

        .image-preview-thumb {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            object-fit: cover;
        }

        .image-preview-remove {
            background: #ef4444;
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .chat-page-container {
                height: calc(100vh - 80px);
                margin: 10px 0;
                padding: 0 5px;
            }

            .chat-sidebar {
                width: 100%;
                display: flex;
            }

            .chat-main {
                display: none;
                width: 100%;
            }

            .chat-card.mobile-active-chat .chat-sidebar {
                display: none;
            }

            .chat-card.mobile-active-chat .chat-main {
                display: flex;
            }

            .mobile-back-btn {
                display: block !important;
            }
        }

        .mobile-back-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #64748b;
            cursor: pointer;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <!-- Navbar Header -->
    <header class="navbar">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <span class="logo-icon">📦</span> Chợ Đồ Cũ
            </a>
            <nav class="nav-links">
                <a href="index.php" class="nav-link">Trang Chủ</a>
                <a href="post_product.php" class="nav-link">Đăng Tin</a>
                <a href="orders.php" class="nav-link">Đơn Hàng</a>
                <a href="chat.php" class="nav-link active">Tin Nhắn</a>
                <a href="cart.php" class="nav-link cart-link">
                    🛒 Giỏ Hàng <span class="cart-badge" id="cartBadge"><?=$cart_count?></span>
                </a>
                <div class="user-menu">
                    <a href="profile.php" class="user-profile-btn" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 8px;">
                        <img src="<?=htmlspecialchars($user_data['google_picture'] ?? 'assets/images/default-avatar.png')?>" alt="Avatar" class="user-avatar" style="width:32px; height:32px; border-radius:50%;">
                        <span class="user-name"><?=htmlspecialchars($user_data['HoTen'])?></span>
                    </a>
                </div>
            </nav>
        </div>
    </header>

    <div class="chat-page-container">
        <div class="chat-card" id="chatCard">
            <!-- Sidebar danh sách hội thoại -->
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">
                    <h2>💬 Tin Nhắn</h2>
                    <input type="text" id="searchConvInput" class="chat-search-input" placeholder="Tìm theo tên người dùng...">
                </div>
                <div class="conversations-list" id="conversationsList">
                    <div style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                        Đang tải danh sách hội thoại...
                    </div>
                </div>
            </div>

            <!-- Khung chat chính -->
            <div class="chat-main">
                <div id="noChatSelected" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #94a3b8; padding: 20px; text-align: center;">
                    <div style="font-size: 3.5rem; margin-bottom: 12px;">💬</div>
                    <h3>Chọn một cuộc hội thoại</h3>
                    <p style="font-size: 0.9rem; max-width: 300px; margin-top: 6px;">Chọn người dùng bên danh sách để bắt đầu trao đổi chi tiết về sản phẩm.</p>
                </div>

                <div id="chatContent" style="display: none; height: 100%; flex-direction: column;">
                    <!-- Header cuộc hội thoại -->
                    <div class="chat-header">
                        <div class="chat-header-user">
                            <button class="mobile-back-btn" id="mobileBackBtn">⬅</button>
                            <img src="assets/images/default-avatar.png" id="chatPartnerAvatar" class="conv-avatar" style="width: 40px; height: 40px;">
                            <div>
                                <h3 id="chatPartnerName">Tên người dùng</h3>
                                <span style="font-size: 0.75rem; color: #22c55e; font-weight: 500;">● Đang hoạt động</span>
                            </div>
                        </div>
                    </div>

                    <!-- Thanh thông tin sản phẩm đính kèm (nếu có) -->
                    <div class="chat-product-bar" id="chatProductBar" style="display: none;">
                        <img src="" id="chatProductImg" class="chat-product-img">
                        <div class="chat-product-info">
                            <h4 id="chatProductTitle" class="chat-product-title">Tên sản phẩm</h4>
                            <span id="chatProductPrice" class="chat-product-price">0 đ</span>
                        </div>
                        <a href="#" id="chatProductLink" class="btn btn-outline" style="width: auto !important; padding: 6px 14px; font-size: 0.78rem; border-radius: 20px; text-decoration: none; flex-shrink: 0; white-space: nowrap;">Xem SP</a>
                    </div>

                    <!-- Luồng tin nhắn -->
                    <div class="messages-feed" id="messagesFeed">
                        <!-- Tin nhắn sẽ được render động vào đây -->
                    </div>

                    <!-- Khung xem trước ảnh tải lên -->
                    <div class="chat-footer">
                        <div class="image-preview-container" id="imagePreviewContainer">
                            <img src="" id="imagePreviewThumb" class="image-preview-thumb">
                            <span style="font-size: 0.82rem; color: #475569;" id="imagePreviewName">ảnh_đính_kèm.png</span>
                            <button type="button" class="image-preview-remove" id="imagePreviewRemove">✕</button>
                        </div>
                        <form id="chatSendForm" class="chat-form" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                            <input type="file" id="chatFileInput" name="chat_image" accept="image/*" style="display: none;">
                            <div class="chat-input-wrap">
                                <input type="text" id="chatMessageInput" class="chat-input" placeholder="Nhập tin nhắn..." autocomplete="off">
                                <button type="button" class="btn-attach-img" id="btnAttachImg" title="Đính kèm ảnh">📷</button>
                            </div>
                            <button type="submit" class="btn-send" title="Gửi tin nhắn">➔</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pass Variables sang JS -->
    <script>
        const CURRENT_USER_ID = <?=$user_id?>;
        const INIT_PARTNER_ID = <?=$init_partner_id?>;
        const INIT_PRODUCT_ID = <?=$init_product_id?>;
        const CSRF_TOKEN = "<?=$csrf_token?>";
    </script>
    <script src="assets/js/chat.js"></script>
</body>
</html>
