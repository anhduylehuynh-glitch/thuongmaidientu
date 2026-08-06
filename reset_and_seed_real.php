<?php
require_once __DIR__ . '/config/config.php';

try {
    $db = getDBConnection();

    // 1. Xóa toàn bộ sản phẩm ảo cũ và dữ liệu liên quan
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE HinhAnhSP;");
    $db->exec("TRUNCATE TABLE SanPham;");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $db->beginTransaction();

    // 2. Tạo 3 tài khoản Người bán thực tế
    $sellers_data = [
        [
            'name' => 'Nguyễn Văn An',
            'username' => 'nguyenvanan',
            'email' => 'nguyenvanan@gmail.com',
            'avatar' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=An',
            'score' => 98
        ],
        [
            'name' => 'Trần Thị Bình',
            'username' => 'tranthibinh',
            'email' => 'tranthibinh@gmail.com',
            'avatar' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=Binh',
            'score' => 95
        ],
        [
            'name' => 'Lê Hoàng Cường',
            'username' => 'lehoangcuong',
            'email' => 'lehoangcuong@gmail.com',
            'avatar' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=Cuong',
            'score' => 99
        ]
    ];

    $seller_ids = [];
    $password_hash = password_hash('123456', PASSWORD_DEFAULT);

    foreach ($sellers_data as $s) {
        $stmt = $db->prepare("SELECT MaNguoiDung FROM NguoiDung WHERE TenDangNhap = :un OR Email = :em");
        $stmt->execute(['un' => $s['username'], 'em' => $s['email']]);
        $uid = $stmt->fetchColumn();

        if (!$uid) {
            $ins = $db->prepare("INSERT INTO NguoiDung (HoTen, TenDangNhap, Email, MatKhau, google_picture, DiemUyTin, TrangThaiTaiKhoan) 
                                 VALUES (:name, :un, :em, :pwd, :avatar, :score, b'1')");
            $ins->execute([
                'name' => $s['name'],
                'un' => $s['username'],
                'em' => $s['email'],
                'pwd' => $password_hash,
                'avatar' => $s['avatar'],
                'score' => $s['score']
            ]);
            $uid = $db->lastInsertId();

            // Gán vai trò BUYER/SELLER
            $vaitro_stmt = $db->prepare("SELECT MaVaiTro FROM VaiTro WHERE TenVaiTro = 'BUYER' OR TenVaiTro = 'SELLER'");
            $vaitro_stmt->execute();
            $vaitros = $vaitro_stmt->fetchAll(PDO::FETCH_COLUMN);
            $ins_vt = $db->prepare("INSERT IGNORE INTO NguoiDung_VaiTro (MaNguoiDung, MaVaiTro) VALUES (:uid, :rid)");
            foreach ($vaitros as $rid) {
                $ins_vt->execute(['uid' => $uid, 'rid' => $rid]);
            }
        }
        $seller_ids[$s['username']] = (int)$uid;
    }

    // 3. Đăng 3 sản phẩm thật với hình ảnh chất lượng cao vừa tự tạo
    $cat_phone = $db->query("SELECT MaDanhMuc FROM DanhMuc WHERE TenDanhMuc = 'Điện thoại' LIMIT 1")->fetchColumn() ?: 1;
    $cat_audio = $db->query("SELECT MaDanhMuc FROM DanhMuc WHERE TenDanhMuc = 'Thiết bị âm thanh' LIMIT 1")->fetchColumn() ?: 4;
    $cat_acc = $db->query("SELECT MaDanhMuc FROM DanhMuc WHERE TenDanhMuc = 'Phụ kiện máy tính' LIMIT 1")->fetchColumn() ?: 3;

    $products_real = [
        [
            'seller_id' => $seller_ids['nguyenvanan'],
            'cat_id' => $cat_phone,
            'name' => 'iPhone 14 Pro Max 256GB Space Black',
            'desc' => "Máy mình mua chính hãng VN/A dùng giữ gìn rất cẩn thận, pin còn 94%.\nNgoại hình đẹp 99%, không xước xát móp méo.\nPhụ kiện gồm fullbox cáp sạc zin. Bao test trực tiếp tại nhà.",
            'cond' => 'Likenew 99%',
            'weight' => 0.45,
            'price' => 21500000,
            'image' => 'uploads/images/iphone14_pro.png'
        ],
        [
            'seller_id' => $seller_ids['tranthibinh'],
            'cat_id' => $cat_audio,
            'name' => 'Tai nghe chống ồn Sony WH-1000XM5 Trắng Bạc',
            'desc' => "Tai nghe Sony WH-1000XM5 mua chính hãng Sony Việt Nam, còn bảo hành 5 tháng.\nÂm thanh đỉnh cao, chống ồn chủ động xuất sắc.\nFullbox đủ hộp bọc đựng tai nghe và cáp kết nối audio 3.5mm.",
            'cond' => 'Mới 98%',
            'weight' => 0.60,
            'price' => 5200000,
            'image' => 'uploads/images/sony_headphones.png'
        ],
        [
            'seller_id' => $seller_ids['lehoangcuong'],
            'cat_id' => $cat_acc,
            'name' => 'Bàn phím cơ không dây Keychron K2 V2 RGB Aluminum',
            'desc' => "Keychron K2 V2 bản khung nhôm LED RGB, Gateron Brown Switch gõ cực êm.\nKết nối Bluetooth 5.1 và cáp Type-C mượt mà, pin trâu 4000mAh.\nĐầy đủ keycap Mac/Windows đi kèm.",
            'cond' => 'Likenew 99%',
            'weight' => 0.90,
            'price' => 1450000, // 1.450.000đ
            'image' => 'uploads/images/keychron_k2.png'
        ]
    ];

    $ins_sp = $db->prepare("INSERT INTO SanPham (MaNguoiBan, MaDanhMuc, TenSanPham, MoTaChiTiet, TinhTrang, KhoiLuong_Kg, GiaBan, SoLuongTon, TrangThaiDuyet, TrangThaiBan)
                            VALUES (:seller, :cat, :name, :desc, :cond, :weight, :price, 1, 1, b'00')");
    $ins_img = $db->prepare("INSERT INTO HinhAnhSP (MaSanPham, DuongDanAnh, AnhChinh) VALUES (:pid, :img, 1)");

    foreach ($products_real as $p) {
        $ins_sp->execute([
            'seller' => $p['seller_id'],
            'cat' => $p['cat_id'],
            'name' => $p['name'],
            'desc' => $p['desc'],
            'cond' => $p['cond'],
            'weight' => $p['weight'],
            'price' => $p['price']
        ]);
        $pid = $db->lastInsertId();
        $ins_img->execute(['pid' => $pid, 'img' => $p['image']]);
    }

    $db->commit();
    echo "Đã xóa toàn bộ sản phẩm ảo cũ thành công!\n";
    echo "Đã tạo 3 tài khoản người bán mới và đăng 3 sản phẩm thực tế kèm ảnh vừa tạo!\n";
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Lỗi: " . $e->getMessage() . "\n";
}
