<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

require_login();

$this_month = date('Y-m-01'); // ngày 1 tháng hiện tại, khớp kiểu DATE trong DB

// ── 1. Thống kê phòng ─────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'occupied')    AS occupied,
        SUM(status = 'vacant')      AS vacant,
        SUM(status = 'maintenance') AS maintenance
     FROM rooms"
);
$stmt->execute();
$room_stats = $stmt->fetch();

// ── 2. Doanh thu tháng hiện tại ───────────────────────────────
$stmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(total_amount), 0)  AS revenue_paid,
        COUNT(*)                         AS invoice_count
     FROM invoices
     WHERE month_year = ? AND status = 'paid'"
);
$stmt->execute([$this_month]);
$revenue = $stmt->fetch();

// Tổng hóa đơn chưa thu trong tháng này
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount), 0) AS pending_amount,
            COUNT(*)                        AS pending_count
     FROM invoices
     WHERE month_year = ? AND status = 'unpaid'"
);
$stmt->execute([$this_month]);
$pending = $stmt->fetch();

// ── 3. Hóa đơn chưa thanh toán (5 gần nhất) ──────────────────
$stmt = $pdo->prepare(
    "SELECT i.id, i.month_year, i.total_amount,
            r.room_number,
            t.full_name AS tenant_name, t.phone AS tenant_phone
     FROM invoices i
     JOIN contracts c ON c.id = i.contract_id
     JOIN rooms r     ON r.id = c.room_id
     JOIN tenants t   ON t.id = c.tenant_id
     WHERE i.status = 'unpaid'
     ORDER BY i.month_year ASC, r.room_number ASC
     LIMIT 5"
);
$stmt->execute();
$unpaid_invoices = $stmt->fetchAll();

// ── 4. Hợp đồng sắp hết hạn (trong 30 ngày tới) ──────────────
$stmt = $pdo->prepare(
    "SELECT c.id, c.end_date,
            DATEDIFF(c.end_date, CURDATE()) AS days_left,
            r.room_number,
            t.full_name AS tenant_name, t.phone AS tenant_phone
     FROM contracts c
     JOIN rooms r   ON r.id = c.room_id
     JOIN tenants t ON t.id = c.tenant_id
     WHERE c.status = 'active'
       AND c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY c.end_date ASC"
);
$stmt->execute();
$expiring_contracts = $stmt->fetchAll();

// ── 5. Tỷ lệ lấp đầy ─────────────────────────────────────────
$occupancy_rate = $room_stats['total'] > 0
    ? round((int)$room_stats['occupied'] / (int)$room_stats['total'] * 100)
    : 0;

ghi_log('VIEW_DASHBOARD',
    'rooms=' . (int)$room_stats['total']
    . ' occupied=' . (int)$room_stats['occupied']
    . ' revenue=' . (int)$revenue['revenue_paid']
);

include __DIR__ . '/../includes/header.php';
?>

<p class="page-title">Tổng Quan</p>
<p class="page-sub">
    Xin chào <strong><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></strong>
    — <?= date('l, d/m/Y') ?>
</p>

<?php if (!empty($expiring_contracts)): ?>
<div class="alert-banner">
    &#9888;
    <span>
        Có <strong><?= count($expiring_contracts) ?> hợp đồng</strong>
        sắp hết hạn trong 30 ngày tới.
    </span>
</div>
<?php endif; ?>

<!-- Thẻ thống kê phòng -->
<div class="stat-grid">
    <div class="stat-card blue">
        <div class="stat-label">Tổng số phòng</div>
        <div class="stat-value"><?= (int)$room_stats['total'] ?></div>
        <div class="stat-sub">Tỷ lệ lấp đầy <?= $occupancy_rate ?>%</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Đang cho thuê</div>
        <div class="stat-value"><?= (int)$room_stats['occupied'] ?></div>
        <div class="stat-sub">phòng có khách</div>
    </div>
    <div class="stat-card orange">
        <div class="stat-label">Còn trống</div>
        <div class="stat-value"><?= (int)$room_stats['vacant'] ?></div>
        <div class="stat-sub">
            <a href="<?= BASE_URL ?>/pages/rooms.php?status=vacant"
               style="color:inherit;text-decoration:underline dotted">Xem danh sách</a>
        </div>
    </div>
    <div class="stat-card red">
        <div class="stat-label">Đang bảo trì</div>
        <div class="stat-value"><?= (int)$room_stats['maintenance'] ?></div>
        <div class="stat-sub">phòng tạm ngưng</div>
    </div>
</div>

<!-- Doanh thu tháng hiện tại -->
<div class="revenue-row">
    <div class="rev-card">
        <div class="rev-label">Đã thu — Tháng <?= date('m/Y') ?></div>
        <div class="rev-amount green"><?= number_format((int)$revenue['revenue_paid']) ?> đ</div>
        <div class="rev-detail"><?= (int)$revenue['invoice_count'] ?> hóa đơn đã thanh toán</div>
    </div>
    <div class="rev-card">
        <div class="rev-label">Chưa thu — Tháng <?= date('m/Y') ?></div>
        <div class="rev-amount orange"><?= number_format((int)$pending['pending_amount']) ?> đ</div>
        <div class="rev-detail"><?= (int)$pending['pending_count'] ?> hóa đơn đang chờ</div>
    </div>
    <?php if (is_admin()): ?>
    <div class="rev-card" style="display:flex;flex-direction:column;justify-content:center;">
        <div class="rev-label">Báo cáo doanh thu</div>
        <div style="margin-top:.5rem;">
            <a href="<?= BASE_URL ?>/pages/bao-cao.php"
               style="display:inline-block;background:#4a6fa5;color:#fff;padding:.45rem 1rem;
                      border-radius:6px;text-decoration:none;font-size:.88rem;font-weight:600;">
                Xem báo cáo đầy đủ →
            </a>
        </div>
        <div class="rev-detail" style="margin-top:.4rem;">Thống kê doanh thu theo tháng</div>
    </div>
    <?php endif; ?>
    <div class="rev-card">
        <div class="rev-label">Tỷ lệ lấp đầy</div>
        <div class="rev-amount" style="color:#4a6fa5;"><?= $occupancy_rate ?>%</div>
        <div class="occupancy-bar-wrap">
            <div class="occupancy-bar-bg">
                <div class="occupancy-bar-fill" style="width:<?= $occupancy_rate ?>%"></div>
            </div>
            <div class="occupancy-pct">
                <?= (int)$room_stats['occupied'] ?> / <?= (int)$room_stats['total'] ?> phòng
            </div>
        </div>
    </div>
</div>

<!-- Hai bảng nằm cạnh nhau -->
<div class="dashboard-grid">

    <!-- Hóa đơn chưa thanh toán -->
    <div class="card">
        <div class="card-header">
            <p class="card-title">Hóa Đơn Chưa Thu</p>
            <a href="<?= BASE_URL ?>/pages/invoices.php?status=unpaid" class="card-link">
                Xem tất cả →
            </a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Phòng</th>
                    <th>Khách thuê</th>
                    <th>Tháng</th>
                    <th class="num">Số tiền</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($unpaid_invoices)): ?>
                <tr class="empty-row"><td colspan="4">Không có hóa đơn chưa thanh toán.</td></tr>
            <?php else: ?>
                <?php foreach ($unpaid_invoices as $inv): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($inv['room_number']) ?></strong>
                    </td>
                    <td>
                        <?= htmlspecialchars($inv['tenant_name']) ?>
                        <div class="phone"><?= htmlspecialchars($inv['tenant_phone']) ?></div>
                    </td>
                    <td><?= date('m/Y', strtotime($inv['month_year'])) ?></td>
                    <td class="num"><?= number_format((int)$inv['total_amount']) ?> đ</td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Hợp đồng sắp hết hạn -->
    <div class="card">
        <div class="card-header">
            <p class="card-title">Hợp Đồng Sắp Hết Hạn</p>
            <a href="<?= BASE_URL ?>/pages/contracts.php?status=active" class="card-link">
                Xem tất cả →
            </a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Phòng</th>
                    <th>Khách thuê</th>
                    <th>Ngày hết hạn</th>
                    <th>Còn lại</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($expiring_contracts)): ?>
                <tr class="empty-row">
                    <td colspan="4">Không có hợp đồng nào hết hạn trong 30 ngày tới.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($expiring_contracts as $ct): ?>
                <?php
                $days  = (int)$ct['days_left'];
                $badge = $days <= 7 ? 'days-urgent' : 'days-soon';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ct['room_number']) ?></strong></td>
                    <td>
                        <?= htmlspecialchars($ct['tenant_name']) ?>
                        <div class="phone"><?= htmlspecialchars($ct['tenant_phone']) ?></div>
                    </td>
                    <td><?= date('d/m/Y', strtotime($ct['end_date'])) ?></td>
                    <td>
                        <span class="days-badge <?= $badge ?>">
                            <?= $days ?> ngày
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>