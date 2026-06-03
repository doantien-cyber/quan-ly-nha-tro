# CLAUDE.md — Hệ Thống Quản Lý Nhà Trọ (LAMP Stack)

## Mục đích file này
Context file cho Claude Code. Đọc file này trước mỗi session để hiểu đúng cấu trúc thật của project.

---

## Bối cảnh dự án

**Loại:** Bài tập lớn môn học — web app hoàn chỉnh  
**Chủ đề:** Hệ thống quản lý nhà trọ  
**Môi trường:** XAMPP (Windows), chạy tại localhost  
**Mục tiêu học thuật gồm 3 phần bắt buộc:**
1. Xây dựng web app đầy đủ chức năng (CRUD + auth + truy vấn)
2. Theo dõi & phân tích log (app log + database log)
3. Nâng cấp mã nguồn — thêm 1 tính năng mới sau khi app đã chạy

---

## Tech Stack

| Tầng | Công nghệ |
|------|-----------|
| Web Server | Apache (XAMPP) |
| Backend | PHP 8.x (thuần, không dùng framework) |
| Database | MySQL 8.x — tên DB: `QuanLyNhaTro` |
| Frontend | HTML5, CSS3, JavaScript (vanilla) |
| Local dev | XAMPP — webroot tại `C:/xampp/htdocs/nhatro/` |

---

## Cấu trúc thư mục thực tế

```
nhatro/                        ← webroot, CLAUDE.md đặt ở đây
  CLAUDE.md
  index.php                    ← redirect → login hoặc dashboard
  login.php
  .htaccess                    ← bảo vệ config/ và logs/
  config/
    database.php               ← kết nối PDO
    constants.php              ← giá điện, giá nước, config chung
  includes/
    auth.php                   ← hàm login / logout / check session
    logger.php                 ← hàm ghi_log() vào logs/app.log
    header.php                 ← nav HTML dùng chung
    footer.php
  pages/
    dashboard.php              ← tổng quan: số phòng, doanh thu tháng
    rooms.php                  ← quản lý phòng (CRUD)
    tenants.php                ← quản lý khách thuê (CRUD)
    contracts.php              ← hợp đồng thuê
    utilities.php              ← nhập chỉ số điện nước
    invoices.php               ← hóa đơn thanh toán
  api/
    rooms.php                  ← API endpoint JSON (dùng cho JS fetch)
    invoices.php
  assets/
    css/style.css
    js/main.js
  logs/
    app.log                    ← PHP custom log (gitignore file này)
  sql/
    schema.sql                 ← tất cả CREATE TABLE
    seed_data.sql              ← dữ liệu mẫu
```

---

## Database Schema

**Tên database:** `QuanLyNhaTro`  
**Charset:** utf8mb4 / utf8mb4_unicode_ci  
**Engine:** InnoDB (tất cả bảng)

```sql
-- BẢNG 1: users — tài khoản hệ thống
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,       -- bcrypt, KHÔNG lưu plaintext
    full_name     VARCHAR(100) NOT NULL,
    role          ENUM('admin', 'staff') NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BẢNG 2: rooms — phòng trọ
CREATE TABLE IF NOT EXISTS rooms (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    room_number     VARCHAR(10) NOT NULL UNIQUE,  -- A01, B02...
    floor           INT NOT NULL,
    area_m2         DECIMAL(5,1) NOT NULL,
    price_per_month DECIMAL(10,0) NOT NULL,
    status          ENUM('vacant', 'occupied', 'maintenance') DEFAULT 'vacant',
    notes           TEXT
);

-- BẢNG 3: tenants — khách thuê
CREATE TABLE IF NOT EXISTS tenants (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    full_name         VARCHAR(100) NOT NULL,
    id_card           VARCHAR(20) NOT NULL UNIQUE,  -- CMND/CCCD
    phone             VARCHAR(15) NOT NULL,
    email             VARCHAR(100),
    permanent_address TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BẢNG 4: contracts — hợp đồng thuê
CREATE TABLE IF NOT EXISTS contracts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    room_id    INT NOT NULL,
    tenant_id  INT NOT NULL,
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    deposit    DECIMAL(10,0) NOT NULL,
    status     ENUM('active', 'expired', 'terminated') DEFAULT 'active',
    FOREIGN KEY (room_id)   REFERENCES rooms(id)   ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT ON UPDATE CASCADE
);
-- Lưu ý: validate ở PHP để tránh 1 phòng có 2 contract active cùng lúc

-- BẢNG 5: utility_readings — chỉ số điện nước
CREATE TABLE IF NOT EXISTS utility_readings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    room_id       INT NOT NULL,
    month_year    DATE NOT NULL,         -- ngày 1 của tháng, VD: 2025-06-01
    electric_prev INT NOT NULL,
    electric_curr INT NOT NULL,
    water_prev    INT NOT NULL,
    water_curr    INT NOT NULL,
    electric_rate DECIMAL(8,0) NOT NULL, -- đơn giá điện (đồng/kWh)
    water_rate    DECIMAL(8,0) NOT NULL, -- đơn giá nước (đồng/m³)
    UNIQUE KEY uq_room_month (room_id, month_year), -- ⚠️ quan trọng: không nhập 2 lần
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- BẢNG 6: invoices — hóa đơn hàng tháng
CREATE TABLE IF NOT EXISTS invoices (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    contract_id  INT NOT NULL,
    reading_id   INT,                    -- NULL nếu không có chỉ số điện nước
    month_year   DATE NOT NULL,
    room_fee     DECIMAL(10,0) NOT NULL,
    electric_fee DECIMAL(10,0) NOT NULL,
    water_fee    DECIMAL(10,0) NOT NULL,
    total_amount DECIMAL(10,0) NOT NULL,
    status       ENUM('unpaid', 'paid') DEFAULT 'unpaid',
    paid_at      TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (contract_id) REFERENCES contracts(id)        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (reading_id)  REFERENCES utility_readings(id) ON DELETE SET NULL ON UPDATE CASCADE
);
```

### Quan hệ giữa các bảng
```
users         (độc lập — quản lý đăng nhập)
rooms ──────< contracts >────── tenants
rooms ──────< utility_readings
contracts ──< invoices >──────── utility_readings
```

---

## Cơ chế kỹ thuật

### Session & bảo vệ route
Mọi trang trong `pages/` phải include auth check ở dòng đầu:
```php
<?php
session_start();
require_once '../includes/auth.php';
require_login(); // redirect về login.php nếu chưa đăng nhập
```

Hàm trong `includes/auth.php`:
```php
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /nhatro/login.php');
        exit;
    }
}
function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}
```

### Kết nối Database (PDO)
```php
// config/database.php
$pdo = new PDO(
    'mysql:host=localhost;dbname=QuanLyNhaTro;charset=utf8mb4',
    'root',
    ''
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
```
Luôn dùng **prepared statements** — không nối chuỗi SQL.

### Custom App Log
```php
// includes/logger.php
function ghi_log(string $action, string $detail = ''): void {
    $time    = date('Y-m-d H:i:s');
    $user    = $_SESSION['username'] ?? 'anonymous';
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line    = "[$time] USER=$user IP=$ip ACTION=$action $detail\n";
    file_put_contents(__DIR__ . '/../logs/app.log', $line, FILE_APPEND | LOCK_EX);
}

// Cách dùng:
ghi_log('LOGIN_SUCCESS', 'username=admin');
ghi_log('ADD_ROOM', 'room_number=A01');
ghi_log('GENERATE_INVOICE', 'contract_id=3 month=2025-06');
ghi_log('EXCEPTION', 'PDOException: ' . $e->getMessage());
```

### MySQL General Log (bật trong XAMPP)
Sửa `C:\xampp\mysql\bin\my.ini`, thêm vào section `[mysqld]`:
```ini
general_log = 1
general_log_file = "C:/xampp/mysql/logs/mysql_general.log"
```
Restart MySQL service sau khi sửa.

### Xử lý lỗi — pattern chuẩn
```php
try {
    $stmt = $pdo->prepare("SELECT ...");
    $stmt->execute([...]);
} catch (PDOException $e) {
    ghi_log('EXCEPTION', $e->getMessage());
    $_SESSION['error'] = 'Có lỗi xảy ra, vui lòng thử lại.';
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}
```

---

## Tính năng

### Đã có / đang build
- [ ] Đăng nhập / đăng xuất (session-based, bcrypt)
- [ ] Quản lý phòng: thêm, sửa, xóa, danh sách + filter trạng thái
- [ ] Quản lý khách thuê: thêm, sửa, xóa, tìm kiếm
- [ ] Hợp đồng: tạo, xem, kết thúc hợp đồng
- [ ] Nhập chỉ số điện nước theo tháng
- [ ] Hóa đơn: tự động tính từ chỉ số + giá, đánh dấu đã thanh toán
- [ ] App log (ghi file) + MySQL general log

### Tính năng nâng cấp (Phần 3 đề bài)
- [ ] **Báo cáo thống kê**: doanh thu theo tháng, tỷ lệ lấp đầy phòng

### KHÔNG làm
- Không mobile app, không dark mode, không export PDF
- Không gửi email, không REST API public
- Không Laravel / Symfony hay bất kỳ PHP framework nào
- Tính năng nâng cấp: chỉ thêm file mới, không refactor file cũ

---

## Quy ước code

- **Ngôn ngữ comment:** tiếng Việt
- **Encoding:** UTF-8 toàn bộ
- **PHP:** không có closing tag `?>` ở cuối file
- **Tên biến/hàm:** `snake_case` (`$room_id`, `get_active_contracts()`)
- **Output HTML:** luôn dùng `htmlspecialchars()` khi echo biến
- **Query:** luôn dùng prepared statement, không nối chuỗi SQL

---

## Mẫu prompt cho Claude Code

**Khởi tạo / làm việc với file cụ thể:**
```
Đọc CLAUDE.md. Tạo pages/rooms.php: hiển thị danh sách phòng từ bảng `rooms`,
có filter theo status, phân trang 10 dòng/trang. Dùng PDO từ config/database.php,
include header.php và footer.php, gọi ghi_log() khi load trang.
```

**Thêm tính năng:**
```
Đọc CLAUDE.md. Tạo pages/utilities.php: form nhập chỉ số điện nước cho 1 phòng
trong tháng. Validate không nhập 2 lần cùng room+month (check trước khi INSERT).
Ghi log action=ADD_UTILITY_READING sau khi lưu thành công.
```

**Nâng cấp mã nguồn:**
```
Đọc CLAUDE.md. Tạo pages/bao-cao.php (file mới, không sửa file cũ):
query tổng total_amount từ invoices GROUP BY month_year trong năm hiện tại,
hiển thị bảng HTML. Ghi log action=VIEW_REPORT khi truy cập.
```

---

## Checklist trước khi demo

- [ ] Mọi trang trong `pages/` có `require_login()` ở đầu
- [ ] Không có SQL injection (prepared statement hết)
- [ ] `logs/app.log` ghi được (thư mục `logs/` có quyền write)
- [ ] MySQL general log đã bật và xuất hiện query
- [ ] Trang lỗi hiển thị message thân thiện, không lộ stack trace PHP
- [ ] `seed_data.sql` có: 3+ users, 5+ phòng, 3+ khách, 2+ hợp đồng active, hóa đơn mẫu
- [ ] `.htaccess` chặn truy cập trực tiếp vào `config/` và `logs/`