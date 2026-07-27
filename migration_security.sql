-- 1. Tạo bảng FailedLogins phục vụ chống brute-force
CREATE TABLE IF NOT EXISTS FailedLogins (
    Identifier VARCHAR(64) PRIMARY KEY, -- Hash của username/email + IP
    Attempts INT NOT NULL DEFAULT 1,
    LastAttempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. Đảm bảo các quyền bảo mật tồn tại trong bảng Quyen
INSERT IGNORE INTO Quyen (TenQuyen, MoTa) VALUES
('product.view', 'Quyền xem danh sách và chi tiết sản phẩm'),
('product.create', 'Quyền đăng bán sản phẩm mới'),
('product.update', 'Quyền phê duyệt hoặc cập nhật trạng thái sản phẩm'),
('product.delete', 'Quyền xóa sản phẩm khỏi hệ thống');

-- 3. Ánh xạ quyền cho vai trò ADMIN
-- Tìm MaVaiTro của ADMIN
SET @admin_role_id = (SELECT MaVaiTro FROM VaiTro WHERE TenVaiTro = 'ADMIN' LIMIT 1);
-- Tìm MaQuyen của các quyền
SET @view_perm_id = (SELECT MaQuyen FROM Quyen WHERE TenQuyen = 'product.view' LIMIT 1);
SET @create_perm_id = (SELECT MaQuyen FROM Quyen WHERE TenQuyen = 'product.create' LIMIT 1);
SET @update_perm_id = (SELECT MaQuyen FROM Quyen WHERE TenQuyen = 'product.update' LIMIT 1);
SET @delete_perm_id = (SELECT MaQuyen FROM Quyen WHERE TenQuyen = 'product.delete' LIMIT 1);

-- Gán quyền cho ADMIN nếu ADMIN tồn tại
INSERT IGNORE INTO VaiTro_Quyen (MaVaiTro, MaQuyen)
SELECT @admin_role_id, q.MaQuyen FROM Quyen q WHERE q.TenQuyen IN ('product.view', 'product.create', 'product.update', 'product.delete') AND @admin_role_id IS NOT NULL;

-- 4. Ánh xạ quyền cho vai trò SELLER
SET @seller_role_id = (SELECT MaVaiTro FROM VaiTro WHERE TenVaiTro = 'SELLER' LIMIT 1);
INSERT IGNORE INTO VaiTro_Quyen (MaVaiTro, MaQuyen)
SELECT @seller_role_id, q.MaQuyen FROM Quyen q WHERE q.TenQuyen IN ('product.view', 'product.create', 'product.update', 'product.delete') AND @seller_role_id IS NOT NULL;

-- 5. Ánh xạ quyền cho vai trò BUYER
SET @buyer_role_id = (SELECT MaVaiTro FROM VaiTro WHERE TenVaiTro = 'BUYER' LIMIT 1);
INSERT IGNORE INTO VaiTro_Quyen (MaVaiTro, MaQuyen)
SELECT @buyer_role_id, q.MaQuyen FROM Quyen q WHERE q.TenQuyen IN ('product.view') AND @buyer_role_id IS NOT NULL;

-- 6. Tạo các chỉ mục phụ tối ưu hiệu năng truy vấn sản phẩm
-- Trang quản lý: lọc sản phẩm chờ duyệt/đã duyệt và sắp xếp mới nhất
-- CREATE INDEX idx_sp_duyet_ban_ngaydang ON SanPham(TrangThaiDuyet, TrangThaiBan, NgayDang);

-- Hiển thị sản phẩm theo từng danh mục
-- CREATE INDEX idx_sp_danhmuc_trangthai ON SanPham(MaDanhMuc, TrangThaiDuyet, TrangThaiBan);

-- Người bán quản lý sản phẩm của mình
-- CREATE INDEX idx_sp_nguoiban_ngaydang ON SanPham(MaNguoiBan, NgayDang);
