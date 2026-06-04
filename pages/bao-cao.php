<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

require_login();
require_admin(); // Chỉ admin xem báo cáo doanh thu đầy đủ

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

ghi_log('VIEW_REPORT', 'year=' . $year);

// ── Doanh thu theo tháng ──────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(month_year, '%Y-%m') AS ym,
            DATE_FORMAT(month_year, '%m/%Y')  AS label,
            COUNT(*)                           AS invoice_count,
            COALESCE(SUM(total_amount),   0)   AS total,
            COALESCE(SUM(CASE WHEN status='paid'   THEN total_amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN status='unpaid' THEN total_amount ELSE 0 END), 0) AS unpaid,
            SUM(CASE WHEN status='paid'   THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN status='unpaid' THEN 1 ELSE 0 END) AS unpaid_count
     FROM invoices
     WHERE YEAR(month_year) = ?
     GROUP BY month_year
     ORDER BY month_year ASC"
);
$stmt->execute([$year]);
$monthly_data = $stmt->fetchAll();

// ── Tổng cộng cả năm ─────────────────────────────────────────
$year_total  = 0;
$year_paid   = 0;
$year_unpaid = 0;
foreach ($monthly_data as $row) {
    $year_total  += (int)$row['total'];
    $year_paid   += (int)$row['paid'];
    $year_unpaid += (int)$row['unpaid'];
}

// ── Thống kê phòng hiện tại ───────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(status = 'occupied')    AS occupied,
            SUM(status = 'vacant')      AS vacant,
            SUM(status = 'maintenance') AS maintenance
     FROM rooms"
);
$stmt->execute();
$room_stats = $stmt->fetch();
$occupancy_rate = $room_stats['total'] > 0
    ? round((int)$room_stats['occupied'] / (int)$room_stats['total'] * 100)
    : 0;

// ── Năm có hóa đơn để hiển thị selector ──────────────────────
$stmt = $pdo->prepare("SELECT DISTINCT YEAR(month_year) AS y FROM invoices ORDER BY y DESC");
$stmt->execute();
$available_years = array_column($stmt->fetchAll(), 'y');
if (empty($available_years)) {
    $available_years = [$year];
}

// Giá trị lớn nhất tháng (để scale cột trong biểu đồ)
$max_paid = 1;
foreach ($monthly_data as $row) {
    if ((int)$row['paid'] > $max_paid) {
        $max_paid = (int)$row['paid'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<p class="page-title">Báo Cáo Doanh Thu</p>
<p class="page-sub">Thống kê tài chính theo tháng — chỉ admin xem được</p>

<!-- Chọn năm -->
<form method="GET" action="bao-cao.php" class="year-bar">
    <label for="year_select">Năm:</label>
    <select name="year" id="year_select" class="year-select" onchange="this.form.submit()">
        <?php foreach ($available_years as $y): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
        <?php if (!in_array($year, $available_years, true)): ?>
            <option value="<?= $year ?>" selected><?= $year ?></option>
        <?php endif; ?>
    </select>
</form>

<!-- Tổng kết năm -->
<div class="summary-grid">
    <div class="sum-card blue">
        <div class="sum-label">Tổng phát sinh <?= $year ?></div>
        <div class="sum-value"><?= number_format($year_total) ?> đ</div>
        <div class="sum-sub"><?= count($monthly_data) ?> tháng có hóa đơn</div>
    </div>
    <div class="sum-card green">
        <div class="sum-label">Đã thu <?= $year ?></div>
        <div class="sum-value green"><?= number_format($year_paid) ?> đ</div>
        <div class="sum-sub">
            <?= $year_total > 0 ? round($year_paid / $year_total * 100) : 0 ?>% tổng phát sinh
        </div>
    </div>
    <div class="sum-card orange">
        <div class="sum-label">Còn nợ <?= $year ?></div>
        <div class="sum-value orange"><?= number_format($year_unpaid) ?> đ</div>
        <div class="sum-sub">
            <?= $year_total > 0 ? round($year_unpaid / $year_total * 100) : 0 ?>% chưa thu
        </div>
    </div>
    <div class="sum-card teal">
        <div class="sum-label">Tỷ lệ lấp đầy hiện tại</div>
        <div class="sum-value" style="color:#16a085;"><?= $occupancy_rate ?>%</div>
        <div class="occupancy-bar-bg">
            <div class="occupancy-bar-fill" style="width:<?= $occupancy_rate ?>%"></div>
        </div>
        <div class="sum-sub"><?= (int)$room_stats['occupied'] ?> / <?= (int)$room_stats['total'] ?> phòng</div>
    </div>
</div>

<!-- Biểu đồ cột doanh thu đã thu -->
<div class="card">
    <p class="card-title">Doanh Thu Đã Thu Theo Tháng — <?= $year ?></p>
    <?php if (empty($monthly_data)): ?>
        <p class="chart-empty">Không có dữ liệu hóa đơn trong năm <?= $year ?>.</p>
    <?php else: ?>
    <div class="bar-chart">
        <?php foreach ($monthly_data as $row): ?>
            <?php $height = $max_paid > 0 ? max(2, round((int)$row['paid'] / $max_paid * 150)) : 2; ?>
            <div class="bar-wrap">
                <div class="bar-amount">
                    <?= (int)$row['paid'] >= 1000000
                        ? round((int)$row['paid'] / 1000000, 1) . 'M'
                        : number_format((int)$row['paid']) ?>
                </div>
                <div class="bar-col" style="height:<?= $height ?>px"
                     title="Tháng <?= htmlspecialchars($row['label']) ?>: <?= number_format((int)$row['paid']) ?> đ đã thu">
                </div>
                <div class="bar-label"><?= htmlspecialchars($row['label']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Bảng chi tiết theo tháng -->
<div class="card">
    <p class="card-title">Chi Tiết Theo Tháng — <?= $year ?></p>
    <?php if (empty($monthly_data)): ?>
        <p class="empty-state">Không có dữ liệu hóa đơn trong năm <?= $year ?>.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Tháng</th>
                <th class="num">Số HĐ</th>
                <th class="num">Đã thanh toán</th>
                <th class="num">Chưa thanh toán</th>
                <th class="num">Tổng phát sinh</th>
                <th>Tỷ lệ thu</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($monthly_data as $row): ?>
            <?php
            $rate = (int)$row['total'] > 0
                ? round((int)$row['paid'] / (int)$row['total'] * 100)
                : 0;
            ?>
            <tr>
                <td><strong>Tháng <?= htmlspecialchars($row['label']) ?></strong></td>
                <td class="num"><?= (int)$row['invoice_count'] ?></td>
                <td class="num" style="color:#2d6a4f;font-weight:600;">
                    <?= number_format((int)$row['paid']) ?> đ
                    <div style="font-size:.75rem;color:#aaa;"><?= (int)$row['paid_count'] ?> HĐ</div>
                </td>
                <td class="num" style="color:#e65100;">
                    <?= number_format((int)$row['unpaid']) ?> đ
                    <div style="font-size:.75rem;color:#aaa;"><?= (int)$row['unpaid_count'] ?> HĐ</div>
                </td>
                <td class="num"><?= number_format((int)$row['total']) ?> đ</td>
                <td style="min-width:130px;">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <div style="flex:1;background:#f0f0f0;border-radius:4px;height:8px;overflow:hidden;">
                            <div style="width:<?= $rate ?>%;background:#27ae60;height:100%;border-radius:4px;"></div>
                        </div>
                        <span class="rate-cell"><?= $rate ?>%</span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>Tổng cộng</td>
                <td class="num">—</td>
                <td class="num" style="color:#2d6a4f;"><?= number_format($year_paid) ?> đ</td>
                <td class="num" style="color:#e65100;"><?= number_format($year_unpaid) ?> đ</td>
                <td class="num"><?= number_format($year_total) ?> đ</td>
                <td class="rate-cell">
                    <?= $year_total > 0 ? round($year_paid / $year_total * 100) : 0 ?>% đã thu
                </td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
