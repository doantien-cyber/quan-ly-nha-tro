<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

require_login();

// ── Flash messages ─────────────────────────────────────────────
$flash_success = $_SESSION['success'] ?? '';
$flash_error   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── POST: tạo hợp đồng mới ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $room_id      = (int)($_POST['room_id']   ?? 0);
    $tenant_id    = (int)($_POST['tenant_id'] ?? 0);
    $start_date   = trim($_POST['start_date'] ?? '');
    $end_date     = trim($_POST['end_date']   ?? '');
    $deposit      = (int)str_replace([',', ' '], '', $_POST['deposit'] ?? '0');
    $status_filter = $_POST['status_filter'] ?? '';

    // Validate cơ bản
    $err = '';
    if ($room_id < 1 || $tenant_id < 1) {
        $err = 'Vui lòng chọn phòng và khách thuê.';
    } elseif ($start_date === '' || $end_date === '') {
        $err = 'Vui lòng nhập ngày bắt đầu và ngày kết thúc.';
    } elseif ($start_date >= $end_date) {
        $err = 'Ngày kết thúc phải sau ngày bắt đầu.';
    } elseif ($deposit < 0) {
        $err = 'Tiền cọc không hợp lệ.';
    }

    if ($err !== '') {
        $_SESSION['error'] = $err;
    } else {
        try {
            // Kiểm tra phòng đã có contract active chưa
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM contracts WHERE room_id = ? AND status = 'active'"
            );
            $stmt->execute([$room_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                $_SESSION['error'] = 'Phòng này đã có hợp đồng đang active. Không thể tạo thêm.';
            } else {
                // Kiểm tra phòng thực sự còn trống
                $stmt = $pdo->prepare("SELECT room_number, status FROM rooms WHERE id = ?");
                $stmt->execute([$room_id]);
                $room = $stmt->fetch();

                if (!$room || $room['status'] !== 'vacant') {
                    $_SESSION['error'] = 'Phòng đã chọn không còn ở trạng thái trống.';
                } else {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        "INSERT INTO contracts (room_id, tenant_id, start_date, end_date, deposit, status)
                         VALUES (?, ?, ?, ?, ?, 'active')"
                    );
                    $stmt->execute([$room_id, $tenant_id, $start_date, $end_date, $deposit]);
                    $new_id = $pdo->lastInsertId();

                    $stmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
                    $stmt->execute([$room_id]);

                    $pdo->commit();

                    ghi_log('CREATE_CONTRACT',
                        'id=' . $new_id . ' room_id=' . $room_id . ' tenant_id=' . $tenant_id
                        . ' start=' . $start_date . ' end=' . $end_date
                    );
                    $_SESSION['success'] = 'Đã tạo hợp đồng cho phòng ' . $room['room_number'] . ' thành công.';
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            ghi_log('EXCEPTION', 'CREATE_CONTRACT ' . $e->getMessage());
            $_SESSION['error'] = 'Có lỗi xảy ra, vui lòng thử lại.';
        }
    }
    $qs = $status_filter !== '' ? '?status=' . urlencode($status_filter) : '';
    header('Location: contracts.php' . $qs);
    exit;
}

// ── GET: kết thúc hợp đồng (admin only) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'terminate') {
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) {
        $_SESSION['error'] = 'ID hợp đồng không hợp lệ.';
    } else {
        try {
            // Lấy thông tin hợp đồng kèm tên phòng
            $stmt = $pdo->prepare(
                "SELECT c.id, c.room_id, c.status, r.room_number
                 FROM contracts c
                 JOIN rooms r ON r.id = c.room_id
                 WHERE c.id = ?"
            );
            $stmt->execute([$id]);
            $contract = $stmt->fetch();

            if (!$contract) {
                $_SESSION['error'] = 'Hợp đồng không tồn tại.';
            } elseif ($contract['status'] !== 'active') {
                $_SESSION['error'] = 'Chỉ có thể kết thúc hợp đồng đang active.';
            } else {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE contracts SET status = 'terminated' WHERE id = ?");
                $stmt->execute([$id]);

                $stmt = $pdo->prepare("UPDATE rooms SET status = 'vacant' WHERE id = ?");
                $stmt->execute([$contract['room_id']]);

                $pdo->commit();

                ghi_log('TERMINATE_CONTRACT',
                    'id=' . $id . ' room_id=' . $contract['room_id']
                    . ' room_number=' . $contract['room_number']
                );
                $_SESSION['success'] = 'Đã kết thúc hợp đồng phòng ' . $contract['room_number'] . '. Phòng đã về trạng thái trống.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            ghi_log('EXCEPTION', 'TERMINATE_CONTRACT id=' . $id . ' ' . $e->getMessage());
            $_SESSION['error'] = 'Có lỗi xảy ra khi kết thúc hợp đồng.';
        }
    }
    header('Location: contracts.php');
    exit;
}

// ── Lấy dữ liệu hiển thị ──────────────────────────────────────
$valid_statuses = ['active', 'expired', 'terminated'];
$filter_status  = in_array($_GET['status'] ?? '', $valid_statuses, true) ? $_GET['status'] : '';

// Danh sách hợp đồng (JOIN rooms + tenants)
if ($filter_status !== '') {
    $stmt = $pdo->prepare(
        "SELECT c.*,
                r.room_number, r.price_per_month,
                t.full_name AS tenant_name, t.phone AS tenant_phone
         FROM contracts c
         JOIN rooms r   ON r.id = c.room_id
         JOIN tenants t ON t.id = c.tenant_id
         WHERE c.status = ?
         ORDER BY c.id DESC"
    );
    $stmt->execute([$filter_status]);
} else {
    $stmt = $pdo->prepare(
        "SELECT c.*,
                r.room_number, r.price_per_month,
                t.full_name AS tenant_name, t.phone AS tenant_phone
         FROM contracts c
         JOIN rooms r   ON r.id = c.room_id
         JOIN tenants t ON t.id = c.tenant_id
         ORDER BY c.id DESC"
    );
    $stmt->execute();
}
$contracts = $stmt->fetchAll();

// Đếm theo trạng thái cho filter tabs
$stmt_cnt = $pdo->prepare(
    "SELECT status, COUNT(*) AS cnt FROM contracts GROUP BY status"
);
$stmt_cnt->execute();
$counts = ['active' => 0, 'expired' => 0, 'terminated' => 0, 'all' => 0];
foreach ($stmt_cnt->fetchAll() as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['cnt'];
    }
    $counts['all'] += (int)$row['cnt'];
}

// Phòng đang trống (cho form tạo hợp đồng)
$stmt = $pdo->prepare(
    "SELECT id, room_number, floor, price_per_month
     FROM rooms WHERE status = 'vacant'
     ORDER BY room_number"
);
$stmt->execute();
$vacant_rooms = $stmt->fetchAll();

// Tất cả khách thuê (cho form tạo hợp đồng)
$stmt = $pdo->prepare(
    "SELECT id, full_name, id_card FROM tenants ORDER BY full_name"
);
$stmt->execute();
$all_tenants = $stmt->fetchAll();

ghi_log('VIEW_CONTRACTS', 'filter=' . ($filter_status ?: 'all') . ' count=' . count($contracts));

$status_label = [
    'active'     => 'Đang hiệu lực',
    'expired'    => 'Hết hạn',
    'terminated' => 'Đã chấm dứt',
];
$status_badge = [
    'active'     => 'badge-active',
    'expired'    => 'badge-expired',
    'terminated' => 'badge-terminated',
];

include __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Quản Lý Hợp Đồng</h1>

<?php if ($flash_success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="filter-tabs">
    <a href="contracts.php" class="filter-tab <?= $filter_status === '' ? 'active' : '' ?>">
        Tất cả <span class="filter-count"><?= $counts['all'] ?></span>
    </a>
    <a href="contracts.php?status=active" class="filter-tab <?= $filter_status === 'active' ? 'active' : '' ?>">
        Đang hiệu lực <span class="filter-count"><?= $counts['active'] ?></span>
    </a>
    <a href="contracts.php?status=expired" class="filter-tab <?= $filter_status === 'expired' ? 'active' : '' ?>">
        Hết hạn <span class="filter-count"><?= $counts['expired'] ?></span>
    </a>
    <a href="contracts.php?status=terminated" class="filter-tab <?= $filter_status === 'terminated' ? 'active' : '' ?>">
        Đã chấm dứt <span class="filter-count"><?= $counts['terminated'] ?></span>
    </a>
</div>

<!-- Bảng danh sách hợp đồng -->
<div class="card">
    <p class="card-title">
        Danh sách hợp đồng
        <?= $filter_status !== '' ? '— ' . $status_label[$filter_status] : '' ?>
        (<?= count($contracts) ?> hợp đồng)
    </p>
    <?php if (empty($contracts)): ?>
        <p class="empty-state">Không có hợp đồng nào<?= $filter_status !== '' ? ' ở trạng thái này' : '' ?>.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Phòng</th>
                <th>Giá thuê</th>
                <th>Khách thuê</th>
                <th>SĐT</th>
                <th>Ngày bắt đầu</th>
                <th>Ngày kết thúc</th>
                <th>Tiền cọc</th>
                <th>Trạng thái</th>
                <th>Thao tác</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($contracts as $c): ?>
            <tr>
                <td><?= (int)$c['id'] ?></td>
                <td><strong><?= htmlspecialchars($c['room_number']) ?></strong></td>
                <td><?= number_format((int)$c['price_per_month']) ?> đ</td>
                <td><?= htmlspecialchars($c['tenant_name']) ?></td>
                <td><?= htmlspecialchars($c['tenant_phone']) ?></td>
                <td><?= htmlspecialchars($c['start_date']) ?></td>
                <td>
                    <?php
                    // Làm nổi bật nếu hợp đồng sắp hết hạn (trong vòng 30 ngày)
                    $days_left = (int)ceil((strtotime($c['end_date']) - time()) / 86400);
                    $expiring  = $c['status'] === 'active' && $days_left <= 30 && $days_left >= 0;
                    ?>
                    <span <?= $expiring ? 'style="color:#e67e22;font-weight:600"' : '' ?>>
                        <?= htmlspecialchars($c['end_date']) ?>
                        <?= $expiring ? '<small>(còn ' . $days_left . ' ngày)</small>' : '' ?>
                    </span>
                </td>
                <td class="deposit-cell"><?= number_format((int)$c['deposit']) ?> đ</td>
                <td>
                    <span class="badge <?= $status_badge[$c['status']] ?>">
                        <?= $status_label[$c['status']] ?>
                    </span>
                </td>
                <td>
                    <?php if ($c['status'] === 'active' && is_admin()): ?>
                        <a href="contracts.php?action=terminate&id=<?= $c['id'] ?>"
                           class="btn btn-terminate"
                           onclick="return confirm('Kết thúc hợp đồng phòng <?= htmlspecialchars($c['room_number'], ENT_QUOTES) ?> của <?= htmlspecialchars($c['tenant_name'], ENT_QUOTES) ?>?\nPhòng sẽ được đặt lại về trạng thái Trống.')">
                            Kết thúc
                        </a>
                    <?php else: ?>
                        <span style="color:#aaa;font-size:.82rem">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Form tạo hợp đồng mới -->
<div class="card">
    <p class="card-title">Tạo Hợp Đồng Mới</p>

    <?php if (empty($vacant_rooms)): ?>
        <p class="no-vacant">
            Hiện không có phòng nào đang trống. Vui lòng kiểm tra lại danh sách phòng.
        </p>
    <?php elseif (empty($all_tenants)): ?>
        <p class="no-vacant">
            Chưa có khách thuê nào trong hệ thống. Vui lòng thêm khách thuê trước.
        </p>
    <?php else: ?>
    <form method="POST" action="contracts.php">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($filter_status) ?>">
        <div class="form-grid">

            <div class="form-group">
                <label for="room_id">Phòng (đang trống) <span style="color:red">*</span></label>
                <select id="room_id" name="room_id" required>
                    <option value="">— Chọn phòng —</option>
                    <?php foreach ($vacant_rooms as $r): ?>
                        <option value="<?= $r['id'] ?>">
                            <?= htmlspecialchars($r['room_number']) ?>
                            — T<?= $r['floor'] ?>
                            — <?= number_format((int)$r['price_per_month']) ?> đ/tháng
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="hint"><?= count($vacant_rooms) ?> phòng trống</span>
            </div>

            <div class="form-group">
                <label for="tenant_id">Khách thuê <span style="color:red">*</span></label>
                <select id="tenant_id" name="tenant_id" required>
                    <option value="">— Chọn khách thuê —</option>
                    <?php foreach ($all_tenants as $t): ?>
                        <option value="<?= $t['id'] ?>">
                            <?= htmlspecialchars($t['full_name']) ?>
                            (<?= htmlspecialchars($t['id_card']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="start_date">Ngày bắt đầu <span style="color:red">*</span></label>
                <input type="date" id="start_date" name="start_date"
                       value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label for="end_date">Ngày kết thúc <span style="color:red">*</span></label>
                <input type="date" id="end_date" name="end_date"
                       value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
            </div>

            <div class="form-group">
                <label for="deposit">Tiền cọc (đồng) <span style="color:red">*</span></label>
                <input type="number" id="deposit" name="deposit"
                       min="0" step="100000" placeholder="2500000" required>
                <span class="hint">Thường bằng 1–2 tháng tiền thuê</span>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Tạo hợp đồng</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>