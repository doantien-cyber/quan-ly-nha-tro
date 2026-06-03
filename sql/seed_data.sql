-- Dữ liệu mẫu cho hệ thống QuanLyNhaTro
-- Mật khẩu tất cả tài khoản: 123456 (bcrypt)
-- Chạy file này SAU KHI đã chạy schema.sql

-- Xóa dữ liệu cũ theo thứ tự phụ thuộc FK
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE invoices;
TRUNCATE TABLE utility_readings;
TRUNCATE TABLE contracts;
TRUNCATE TABLE tenants;
TRUNCATE TABLE rooms;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- USERS (2 tài khoản, password = "123456")
-- ============================================================
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin',     '$2y$10$4V2txTt6BmE1.Y/rl4ddEeaAmyZgdMXBlN5pqB.4/aE/QTM/bbjUm', 'Quản Trị Viên',  'admin'),
('nhanvien1', '$2y$10$4V2txTt6BmE1.Y/rl4ddEeaAmyZgdMXBlN5pqB.4/aE/QTM/bbjUm', 'Nguyễn Thị Lan', 'staff');

-- ============================================================
-- ROOMS (6 phòng: A01-A03 tầng 1, B01-B03 tầng 2)
-- ============================================================
INSERT INTO rooms (room_number, floor, area_m2, price_per_month, status, notes) VALUES
('A01', 1, 20.0, 2500000, 'occupied',    'Phòng tiêu chuẩn, hướng sân trong'),
('A02', 1, 22.5, 2800000, 'occupied',    'Phòng có ban công nhỏ'),
('A03', 1, 18.0, 2500000, 'vacant',      NULL),
('B01', 2, 25.0, 3500000, 'occupied',    'Phòng rộng, view sân thượng'),
('B02', 2, 25.0, 3500000, 'maintenance', 'Đang sửa hệ thống điện'),
('B03', 2, 30.0, 4000000, 'vacant',      'Phòng góc, diện tích lớn nhất');

-- ============================================================
-- TENANTS (4 khách thuê)
-- ============================================================
INSERT INTO tenants (full_name, id_card, phone, email, permanent_address) VALUES
('Nguyễn Văn An',   '034095001234', '0901234567', 'nguyenvanan@gmail.com',   '12 Nguyễn Trãi, Quận 1, TP.HCM'),
('Trần Thị Bình',   '034195005678', '0912345678', 'tranthib@gmail.com',      '45 Lê Lợi, Quận 3, TP.HCM'),
('Lê Văn Cường',    '030085009999', '0923456789', NULL,                      '89 Hai Bà Trưng, Quận 1, TP.HCM'),
('Phạm Thị Dung',   '025075003456', '0934567890', 'phamthidung@gmail.com',   '67 Đinh Tiên Hoàng, Quận Bình Thạnh, TP.HCM');

-- ============================================================
-- CONTRACTS
--   ID 1-3: active  (A01→An, A02→Bình, B01→Cường)
--   ID 4:   expired (A03→Dung — phòng hiện đang trống)
-- ============================================================
INSERT INTO contracts (room_id, tenant_id, start_date, end_date, deposit, status) VALUES
(1, 1, '2024-01-15', '2025-12-31', 2500000,  'active'),
(2, 2, '2024-03-01', '2026-02-28', 5600000,  'active'),
(4, 3, '2024-06-01', '2026-05-31', 7000000,  'active'),
(3, 4, '2023-02-01', '2024-01-31', 2500000,  'expired');

-- ============================================================
-- UTILITY READINGS — tháng 05/2025 (3 phòng đang thuê)
-- ============================================================
-- room_id=1 (A01): điện 100→150 kWh (+50), nước 20→25 m³ (+5)
-- room_id=2 (A02): điện 200→260 kWh (+60), nước 30→37 m³ (+7)
-- room_id=4 (B01): điện 150→200 kWh (+50), nước 15→21 m³ (+6)
INSERT INTO utility_readings (room_id, month_year, electric_prev, electric_curr, water_prev, water_curr, electric_rate, water_rate) VALUES
(1, '2025-05-01', 100, 150, 20, 25, 3500, 15000),
(2, '2025-05-01', 200, 260, 30, 37, 3500, 15000),
(4, '2025-05-01', 150, 200, 15, 21, 3500, 15000);

-- ============================================================
-- INVOICES — tháng 05/2025 (2 paid, 1 unpaid)
-- Tính phí:
--   A01: tiền phòng 2.500.000 + điện 50×3.500=175.000 + nước 5×15.000=75.000  = 2.750.000
--   A02: tiền phòng 2.800.000 + điện 60×3.500=210.000 + nước 7×15.000=105.000 = 3.115.000
--   B01: tiền phòng 3.500.000 + điện 50×3.500=175.000 + nước 6×15.000=90.000  = 3.765.000
-- ============================================================
INSERT INTO invoices (contract_id, reading_id, month_year, room_fee, electric_fee, water_fee, total_amount, status, paid_at) VALUES
(1, 1, '2025-05-01', 2500000, 175000,  75000, 2750000, 'paid',   '2025-05-10 09:30:00'),
(2, 2, '2025-05-01', 2800000, 210000, 105000, 3115000, 'paid',   '2025-05-12 14:15:00'),
(3, 3, '2025-05-01', 3500000, 175000,  90000, 3765000, 'unpaid', NULL);