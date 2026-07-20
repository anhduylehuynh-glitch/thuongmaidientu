-- ============================================================================
-- DATABASE SCHEMA: WEB BÁN ĐỒ CŨ & HỆ THỐNG GIAO HÀNG NỘI BỘ
-- Tích hợp Phân Quyền Động (RBAC) & Khắc phục các khoảng trống nghiệp vụ từ PRD
-- Đối tượng CSDL: MySQL / MariaDB / PostgreSQL tương thích
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. HỆ THỐNG PHÂN QUYỀN ĐỘNG (RBAC)
-- ----------------------------------------------------------------------------

-- Bảng Vai trò (Roles)
CREATE TABLE VaiTro (
    MaVaiTro INT AUTO_INCREMENT PRIMARY KEY,
    TenVaiTro VARCHAR(50) NOT NULL UNIQUE, -- VD: 'ADMIN', 'BUYER', 'SELLER', 'SHIPPER', 'WAREHOUSE_STAFF', 'MODERATOR'
    MoTa VARCHAR(255) NULL,
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bảng Quyền hạn chi tiết (Permissions)
CREATE TABLE Quyen (
    MaQuyen INT AUTO_INCREMENT PRIMARY KEY,
    TenQuyen VARCHAR(100) NOT NULL UNIQUE, -- VD: 'PRODUCT_CREATE', 'PRODUCT_APPROVE', 'USER_BLOCK', 'WITHDRAW_APPROVE'
    MoTa VARCHAR(255) NULL,
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 2. TÀI KHOẢN NGƯỜI DÙNG & ĐỊA CHỈ
-- ----------------------------------------------------------------------------

-- Bảng Người dùng (Đã loại bỏ cột VaiTro cứng nhắc để dùng bảng trung gian)
CREATE TABLE NguoiDung (
    MaNguoiDung INT AUTO_INCREMENT PRIMARY KEY,
    TenDangNhap VARCHAR(50) NOT NULL UNIQUE,
    MatKhau VARCHAR(255) NOT NULL, -- Độ rộng 255 để lưu hash mật khẩu (bcrypt/argon2)
    HoTen VARCHAR(100) NOT NULL,
    SoDienThoai VARCHAR(15) NULL,
    Email VARCHAR(50) NULL UNIQUE,
    DiemUyTin INT DEFAULT 0, -- Tích lũy để phân hạng thành viên
    HangThanhVien VARCHAR(30) DEFAULT 'Đồng', -- 'Đồng', 'Bạc', 'Vàng', 'Kim Cương'
    TrangThaiTaiKhoan VARCHAR(30) DEFAULT 'Hoạt động', -- 'Hoạt động', 'Bị khóa'
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bảng trung gian Người dùng - Vai trò (Cho phép một người dùng có nhiều vai trò)
CREATE TABLE NguoiDung_VaiTro (
    MaNguoiDung INT NOT NULL,
    MaVaiTro INT NOT NULL,
    NgayGan TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (MaNguoiDung, MaVaiTro),
    FOREIGN KEY (MaNguoiDung) REFERENCES NguoiDung(MaNguoiDung) ON DELETE CASCADE,
    FOREIGN KEY (MaVaiTro) REFERENCES VaiTro(MaVaiTro) ON DELETE CASCADE
);

-- Bảng trung gian Vai trò - Quyền hạn (Định nghĩa động quyền của từng vai trò)
CREATE TABLE VaiTro_Quyen (
    MaVaiTro INT NOT NULL,
    MaQuyen INT NOT NULL,
    NgayGan TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (MaVaiTro, MaQuyen),
    FOREIGN KEY (MaVaiTro) REFERENCES VaiTro(MaVaiTro) ON DELETE CASCADE,
    FOREIGN KEY (MaQuyen) REFERENCES Quyen(MaQuyen) ON DELETE CASCADE
);

-- Bảng Sổ địa chỉ (Address Book - Hỗ trợ tọa độ Kinh/Vĩ độ để tính khoảng cách giao hàng)
CREATE TABLE SoDiaChi (
    MaDiaChi INT AUTO_INCREMENT PRIMARY KEY,
    MaNguoiDung INT NOT NULL,
    DiaChiChiTiet VARCHAR(200) NOT NULL,
    ViDo DECIMAL(10, 8) NOT NULL, -- Tọa độ vĩ độ
    KinhDo DECIMAL(11, 8) NOT NULL, -- Tọa độ kinh độ
    LaDiaChiMacDinh BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (MaNguoiDung) REFERENCES NguoiDung(MaNguoiDung) ON DELETE CASCADE
);

-- ----------------------------------------------------------------------------
-- 3. HỆ THỐNG VÍ ĐIỆN TỬ, LIÊN KẾT NGÂN HÀNG & NHẬT KÝ DÒNG TIỀN (ESCROW)
-- ----------------------------------------------------------------------------

-- Bảng Ví điện tử
CREATE TABLE ViDienTu (
    MaVi INT AUTO_INCREMENT PRIMARY KEY,
    MaNguoiDung INT NOT NULL UNIQUE,
    SoDu DECIMAL(15, 2) DEFAULT 0.00,
    TrangThaiVi VARCHAR(30) DEFAULT 'Hoạt động', -- 'Hoạt động', 'Bị khóa'
    NgayCapNhat TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (MaNguoiDung) REFERENCES NguoiDung(MaNguoiDung) ON DELETE CASCADE
);

-- Bảng Tài khoản ngân hàng liên kết (Bổ sung theo PRD để phục vụ rút tiền)
CREATE TABLE TaiKhoanNganHangLienKet (
    MaTaiKhoan INT AUTO_INCREMENT PRIMARY KEY,
    MaNguoiDung INT NOT NULL,
    TenNganHang VARCHAR(100) NOT NULL,
    SoTaiKhoan VARCHAR(30) NOT NULL,
    TenChuTaiKhoan VARCHAR(100) NOT NULL,
    ChiNhanh VARCHAR(150) NULL,
    NgayLienKet TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaNguoiDung) REFERENCES NguoiDung(MaNguoiDung) ON DELETE CASCADE
);

-- Bảng Yêu cầu rút tiền (Bổ sung theo PRD để quản lý yêu cầu rút tiền của Seller)
CREATE TABLE YeuCauRutTien (
    MaYeuCau INT AUTO_INCREMENT PRIMARY KEY,
    MaVi INT NOT NULL,
    MaTaiKhoan INT NOT NULL,
    SoTien DECIMAL(15, 2) NOT NULL,
    TrangThai VARCHAR(30) DEFAULT 'Chờ duyệt', -- 'Chờ duyệt', 'Đã chuyển khoản', 'Từ chối'
    LyDoTuChoi VARCHAR(255) NULL,
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    NgayXuLy TIMESTAMP NULL,
    FOREIGN KEY (MaVi) REFERENCES ViDienTu(MaVi),
    FOREIGN KEY (MaTaiKhoan) REFERENCES TaiKhoanNganHangLienKet(MaTaiKhoan)
);

-- ----------------------------------------------------------------------------
-- 4. DANH MỤC & SẢN PHẨM BÁN ĐỒ CŨ
-- ----------------------------------------------------------------------------

-- Bảng Danh mục sản phẩm (Category)
CREATE TABLE DanhMuc (
    MaDanhMuc INT AUTO_INCREMENT PRIMARY KEY,
    TenDanhMuc VARCHAR(100) NOT NULL,
    MoTa VARCHAR(255) NULL
);

-- Bảng Sản phẩm (Second-hand Product)
CREATE TABLE SanPham (
    MaSanPham INT AUTO_INCREMENT PRIMARY KEY,
    MaNguoiBan INT NOT NULL,
    MaDanhMuc INT NOT NULL,
    TenSanPham VARCHAR(200) NOT NULL,
    MoTaChiTiet TEXT NOT NULL,
    TinhTrang VARCHAR(50) NOT NULL, -- VD: 'Mới nguyên seal', 'Mới 99%', 'Cũ xước nhẹ',...
    KhoiLuong_Kg DECIMAL(6, 2) NOT NULL, -- Phục vụ tính phí vận chuyển theo khối lượng
    GiaBan DECIMAL(15, 2) NOT NULL,
    VideoThucTe VARCHAR(255) NULL, -- Link video chứng minh thực trạng sản phẩm
    TrangThaiDuyet VARCHAR(30) DEFAULT 'Chờ duyệt', -- 'Chờ duyệt', 'Đã duyệt', 'Bị từ chối'
    TrangThaiBan VARCHAR(30) DEFAULT 'Sẵn sàng', -- 'Sẵn sàng', 'Đang giao dịch', 'Đã bán', 'Đã ẩn'
    NgayDang TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaNguoiBan) REFERENCES NguoiDung(MaNguoiDung) ON DELETE CASCADE,
    FOREIGN KEY (MaDanhMuc) REFERENCES DanhMuc(MaDanhMuc)
);

-- Bảng Hình ảnh chi tiết của sản phẩm (Hỗ trợ tải lên nhiều hình ảnh)
CREATE TABLE HinhAnhSP (
    MaHinhAnh INT AUTO_INCREMENT PRIMARY KEY,
    MaSanPham INT NOT NULL,
    DuongDanAnh VARCHAR(255) NOT NULL,
    AnhChinh BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (MaSanPham) REFERENCES SanPham(MaSanPham) ON DELETE CASCADE
);

-- ----------------------------------------------------------------------------
-- 5. THƯƠNG LƯỢNG GIÁ & CHAT P2P
-- ----------------------------------------------------------------------------

-- Bảng Phòng chat / Cuộc hội thoại (Bổ sung theo PRD)
CREATE TABLE CuocHoiThoai (
    MaCuocHoiThoai INT AUTO_INCREMENT PRIMARY KEY,
    MaNguoiMua INT NOT NULL,
    MaNguoiBan INT NOT NULL,
    MaSanPham INT NULL, -- Chat bắt đầu từ sản phẩm nào (nếu có)
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaNguoiMua) REFERENCES NguoiDung(MaNguoiDung),
    FOREIGN KEY (MaNguoiBan) REFERENCES NguoiDung(MaNguoiDung),
    FOREIGN KEY (MaSanPham) REFERENCES SanPham(MaSanPham) ON DELETE SET NULL
);

-- Bảng Chi tiết tin nhắn (Bổ sung theo PRD để lưu nội dung chat & hình ảnh thực tế đính kèm)
CREATE TABLE TinNhanChat (
    MaTinNhan INT AUTO_INCREMENT PRIMARY KEY,
    MaCuocHoiThoai INT NOT NULL,
    MaNguoiGui INT NOT NULL,
    NoiDung TEXT NULL,
    DuongDanHinhAnh VARCHAR(255) NULL, -- Gửi hình ảnh qua chat
    NgayGui TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaCuocHoiThoai) REFERENCES CuocHoiThoai(MaCuocHoiThoai) ON DELETE CASCADE,
    FOREIGN KEY (MaNguoiGui) REFERENCES NguoiDung(MaNguoiDung)
);

-- Bảng Yêu cầu trả giá / Thương lượng (Bổ sung theo PRD để theo dõi trả giá sản phẩm)
CREATE TABLE YeuCauTraGia (
    MaYeuCauTraGia INT AUTO_INCREMENT PRIMARY KEY,
    MaSanPham INT NOT NULL,
    MaNguoiMua INT NOT NULL,
    GiaDeNghi DECIMAL(15, 2) NOT NULL,
    TrangThai VARCHAR(30) DEFAULT 'Chờ phản hồi', -- 'Chờ phản hồi', 'Chấp nhận', 'Từ chối', 'Đã hủy'
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaSanPham) REFERENCES SanPham(MaSanPham) ON DELETE CASCADE,
    FOREIGN KEY (MaNguoiMua) REFERENCES NguoiDung(MaNguoiDung)
);

-- ----------------------------------------------------------------------------
-- 6. ĐƠN HÀNG, CHI TIẾT ĐƠN HÀNG & LOGISTICS NỘI BỘ
-- ----------------------------------------------------------------------------

-- Bảng Đơn hàng (Thông tin tổng quát của giao dịch)
CREATE TABLE DonHang (
    MaDonHang INT AUTO_INCREMENT PRIMARY KEY,
    MaNguoiMua INT NOT NULL,
    MaDiaChiGiao INT NOT NULL, -- Địa chỉ nhận hàng của người mua
    PhuongThucThanhToan VARCHAR(50) NOT NULL, -- 'COD', 'Ví điện tử', 'Thẻ ngân hàng', 'VNPay'
    TongTienThanhToan DECIMAL(15, 2) NOT NULL, -- Tổng tiền (Giá sản phẩm + Phí ship thực tế)
    TrangThaiDonHang VARCHAR(30) DEFAULT 'Chờ xác nhận', -- 'Chờ xác nhận', 'Đang xử lý', 'Đang giao', 'Đã giao', 'Khiếu nại', 'Hoàn tất', 'Đã hủy'
    TrangThaiThanhToan VARCHAR(50) DEFAULT 'Chưa thanh toán', -- 'Chưa thanh toán', 'Đã thanh toán', 'Tạm giữ (Escrow)', 'Đã giải ngân', 'Đã hoàn tiền'
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaNguoiMua) REFERENCES NguoiDung(MaNguoiDung),
    FOREIGN KEY (MaDiaChiGiao) REFERENCES SoDiaChi(MaDiaChi)
);

-- Bảng Chi tiết đơn hàng (Quản lý phí ship riêng lẻ cho từng gói hàng từ các Seller khác nhau)
CREATE TABLE ChiTietDonHang (
    MaDonHang INT NOT NULL,
    MaSanPham INT NOT NULL,
    SoLuong INT DEFAULT 1, -- Bổ sung trường SoLuong để phòng trường hợp thanh lý nhiều món giống nhau
    GiaChotMua DECIMAL(15, 2) NOT NULL, -- Giá bán thực tế tại thời điểm chốt đơn
    PhiShipGoc DECIMAL(15, 2) NOT NULL, -- Phí ship gốc chưa giảm giá
    PhiShipThucTe DECIMAL(15, 2) NOT NULL, -- Phí ship thực tế sau khi giảm trừ theo hạng thành viên
    MaVanDon_QR VARCHAR(100) UNIQUE NULL, -- Mã vận đơn cấp bởi sàn để in dán lên gói hàng
    PRIMARY KEY (MaDonHang, MaSanPham),
    FOREIGN KEY (MaDonHang) REFERENCES DonHang(MaDonHang) ON DELETE CASCADE,
    FOREIGN KEY (MaSanPham) REFERENCES SanPham(MaSanPham)
);

-- Bảng Nhật ký giao dịch ví (Bổ sung theo PRD để audit & quản lý dòng tiền Escrow)
CREATE TABLE LichSuGiaoDichVi (
    MaGiaoDich INT AUTO_INCREMENT PRIMARY KEY,
    MaViNguon INT NULL, -- NULL nếu nạp tiền từ bên ngoài ngân hàng vào ví
    MaViDich INT NULL, -- NULL nếu rút tiền về tài khoản ngân hàng
    SoTien DECIMAL(15, 2) NOT NULL,
    LoaiGiaoDich VARCHAR(50) NOT NULL, -- 'THANH_TOAN', 'ESCROW_TAM_GIU', 'ESCROW_GIAI_NGAN', 'HOAN_TIEN_KHI_NAI', 'TRU_QUY_SHIPPER_COD', 'RUT_TIEN'
    TrangThai VARCHAR(30) DEFAULT 'Thành công', -- 'Thành công', 'Thất bại', 'Đang xử lý'
    MoTa VARCHAR(255) NULL,
    MaDonHang INT NULL,
    MaSanPham INT NULL, -- Liên kết trực tiếp tới sản phẩm/chi tiết đơn hàng để dễ đối soát
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaViNguon) REFERENCES ViDienTu(MaVi),
    FOREIGN KEY (MaViDich) REFERENCES ViDienTu(MaVi),
    FOREIGN KEY (MaDonHang, MaSanPham) REFERENCES ChiTietDonHang(MaDonHang, MaSanPham)
);

-- ----------------------------------------------------------------------------
-- 7. HỆ THỐNG VẬN CHUYỂN, KHO BÃI & NHẬM VỤ SHIPPER
-- ----------------------------------------------------------------------------

-- Bảng Kho bãi (Hubs - Hệ thống các điểm trung chuyển hàng hóa)
CREATE TABLE Kho (
    MaKho INT AUTO_INCREMENT PRIMARY KEY,
    TenKho VARCHAR(100) NOT NULL,
    DiaChiKho VARCHAR(150) NOT NULL,
    ViDo DECIMAL(10, 8) NULL, -- Bổ sung Kinh/Vĩ độ của kho để định tuyến và tính khoảng cách nội bộ
    KinhDo DECIMAL(11, 8) NULL
);

-- Bảng Phiếu giao nhận / Nhiệm vụ Shipper (Nhiệm vụ đi lấy hàng hoặc giao hàng)
CREATE TABLE PhieuGiaoNhan (
    MaNhiemVu INT AUTO_INCREMENT PRIMARY KEY,
    MaShipper INT NOT NULL, -- ID của Shipper (Bản chất là NguoiDung có vai trò 'SHIPPER')
    MaDonHang INT NOT NULL,
    MaSanPham INT NOT NULL,
    LoaiNhiemVu VARCHAR(50) NOT NULL, -- 'Lấy hàng' (từ Seller về kho), 'Giao hàng' (từ kho đến Buyer)
    TrangThaiNhiemVu VARCHAR(50) DEFAULT 'Chờ tiếp nhận', -- 'Chờ tiếp nhận', 'Đang thực hiện', 'Thành công', 'Thất bại'
    TienThuHo DECIMAL(15, 2) DEFAULT 0.00, -- Số tiền COD cần thu (nếu có)
    LyDoThatBai VARCHAR(255) NULL,
    NgayNhanNhiemVu TIMESTAMP NULL,
    NgayHoanThanh TIMESTAMP NULL,
    FOREIGN KEY (MaShipper) REFERENCES NguoiDung(MaNguoiDung),
    FOREIGN KEY (MaDonHang, MaSanPham) REFERENCES ChiTietDonHang(MaDonHang, MaSanPham) ON DELETE CASCADE
);

-- Bảng Chi tiết lịch sử vận chuyển (Lưu vết gói hàng đi qua các kho, trung chuyển nội bộ)
CREATE TABLE ChiTietLichSuVanChuyen (
    MaLichSu INT AUTO_INCREMENT PRIMARY KEY,
    MaDonHang INT NOT NULL,
    MaSanPham INT NOT NULL,
    MaKho INT NULL, -- NULL nếu đang trong trạng thái trung chuyển trên đường của Shipper
    MaNhanVien INT NOT NULL, -- Nhân viên thực hiện quét barcode/QR (Nhân viên kho hoặc Shipper)
    HanhDong VARCHAR(50) NOT NULL, -- 'Nhập kho', 'Xuất kho', 'Phân loại', 'Đang vận chuyển', 'Lưu kho hoàn trả'
    GhiChu VARCHAR(200) NULL,
    ThoiGianGhiNhan TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaDonHang, MaSanPham) REFERENCES ChiTietDonHang(MaDonHang, MaSanPham) ON DELETE CASCADE,
    FOREIGN KEY (MaKho) REFERENCES Kho(MaKho),
    FOREIGN KEY (MaNhanVien) REFERENCES NguoiDung(MaNguoiDung)
);

-- Bảng Biên bản sự cố vận chuyển (Bổ sung theo PRD để quản lý rủi ro đền bù cho người bán)
CREATE TABLE BienBanSuCo (
    MaBienBan INT AUTO_INCREMENT PRIMARY KEY,
    MaDonHang INT NOT NULL,
    MaSanPham INT NOT NULL,
    MaNguoiLap INT NOT NULL, -- ID nhân viên/shipper phát hiện sự cố
    LoaiSuCo VARCHAR(50) NOT NULL, -- 'Hao hụt', 'Hư hỏng sản phẩm', 'Mất gói hàng', 'Khách trả hàng vỡ'
    MoTaChiTiet TEXT NOT NULL,
    GiaTriThietHai DECIMAL(15, 2) NOT NULL,
    SoTienDenBu DECIMAL(15, 2) DEFAULT 0.00, -- Sàn đền bù cho người bán/mua
    TrangThai VARCHAR(30) DEFAULT 'Chờ xử lý', -- 'Chờ xử lý', 'Đã đền bù', 'Từ chối'
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaDonHang, MaSanPham) REFERENCES ChiTietDonHang(MaDonHang, MaSanPham) ON DELETE CASCADE,
    FOREIGN KEY (MaNguoiLap) REFERENCES NguoiDung(MaNguoiDung)
);

-- ----------------------------------------------------------------------------
-- 8. KHIẾU NẠI & ĐÁNH GIÁ
-- ----------------------------------------------------------------------------

-- Bảng Đơn khiếu nại trả hàng
CREATE TABLE DonKhieuNaiTraHang (
    MaKhieuNai INT AUTO_INCREMENT PRIMARY KEY,
    MaDonHang INT NOT NULL,
    MaSanPham INT NOT NULL,
    MaNguoiKhieuNai INT NOT NULL, -- Thường là Người mua khiếu nại
    LyDoKhieuNai VARCHAR(200) NOT NULL,
    VideoUnboxing VARCHAR(255) NOT NULL, -- Bắt buộc theo PRD làm bằng chứng đổi trả
    TrangThaiKhieuNai VARCHAR(50) DEFAULT 'Chờ xử lý', -- 'Chờ xử lý', 'Chấp nhận trả tiền', 'Từ chối khiếu nại'
    KetQua VARCHAR(200) NULL, -- Ý kiến giải quyết của Moderator
    NgayTao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaDonHang, MaSanPham) REFERENCES ChiTietDonHang(MaDonHang, MaSanPham) ON DELETE CASCADE,
    FOREIGN KEY (MaNguoiKhieuNai) REFERENCES NguoiDung(MaNguoiDung)
);

-- Bảng Đánh giá sản phẩm
CREATE TABLE DonDanhGiaSanPham (
    MaDanhGia INT AUTO_INCREMENT PRIMARY KEY,
    MaDonHang INT NOT NULL,
    MaSanPham INT NOT NULL,
    MaNguoiDanhGia INT NOT NULL, -- Người mua đánh giá
    SoSao INT NOT NULL CHECK (SoSao BETWEEN 1 AND 5),
    NhanXet VARCHAR(200) NULL,
    NgayDanhGia TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MaDonHang, MaSanPham) REFERENCES ChiTietDonHang(MaDonHang, MaSanPham) ON DELETE CASCADE,
    FOREIGN KEY (MaNguoiDanhGia) REFERENCES NguoiDung(MaNguoiDung)
);

-- ----------------------------------------------------------------------------
-- 9. CẤU HÌNH ĐỘNG HỆ THỐNG
-- ----------------------------------------------------------------------------

-- Bảng Cấu hình hệ thống (Bổ sung để Admin chỉnh sửa cấu hình trực tiếp trên Web)
CREATE TABLE CauHinhHeThong (
    MaCauHinh VARCHAR(50) PRIMARY KEY, -- VD: 'SHIP_OPENING_FEE', 'PLATFORM_FEE_PERCENT', 'BRONZE_TIER_DISCOUNT'
    GiaTri VARCHAR(100) NOT NULL,
    MoTa VARCHAR(255) NULL,
    NgayCapNhat TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Thêm cấu hình mặc định tương ứng với PRD Mục 2.1 & 2.2
INSERT INTO CauHinhHeThong (MaCauHinh, GiaTri, MoTa) VALUES
('SHIP_OPENING_FEE', '15000', 'Phí ship mở cửa cố định áp dụng cho 2km đầu tiên (định dạng đồng)'),
('SHIP_PER_KM_FEE', '5000', 'Đơn giá khoảng cách từ km thứ 3 trở đi (định dạng đồng/km)'),
('SHIP_MAX_LIGHT_WEIGHT', '2', 'Hạn mức khối lượng hàng nhẹ không tính phụ phí (định dạng kg)'),
('SHIP_HEAVY_SURCHARGE_FEE', '5000', 'Phụ phí cồng kềnh cho mỗi kg vượt hạn mức (định dạng đồng/kg)'),
('PLATFORM_FEE_PERCENT', '5.0', 'Tỷ lệ phí hoa hồng sàn thu từ người bán (định dạng phần trăm, VD: 5.0)'),
('MEMBER_BRONZE_DISCOUNT', '0.00', 'Tỷ lệ giảm phí ship cho hạng Đồng (0%)'),
('MEMBER_SILVER_DISCOUNT', '0.01', 'Tỷ lệ giảm phí ship cho hạng Bạc (1%)'),
('MEMBER_GOLD_DISCOUNT', '0.03', 'Tỷ lệ giảm phí ship cho hạng Vàng (3%)'),
('MEMBER_DIAMOND_DISCOUNT', '0.05', 'Tỷ lệ giảm phí ship cho hạng Kim Cương (5%)');
