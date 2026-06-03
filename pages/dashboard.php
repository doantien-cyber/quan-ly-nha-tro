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
<style>
/* ── Layout ── */
.page-title   { font-size:1.4rem; font-weight:700; color:#1a1a2e; margin:0 0 .3rem; }
.page-sub     { font-size:.88rem; color:#888; margin:0 0 1.4rem; }

/* ── Stat cards ── */
.stat-grid    { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr));
                gap:1rem; margin-bottom:1.5rem; }
.stat-card    { background:#fff; border-radius:10px; box-shadow:0 1px 6px rgba(0,0,0,.09);
                padding:1.1rem 1.3rem; display:flex; flex-direction:column; gap:.4rem;
                border-top:4px solid #ccc; }
.stat-card.blue   { border-top-color:#4a6fa5; }
.stat-card.green  { border-top-color:#27ae60; }
.stat-card.orange { border-top-color:#e67e22; }
.stat-card.red    { border-top-color:#e74c3c; }
.stat-card.teal   { border-top-color:#16a085; }
.stat-label   { font-size:.8rem; font-weight:600; color:#777; text-transform:uppercase;
                letter-spacing:.04em; }
.stat-value   { font-size:2rem; font-weight:800; color:#1a1a2e; line-height:1; }
.stat-sub     { font-size:.8rem; color:#999; }

/* ── Revenue row ── */
.revenue-row  { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr));
                gap:1rem; margin-bottom:1.5rem; }
.rev-card     { background:#fff; border-radius:10px; box-shadow:0 1px 6px rgba(0,0,0,.09);
                padding:1.1rem 1.4rem; }
.rev-label    { font-size:.82rem; color:#777; font-weight:600; margin-bottom:.4rem; }
.rev-amount   { font-size:1.55rem; font-weight:800; }
.rev-amount.green  { color:#27ae60; }
.rev-amount.orange { color:#e67e22; }
.rev-detail   { font-size:.8rem; color:#aaa; margin-top:.3rem; }
.occupancy-bar-wrap { margin-top:.6rem; }
.occupancy-bar-bg   { background:#f0f0f0; border-radius:6px; height:8px; overflow:hidden; }
.occupancy-bar-fill { background:linear-gradient(90deg,#27ae60,#52c77c);
                      height:100%; border-radius:6px; transition:width .4s ease; }
.occupancy-pct  { font-size:.82rem; color:#555; margin-top:.3rem; }

/* ── Content cards ── */
.dashboard-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;
                  margin-bottom:1.5rem; }
@media(max-width:820px){ .dashboard-grid { grid-template-columns:1fr; } }
.card         { background:#fff; border-radius:10px; box-shadow:0 1px 6px rgba(0,0,0,.09);
                padding:1.1rem 1.4rem; }
.card-header  { display:flex; justify-content:space-between; align-items:center;
                margin-bottom:.9rem; }
.card-title   { font-size:.95rem; font-weight:700; color:#333; margin:0; }
.card-link    { font-size:.8rem; color:#4a6fa5; text-decoration:none; }
.card-link:hover { text-decoration:underline; }

/* ── Tables ── */
table         { width:100%; border-collapse:collapse; font-size:.86rem; }
th            { background:#f5f6fa; text-align:left; padding:.5rem .7rem; color:#666;
                font-weight:600; border-bottom:2px solid #eee; white-space:nowrap; }
td            { padding:.52rem .7rem; border-bottom:1px solid #f5f5f5; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td   { background:#fafbff; }
.num          { text-align:right; font-variant-numeric:tabular-nums; font-weight:600;
                color:#1a1a2e; }
.phone        { font-size:.8rem; color:#888; }
.days-badge   { display:inline-block; padding:.18rem .55rem; border-radius:10px;
                font-size:.78rem; font-weight:700; }
.days-urgent  { background:#fce4ec; color:#c62828; }
.days-soon    { background:#fff3e0; color:#e65100; }
.empty-row td { text-align:center; color:#aaa; font-style:italic; padding:1.5rem; }

/* ── Alert banner ── */
.alert-banner { background:#fff8e1; border:1px solid #ffe082; border-radius:8px;
                padding:.7rem 1rem; font-size:.88rem; color:#795548;
                margin-bottom:1.2rem; display:flex; align-items:center; gap:.6rem; }
</style>

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