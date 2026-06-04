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

// ── POST: thêm khách thuê mới ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $full_name         = trim($_POST['full_name']         ?? '');
    $id_card           = trim($_POST['id_card']           ?? '');
    $phone             = trim($_POST['phone']             ?? '');
    $email             = trim($_POST['email']             ?? '');
    $permanent_address = trim($_POST['permanent_address'] ?? '');
    $search            = $_POST['search'] ?? '';

    if ($full_name === '' || $id_card === '' || $phone === '') {
        $_SESSION['error'] = 'Vui lòng nhập đầy đủ họ tên, CMND/CCCD và số điện thoại.';
    } elseif (!preg_match('/^\d{9,12}$/', $id_card)) {
        $_SESSION['error'] = 'CMND/CCCD phải có 9–12 chữ số.';
    } elseif (!preg_match('/^0\d{8,9}$/', $phone)) {
        $_SESSION['error'] = 'Số điện thoại không hợp lệ (phải bắt đầu bằng 0, 9–10 chữ số).';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Địa chỉ email không hợp lệ.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tenants (full_name, id_card, phone, email, permanent_address)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $full_name,
                $id_card,
                $phone,
                $email ?: null,
                $permanent_address ?: null,
            ]);
            ghi_log('ADD_TENANT', 'id_card=' . $id_card . ' name=' . $full_name);
            $_SESSION['success'] = 'Đã thêm khách thuê ' . $full_name . ' thành công.';
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'ADD_TENANT ' . $e->getMessage());
            $_SESSION['error'] = ($e->getCode() === '23000')
                ? 'CMND/CCCD "' . htmlspecialchars($id_card) . '" đã tồn tại trong hệ thống.'
                : 'Có lỗi xảy ra, vui lòng thử lại.';
        }
    }
    $qs = $search !== '' ? '?q=' . urlencode($search) : '';
    header('Location: tenants.php' . $qs);
    exit;
}

// ── POST: sửa thông tin khách thuê ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id                = (int)($_POST['id']               ?? 0);
    $full_name         = trim($_POST['full_name']         ?? '');
    $id_card           = trim($_POST['id_card']           ?? '');
    $phone             = trim($_POST['phone']             ?? '');
    $email             = trim($_POST['email']             ?? '');
    $permanent_address = trim($_POST['permanent_address'] ?? '');
    $search            = $_POST['search'] ?? '';

    if ($id < 1 || $full_name === '' || $id_card === '' || $phone === '') {
        $_SESSION['error'] = 'Vui lòng nhập đầy đủ họ tên, CMND/CCCD và số điện thoại.';
    } elseif (!preg_match('/^\d{9,12}$/', $id_card)) {
        $_SESSION['error'] = 'CMND/CCCD phải có 9–12 chữ số.';
    } elseif (!preg_match('/^0\d{8,9}$/', $phone)) {
        $_SESSION['error'] = 'Số điện thoại không hợp lệ (phải bắt đầu bằng 0, 9–10 chữ số).';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Địa chỉ email không hợp lệ.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'UPDATE tenants SET full_name=?, id_card=?, phone=?, email=?, permanent_address=?
                 WHERE id=?'
            );
            $stmt->execute([
                $full_name,
                $id_card,
                $phone,
                $email ?: null,
                $permanent_address ?: null,
                $id,
            ]);
            ghi_log('EDIT_TENANT', 'id=' . $id . ' id_card=' . $id_card . ' name=' . $full_name);
            $_SESSION['success'] = 'Đã cập nhật thông tin ' . $full_name . ' thành công.';
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'EDIT_TENANT id=' . $id . ' ' . $e->getMessage());
            $_SESSION['error'] = ($e->getCode() === '23000')
                ? 'CMND/CCCD "' . htmlspecialchars($id_card) . '" đã được dùng bởi khách thuê khác.'
                : 'Có lỗi xảy ra, vui lòng thử lại.';
        }
    }
    $qs = $search !== '' ? '?q=' . urlencode($search) : '';
    header('Location: tenants.php' . $qs);
    exit;
}

// ── GET: xóa khách thuê (admin only) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'delete') {
    block_staff('DELETE_TENANT');
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) {
        $_SESSION['error'] = 'ID khách thuê không hợp lệ.';
    } else {
        try {
            // Kiểm tra còn hợp đồng active không
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM contracts WHERE tenant_id = ? AND status = ?'
            );
            $stmt->execute([$id, 'active']);
            $active_count = (int)$stmt->fetchColumn();

            if ($active_count > 0) {
                $_SESSION['error'] = 'Không thể xóa: khách thuê này đang có hợp đồng active.';
            } else {
                // Lấy tên để log trước khi xóa
                $stmt = $pdo->prepare('SELECT full_name FROM tenants WHERE id = ?');
                $stmt->execute([$id]);
                $tenant = $stmt->fetch();

                if (!$tenant) {
                    $_SESSION['error'] = 'Khách thuê không tồn tại.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM tenants WHERE id = ?');
                    $stmt->execute([$id]);
                    ghi_log('DELETE_TENANT', 'id=' . $id . ' name=' . $tenant['full_name']);
                    $_SESSION['success'] = 'Đã xóa khách thuê ' . $tenant['full_name'] . '.';
                }
            }
        } catch (PDOException $e) {
            ghi_log('EXCEPTION', 'DELETE_TENANT id=' . $id . ' ' . $e->getMessage());
            $_SESSION['error'] = 'Không thể xóa khách thuê do còn dữ liệu liên quan.';
        }
    }
    header('Location: tenants.php');
    exit;
}

// ── Lấy dữ liệu hiển thị ──────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$edit_tenant = null;

if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $edit_tenant = $stmt->fetch() ?: null;
}

// Danh sách khách thuê + đếm hợp đồng active mỗi người
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $pdo->prepare(
        'SELECT t.*,
                COUNT(CASE WHEN c.status = \'active\' THEN 1 END) AS active_contracts
         FROM tenants t
         LEFT JOIN contracts c ON c.tenant_id = t.id
         WHERE t.full_name LIKE ? OR t.id_card LIKE ?
         GROUP BY t.id
         ORDER BY t.full_name'
    );
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->prepare(
        'SELECT t.*,
                COUNT(CASE WHEN c.status = \'active\' THEN 1 END) AS active_contracts
         FROM tenants t
         LEFT JOIN contracts c ON c.tenant_id = t.id
         GROUP BY t.id
         ORDER BY t.full_name'
    );
    $stmt->execute();
}
$tenants = $stmt->fetchAll();

ghi_log('VIEW_TENANTS', 'search=' . ($search ?: '(none)') . ' count=' . count($tenants));

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Quản Lý Khách Thuê</h1>
</div>

<?php if ($flash_success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Thanh tìm kiếm -->
<form method="GET" action="tenants.php" class="search-bar">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
           placeholder="Tìm theo tên hoặc CMND/CCCD..." autofocus>
    <button type="submit">Tìm kiếm</button>
    <?php if ($search !== ''): ?>
        <a href="tenants.php">Xóa bộ lọc</a>
    <?php endif; ?>
</form>

<?php if ($search !== ''): ?>
    <p class="search-info">
        Kết quả tìm kiếm cho "<strong><?= htmlspecialchars($search) ?></strong>":
        <?= count($tenants) ?> khách thuê
    </p>
<?php endif; ?>

<!-- Bảng danh sách khách thuê -->
<div class="card">
    <p class="card-title">
        Danh sách khách thuê (<?= count($tenants) ?> người)
    </p>
    <?php if (empty($tenants)): ?>
        <p class="empty-state">
            <?= $search !== '' ? 'Không tìm thấy khách thuê nào phù hợp.' : 'Chưa có khách thuê nào.' ?>
        </p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Họ và tên</th>
                <th>CMND / CCCD</th>
                <th>Số điện thoại</th>
                <th>Email</th>
                <th>Địa chỉ thường trú</th>
                <th>Hợp đồng</th>
                <th style="width:130px">Thao tác</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tenants as $t): ?>
            <?php $has_active = (int)$t['active_contracts'] > 0; ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['full_name']) ?></strong></td>
                <td><?= htmlspecialchars($t['id_card']) ?></td>
                <td><?= htmlspecialchars($t['phone']) ?></td>
                <td><?= $t['email'] ? htmlspecialchars($t['email']) : '<span style="color:#aaa">—</span>' ?></td>
                <td><?= $t['permanent_address'] ? htmlspecialchars($t['permanent_address']) : '<span style="color:#aaa">—</span>' ?></td>
                <td>
                    <?php if ($has_active): ?>
                        <span class="badge-active"><?= (int)$t['active_contracts'] ?> active</span>
                    <?php else: ?>
                        <span style="color:#aaa;font-size:.82rem">Không có</span>
                    <?php endif; ?>
                </td>
                <td class="actions-cell">
                    <a href="tenants.php?action=edit&id=<?= $t['id'] ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                       class="btn btn-edit">Sửa</a>
                    <?php if ($has_active): ?>
                        <span class="btn btn-delete-disabled"
                              title="Không thể xóa: đang có hợp đồng active">Xóa</span>
                    <?php elseif (is_admin()): ?>
                        <a href="tenants.php?action=delete&id=<?= $t['id'] ?>"
                           class="btn btn-delete"
                           onclick="return confirm('Xóa khách thuê <?= htmlspecialchars($t['full_name'], ENT_QUOTES) ?>? Hành động này không thể hoàn tác.')">
                            Xóa
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Form thêm / sửa khách thuê -->
<div class="card">
    <?php if ($edit_tenant): ?>
        <p class="card-title">Chỉnh Sửa — <?= htmlspecialchars($edit_tenant['full_name']) ?></p>
        <form method="POST" action="tenants.php">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id"     value="<?= (int)$edit_tenant['id'] ?>">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="edit_full_name">Họ và tên <span style="color:red">*</span></label>
                    <input type="text" id="edit_full_name" name="full_name"
                           value="<?= htmlspecialchars($edit_tenant['full_name']) ?>"
                           maxlength="100" required>
                </div>
                <div class="form-group">
                    <label for="edit_id_card">CMND / CCCD <span style="color:red">*</span></label>
                    <input type="text" id="edit_id_card" name="id_card"
                           value="<?= htmlspecialchars($edit_tenant['id_card']) ?>"
                           maxlength="20" pattern="\d{9,12}" required>
                    <span class="hint">9–12 chữ số</span>
                </div>
                <div class="form-group">
                    <label for="edit_phone">Số điện thoại <span style="color:red">*</span></label>
                    <input type="text" id="edit_phone" name="phone"
                           value="<?= htmlspecialchars($edit_tenant['phone']) ?>"
                           maxlength="15" pattern="0\d{8,9}" required>
                    <span class="hint">VD: 0912345678</span>
                </div>
                <div class="form-group">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email"
                           value="<?= htmlspecialchars($edit_tenant['email'] ?? '') ?>"
                           maxlength="100">
                </div>
                <div class="form-group form-group-full">
                    <label for="edit_address">Địa chỉ thường trú</label>
                    <textarea id="edit_address" name="permanent_address"
                              rows="2" maxlength="500"><?= htmlspecialchars($edit_tenant['permanent_address'] ?? '') ?></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                    <a href="tenants.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>"
                       class="btn btn-secondary">Hủy</a>
                </div>
            </div>
        </form>
    <?php else: ?>
        <p class="card-title">Thêm Khách Thuê Mới</p>
        <form method="POST" action="tenants.php">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="full_name">Họ và tên <span style="color:red">*</span></label>
                    <input type="text" id="full_name" name="full_name"
                           placeholder="Nguyễn Văn A" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label for="id_card">CMND / CCCD <span style="color:red">*</span></label>
                    <input type="text" id="id_card" name="id_card"
                           placeholder="034095001234" maxlength="20" pattern="\d{9,12}" required>
                    <span class="hint">9–12 chữ số</span>
                </div>
                <div class="form-group">
                    <label for="phone">Số điện thoại <span style="color:red">*</span></label>
                    <input type="text" id="phone" name="phone"
                           placeholder="0912345678" maxlength="15" pattern="0\d{8,9}" required>
                    <span class="hint">VD: 0912345678</span>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           placeholder="example@email.com" maxlength="100">
                </div>
                <div class="form-group form-group-full">
                    <label for="permanent_address">Địa chỉ thường trú</label>
                    <textarea id="permanent_address" name="permanent_address"
                              rows="2" maxlength="500"
                              placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành"></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Thêm khách thuê</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>