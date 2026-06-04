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

// ── POST: tạo hóa đơn hàng loạt theo tháng ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_batch') {
    $month_input = trim($_POST['month_year'] ?? '');
    $month_date  = '';
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month_input)) {
        $month_date = $month_input . '-01';
    }

    if ($month_date === '') {
        $_SESSION['error'] = 'Tháng / năm không hợp lệ.';
    } else {
        try {
            // Lấy tất cả hợp đồng active kèm thông tin phòng
            $stmt = $pdo->prepare(
                "SELECT c.id AS contract_id, c.room_id,
                        r.room_number, r.price_per_month
                 FROM contracts c
                 JOIN rooms r ON r.id = c.room_id
                 WHERE c.status = 'active'
                 ORDER BY r.room_number"
            );
            $stmt->execute();
            $active_contracts = $stmt->fetchAll();

            $created = 0;
            $skipped = 0;

            $pdo->beginTransaction();

            foreach ($active_contracts as $ct) {
                // Bỏ qua nếu hóa đơn tháng này đã tồn tại
                $chk = $pdo->prepare(
                    "SELECT COUNT(*) FROM invoices WHERE contract_id = ? AND month_year = ?"
                );
                $chk->execute([$ct['contract_id'], $month_date]);
                if ((int)$chk->fetchColumn() > 0) {
                    $skipped++;
                    continue;
                }

                // Lấy chỉ số điện nước (có thể NULL)
                $urq = $pdo->prepare(
                    "SELECT id, electric_prev, electric_curr,
                            water_prev, water_curr,
                            electric_rate, water_rate
                     FROM utility_readings
                     WHERE room_id = ? AND month_year = ?"
                );
                $urq->execute([$ct['room_id'], $month_date]);
                $reading = $urq->fetch();

                $room_fee     = (int)$ct['price_per_month'];
                $reading_id   = null;
                $electric_fee = 0;
                $water_fee    = 0;

                if ($reading) {
                    $reading_id   = (int)$reading['id'];
                    $electric_fee = (int)(
                        ($reading['electric_curr'] - $reading['electric_prev']) * $reading['electric_rate']
                    );
                    $water_fee = (int)(
                        ($reading['water_curr'] - $reading['water_prev']) * $reading['water_rate']
                    );
                }

                $total = $room_fee + $electric_fee + $water_fee;

                $ins = $pdo->prepare(
                    "INSERT INTO invoices
                       (contract_id, reading_id, month_year,
                        room_fee, electric_fee, water_fee, total_amount, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'unpaid')"
                );
                $ins->execute([
                    $ct['contract_id'], $reading_id, $month_date,
                    $room_fee, $electric_fee, $water_fee, $total,
                ]);

                ghi_log('CREATE_INVOICE',
                    'contract_id=' . $ct['contract_id']
                    . ' room=' . $ct['room_number']
                    . ' month=' . $month_date
                    . ' total=' . $total
                    . ($reading_id ? ' reading_id=' . $reading_id : ' no_reading')
                );
                $created++;
            }

            $pdo->commit();

            if ($created === 0 && $skipped === 0) {
                $_SESSION['error'] = 'Không có hợp đồng active nào để tạo hóa đơn.';
            } else {
                $msg = 'Đã tạo ' . $created . ' hóa đơn tháng ' . date('m/Y', strtotime($month_date)) . '.';
                if ($skipped > 0) {
                    $msg .= ' Bỏ qua ' . $skipped . ' hóa đơn đã tồn tại.';
                }
                if ($created > 0 && $skipped === count($active_contracts)) {
                    $msg = 'Tất cả hóa đơn tháng ' . date('m/Y', strtotime($month_date)) . ' đã tồn tại.';
                }
                $_SESSION['success'] = $msg;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            ghi_log('EXCEPTION', 'CREATE_INVOICE_BATCH ' . $e->getMessage());
            $_SESSION['error'] = 'Có lỗi xảy ra khi tạo hóa đơn.';
        }
    }
    header('Location: invoices.php?month=' . urlencode($month_input));
    exit;
}

// ── GET: đánh dấu đã thanh toán ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'mark_paid') {
    $id           = (int)($_GET['id']            ?? 0);
    $ref_status   = trim($_GET['filter_status']  ?? '');
    $ref_month    = trim($_GET['filter_month']   ?? '');

    if ($id < 1) {
        $_SESSION['error'] = 'ID hóa đơn không hợp lệ.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, status FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch();

            if (!$inv) {
                $_SESSION['error'] = 'Hóa đơn không tồn tại.';
            } elseif ($inv['status'] === 'paid') {
                $_SESSION['error'] = 'Hóa đơn #' . $id . ' đã được thanh toán trước đó.';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ?"
                );
                $stmt->execute([$id]);
                ghi_log('MARK_PAID', 'invoice_id=' . $id);
                $_SESSION['success'] = 'Đã đánh dấu thanh toán cho hóa đơn #' . $id . '.';
            }
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'MARK_PAID id=' . $id . ' ' . $e->getMessage());
            $_SESSION['error'] = 'Có lỗi xảy ra, vui lòng thử lại.';
        }
    }

    // Trả về đúng filter cũ
    $qs_parts = [];
    if (in_array($ref_status, ['unpaid', 'paid'], true)) {
        $qs_parts[] = 'status=' . $ref_status;
    }
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ref_month)) {
        $qs_parts[] = 'month=' . $ref_month;
    }
    header('Location: invoices.php' . ($qs_parts ? '?' . implode('&', $qs_parts) : ''));
    exit;
}

// ── Lấy chi tiết 1 hóa đơn ────────────────────────────────────
$detail = null;
if (($_GET['action'] ?? '') === 'detail' && isset($_GET['id'])) {
    $stmt = $pdo->prepare(
        "SELECT i.*,
                r.room_number, r.floor, r.area_m2, r.price_per_month,
                t.full_name AS tenant_name, t.phone AS tenant_phone,
                t.id_card, t.email AS tenant_email,
                c.start_date AS contract_start, c.end_date AS contract_end, c.deposit,
                u.electric_prev, u.electric_curr,
                u.water_prev,    u.water_curr,
                u.electric_rate, u.water_rate
         FROM invoices i
         JOIN contracts c ON c.id = i.contract_id
         JOIN rooms r     ON r.id = c.room_id
         JOIN tenants t   ON t.id = c.tenant_id
         LEFT JOIN utility_readings u ON u.id = i.reading_id
         WHERE i.id = ?"
    );
    $stmt->execute([(int)$_GET['id']]);
    $detail = $stmt->fetch() ?: null;
}

// ── Filter + danh sách hóa đơn ────────────────────────────────
$valid_statuses = ['unpaid', 'paid'];
$filter_status  = in_array($_GET['status'] ?? '', $valid_statuses, true) ? $_GET['status'] : '';
$filter_month   = '';
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim($_GET['month'] ?? ''))) {
    $filter_month = trim($_GET['month']);
}

// Xây dựng WHERE động
$where_parts = [];
$params      = [];

if ($filter_status !== '') {
    $where_parts[] = 'i.status = ?';
    $params[]      = $filter_status;
}
if ($filter_month !== '') {
    $where_parts[] = 'i.month_year = ?';
    $params[]      = $filter_month . '-01';
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$stmt = $pdo->prepare(
    "SELECT i.*,
            r.room_number,
            t.full_name AS tenant_name
     FROM invoices i
     JOIN contracts c ON c.id = i.contract_id
     JOIN rooms r     ON r.id = c.room_id
     JOIN tenants t   ON t.id = c.tenant_id
     $where_sql
     ORDER BY i.month_year DESC, r.room_number ASC"
);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Thống kê tổng hợp cho bộ lọc hiện tại
$stmt_sum = $pdo->prepare(
    "SELECT
        COUNT(*)                                     AS total_count,
        SUM(i.total_amount)                          AS total_amount,
        SUM(CASE WHEN i.status='paid'   THEN i.total_amount ELSE 0 END) AS paid_amount,
        SUM(CASE WHEN i.status='unpaid' THEN i.total_amount ELSE 0 END) AS unpaid_amount,
        SUM(CASE WHEN i.status='paid'   THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN i.status='unpaid' THEN 1 ELSE 0 END) AS unpaid_count
     FROM invoices i
     JOIN contracts c ON c.id = i.contract_id
     JOIN rooms r     ON r.id = c.room_id
     JOIN tenants t   ON t.id = c.tenant_id
     $where_sql"
);
$stmt_sum->execute($params);
$summary = $stmt_sum->fetch();

// Danh sách các tháng đã có hóa đơn (cho dropdown filter)
$stmt_months = $pdo->prepare(
    "SELECT DISTINCT DATE_FORMAT(month_year, '%Y-%m') AS ym,
                    DATE_FORMAT(month_year, '%m/%Y')  AS label
     FROM invoices
     ORDER BY month_year DESC"
);
$stmt_months->execute();
$available_months = $stmt_months->fetchAll();

ghi_log('VIEW_INVOICES',
    'filter_status=' . ($filter_status ?: 'all')
    . ' filter_month=' . ($filter_month ?: 'all')
    . ' count=' . count($invoices)
);

$status_label = ['unpaid' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán'];
$status_badge = ['unpaid' => 'badge-unpaid',     'paid' => 'badge-paid'];

include __DIR__ . '/../includes/header.php';
?>

<h1 class="page-title">Hóa Đơn Thanh Toán</h1>

<?php if ($flash_success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Thanh filter -->
<div class="toolbar">
    <div class="filter-tabs">
        <?php
        $base_qs = $filter_month !== '' ? '?month=' . $filter_month : '?';
        $sep     = $filter_month !== '' ? '&' : '';
        ?>
        <a href="invoices.php<?= $filter_month !== '' ? '?month=' . $filter_month : '' ?>"
           class="filter-tab <?= $filter_status === '' ? 'active' : '' ?>">
            Tất cả <span class="filter-count"><?= (int)$summary['total_count'] ?></span>
        </a>
        <a href="invoices.php?status=unpaid<?= $filter_month !== '' ? '&month=' . $filter_month : '' ?>"
           class="filter-tab <?= $filter_status === 'unpaid' ? 'active' : '' ?>">
            Chưa thanh toán <span class="filter-count"><?= (int)$summary['unpaid_count'] ?></span>
        </a>
        <a href="invoices.php?status=paid<?= $filter_month !== '' ? '&month=' . $filter_month : '' ?>"
           class="filter-tab <?= $filter_status === 'paid' ? 'active' : '' ?>">
            Đã thanh toán <span class="filter-count"><?= (int)$summary['paid_count'] ?></span>
        </a>
    </div>

    <!-- Filter tháng -->
    <form method="GET" action="invoices.php" style="display:flex;gap:.5rem;align-items:center;">
        <?php if ($filter_status !== ''): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
        <?php endif; ?>
        <select name="month" class="month-select"
                onchange="this.form.submit()">
            <option value="">— Tất cả các tháng —</option>
            <?php foreach ($available_months as $m): ?>
                <option value="<?= htmlspecialchars($m['ym']) ?>"
                        <?= $filter_month === $m['ym'] ? 'selected' : '' ?>>
                    Tháng <?= htmlspecialchars($m['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($filter_status !== '' || $filter_month !== ''): ?>
        <a href="invoices.php" class="btn-clear">✕ Xóa bộ lọc</a>
    <?php endif; ?>
</div>

<!-- Summary bar -->
<div class="summary-bar">
    <div class="sum-card">
        <div class="sum-label">Tổng hóa đơn</div>
        <div class="sum-value"><?= number_format((int)$summary['total_amount']) ?> đ</div>
    </div>
    <div class="sum-card">
        <div class="sum-label">Đã thu (<?= (int)$summary['paid_count'] ?> HĐ)</div>
        <div class="sum-value green"><?= number_format((int)$summary['paid_amount']) ?> đ</div>
    </div>
    <div class="sum-card">
        <div class="sum-label">Chưa thu (<?= (int)$summary['unpaid_count'] ?> HĐ)</div>
        <div class="sum-value orange"><?= number_format((int)$summary['unpaid_amount']) ?> đ</div>
    </div>
</div>

<!-- Chi tiết hóa đơn (hiện khi ?action=detail) -->
<?php if ($detail): ?>
<div class="detail-card" id="detailPanel">
    <a href="invoices.php<?= ($filter_status || $filter_month) ? '?' . http_build_query(array_filter(['status' => $filter_status, 'month' => $filter_month])) : '' ?>"
       class="close-detail">✕ Đóng</a>
    <p class="detail-title">
        Chi tiết hóa đơn #<?= (int)$detail['id'] ?>
        — Tháng <?= date('m/Y', strtotime($detail['month_year'])) ?>
        &nbsp;<span class="badge <?= $status_badge[$detail['status']] ?>">
            <?= $status_label[$detail['status']] ?>
        </span>
    </p>

    <div class="detail-grid">
        <div>
            <div class="detail-row">
                <span class="dl-label">Phòng</span>
                <span class="dl-value"><?= htmlspecialchars($detail['room_number']) ?></span>
            </div>
            <div class="detail-row">
                <span class="dl-label">Tầng / Diện tích</span>
                <span class="dl-value">Tầng <?= (int)$detail['floor'] ?> — <?= number_format((float)$detail['area_m2'], 1) ?> m²</span>
            </div>
            <div class="detail-row">
                <span class="dl-label">Khách thuê</span>
                <span class="dl-value"><?= htmlspecialchars($detail['tenant_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="dl-label">CMND / CCCD</span>
                <span class="dl-value"><?= htmlspecialchars($detail['id_card']) ?></span>
            </div>
            <div class="detail-row">
                <span class="dl-label">Số điện thoại</span>
                <span class="dl-value"><?= htmlspecialchars($detail['tenant_phone']) ?></span>
            </div>
        </div>
        <div>
            <div class="detail-row">
                <span class="dl-label">Hợp đồng</span>
                <span class="dl-value"><?= htmlspecialchars($detail['contract_start']) ?> → <?= htmlspecialchars($detail['contract_end']) ?></span>
            </div>
            <div class="detail-row">
                <span class="dl-label">Tiền cọc</span>
                <span class="dl-value"><?= number_format((int)$detail['deposit']) ?> đ</span>
            </div>
            <?php if ($detail['status'] === 'paid' && $detail['paid_at']): ?>
            <div class="detail-row">
                <span class="dl-label">Ngày thanh toán</span>
                <span class="dl-value" style="color:#2d6a4f"><?= htmlspecialchars($detail['paid_at']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Breakdown phí -->
    <div class="fee-breakdown">
        <div class="fee-row">
            <span>Tiền thuê phòng</span>
            <span><strong><?= number_format((int)$detail['room_fee']) ?> đ</strong></span>
        </div>
        <?php if ($detail['electric_prev'] !== null): ?>
        <div class="fee-row">
            <span>
                Điện: <?= (int)$detail['electric_prev'] ?> → <?= (int)$detail['electric_curr'] ?> kWh
                (<?= (int)$detail['electric_curr'] - (int)$detail['electric_prev'] ?> kWh
                × <?= number_format((int)$detail['electric_rate']) ?> đ)
            </span>
            <span><strong><?= number_format((int)$detail['electric_fee']) ?> đ</strong></span>
        </div>
        <div class="fee-row">
            <span>
                Nước: <?= (int)$detail['water_prev'] ?> → <?= (int)$detail['water_curr'] ?> m³
                (<?= (int)$detail['water_curr'] - (int)$detail['water_prev'] ?> m³
                × <?= number_format((int)$detail['water_rate']) ?> đ)
            </span>
            <span><strong><?= number_format((int)$detail['water_fee']) ?> đ</strong></span>
        </div>
        <?php else: ?>
        <div class="fee-row" style="color:#888;font-style:italic;">
            <span>Điện / Nước</span>
            <span>Chưa có chỉ số — 0 đ</span>
        </div>
        <?php endif; ?>
        <div class="fee-row fee-total">
            <span>Tổng cộng</span>
            <span><?= number_format((int)$detail['total_amount']) ?> đ</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Danh sách hóa đơn -->
<div class="card">
    <p class="card-title">
        Danh sách hóa đơn (<?= count($invoices) ?> hóa đơn)
        <?= $filter_month !== '' ? '— Tháng ' . date('m/Y', strtotime($filter_month . '-01')) : '' ?>
    </p>
    <?php if (empty($invoices)): ?>
        <p class="empty-state">Không có hóa đơn nào phù hợp với bộ lọc.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Tháng</th>
                <th>Phòng</th>
                <th>Khách thuê</th>
                <th class="num">Tiền phòng</th>
                <th class="num">Tiền điện</th>
                <th class="num">Tiền nước</th>
                <th class="num">Tổng cộng</th>
                <th>Trạng thái</th>
                <th>Thao tác</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
            <tr>
                <td><?= (int)$inv['id'] ?></td>
                <td><?= date('m/Y', strtotime($inv['month_year'])) ?></td>
                <td><strong><?= htmlspecialchars($inv['room_number']) ?></strong></td>
                <td><?= htmlspecialchars($inv['tenant_name']) ?></td>
                <td class="num"><?= number_format((int)$inv['room_fee']) ?> đ</td>
                <td class="num"><?= number_format((int)$inv['electric_fee']) ?> đ</td>
                <td class="num"><?= number_format((int)$inv['water_fee']) ?> đ</td>
                <td class="total-cell"><?= number_format((int)$inv['total_amount']) ?> đ</td>
                <td>
                    <span class="badge <?= $status_badge[$inv['status']] ?>">
                        <?= $status_label[$inv['status']] ?>
                    </span>
                    <?php if ($inv['status'] === 'paid' && $inv['paid_at']): ?>
                        <div class="paid-at"><?= date('d/m/Y', strtotime($inv['paid_at'])) ?></div>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:5px;flex-wrap:wrap;">
                    <?php
                    $detail_qs = '?action=detail&id=' . $inv['id'];
                    if ($filter_status !== '') { $detail_qs .= '&status=' . $filter_status; }
                    if ($filter_month  !== '') { $detail_qs .= '&month='  . $filter_month; }
                    ?>
                    <a href="invoices.php<?= $detail_qs ?>" class="btn btn-detail">Chi tiết</a>
                    <?php if ($inv['status'] === 'unpaid'): ?>
                        <?php
                        $paid_qs = '?action=mark_paid&id=' . $inv['id'];
                        if ($filter_status !== '') { $paid_qs .= '&filter_status=' . $filter_status; }
                        if ($filter_month  !== '') { $paid_qs .= '&filter_month='  . $filter_month; }
                        ?>
                        <a href="invoices.php<?= $paid_qs ?>"
                           class="btn btn-paid"
                           onclick="return confirm('Xác nhận đã nhận thanh toán hóa đơn #<?= $inv['id'] ?> (<?= number_format((int)$inv['total_amount']) ?> đ)?')">
                            Đã thu
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Form tạo hóa đơn theo tháng -->
<div class="card">
    <p class="card-title">Tạo Hóa Đơn Theo Tháng</p>
    <form method="POST" action="invoices.php" class="create-form">
        <input type="hidden" name="action" value="create_batch">
        <div class="form-group">
            <label for="month_year">Chọn tháng</label>
            <input type="month" id="month_year" name="month_year"
                   value="<?= $filter_month !== '' ? htmlspecialchars($filter_month) : date('Y-m') ?>"
                   required>
        </div>
        <button type="submit" class="btn-primary"
                onclick="return confirm('Tạo hóa đơn cho tất cả hợp đồng active trong tháng đã chọn?')">
            Tạo hóa đơn
        </button>
    </form>
    <p class="create-note">
        Hệ thống sẽ tạo hóa đơn cho tất cả hợp đồng đang active.<br>
        Phòng nào chưa có chỉ số điện nước sẽ ghi tiền điện/nước = 0 đ.<br>
        Hóa đơn đã tồn tại trong tháng đó sẽ được bỏ qua.
    </p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>