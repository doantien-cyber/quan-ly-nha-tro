<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

require_login();

// ── Flash messages ────────────────────────────────────────────
$flash_success = $_SESSION['success'] ?? '';
$flash_error   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── POST: thêm phòng mới ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $room_number     = trim($_POST['room_number'] ?? '');
    $floor           = (int)($_POST['floor'] ?? 0);
    $area_m2         = (float)str_replace(',', '.', $_POST['area_m2'] ?? '0');
    $price_per_month = (int)str_replace([',', '.', ' '], '', $_POST['price_per_month'] ?? '0');
    $status          = $_POST['status'] ?? 'vacant';
    $notes           = trim($_POST['notes'] ?? '');
    $status_filter   = $_POST['status_filter'] ?? '';

    $valid_statuses = ['vacant', 'occupied', 'maintenance'];

    if ($room_number === '' || $floor < 1 || $area_m2 <= 0 || $price_per_month <= 0
        || !in_array($status, $valid_statuses, true)) {
        $_SESSION['error'] = 'Dữ liệu không hợp lệ. Vui lòng kiểm tra lại các trường bắt buộc.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rooms (room_number, floor, area_m2, price_per_month, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$room_number, $floor, $area_m2, $price_per_month, $status, $notes ?: null]);
            ghi_log('ADD_ROOM', 'room_number=' . $room_number . ' floor=' . $floor . ' price=' . $price_per_month);
            $_SESSION['success'] = 'Đã thêm phòng ' . $room_number . ' thành công.';
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'ADD_ROOM ' . $e->getMessage());
            $_SESSION['error'] = ($e->getCode() === '23000')
                ? 'Mã phòng "' . htmlspecialchars($room_number) . '" đã tồn tại.'
                : 'Có lỗi xảy ra, vui lòng thử lại.';
        }
    }
    $qs = $status_filter !== '' ? '?status=' . urlencode($status_filter) : '';
    header('Location: rooms.php' . $qs);
    exit;
}

// ── POST: sửa phòng ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id              = (int)($_POST['id'] ?? 0);
    $room_number     = trim($_POST['room_number'] ?? '');
    $floor           = (int)($_POST['floor'] ?? 0);
    $area_m2         = (float)str_replace(',', '.', $_POST['area_m2'] ?? '0');
    $price_per_month = (int)str_replace([',', '.', ' '], '', $_POST['price_per_month'] ?? '0');
    $status          = $_POST['status'] ?? 'vacant';
    $notes           = trim($_POST['notes'] ?? '');
    $status_filter   = $_POST['status_filter'] ?? '';

    $valid_statuses = ['vacant', 'occupied', 'maintenance'];

    if ($id < 1 || $room_number === '' || $floor < 1 || $area_m2 <= 0 || $price_per_month <= 0
        || !in_array($status, $valid_statuses, true)) {
        $_SESSION['error'] = 'Dữ liệu không hợp lệ. Vui lòng kiểm tra lại các trường bắt buộc.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'UPDATE rooms SET room_number=?, floor=?, area_m2=?, price_per_month=?, status=?, notes=?
                 WHERE id=?'
            );
            $stmt->execute([$room_number, $floor, $area_m2, $price_per_month, $status, $notes ?: null, $id]);
            ghi_log('EDIT_ROOM', 'id=' . $id . ' room_number=' . $room_number . ' status=' . $status);
            $_SESSION['success'] = 'Đã cập nhật phòng ' . $room_number . ' thành công.';
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'EDIT_ROOM id=' . $id . ' ' . $e->getMessage());
            $_SESSION['error'] = ($e->getCode() === '23000')
                ? 'Mã phòng "' . htmlspecialchars($room_number) . '" đã tồn tại.'
                : 'Có lỗi xảy ra, vui lòng thử lại.';
        }
    }
    $qs = $status_filter !== '' ? '?status=' . urlencode($status_filter) : '';
    header('Location: rooms.php' . $qs);
    exit;
}

// ── GET: xóa phòng (chỉ khi vacant) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) {
        $_SESSION['error'] = 'ID phòng không hợp lệ.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT room_number, status FROM rooms WHERE id = ?');
            $stmt->execute([$id]);
            $room = $stmt->fetch();

            if (!$room) {
                $_SESSION['error'] = 'Phòng không tồn tại.';
            } elseif ($room['status'] !== 'vacant') {
                $_SESSION['error'] = 'Chỉ xóa được phòng đang trống. Phòng ' . $room['room_number'] . ' hiện có trạng thái "' . $room['status'] . '".';
            } else {
                $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ? AND status = ?');
                $stmt->execute([$id, 'vacant']);
                ghi_log('DELETE_ROOM', 'id=' . $id . ' room_number=' . $room['room_number']);
                $_SESSION['success'] = 'Đã xóa phòng ' . $room['room_number'] . '.';
            }
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'DELETE_ROOM id=' . $id . ' ' . $e->getMessage());
            $_SESSION['error'] = 'Không thể xóa phòng này do còn dữ liệu liên quan.';
        }
    }
    header('Location: rooms.php');
    exit;
}

// ── Lấy dữ liệu hiển thị ─────────────────────────────────────
$valid_statuses = ['vacant', 'occupied', 'maintenance'];
$filter_status  = in_array($_GET['status'] ?? '', $valid_statuses, true) ? $_GET['status'] : '';

// Nếu đang ở chế độ edit, load phòng cần sửa
$edit_room = null;
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $edit_room = $stmt->fetch() ?: null;
}

// Danh sách phòng (có filter)
if ($filter_status !== '') {
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE status = ? ORDER BY room_number');
    $stmt->execute([$filter_status]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM rooms ORDER BY room_number');
    $stmt->execute();
}
$rooms = $stmt->fetchAll();

ghi_log('VIEW_ROOMS', 'filter=' . ($filter_status ?: 'all') . ' count=' . count($rooms));

// Thống kê nhanh cho badge filter
$stmt_count = $pdo->prepare(
    "SELECT status, COUNT(*) AS cnt FROM rooms GROUP BY status"
);
$stmt_count->execute();
$counts = ['vacant' => 0, 'occupied' => 0, 'maintenance' => 0, 'all' => 0];
foreach ($stmt_count->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['cnt'];
    $counts['all'] += (int)$row['cnt'];
}

$status_label = ['vacant' => 'Trống', 'occupied' => 'Đang thuê', 'maintenance' => 'Bảo trì'];

include __DIR__ . '/../includes/header.php';
?>
<style>
.page-header   { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.2rem; }
.page-title    { font-size:1.4rem; font-weight:700; color:#1a1a2e; margin:0; }
.filter-tabs   { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
.filter-tab    { padding:.35rem .9rem; border-radius:20px; text-decoration:none; font-size:.85rem;
                 border:1px solid #ccc; color:#555; background:#fff; }
.filter-tab:hover, .filter-tab.active { background:#4a6fa5; color:#fff; border-color:#4a6fa5; }
.filter-count  { font-size:.75rem; background:rgba(255,255,255,.3); border-radius:10px;
                 padding:0 6px; margin-left:4px; }
.alert         { padding:.7rem 1rem; border-radius:6px; margin-bottom:1rem; font-size:.9rem; }
.alert-success { background:#e6f4ea; color:#2d6a4f; border:1px solid #b7ddc6; }
.alert-error   { background:#fde8e8; color:#c0392b; border:1px solid #f5c6c6; }
.card          { background:#fff; border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,.1); padding:1.2rem 1.5rem; margin-bottom:1.5rem; }
.card-title    { font-size:1rem; font-weight:600; margin:0 0 1rem; color:#333; }
table          { width:100%; border-collapse:collapse; font-size:.9rem; }
th             { background:#f5f6fa; text-align:left; padding:.6rem .9rem; color:#555; font-weight:600;
                 border-bottom:2px solid #e0e0e0; }
td             { padding:.6rem .9rem; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td    { background:#fafbff; }
.badge         { display:inline-block; padding:.2rem .65rem; border-radius:12px; font-size:.78rem; font-weight:600; }
.badge-vacant      { background:#e8f5e9; color:#2e7d32; }
.badge-occupied    { background:#e3f2fd; color:#1565c0; }
.badge-maintenance { background:#fff3e0; color:#e65100; }
.btn           { display:inline-block; padding:.3rem .75rem; border-radius:5px; font-size:.82rem;
                 text-decoration:none; cursor:pointer; border:none; }
.btn-edit      { background:#4a6fa5; color:#fff; }
.btn-edit:hover{ background:#3a5a8a; }
.btn-delete    { background:#e74c3c; color:#fff; }
.btn-delete:hover{ background:#c0392b; }
.btn-delete-disabled { background:#ccc; color:#888; cursor:not-allowed; }
.btn-primary   { background:#4a6fa5; color:#fff; padding:.5rem 1.2rem; font-size:.9rem; }
.btn-primary:hover { background:#3a5a8a; }
.btn-secondary { background:#6c757d; color:#fff; padding:.5rem 1rem; font-size:.9rem; }
.btn-secondary:hover { background:#5a6268; }
.form-grid     { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:.9rem; }
.form-group    { display:flex; flex-direction:column; gap:.3rem; }
.form-group label  { font-size:.85rem; font-weight:600; color:#444; }
.form-group input,
.form-group select,
.form-group textarea { padding:.5rem .7rem; border:1px solid #ccc; border-radius:5px; font-size:.9rem; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline:none; border-color:#4a6fa5; box-shadow:0 0 0 3px rgba(74,111,165,.12); }
.form-group-full { grid-column:1/-1; }
.form-actions  { grid-column:1/-1; display:flex; gap:.6rem; padding-top:.3rem; }
.price-cell    { font-weight:600; color:#1a1a2e; }
.empty-state   { text-align:center; padding:2rem; color:#888; font-style:italic; }
</style>

<div class="page-header">
    <h1 class="page-title">Quản Lý Phòng</h1>
</div>

<?php if ($flash_success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="filter-tabs">
    <a href="rooms.php" class="filter-tab <?= $filter_status === '' ? 'active' : '' ?>">
        Tất cả <span class="filter-count"><?= $counts['all'] ?></span>
    </a>
    <a href="rooms.php?status=occupied" class="filter-tab <?= $filter_status === 'occupied' ? 'active' : '' ?>">
        Đang thuê <span class="filter-count"><?= $counts['occupied'] ?></span>
    </a>
    <a href="rooms.php?status=vacant" class="filter-tab <?= $filter_status === 'vacant' ? 'active' : '' ?>">
        Trống <span class="filter-count"><?= $counts['vacant'] ?></span>
    </a>
    <a href="rooms.php?status=maintenance" class="filter-tab <?= $filter_status === 'maintenance' ? 'active' : '' ?>">
        Bảo trì <span class="filter-count"><?= $counts['maintenance'] ?></span>
    </a>
</div>

<!-- Bảng danh sách phòng -->
<div class="card">
    <p class="card-title">
        Danh sách phòng
        <?= $filter_status !== '' ? '— ' . $status_label[$filter_status] : '' ?>
        (<?= count($rooms) ?> phòng)
    </p>
    <?php if (empty($rooms)): ?>
        <p class="empty-state">Không có phòng nào phù hợp với bộ lọc.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Mã phòng</th>
                <th>Tầng</th>
                <th>Diện tích</th>
                <th>Giá thuê / tháng</th>
                <th>Trạng thái</th>
                <th>Ghi chú</th>
                <th style="width:120px">Thao tác</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rooms as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['room_number']) ?></strong></td>
                <td><?= (int)$r['floor'] ?></td>
                <td><?= number_format((float)$r['area_m2'], 1) ?> m²</td>
                <td class="price-cell"><?= number_format((int)$r['price_per_month']) ?> đ</td>
                <td>
                    <span class="badge badge-<?= $r['status'] ?>">
                        <?= $status_label[$r['status']] ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($r['notes'] ?? '—') ?></td>
                <td style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="rooms.php?action=edit&id=<?= $r['id'] ?><?= $filter_status !== '' ? '&status=' . urlencode($filter_status) : '' ?>"
                       class="btn btn-edit">Sửa</a>
                    <?php if ($r['status'] === 'vacant'): ?>
                        <a href="rooms.php?action=delete&id=<?= $r['id'] ?>"
                           class="btn btn-delete"
                           onclick="return confirm('Xóa phòng <?= htmlspecialchars($r['room_number'], ENT_QUOTES) ?>? Hành động này không thể hoàn tác.')">
                            Xóa
                        </a>
                    <?php else: ?>
                        <span class="btn btn-delete-disabled" title="Chỉ xóa được phòng đang trống">Xóa</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Form thêm / sửa phòng -->
<div class="card">
    <?php if ($edit_room): ?>
        <!-- Chế độ sửa -->
        <p class="card-title">Chỉnh Sửa Phòng — <?= htmlspecialchars($edit_room['room_number']) ?></p>
        <form method="POST" action="rooms.php">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id"     value="<?= (int)$edit_room['id'] ?>">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($filter_status) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="edit_room_number">Mã phòng <span style="color:red">*</span></label>
                    <input type="text" id="edit_room_number" name="room_number"
                           value="<?= htmlspecialchars($edit_room['room_number']) ?>"
                           maxlength="10" required>
                </div>
                <div class="form-group">
                    <label for="edit_floor">Tầng <span style="color:red">*</span></label>
                    <input type="number" id="edit_floor" name="floor" min="1" max="50"
                           value="<?= (int)$edit_room['floor'] ?>" required>
                </div>
                <div class="form-group">
                    <label for="edit_area">Diện tích (m²) <span style="color:red">*</span></label>
                    <input type="number" id="edit_area" name="area_m2" min="1" max="999" step="0.1"
                           value="<?= number_format((float)$edit_room['area_m2'], 1, '.', '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="edit_price">Giá thuê (đồng/tháng) <span style="color:red">*</span></label>
                    <input type="number" id="edit_price" name="price_per_month" min="100000" step="50000"
                           value="<?= (int)$edit_room['price_per_month'] ?>" required>
                </div>
                <div class="form-group">
                    <label for="edit_status">Trạng thái <span style="color:red">*</span></label>
                    <select id="edit_status" name="status">
                        <option value="vacant"      <?= $edit_room['status'] === 'vacant'      ? 'selected' : '' ?>>Trống</option>
                        <option value="occupied"    <?= $edit_room['status'] === 'occupied'    ? 'selected' : '' ?>>Đang thuê</option>
                        <option value="maintenance" <?= $edit_room['status'] === 'maintenance' ? 'selected' : '' ?>>Bảo trì</option>
                    </select>
                </div>
                <div class="form-group form-group-full">
                    <label for="edit_notes">Ghi chú</label>
                    <textarea id="edit_notes" name="notes" rows="2" maxlength="500"><?= htmlspecialchars($edit_room['notes'] ?? '') ?></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                    <a href="rooms.php<?= $filter_status !== '' ? '?status=' . urlencode($filter_status) : '' ?>"
                       class="btn btn-secondary">Hủy</a>
                </div>
            </div>
        </form>
    <?php else: ?>
        <!-- Chế độ thêm mới -->
        <p class="card-title">Thêm Phòng Mới</p>
        <form method="POST" action="rooms.php">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($filter_status) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="room_number">Mã phòng <span style="color:red">*</span></label>
                    <input type="text" id="room_number" name="room_number"
                           placeholder="VD: A01, B02" maxlength="10" required>
                </div>
                <div class="form-group">
                    <label for="floor">Tầng <span style="color:red">*</span></label>
                    <input type="number" id="floor" name="floor" min="1" max="50" placeholder="1" required>
                </div>
                <div class="form-group">
                    <label for="area_m2">Diện tích (m²) <span style="color:red">*</span></label>
                    <input type="number" id="area_m2" name="area_m2" min="1" max="999" step="0.1"
                           placeholder="20.0" required>
                </div>
                <div class="form-group">
                    <label for="price_per_month">Giá thuê (đồng/tháng) <span style="color:red">*</span></label>
                    <input type="number" id="price_per_month" name="price_per_month"
                           min="100000" step="50000" placeholder="2500000" required>
                </div>
                <div class="form-group">
                    <label for="status">Trạng thái <span style="color:red">*</span></label>
                    <select id="status" name="status">
                        <option value="vacant">Trống</option>
                        <option value="occupied">Đang thuê</option>
                        <option value="maintenance">Bảo trì</option>
                    </select>
                </div>
                <div class="form-group form-group-full">
                    <label for="notes">Ghi chú</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="500" placeholder="Mô tả thêm về phòng..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Thêm phòng</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>