<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

requireRole('production', 'manager', 'director', 'accountant');

$pdo  = getDBConnection();
$user = currentUser();

// Helper: lấy owner của 1 đơn OT (id + role) để kiểm tra canApprove
function getOtOwner(PDO $pdo, int $ot_id): ?array {
    $s = $pdo->prepare("
        SELECT u.id, r.name AS role
        FROM overtime_requests ot
        JOIN users u ON ot.user_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE ot.id = ? AND ot.status = 'pending'
    ");
    $s->execute([$ot_id]);
    $row = $s->fetch();
    return $row ?: null;
}

// ── XỬ LÝ DUYỆT / TỪ CHỐI / XÓA ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action        = $_POST['action'] ?? '';
    $ot_id         = (int)($_POST['ot_id'] ?? 0);
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    // ── Giám đốc override đơn OT đã duyệt/từ chối ──
    if ($action === 'director_override_ot') {
        if (!hasRole('director')) {
            setFlash('danger', '⛔ Bạn không có quyền thực hiện thao tác này.');
            header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
            exit();
        }

        $newStatus = $_POST['new_status'] ?? '';
        $note = trim($_POST['override_note'] ?? '');
        $ownerStmt = $pdo->prepare("
            SELECT ot.id, ot.user_id, ot.status, ot.ot_date, ot.start_time, ot.end_time, ot.hours, r.name AS owner_role
            FROM overtime_requests ot
            JOIN users u ON ot.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE ot.id = ? AND ot.status IN ('approved', 'rejected')
        ");
        $ownerStmt->execute([$ot_id]);
        $ownerRow = $ownerStmt->fetch();
        $ownerForCheck = $ownerRow ? ['id' => $ownerRow['user_id'], 'role' => $ownerRow['owner_role']] : null;

        if ($ownerRow && $ownerForCheck && canApprove($user, $ownerForCheck) && in_array($newStatus, ['pending', 'rejected'], true)) {
            try {
                $pdo->beginTransaction();
                if ($newStatus === 'pending') {
                    $pdo->prepare("UPDATE overtime_requests SET status = 'pending', approved_by = NULL, approved_at = NULL, reject_reason = NULL WHERE id = ?")
                        ->execute([$ot_id]);
                } else {
                    $pdo->prepare("UPDATE overtime_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), reject_reason = ? WHERE id = ?")
                        ->execute([$user['id'], $note, $ot_id]);
                }

                $statusLabel = $newStatus === 'pending' ? 'thu hồi về chờ duyệt' : 'từ chối';
                $msg = "⚠️ Đơn OT ngày " . formatDate($ownerRow['ot_date']) .
                       " ({$ownerRow['start_time']}–{$ownerRow['end_time']}, {$ownerRow['hours']} giờ) đã bị giám đốc {$statusLabel}" .
                       ($note ? ": $note" : '.');
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Giám đốc đã cập nhật đơn OT', ?, 'ot_request', ?)")
                    ->execute([$ownerRow['user_id'], $msg, $ot_id]);
                $pdo->commit();

                setFlash('success', '✅ Đã cập nhật trạng thái đơn OT.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('danger', '❌ Không thể cập nhật đơn OT.');
            }
        } else {
            setFlash('danger', '❌ Không thể thực hiện thao tác này.');
        }

        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // ── Giám đốc sửa tay giờ OT (mọi trạng thái) ──
    if ($action === 'director_edit_hours') {
        if ($user['role'] !== 'director') {
            setFlash('danger', '⛔ Bạn không có quyền thực hiện thao tác này.');
            header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
            exit();
        }

        $start_time = trim($_POST['start_time'] ?? '');
        $end_time   = trim($_POST['end_time'] ?? '');
        $note       = trim($_POST['edit_note'] ?? '');
        if (!$ot_id || !$start_time || !$end_time) {
            setFlash('danger', '❌ Dữ liệu không hợp lệ.');
            header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
            exit();
        }

        $startDt = DateTime::createFromFormat('H:i', $start_time);
        $endDt   = DateTime::createFromFormat('H:i', $end_time);
        if (!$startDt || !$endDt) {
            setFlash('danger', '❌ Định dạng giờ không hợp lệ.');
            header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
            exit();
        }

        $startMin = ((int)$startDt->format('H')) * 60 + (int)$startDt->format('i');
        $endMin   = ((int)$endDt->format('H')) * 60 + (int)$endDt->format('i');
        if ($endMin <= $startMin) $endMin += 1440; // qua ngày hôm sau
        $hours = round(($endMin - $startMin) / 60, 2);
        if ($hours <= 0 || $hours > 24) {
            setFlash('danger', '❌ Số giờ OT không hợp lệ.');
            header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
            exit();
        }

        $otStmt = $pdo->prepare("SELECT user_id, ot_date, status FROM overtime_requests WHERE id = ? AND status IN ('pending','approved','rejected')");
        $otStmt->execute([$ot_id]);
        $otRow = $otStmt->fetch();
        if (!$otRow) {
            setFlash('danger', '❌ Không tìm thấy đơn OT hợp lệ.');
            header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
            exit();
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE overtime_requests SET start_time = ?, end_time = ?, hours = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$start_time, $end_time, $hours, $user['id'], $ot_id]);
            $msg = "Giám đốc đã cập nhật số giờ OT ngày " . formatDate($otRow['ot_date']) .
                   " thành {$hours}h ({$start_time}–{$end_time})" . ($note ? ". Ghi chú: {$note}" : ".");
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Giám đốc cập nhật giờ OT', ?, 'ot_request', ?)")
                ->execute([$otRow['user_id'], $msg, $ot_id]);
            $pdo->commit();
            setFlash('success', "✅ Đã cập nhật số giờ OT thành {$hours}h.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('danger', '❌ Không thể cập nhật giờ OT.');
        }

        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // ── Duyệt 1 đơn ──
    if ($action === 'approve') {
        $owner = getOtOwner($pdo, $ot_id);
        if (!$owner || !canApprove($user, $owner)) {
            setFlash('danger', '⛔ Bạn không có quyền duyệt đơn này.');
            header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
            exit();
        }
        $stmt = $pdo->prepare("
            UPDATE overtime_requests
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$user['id'], $ot_id]);
        if ($stmt->rowCount()) {
            $ot = $pdo->prepare("SELECT user_id, ot_date, start_time, end_time, hours FROM overtime_requests WHERE id = ?");
            $ot->execute([$ot_id]);
            $otData = $ot->fetch();
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?,?,?,'ot_approved',?)")
                ->execute([
                    $otData['user_id'],
                    '✅ Đơn OT được duyệt',
                    'Đơn OT ngày ' . formatDate($otData['ot_date']) .
                    ' (' . $otData['start_time'] . '–' . $otData['end_time'] . ', ' . $otData['hours'] . ' giờ) đã được duyệt bởi ' . $user['full_name'],
                    $ot_id
                ]);
            setFlash('success', '✅ Đã duyệt đơn OT.');
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // ── Từ chối 1 đơn ──
    if ($action === 'reject') {
        if (empty($reject_reason)) {
            setFlash('danger', '❌ Vui lòng nhập lý do từ chối.');
        } else {
            $owner = getOtOwner($pdo, $ot_id);
            if (!$owner || !canApprove($user, $owner)) {
                setFlash('danger', '⛔ Bạn không có quyền từ chối đơn này.');
                header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
                exit();
            }
            $stmt = $pdo->prepare("
                UPDATE overtime_requests
                SET status = 'rejected', approved_by = ?, approved_at = NOW(), reject_reason = ?
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$user['id'], $reject_reason, $ot_id]);
            if ($stmt->rowCount()) {
                $ot = $pdo->prepare("SELECT user_id, ot_date FROM overtime_requests WHERE id = ?");
                $ot->execute([$ot_id]);
                $otData = $ot->fetch();
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?,?,?,'ot_rejected',?)")
                    ->execute([
                        $otData['user_id'],
                        '❌ Đơn OT bị từ chối',
                        'Đơn OT ngày ' . formatDate($otData['ot_date']) . ' bị từ chối. Lý do: ' . $reject_reason,
                        $ot_id
                    ]);
                setFlash('warning', '⚠️ Đã từ chối đơn OT.');
            }
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // ── Duyệt hàng loạt ──
    if ($action === 'bulk_approve') {
        $ids = $_POST['selected_ids'] ?? [];
        if (empty($ids)) {
            setFlash('danger', 'Vui lòng chọn ít nhất 1 đơn.');
        } else {
            $count = 0;
            foreach ($ids as $id) {
                $id    = (int)$id;
                $owner = getOtOwner($pdo, $id);
                if (!$owner || !canApprove($user, $owner)) continue; // bỏ qua đơn không có quyền
                $stmt = $pdo->prepare("UPDATE overtime_requests SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='pending'");
                $stmt->execute([$user['id'], $id]);
                if ($stmt->rowCount()) {
                    $count++;
                    $ot = $pdo->prepare("SELECT user_id, ot_date, hours FROM overtime_requests WHERE id=?");
                    $ot->execute([$id]);
                    $otData = $ot->fetch();
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?,?,?,'ot_approved',?)")
                        ->execute([
                            $otData['user_id'],
                            '✅ Đơn OT được duyệt',
                            'Đơn OT ngày ' . formatDate($otData['ot_date']) . ' (' . $otData['hours'] . ' giờ) đã được duyệt.',
                            $id
                        ]);
                }
            }
            setFlash('success', "✅ Đã duyệt <strong>$count</strong> đơn OT.");
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // ── Từ chối hàng loạt ──
    if ($action === 'bulk_reject') {
        $ids         = $_POST['selected_ids'] ?? [];
        $bulk_reason = trim($_POST['bulk_reject_reason'] ?? '');
        if (empty($ids)) {
            setFlash('danger', 'Vui lòng chọn ít nhất 1 đơn.');
        } elseif (empty($bulk_reason)) {
            setFlash('danger', 'Vui lòng nhập lý do từ chối hàng loạt.');
        } else {
            $count = 0;
            foreach ($ids as $id) {
                $id    = (int)$id;
                $owner = getOtOwner($pdo, $id);
                if (!$owner || !canApprove($user, $owner)) continue;
                $stmt = $pdo->prepare("UPDATE overtime_requests SET status='rejected', approved_by=?, approved_at=NOW(), reject_reason=? WHERE id=? AND status='pending'");
                $stmt->execute([$user['id'], $bulk_reason, $id]);
                if ($stmt->rowCount()) {
                    $count++;
                    $ot = $pdo->prepare("SELECT user_id, ot_date FROM overtime_requests WHERE id=?");
                    $ot->execute([$id]);
                    $otData = $ot->fetch();
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?,?,?,'ot_rejected',?)")
                        ->execute([
                            $otData['user_id'],
                            '❌ Đơn OT bị từ chối',
                            'Đơn OT ngày ' . formatDate($otData['ot_date']) . ' bị từ chối. Lý do: ' . $bulk_reason,
                            $id
                        ]);
                }
            }
            setFlash('warning', "⚠️ Đã từ chối <strong>$count</strong> đơn OT.");
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // ── Xóa đơn OT (chỉ director) ──
    if ($action === 'delete_ot') {
        if ($user['role'] !== 'director') {
            setFlash('danger', '⛔ Bạn không có quyền thực hiện thao tác này.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM overtime_requests WHERE id = ?");
            $stmt->execute([$ot_id]);
            if ($stmt->rowCount()) {
                setFlash('success', '🗑️ Đã xóa đơn OT.');
            }
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }
}

// ── BỘ LỌC ────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status']    ?? 'pending';
$filterDept   = (int)($_GET['dept'] ?? 0);
$filterMonth  = (int)($_GET['month'] ?? date('m'));
$filterYear   = (int)($_GET['year']  ?? date('Y'));
$filterUser   = (int)($_GET['user_id'] ?? 0);
$myLevel      = getRoleLevel($user['role']);

// ── Query danh sách đơn OT — chỉ hiển thị đơn cấp dưới mình ──
$sql = "
    SELECT ot.*,
           u.full_name, u.employee_code,
           r.name AS requester_role,
           d.name AS dept_name,
           ws.shift_name, ws.color AS shift_color,
           ws.ot_multiplier, ws.weekend_multiplier, ws.holiday_multiplier,
           a.full_name AS approver_name
    FROM overtime_requests ot
    JOIN users u ON ot.user_id = u.id
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN work_shifts ws ON ot.shift_id = ws.id
    LEFT JOIN users a ON ot.approved_by = a.id
    WHERE MONTH(ot.ot_date) = ? AND YEAR(ot.ot_date) = ?
      AND ot.user_id != ?
      AND (
           (r.name = 'employee'   AND ? >= 2)
        OR (r.name = 'production' AND ? >= 3)
        OR (r.name = 'manager'    AND ? >= 4)
        OR (r.name = 'accountant' AND ? >= 5)
      )
";
$params = [$filterMonth, $filterYear, $user['id'], $myLevel, $myLevel, $myLevel, $myLevel];
if ($filterStatus !== 'all') { $sql .= " AND ot.status = ?";       $params[] = $filterStatus; }
if ($filterDept)             { $sql .= " AND u.department_id = ?"; $params[] = $filterDept; }
if ($filterUser)             { $sql .= " AND ot.user_id = ?";      $params[] = $filterUser; }
$sql .= " ORDER BY FIELD(ot.status,'pending','approved','rejected'), ot.ot_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// ── Thống kê tháng (toàn bộ, không lọc theo quyền) ──
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')  AS pending,
        SUM(status = 'approved') AS approved,
        SUM(status = 'rejected') AS rejected,
        SUM(CASE WHEN status = 'approved' THEN hours ELSE 0 END) AS total_hours,
        SUM(CASE WHEN status = 'approved' AND ot_type = 'weekday' THEN hours ELSE 0 END) AS weekday_hours,
        SUM(CASE WHEN status = 'approved' AND ot_type = 'weekend' THEN hours ELSE 0 END) AS weekend_hours,
        SUM(CASE WHEN status = 'approved' AND ot_type = 'holiday' THEN hours ELSE 0 END) AS holiday_hours
    FROM overtime_requests
    WHERE MONTH(ot_date) = ? AND YEAR(ot_date) = ?
");
$statsStmt->execute([$filterMonth, $filterYear]);
$stats = $statsStmt->fetch();

$statTotalHours   = (float)($stats['total_hours']   ?? 0);
$statWeekdayHours = (float)($stats['weekday_hours'] ?? 0);
$statWeekendHours = (float)($stats['weekend_hours'] ?? 0);
$statHolidayHours = (float)($stats['holiday_hours'] ?? 0);
$statPending      = (int)  ($stats['pending']       ?? 0);
$statApproved     = (int)  ($stats['approved']      ?? 0);

$depts   = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$empList = $pdo->query("SELECT id, full_name, employee_code FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

$otTypeLabel = [
    'weekday'       => ['Ngày thường',         'secondary'],
    'weekend'       => ['Cuối tuần',            'warning'],
    'holiday'       => ['Ngày lễ',              'danger'],
    'night'         => ['🌙 Đêm (cũ) ×1.3',    'dark'],
    'night_weekday' => ['🌙 Đêm thường ×2.1',   'dark'],
    'night_weekend' => ['🌙 Đêm CN ×2.7',       'secondary'],
    'night_holiday' => ['🌙 Đêm lễ ×3.9',       'danger'],
];
$statusLabel = [
    'pending'  => ['⌛ Chờ duyệt', 'warning'],
    'approved' => ['✅ Đã duyệt',  'success'],
    'rejected' => ['❌ Từ chối',   'danger'],
];

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">✅ Duyệt Tăng ca (OT)</h4>
            <p class="text-muted small mb-0">Tháng <?= $filterMonth ?>/<?= $filterYear ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="/erp/modules/attendance/import_ot.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-upload me-1"></i>Import OT
            </a>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- Thống kê tháng -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-warning"><?= $statPending ?></div>
                <div class="small text-muted">⌛ Chờ duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-success"><?= $statApproved ?></div>
                <div class="small text-muted">✅ Đã duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-primary"><?= number_format($statTotalHours, 1) ?></div>
                <div class="small text-muted">⏱️ Tổng giờ OT</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="d-flex justify-content-center gap-2">
                    <div>
                        <div class="fw-bold text-secondary"><?= number_format($statWeekdayHours, 1) ?>h</div>
                        <div style="font-size:10px;" class="text-muted">Thường</div>
                    </div>
                    <div>
                        <div class="fw-bold text-warning"><?= number_format($statWeekendHours, 1) ?>h</div>
                        <div style="font-size:10px;" class="text-muted">CN</div>
                    </div>
                    <div>
                        <div class="fw-bold text-danger"><?= number_format($statHolidayHours, 1) ?>h</div>
                        <div style="font-size:10px;" class="text-muted">Lễ</div>
                    </div>
                </div>
                <div class="small text-muted mt-1">📊 Phân loại giờ</div>
            </div>
        </div>
    </div>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Tháng</label>
                    <select name="month" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $filterMonth ? 'selected' : '' ?>>Tháng <?= $m ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label small fw-semibold mb-1">Năm</label>
                    <select name="year" class="form-select form-select-sm">
                        <?php for ($y = date('Y')-1; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $filterYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Trạng thái</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="pending"  <?= $filterStatus==='pending'  ?'selected':'' ?>>⌛ Chờ duyệt</option>
                        <option value="approved" <?= $filterStatus==='approved' ?'selected':'' ?>>✅ Đã duyệt</option>
                        <option value="rejected" <?= $filterStatus==='rejected' ?'selected':'' ?>>❌ Từ chối</option>
                        <option value="all"      <?= $filterStatus==='all'      ?'selected':'' ?>>Tất cả</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Phòng ban</label>
                    <select name="dept" class="form-select form-select-sm">
                        <option value="">Tất cả</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $filterDept==$d['id']?'selected':'' ?>>
                            <?= htmlspecialchars($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Nhân viên</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">Tất cả nhân viên</option>
                        <?php foreach ($empList as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filterUser==$e['id']?'selected':'' ?>>
                            <?= htmlspecialchars($e['employee_code'] . ' - ' . $e['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Lọc</button>
                    <a href="/erp/modules/attendance/ot_manage.php" class="btn btn-outline-secondary btn-sm">↺</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Form bulk actions -->
    <form method="POST" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" id="bulkAction" value="bulk_approve">
        <div id="bulkIdsContainer"></div>
        <input type="hidden" name="bulk_reject_reason" id="bulkRejectReasonInput" value="">
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-bold">
                📋 Danh sách đơn OT
                <span class="badge bg-secondary ms-1"><?= count($requests) ?></span>
            </span>
            <?php if ($filterStatus === 'pending' && !empty($requests)): ?>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="selectAll"
                           onchange="toggleAll(this.checked)">
                    <label class="form-check-label small" for="selectAll">Chọn tất cả</label>
                </div>
                <button type="button" class="btn btn-success btn-sm" id="bulkApproveBtn" disabled
                        onclick="submitBulk('bulk_approve')">
                    <i class="fas fa-check-double me-1"></i>Duyệt hàng loạt
                    <span id="bulkCount" class="badge bg-white text-success ms-1">0</span>
                </button>
                <button type="button" class="btn btn-danger btn-sm" id="bulkRejectBtn" disabled
                        onclick="showBulkRejectModal()">
                    <i class="fas fa-times-circle me-1"></i>Từ chối hàng loạt
                    <span id="bulkCount2" class="badge bg-white text-danger ms-1">0</span>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body p-0">
            <?php if (empty($requests)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-clipboard-check fa-3x mb-3 d-block opacity-25"></i>
                Không có đơn OT nào
            </div>
            <?php else: ?>

            <!-- Desktop: table -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <?php if ($filterStatus === 'pending'): ?><th width="40"></th><?php endif; ?>
                            <th>Nhân viên</th>
                            <th>Ngày OT</th>
                            <th>Giờ OT</th>
                            <th>Loại</th>
                            <th>Hệ số</th>
                            <th>Lý do</th>
                            <th>Ngày gửi</th>
                            <th>Trạng thái</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $ot):
                        $otp  = $otTypeLabel[$ot['ot_type']] ?? ['?', 'secondary'];
                        $st   = $statusLabel[$ot['status']];
                        $mult = match($ot['ot_type']) {
                            'weekend'       => $ot['weekend_multiplier'] ?? 2.0,
                            'holiday'       => $ot['holiday_multiplier'] ?? 3.0,
                            'night_weekday' => 2.1,
                            'night_weekend' => 2.7,
                            'night_holiday' => 3.9,
                            'night'         => 1.3,
                            default         => $ot['ot_multiplier'] ?? 1.5,
                        };
                        $requesterForCheck = ['id' => $ot['user_id'], 'role' => $ot['requester_role']];
                        $canAct = ($ot['status'] === 'pending') && canApprove($user, $requesterForCheck);
                    ?>
                    <tr class="<?= $ot['status']==='rejected'?'opacity-50':'' ?>">
                        <?php if ($filterStatus === 'pending'): ?>
                        <td>
                            <?php if ($canAct): ?>
                            <input type="checkbox" value="<?= $ot['id'] ?>"
                                   class="form-check-input ot-check" onchange="updateBulkBtn()">
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($ot['full_name']) ?></div>
                            <div class="text-muted" style="font-size:11px;">
                                <?= $ot['employee_code'] ?> · <?= htmlspecialchars($ot['dept_name'] ?? '') ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-semibold small"><?= formatDate($ot['ot_date']) ?></div>
                            <div style="font-size:11px;" class="text-muted">
                                <?= date('l', strtotime($ot['ot_date'])) ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold text-primary"><?= $ot['hours'] ?>h</div>
                            <div style="font-size:11px;" class="text-muted">
                                <?= substr($ot['start_time'],0,5) ?>–<?= substr($ot['end_time'],0,5) ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-<?= $otp[1] ?> text-<?= $otp[1]==='warning'?'dark':'white' ?>">
                                <?= $otp[0] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($ot['shift_name']): ?>
                            <span class="badge" style="background:<?= $ot['shift_color'] ?>; font-size:11px;">
                                <?= $mult ?>x
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted" title="<?= htmlspecialchars($ot['reason']) ?>">
                                <?= mb_strimwidth(htmlspecialchars($ot['reason']), 0, 30, '...') ?>
                            </small>
                        </td>
                        <td><small class="text-muted"><?= formatDate($ot['created_at'], 'd/m H:i') ?></small></td>
                        <td>
                            <span class="badge bg-<?= $st[1] ?> text-<?= $st[1]==='warning'?'dark':'white' ?>">
                                <?= $st[0] ?>
                            </span>
                            <?php if ($ot['status'] !== 'pending' && $ot['approver_name']): ?>
                            <div style="font-size:10px;" class="text-muted"><?= htmlspecialchars($ot['approver_name']) ?></div>
                            <?php endif; ?>
                            <?php if ($ot['status'] === 'rejected' && $ot['reject_reason']): ?>
                            <div style="font-size:10px;" class="text-danger" title="<?= htmlspecialchars($ot['reject_reason']) ?>">
                                <?= mb_strimwidth(htmlspecialchars($ot['reject_reason']), 0, 20, '...') ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($canAct): ?>
                            <div class="d-flex gap-1 justify-content-center">
                                <button type="button" class="btn btn-xs btn-success"
                                        onclick="approveOne(<?= $ot['id'] ?>, '<?= htmlspecialchars(addslashes($ot['full_name'])) ?>')"
                                        title="Duyệt">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button type="button" class="btn btn-xs btn-danger"
                                        onclick="showRejectModal(<?= $ot['id'] ?>, '<?= htmlspecialchars(addslashes($ot['full_name'])) ?>')"
                                        title="Từ chối">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php if ($user['role'] === 'director'): ?>
                                <button type="button" class="btn btn-xs btn-outline-primary"
                                        onclick='showEditHours(<?= $ot['id'] ?>, <?= htmlspecialchars(json_encode($ot["full_name"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($ot["start_time"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($ot["end_time"]), ENT_QUOTES, "UTF-8") ?>, <?= (float)$ot["hours"] ?>)'
                                        title="Sửa giờ OT">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="d-flex gap-1 justify-content-center">
                                <button type="button" class="btn btn-xs btn-outline-secondary"
                                        onclick="showDetail(<?= htmlspecialchars(json_encode($ot)) ?>)"
                                        title="Chi tiết">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if (hasRole('director') && in_array($ot['status'], ['approved', 'rejected'], true)): ?>
                                <button type="button" class="btn btn-xs btn-outline-warning"
                                        onclick='showOverrideOt(<?= $ot['id'] ?>, <?= htmlspecialchars(json_encode($ot["full_name"]), ENT_QUOTES, "UTF-8") ?>)'
                                        title="Override">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($user['role'] === 'director'): ?>
                                <button type="button" class="btn btn-xs btn-outline-primary"
                                        onclick='showEditHours(<?= $ot['id'] ?>, <?= htmlspecialchars(json_encode($ot["full_name"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($ot["start_time"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($ot["end_time"]), ENT_QUOTES, "UTF-8") ?>, <?= (float)$ot["hours"] ?>)'
                                        title="Sửa giờ OT">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($user['role'] === 'director' && $ot['status'] === 'approved'): ?>
                                <button type="button" class="btn btn-xs btn-outline-danger"
                                        onclick="deleteOt(<?= $ot['id'] ?>, '<?= htmlspecialchars(addslashes($ot['full_name'])) ?>')"
                                        title="Xóa đơn OT">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile: cards -->
            <div class="d-md-none">
                <?php foreach ($requests as $ot):
                    $otp = $otTypeLabel[$ot['ot_type']] ?? ['?', 'secondary'];
                    $st  = $statusLabel[$ot['status']];
                    $requesterForCheck = ['id' => $ot['user_id'], 'role' => $ot['requester_role']];
                    $canAct = ($ot['status'] === 'pending') && canApprove($user, $requesterForCheck);
                ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex justify-content-between mb-1">
                        <div>
                            <strong class="small"><?= htmlspecialchars($ot['full_name']) ?></strong>
                            <span class="text-muted small ms-1">(<?= $ot['employee_code'] ?>)</span>
                        </div>
                        <span class="badge bg-<?= $st[1] ?> text-<?= $st[1]==='warning'?'dark':'white' ?>"><?= $st[0] ?></span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 small mb-2">
                        <span><i class="fas fa-calendar me-1 text-primary"></i><?= formatDate($ot['ot_date']) ?></span>
                        <span><i class="fas fa-clock me-1 text-success"></i><?= $ot['hours'] ?>h (<?= substr($ot['start_time'],0,5) ?>–<?= substr($ot['end_time'],0,5) ?>)</span>
                        <span class="badge bg-<?= $otp[1] ?>"><?= $otp[0] ?></span>
                    </div>
                    <div class="small text-muted mb-2"><?= htmlspecialchars($ot['reason']) ?></div>
                    <?php if ($canAct): ?>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success btn-sm flex-grow-1"
                                onclick="approveOne(<?= $ot['id'] ?>, '<?= htmlspecialchars(addslashes($ot['full_name'])) ?>')">
                            ✅ Duyệt
                        </button>
                        <button type="button" class="btn btn-danger btn-sm flex-grow-1"
                                onclick="showRejectModal(<?= $ot['id'] ?>, '<?= htmlspecialchars(addslashes($ot['full_name'])) ?>')">
                            ❌ Từ chối
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if (hasRole('director') && in_array($ot['status'], ['approved', 'rejected'], true)): ?>
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-warning btn-sm w-100"
                                onclick='showOverrideOt(<?= $ot['id'] ?>, <?= htmlspecialchars(json_encode($ot["full_name"]), ENT_QUOTES, "UTF-8") ?>)'>
                            ✏️ Override
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'director'): ?>
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100"
                                onclick='showEditHours(<?= $ot['id'] ?>, <?= htmlspecialchars(json_encode($ot["full_name"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($ot["start_time"]), ENT_QUOTES, "UTF-8") ?>, <?= htmlspecialchars(json_encode($ot["end_time"]), ENT_QUOTES, "UTF-8") ?>, <?= (float)$ot["hours"] ?>)'>
                            ⏱️ Sửa giờ
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'director' && $ot['status'] === 'approved'): ?>
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-danger btn-sm w-100"
                                onclick="deleteOt(<?= $ot['id'] ?>, '<?= htmlspecialchars(addslashes($ot['full_name'])) ?>')">
                            🗑️ Xóa đơn OT
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<!-- Modal Từ chối 1 đơn -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="rejectForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="ot_id" id="rejectOtId">
                <div class="modal-header border-0">
                    <h6 class="modal-title">❌ Từ chối đơn OT của <strong id="rejectEmpName"></strong></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Lý do từ chối <span class="text-danger">*</span></label>
                    <textarea name="reject_reason" class="form-control" rows="3" required
                              placeholder="Nhập lý do từ chối..."></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                    <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Từ chối hàng loạt -->
<div class="modal fade" id="bulkRejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h6 class="modal-title">❌ Từ chối hàng loạt <span id="bulkRejectCount" class="badge bg-danger ms-1"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Lý do từ chối <span class="text-danger">*</span></label>
                <textarea id="bulkRejectReason" class="form-control" rows="3"
                          placeholder="Nhập lý do từ chối cho tất cả đơn đã chọn..."></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-danger" onclick="confirmBulkReject()">
                    Xác nhận từ chối hàng loạt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Override OT (Giám đốc) -->
<div class="modal fade" id="overrideOtModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="director_override_ot">
                <input type="hidden" name="ot_id" id="overrideOtId">
                <div class="modal-header bg-warning bg-opacity-10 border-0">
                    <h6 class="modal-title fw-bold">✏️ Giám đốc Override OT — <span id="overrideOtEmp"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Đổi trạng thái thành</label>
                        <select name="new_status" class="form-select" required>
                            <option value="pending">⌛ Thu hồi về Chờ duyệt</option>
                            <option value="rejected">❌ Từ chối</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú lý do</label>
                        <textarea name="override_note" class="form-control" rows="3" placeholder="Nhập lý do override..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                    <button type="submit" class="btn btn-warning fw-bold">Xác nhận Override</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Sửa giờ OT (Giám đốc) -->
<div class="modal fade" id="editHoursModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="director_edit_hours">
                <input type="hidden" name="ot_id" id="editHoursOtId">
                <div class="modal-header bg-primary bg-opacity-10 border-0">
                    <h6 class="modal-title fw-bold">⏱️ Sửa giờ OT — <span id="editHoursEmp"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Giờ bắt đầu <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="editHoursStart" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Giờ kết thúc <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="editHoursEnd" class="form-control" required>
                        </div>
                    </div>
                    <div class="mt-3 p-2 bg-light rounded text-center">
                        Số giờ tính được: <strong id="editHoursCalc" class="text-primary fs-5">—</strong>
                    </div>
                    <div class="mb-0 mt-3">
                        <label class="form-label fw-semibold">Ghi chú lý do</label>
                        <textarea name="edit_note" class="form-control" rows="2" placeholder="Lý do chỉnh sửa giờ OT..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                    <button type="submit" class="btn btn-primary fw-bold">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Chi tiết -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h6 class="modal-title">📋 Chi tiết đơn OT</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailBody"></div>
        </div>
    </div>
</div>

<style>.btn-xs { padding: 3px 10px; font-size: 12px; }</style>

<script>
const CSRF = <?= json_encode($csrf) ?>;

function approveOne(id, name) {
    if (!confirm('Duyệt đơn OT của ' + name + '?')) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `<input name="csrf_token" value="${CSRF}"><input name="action" value="approve"><input name="ot_id" value="${id}">`;
    document.body.appendChild(f); f.submit();
}

function showRejectModal(id, name) {
    document.getElementById('rejectOtId').value = id;
    document.getElementById('rejectEmpName').textContent = name;
    document.querySelector('#rejectForm textarea').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function showOverrideOt(id, name) {
    document.getElementById('overrideOtId').value = id;
    document.getElementById('overrideOtEmp').textContent = name;
    document.querySelector('#overrideOtModal textarea').value = '';
    new bootstrap.Modal(document.getElementById('overrideOtModal')).show();
}

function recalcEditHours() {
    const s = document.getElementById('editHoursStart').value;
    const e = document.getElementById('editHoursEnd').value;
    if (s && e) {
        let sm = parseInt(s.split(':')[0], 10) * 60 + parseInt(s.split(':')[1], 10);
        let em = parseInt(e.split(':')[0], 10) * 60 + parseInt(e.split(':')[1], 10);
        if (em <= sm) em += 1440;
        const h = ((em - sm) / 60).toFixed(2);
        document.getElementById('editHoursCalc').textContent = h + 'h';
    } else {
        document.getElementById('editHoursCalc').textContent = '—';
    }
}

function showEditHours(id, name, startTime, endTime, hours) {
    document.getElementById('editHoursOtId').value = id;
    document.getElementById('editHoursEmp').textContent = name;
    document.getElementById('editHoursStart').value = (startTime || '').substring(0, 5);
    document.getElementById('editHoursEnd').value = (endTime || '').substring(0, 5);
    document.getElementById('editHoursCalc').textContent = hours + 'h';
    document.querySelector('#editHoursModal textarea').value = '';
    recalcEditHours();
    new bootstrap.Modal(document.getElementById('editHoursModal')).show();
}
document.getElementById('editHoursStart').addEventListener('change', recalcEditHours);
document.getElementById('editHoursEnd').addEventListener('change', recalcEditHours);

function getCheckedIds() {
    return [...document.querySelectorAll('.ot-check:checked')].map(cb => cb.value);
}

function updateBulkBtn() {
    const count = getCheckedIds().length;
    const total = document.querySelectorAll('.ot-check').length;
    ['bulkApproveBtn','bulkRejectBtn'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.disabled = count === 0;
    });
    ['bulkCount','bulkCount2'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = count;
    });
    const sa = document.getElementById('selectAll');
    if (sa) { sa.checked = count === total && total > 0; sa.indeterminate = count > 0 && count < total; }
}

function toggleAll(checked) {
    document.querySelectorAll('.ot-check').forEach(cb => cb.checked = checked);
    updateBulkBtn();
}

function submitBulk(action) {
    const ids = getCheckedIds();
    if (ids.length === 0) { alert('Vui lòng chọn ít nhất 1 đơn.'); return; }
    if (!confirm((action === 'bulk_approve' ? 'Duyệt' : 'Từ chối') + ' ' + ids.length + ' đơn OT đã chọn?')) return;
    document.getElementById('bulkAction').value = action;
    const container = document.getElementById('bulkIdsContainer');
    container.innerHTML = '';
    ids.forEach(id => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'selected_ids[]'; inp.value = id;
        container.appendChild(inp);
    });
    document.getElementById('bulkForm').submit();
}

function showBulkRejectModal() {
    const ids = getCheckedIds();
    if (ids.length === 0) { alert('Vui lòng chọn ít nhất 1 đơn.'); return; }
    document.getElementById('bulkRejectCount').textContent = ids.length + ' đơn';
    document.getElementById('bulkRejectReason').value = '';
    new bootstrap.Modal(document.getElementById('bulkRejectModal')).show();
}

function confirmBulkReject() {
    const reason = document.getElementById('bulkRejectReason').value.trim();
    if (!reason) { alert('Vui lòng nhập lý do từ chối.'); return; }
    document.getElementById('bulkRejectReasonInput').value = reason;
    bootstrap.Modal.getInstance(document.getElementById('bulkRejectModal')).hide();
    submitBulk('bulk_reject');
}

function showDetail(ot) {
    const otTypeLabel = { weekday:'Ngày thường', weekend:'Cuối tuần', holiday:'Ngày lễ' };
    const statusLabel = { pending:'Chờ duyệt', approved:'Đã duyệt', rejected:'Từ chối' };
    document.getElementById('detailBody').innerHTML = `
        <table class="table table-sm">
            <tr><th>Nhân viên</th><td>${ot.full_name} (${ot.employee_code})</td></tr>
            <tr><th>Phòng ban</th><td>${ot.dept_name || '—'}</td></tr>
            <tr><th>Ngày OT</th><td>${ot.ot_date}</td></tr>
            <tr><th>Giờ OT</th><td>${ot.start_time} – ${ot.end_time} (${ot.hours} giờ)</td></tr>
            <tr><th>Loại</th><td>${otTypeLabel[ot.ot_type] || ot.ot_type}</td></tr>
            <tr><th>Lý do</th><td>${ot.reason}</td></tr>
            <tr><th>Trạng thái</th><td>${statusLabel[ot.status]}</td></tr>
            ${ot.approver_name ? `<tr><th>Người duyệt</th><td>${ot.approver_name}</td></tr>` : ''}
            ${ot.reject_reason ? `<tr><th>Lý do từ chối</th><td class="text-danger">${ot.reject_reason}</td></tr>` : ''}
        </table>`;
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function deleteOt(id, name) {
    if (!confirm('Bạn có chắc muốn XÓA đơn OT của ' + name + '?\nHành động này không thể hoàn tác!')) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `<input name="csrf_token" value="${CSRF}"><input name="action" value="delete_ot"><input name="ot_id" value="${id}">`;
    document.body.appendChild(f); f.submit();
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>