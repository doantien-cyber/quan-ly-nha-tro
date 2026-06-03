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

// ── POST: lưu chỉ số điện nước ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $room_id       = (int)($_POST['room_id']       ?? 0);
    $month_input   = trim($_POST['month_year']      ?? ''); // YYYY-MM từ input[type=month]
    $electric_prev = (int)($_POST['electric_prev']  ?? -1);
    $electric_curr = (int)($_POST['electric_curr']  ?? -1);
    $water_prev    = (int)($_POST['water_prev']     ?? -1);
    $water_curr    = (int)($_POST['water_curr']     ?? -1);

    // Chuyển YYYY-MM → YYYY-MM-01 (ngày đầu tháng, đúng kiểu DATE)
    $month_date = '';
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month_input)) {
        $month_date = $month_input . '-01';
    }

    $err = '';
    if ($room_id < 1) {
        $err = 'Vui lòng chọn phòng.';
    } elseif ($month_date === '') {
        $err = 'Tháng / năm không hợp lệ.';
    } elseif ($electric_prev < 0 || $electric_curr < 0 || $water_prev < 0 || $water_curr < 0) {
        $err = 'Chỉ số không được âm.';
    } elseif ($electric_curr < $electric_prev) {
        $err = 'Chỉ số điện cuối kỳ phải ≥ chỉ số đầu kỳ.';
    } elseif ($water_curr < $water_prev) {
        $err = 'Chỉ số nước cuối kỳ phải ≥ chỉ số đầu kỳ.';
    }

    if ($err !== '') {
        $_SESSION['error'] = $err;
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO utility_readings
                   (room_id, month_year,
                    electric_prev, electric_curr,
                    water_prev, water_curr,
                    electric_rate, water_rate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $room_id, $month_date,
                $electric_prev, $electric_curr,
                $water_prev,    $water_curr,
                ELECTRIC_RATE,  WATER_RATE,
            ]);
            ghi_log('ADD_UTILITY_READING',
                'room_id=' . $room_id . ' month=' . $month_date
                . ' elec=' . $electric_prev . '->' . $electric_curr
                . ' water=' . $water_prev . '->' . $water_curr
            );
            $_SESSION['success'] = 'Đã lưu chỉ số điện nước tháng '
                . date('m/Y', strtotime($month_date)) . ' thành công.';
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'ADD_UTILITY_READING ' . $e->getMessage());
            if ($e->getCode() === '23000') {
                $_SESSION['error'] = 'Phòng này đã có chỉ số điện nước cho tháng '
                    . date('m/Y', strtotime($month_date)) . '. Không thể nhập 2 lần.';
            } else {
                $_SESSION['error'] = 'Có lỗi xảy ra, vui lòng thử lại.';
            }
        }
    }
    header('Location: utilities.php');
    exit;
}

// ── Lấy dữ liệu hiển thị ──────────────────────────────────────

// Danh sách chỉ số đã nhập, JOIN rooms để lấy room_number
$stmt = $pdo->prepare(
    'SELECT u.*, r.room_number,
            (u.electric_curr - u.electric_prev) AS elec_used,
            (u.water_curr    - u.water_prev)    AS water_used,
            (u.electric_curr - u.electric_prev) * u.electric_rate AS elec_fee,
            (u.water_curr    - u.water_prev)    * u.water_rate    AS water_fee
     FROM utility_readings u
     JOIN rooms r ON r.id = u.room_id
     ORDER BY u.month_year DESC, r.room_number ASC'
);
$stmt->execute();
$readings = $stmt->fetchAll();

// Nhóm theo tháng để hiển thị dạng accordion
$grouped = [];
foreach ($readings as $row) {
    $key = date('m/Y', strtotime($row['month_year']));
    $grouped[$key][] = $row;
}

// Phòng đang thuê để hiện trong form
$stmt = $pdo->prepare(
    "SELECT id, room_number FROM rooms WHERE status = 'occupied' ORDER BY room_number"
);
$stmt->execute();
$occupied_rooms = $stmt->fetchAll();

ghi_log('VIEW_UTILITIES', 'count=' . count($readings));

include __DIR__ . '/../includes/header.php';
?>
<style>
.page-title    { font-size:1.4rem; font-weight:700; color:#1a1a2e; margin:0 0 1.2rem; }
.alert         { padding:.7rem 1rem; border-radius:6px; margin-bottom:1rem; font-size:.9rem; }
.alert-success { background:#e6f4ea; color:#2d6a4f; border:1px solid #b7ddc6; }
.alert-error   { background:#fde8e8; color:#c0392b; border:1px solid #f5c6c6; }
.card          { background:#fff; border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,.1);
                 padding:1.2rem 1.5rem; margin-bottom:1.5rem; }
.card-title    { font-size:1rem; font-weight:600; margin:0 0 1rem; color:#333; }

/* Form nhập */
.form-grid     { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:.9rem; }
.form-group    { display:flex; flex-direction:column; gap:.3rem; }
.form-group label { font-size:.85rem; font-weight:600; color:#444; }
.form-group input,
.form-group select { padding:.5rem .7rem; border:1px solid #ccc; border-radius:5px; font-size:.9rem; }
.form-group input:focus,
.form-group select:focus { outline:none; border-color:#4a6fa5; box-shadow:0 0 0 3px rgba(74,111,165,.12); }
.form-group-full { grid-column:1/-1; }
.form-actions  { grid-column:1/-1; display:flex; gap:.6rem; align-items:center; padding-top:.3rem; }
.hint          { font-size:.78rem; color:#888; }
.rate-badge    { display:inline-block; background:#f0f4ff; border:1px solid #c5d3f0;
                 border-radius:5px; padding:.3rem .7rem; font-size:.82rem; color:#3a5a8a; font-weight:600; }
.btn-primary   { background:#4a6fa5; color:#fff; padding:.5rem 1.2rem; font-size:.9rem;
                 border:none; border-radius:5px; cursor:pointer; }
.btn-primary:hover { background:#3a5a8a; }

/* Preview tiêu thụ */
.preview-box   { grid-column:1/-1; background:#f8faff; border:1px solid #d0dcf5;
                 border-radius:8px; padding:1rem 1.2rem; display:none; }
.preview-box.visible { display:block; }
.preview-title { font-size:.85rem; font-weight:600; color:#4a6fa5; margin:0 0 .7rem; }
.preview-grid  { display:grid; grid-template-columns:1fr 1fr; gap:.5rem .8rem; }
.preview-row   { display:flex; justify-content:space-between; font-size:.88rem; }
.preview-label { color:#555; }
.preview-value { font-weight:600; color:#1a1a2e; }
.preview-total { grid-column:1/-1; border-top:1px dashed #c5d3f0; padding-top:.6rem;
                 display:flex; justify-content:space-between; font-size:.95rem; margin-top:.3rem; }
.preview-total .preview-value { color:#2e7d32; font-size:1rem; }
.preview-warn  { grid-column:1/-1; font-size:.82rem; color:#e67e22; margin-top:.4rem; }

/* Bảng lịch sử */
.month-group   { margin-bottom:1.2rem; }
.month-heading { font-size:.95rem; font-weight:700; color:#4a6fa5;
                 background:#f0f4ff; padding:.45rem .9rem; border-radius:6px;
                 margin-bottom:.5rem; border-left:3px solid #4a6fa5; }
table          { width:100%; border-collapse:collapse; font-size:.88rem; }
th             { background:#f5f6fa; text-align:left; padding:.55rem .8rem; color:#555;
                 font-weight:600; border-bottom:2px solid #e0e0e0; white-space:nowrap; }
td             { padding:.55rem .8rem; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td    { background:#fafbff; }
.num           { text-align:right; font-variant-numeric:tabular-nums; }
.fee-cell      { text-align:right; font-weight:600; color:#1a1a2e; }
.empty-state   { text-align:center; padding:2rem; color:#888; font-style:italic; }
.no-occupied   { background:#fff8e1; border:1px solid #ffe082; border-radius:6px;
                 padding:.8rem 1rem; font-size:.9rem; color:#795548; }
</style>

<h1 class="page-title">Nhập Chỉ Số Điện Nước</h1>

<?php if ($flash_success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Form nhập chỉ số -->
<div class="card">
    <p class="card-title">Nhập Chỉ Số Mới</p>

    <!-- Hiển thị đơn giá đang áp dụng -->
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem;">
        <span class="rate-badge">Điện: <?= number_format(ELECTRIC_RATE) ?> đ/kWh</span>
        <span class="rate-badge">Nước: <?= number_format(WATER_RATE) ?> đ/m³</span>
    </div>

    <?php if (empty($occupied_rooms)): ?>
        <p class="no-occupied">Hiện không có phòng nào đang thuê. Vui lòng kiểm tra lại danh sách phòng.</p>
    <?php else: ?>
    <form method="POST" action="utilities.php" id="utilForm">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">

            <div class="form-group">
                <label for="room_id">Phòng (đang thuê) <span style="color:red">*</span></label>
                <select id="room_id" name="room_id" required>
                    <option value="">— Chọn phòng —</option>
                    <?php foreach ($occupied_rooms as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['room_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="month_year">Tháng / Năm <span style="color:red">*</span></label>
                <input type="month" id="month_year" name="month_year"
                       value="<?= date('Y-m') ?>" required>
            </div>

            <!-- Chỉ số điện -->
            <div class="form-group">
                <label for="electric_prev">Điện đầu kỳ (kWh) <span style="color:red">*</span></label>
                <input type="number" id="electric_prev" name="electric_prev"
                       min="0" placeholder="100" required>
            </div>
            <div class="form-group">
                <label for="electric_curr">Điện cuối kỳ (kWh) <span style="color:red">*</span></label>
                <input type="number" id="electric_curr" name="electric_curr"
                       min="0" placeholder="150" required>
            </div>

            <!-- Chỉ số nước -->
            <div class="form-group">
                <label for="water_prev">Nước đầu kỳ (m³) <span style="color:red">*</span></label>
                <input type="number" id="water_prev" name="water_prev"
                       min="0" placeholder="20" required>
            </div>
            <div class="form-group">
                <label for="water_curr">Nước cuối kỳ (m³) <span style="color:red">*</span></label>
                <input type="number" id="water_curr" name="water_curr"
                       min="0" placeholder="25" required>
            </div>

            <!-- Preview tính toán — hiện bằng JS -->
            <div class="preview-box" id="previewBox">
                <p class="preview-title">Xem trước tiêu thụ</p>
                <div class="preview-grid">
                    <div class="preview-row">
                        <span class="preview-label">Điện tiêu thụ</span>
                        <span class="preview-value" id="prevElecUsed">— kWh</span>
                    </div>
                    <div class="preview-row">
                        <span class="preview-label">Tiền điện</span>
                        <span class="preview-value" id="prevElecFee">—</span>
                    </div>
                    <div class="preview-row">
                        <span class="preview-label">Nước tiêu thụ</span>
                        <span class="preview-value" id="prevWaterUsed">— m³</span>
                    </div>
                    <div class="preview-row">
                        <span class="preview-label">Tiền nước</span>
                        <span class="preview-value" id="prevWaterFee">—</span>
                    </div>
                    <div class="preview-total">
                        <span class="preview-label">Tổng tiền điện + nước</span>
                        <span class="preview-value" id="prevTotal">—</span>
                    </div>
                    <p class="preview-warn" id="previewWarn" style="display:none"></p>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Lưu chỉ số</button>
                <span class="hint">Đơn giá được ghi vào bản ghi tại thời điểm nhập.</span>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- Lịch sử chỉ số đã nhập -->
<div class="card">
    <p class="card-title">Lịch Sử Chỉ Số (<?= count($readings) ?> bản ghi)</p>

    <?php if (empty($grouped)): ?>
        <p class="empty-state">Chưa có chỉ số nào được nhập.</p>
    <?php else: ?>
        <?php foreach ($grouped as $month_label => $rows): ?>
        <div class="month-group">
            <div class="month-heading">Tháng <?= htmlspecialchars($month_label) ?> — <?= count($rows) ?> phòng</div>
            <table>
                <thead>
                    <tr>
                        <th>Phòng</th>
                        <th class="num">Điện đầu</th>
                        <th class="num">Điện cuối</th>
                        <th class="num">Tiêu thụ (kWh)</th>
                        <th class="num">Đơn giá điện</th>
                        <th class="num">Tiền điện</th>
                        <th class="num">Nước đầu</th>
                        <th class="num">Nước cuối</th>
                        <th class="num">Tiêu thụ (m³)</th>
                        <th class="num">Đơn giá nước</th>
                        <th class="num">Tiền nước</th>
                        <th class="num">Tổng</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['room_number']) ?></strong></td>
                        <td class="num"><?= number_format((int)$u['electric_prev']) ?></td>
                        <td class="num"><?= number_format((int)$u['electric_curr']) ?></td>
                        <td class="num"><?= number_format((int)$u['elec_used']) ?></td>
                        <td class="num"><?= number_format((int)$u['electric_rate']) ?></td>
                        <td class="fee-cell"><?= number_format((int)$u['elec_fee']) ?> đ</td>
                        <td class="num"><?= number_format((int)$u['water_prev']) ?></td>
                        <td class="num"><?= number_format((int)$u['water_curr']) ?></td>
                        <td class="num"><?= number_format((int)$u['water_used']) ?></td>
                        <td class="num"><?= number_format((int)$u['water_rate']) ?></td>
                        <td class="fee-cell"><?= number_format((int)$u['water_fee']) ?> đ</td>
                        <td class="fee-cell" style="color:#2e7d32">
                            <?= number_format((int)$u['elec_fee'] + (int)$u['water_fee']) ?> đ
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Truyền hằng số từ PHP sang JS -->
<script>
const ELECTRIC_RATE = <?= ELECTRIC_RATE ?>;
const WATER_RATE    = <?= WATER_RATE ?>;

const fmtVND = n => n.toLocaleString('vi-VN') + ' đ';

function updatePreview() {
    const ep = parseInt(document.getElementById('electric_prev').value, 10);
    const ec = parseInt(document.getElementById('electric_curr').value, 10);
    const wp = parseInt(document.getElementById('water_prev').value,    10);
    const wc = parseInt(document.getElementById('water_curr').value,    10);

    const box  = document.getElementById('previewBox');
    const warn = document.getElementById('previewWarn');

    // Chỉ hiện preview khi có đủ 4 giá trị hợp lệ
    if ([ep, ec, wp, wc].some(isNaN)) { box.classList.remove('visible'); return; }

    box.classList.add('visible');

    const elecUsed = ec - ep;
    const waterUsed = wc - wp;
    const elecFee  = elecUsed  * ELECTRIC_RATE;
    const waterFee = waterUsed * WATER_RATE;

    document.getElementById('prevElecUsed').textContent  = elecUsed  + ' kWh';
    document.getElementById('prevElecFee').textContent   = fmtVND(elecFee);
    document.getElementById('prevWaterUsed').textContent = waterUsed + ' m³';
    document.getElementById('prevWaterFee').textContent  = fmtVND(waterFee);
    document.getElementById('prevTotal').textContent     = fmtVND(elecFee + waterFee);

    // Cảnh báo nếu chỉ số cuối < đầu
    const warnings = [];
    if (elecUsed  < 0) warnings.push('Chỉ số điện cuối kỳ nhỏ hơn đầu kỳ.');
    if (waterUsed < 0) warnings.push('Chỉ số nước cuối kỳ nhỏ hơn đầu kỳ.');
    if (warnings.length) {
        warn.textContent = warnings.join(' ');
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}

['electric_prev', 'electric_curr', 'water_prev', 'water_curr'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updatePreview);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>