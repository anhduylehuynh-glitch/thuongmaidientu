<?php
require_once __DIR__ . '/config/config.php';

try {
    $db = getDBConnection();

    // 1. Lấy danh sách danh mục hiện có
    $categories = $db->query("SELECT MaDanhMuc, TenDanhMuc FROM DanhMuc")->fetchAll();
    if (empty($categories)) {
        // Nếu chưa có danh mục nào, thêm các danh mục mặc định
        $db->exec("INSERT INTO DanhMuc (TenDanhMuc, MoTa) VALUES 
            ('Điện thoại', 'Các dòng điện thoại thông minh, máy tính bảng'),
            ('Máy tính & Laptop', 'Laptop văn phòng, laptop gaming, PC đồng bộ'),
            ('Phụ kiện máy tính', 'Bàn phím cơ, chuột không dây, cáp kết nối'),
            ('Thiết bị âm thanh', 'Tai nghe bluetooth, loa di động, micro thu âm'),
            ('Đồ gia dụng', 'Nồi chiên không dầu, quạt máy, ấm siêu tốc'),
            ('Thời trang', 'Quần áo nam nữ, giày thể thao, phụ kiện thời trang')");
        $categories = $db->query("SELECT MaDanhMuc, TenDanhMuc FROM DanhMuc")->fetchAll();
    }

    // 2. Lấy danh sách người bán hiện có
    $sellers = $db->query("SELECT MaNguoiDung FROM NguoiDung")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($sellers)) {
        // Nếu chưa có người dùng nào, tạo một tài khoản test
        $db->exec("INSERT INTO NguoiDung (HoTen, TenDangNhap, Email, MatKhau, TrangThaiTaiKhoan) VALUES 
            ('Nguyễn Văn Bán', 'seller_test', 'sellertest@gmail.com', '123456', b'1')");
        $sellers = $db->query("SELECT MaNguoiDung FROM NguoiDung")->fetchAll(PDO::FETCH_COLUMN);
    }

    // 3. Chuẩn bị mẫu tên sản phẩm theo danh mục để sinh dữ liệu chân thực
    $sample_products = [
        'Điện thoại' => [
            ['iPhone 13 Pro Max 256GB', 'iPhone 13 Pro Max màu xanh Sierra cực đẹp, pin 90%, nguyên zin chưa sửa chữa.'],
            ['Samsung Galaxy S22 Ultra', 'Samsung S22 Ultra bản chính hãng Việt Nam, ram 12GB, bộ nhớ 256GB, kèm bút S-Pen.'],
            ['Oppo Reno 8 5G', 'Máy mới mua được 3 tháng, còn bảo hành chính hãng. Ngoại hình đẹp không tì vết.'],
            ['Xiaomi Redmi Note 11', 'Dòng máy phân khúc giá rẻ pin cực trâu, lướt web xem phim mượt mà, phù hợp làm máy phụ.'],
            ['iPhone 11 64GB cũ', 'iPhone 11 quốc tế màu đỏ, máy dùng giữ gìn nên còn rất mới, face ID nhạy.'],
            ['iPad Air 4 Wifi 64GB', 'iPad Air 4 màu xám không gian, máy ít dùng chủ yếu xem Youtube, không trầy xước.'],
        ],
        'Máy tính & Laptop' => [
            ['MacBook Pro M1 2020', 'Macbook Pro chip M1, RAM 8GB, SSD 256GB. Máy nguyên bản, kèm sạc zin theo máy.'],
            ['Dell XPS 13 9305', 'Dòng laptop cao cấp siêu mỏng nhẹ, chip Core i5 đời 11, màn hình Full HD cực nét.'],
            ['Asus ROG Strix G15', 'Laptop gaming cấu hình mạnh mẽ, Ryzen 7, RTX 3050. Chiến mượt các tựa game Esport.'],
            ['Lenovo ThinkPad X1 Carbon Gen 7', 'Dòng laptop doanh nhân siêu bền bỉ, bàn phím gõ cực êm, máy chạy mượt ổn định.'],
            ['HP Pavilion 15', 'Laptop HP học tập làm việc văn phòng tốt, màn hình rộng 15.6 inch tiện lợi.'],
            ['PC Gaming Core i5 10400F', 'Thùng máy PC chơi game thiết kế đồ họa tốt, card GTX 1660 Super, RAM 16GB.'],
        ],
        'Phụ kiện máy tính' => [
            ['Bàn phím cơ AKKO 3087', 'Bàn phím cơ TKL, AKKO switch gõ rất êm tay. Fullbox đầy đủ phụ kiện.'],
            ['Chuột không dây Logitech G304', 'Chuột gaming không dây quốc dân, độ nhạy cao, pin dùng cực lâu.'],
            ['Tai nghe chụp tai HyperX Cloud III', 'Tai nghe chơi game chuyên nghiệp, âm thanh vòm chuẩn xác, mic lọc tiếng ồn tốt.'],
            ['Cáp sạc đa năng Anker 3 in 1', 'Cáp sạc nhanh Anker chính hãng, hỗ trợ sạc đồng thời 3 thiết bị.'],
            ['Lót chuột cỡ lớn Razer 90x40', 'Lót chuột Razer dày dặn, di chuột mượt mà, bo viền chắc chắn.'],
        ],
        'Thiết bị âm thanh' => [
            ['Tai nghe Sony WH-1000XM4', 'Tai nghe chụp tai chống ồn chủ động cao cấp nhất của Sony, âm thanh cực hay.'],
            ['Loa bluetooth JBL Charge 5', 'Loa JBL chống nước, pin trâu, bass mạnh mẽ, thích hợp mang đi dã ngoại.'],
            ['AirPods Pro Hổ Vằn', 'Tai nghe AirPods Pro rep 1:1 chip hổ vằn, chống ồn xuyên âm đầy đủ, chất âm tốt.'],
            ['Loa di động Marshall Emberton', 'Loa Marshall Emberton thiết kế vintage sang trọng, âm thanh đa hướng sắc nét.'],
            ['Micro thu âm không dây GoChek', 'Micro không dây cài áo dùng cho điện thoại quay video, lọc tạp âm tốt.'],
        ],
        'Đồ gia dụng' => [
            ['Nồi chiên không dầu Philips 5L', 'Nồi chiên không dầu Philips hoạt động hoàn hảo, lòng nồi chống dính dễ vệ sinh.'],
            ['Máy hút bụi không dây Xiaomi', 'Máy hút bụi cầm tay lực hút mạnh, sạc pin tiện lợi, dọn dẹp nhà cửa nhanh chóng.'],
            ['Quạt tích điện hơi nước Sunhouse', 'Quạt hơi nước làm mát nhanh, có cổng sạc dự phòng khi mất điện.'],
            ['Ấm siêu tốc Kangaroo 1.8L', 'Ấm đun nước siêu tốc inox 304 bền bỉ, tự động ngắt điện khi sôi.'],
            ['Máy pha cà phê mini Delonghi', 'Máy pha cà phê espresso gia đình gọn nhẹ, hoạt động ổn định.'],
        ],
        'Thời trang' => [
            ['Áo khoác gió Uniqlo chính hãng', 'Áo gió Uniqlo chống nước nhẹ, cản gió tốt, size L nam mặc vừa.'],
            ['Giày thể thao Adidas Ultraboost', 'Giày chạy bộ Ultraboost chính hãng size 42, đế boost đi cực êm chân.'],
            ['Balo chống nước Tigernu', 'Balo đựng laptop có cổng sạc USB ngoài, chất liệu vải oxford bền bỉ.'],
            ['Áo thun Local Brand Teelab', 'Áo phông thun cotton dáng rộng, hình in đẹp mắt, mặc mát.'],
            ['Giày thể thao Nike Air Force 1', 'Nike AF1 màu trắng huyền thoại size 40, mới đi 2 lần, còn box đầy đủ.'],
        ]
    ];

    $conditions = ['Mới 99%', 'Mới 95%', 'Đã sử dụng (Tốt)', 'Hơi cũ', 'Như mới (Likenew)'];
    $approval_statuses = [0, 1, 2]; // 0: chờ duyệt, 1: đã duyệt, 2: đã cấm

    // Bắt đầu Transaction để chèn nhanh và an toàn
    $db->beginTransaction();

    $stmt_sp = $db->prepare("INSERT INTO `SanPham` 
        (`MaNguoiBan`, `MaDanhMuc`, `TenSanPham`, `MoTaChiTiet`, `TinhTrang`, `KhoiLuong_Kg`, `GiaBan`, `TrangThaiDuyet`, `TrangThaiBan`) 
        VALUES (:seller, :cat, :name, :desc, :cond, :weight, :price, :status, b'00')");

    $stmt_img = $db->prepare("INSERT INTO `HinhAnhSP` (`MaSanPham`, `DuongDanAnh`, `AnhChinh`) VALUES (:pid, :url, :main)");

    $count = 0;
    for ($i = 0; $i < 100; $i++) {
        // Chọn ngẫu nhiên danh mục
        $cat = $categories[array_rand($categories)];
        $cat_id = $cat['MaDanhMuc'];
        $cat_name = $cat['TenDanhMuc'];

        // Lấy danh sách mẫu tương ứng hoặc mặc định
        $templates = isset($sample_products[$cat_name]) ? $sample_products[$cat_name] : $sample_products['Điện thoại'];
        $template = $templates[array_rand($templates)];

        $prod_name = $template[0] . ' (Mẫu ' . ($i + 1) . ')';
        $prod_desc = $template[1] . "\n- Liên hệ để xem hàng trực tiếp.\n- Cam kết hình ảnh thực tế tự chụp.";

        $seller_id = $sellers[array_rand($sellers)];
        $cond = $conditions[array_rand($conditions)];
        $weight = round(rand(1, 80) / 10, 2); // 0.1kg -> 8kg
        $price = rand(10, 500) * 10000; // 100.000đ -> 5.000.000đ
        
        // Trạng thái duyệt: ngẫu nhiên (chờ duyệt, đã duyệt, đã cấm)
        $status_val = $approval_statuses[array_rand($approval_statuses)];

        // Ghi vào bảng SanPham
        $stmt_sp->execute([
            'seller' => $seller_id,
            'cat' => $cat_id,
            'name' => $prod_name,
            'desc' => $prod_desc,
            'cond' => $cond,
            'weight' => $weight,
            'price' => $price,
            'status' => $status_val
        ]);

        $new_pid = $db->lastInsertId();

        // Thêm 2 ảnh random từ mạng (Picsum Photos)
        $random_img_id1 = rand(1, 1000);
        $random_img_id2 = rand(1, 1000);
        
        $img_url1 = "https://picsum.photos/600/400?random=" . $random_img_id1;
        $img_url2 = "https://picsum.photos/600/400?random=" . $random_img_id2;

        $stmt_img->execute(['pid' => $new_pid, 'url' => $img_url1, 'main' => 1]);
        $stmt_img->execute(['pid' => $new_pid, 'url' => $img_url2, 'main' => 0]);

        $count++;
    }

    $db->commit();
    $status = 'success';
    $message = "Đã thêm thành công $count sản phẩm mẫu trải đều khắp các danh mục và tài khoản người bán!";
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $status = 'error';
    $message = "Lỗi trong quá trình thêm dữ liệu mẫu: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seed Dữ Liệu Sản Phẩm Mẫu - Chợ Đồ Cũ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0284c7;
            --success: #16a34a;
            --error: #dc2626;
            --bg: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .card {
            background: #ffffff;
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 24px auto;
        }

        .icon-success {
            background: #dcfce7;
            color: var(--success);
        }

        .icon-error {
            background: #fee2e2;
            color: var(--error);
        }

        h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0 0 12px 0;
        }

        p {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0 0 28px 0;
        }

        .btn {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 12px 28px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.2);
            transition: all 0.2s;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($status === 'success'): ?>
            <div class="icon icon-success">✓</div>
            <h2>Seed Dữ Liệu Thành Công!</h2>
            <p><?php echo $message; ?></p>
        <?php else: ?>
            <div class="icon icon-error">✕</div>
            <h2>Lỗi Seed Dữ Liệu</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <a href="admin.php" class="btn">Quay lại Trang Quản Trị</a>
    </div>
</body>
</html>
