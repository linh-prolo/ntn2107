<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireRole('production', 'manager', 'director', 'accountant');

$user = currentUser();
$pdo = getDBConnection();

// Xử lý duyệt/từ chối
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $id = (int)$_POST['request_id'];
    $action = $_POST['action'] ?? ''; // approved / rejected

    // ── Tạo đơn thủ công ──────────────────────────────────────────────────
    if ($action === 'manual_create') {
        requireRole('director', 'manager', 'accountant', 'production');

        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $leaveType    = $_POST['leave_type'] ?? '';
        $startDate    = $_POST['start_date'] ?? '';
        $endDate      = $_POST['end_date'] ?? '';
        $reason       = trim($_POST['reason'] ?? '');
        $autoApprove  = isset($_POST['auto_approve']);

        $validTypes = ['annual', 'sick', 'unpaid', 'other'];
        $errors = [];
        if ($targetUserId <= 0) $errors[] = 'Vui lòng chọn nhân viên.';
        if (!in_array($leaveType, $validTypes, true)) $errors[] = 'Loại nghỉ không hợp lệ.';
        if (!$startDate || !$endDate) $errors[] = 'Vui lòng chọn ngày bắt đầu và kết thúc.';
        if ($startDate && $endDate && $startDate > $endDate) $errors[] = 'Ngày kết thúc phải sau ngày bắt đầu.';
        if ($reason === '') $errors[] = 'Vui lòng nhập lý do.';

        $totalDays = 0;
        if (empty($errors) && $startDate && $endDate) {
            $d1 = new DateTime($startDate);
            $d2 = new DateTime($endDate);
            $totalDays = (int)$d2->diff($d1)->days + 1;
        }

        if (empty($errors)) {
            try {
                $status    = $autoApprove ? 'approved' : 'pending';
                $approvedBy = $autoApprove ? $user['id'] : null;
                $approvedAt = $autoApprove ? date('Y-m-d H:i:s') : null;

                $pdo->prepare("
                    INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, total_days, reason, status, approved_by, approved_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$targetUserId, $leaveType, $startDate, $endDate, $totalDays, $reason, $status, $approvedBy, $approvedAt]);

                $newId = (int)$pdo->lastInsertId();

                // Thông báo cho nhân viên
                $statusTxt = $autoApprove ? 'đã được duyệt ngay' : 'đang chờ duyệt';
                $typeLabels = ['annual' => 'Phép năm', 'sick' => 'Ốm', 'unpaid' => 'Không lương', 'other' => 'Khác'];
                $typeTxt = $typeLabels[$leaveType] ?? $leaveType;
                $msg = "📋 Đơn nghỉ phép ({$typeTxt}) từ " . date('d/m/Y', strtotime($startDate)) . " đến " . date('d/m/Y', strtotime($endDate)) . " ({$totalDays} ngày) được tạo bởi quản lý, {$statusTxt}.";
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Đơn nghỉ phép được tạo thủ công', ?, 'leave_request', ?)")
                    ->execute([$targetUserId, $msg, $newId]);

                setFlash('success', '✅ Đã tạo đơn nghỉ phép thủ công thành công.');
            } catch (Throwable $e) {
                setFlash('danger', '❌ Không thể tạo đơn: ' . $e->getMessage());
            }
        } else {
            setFlash('danger', '❌ ' . implode(' ', $errors));
        }
        header('Location: /erp/modules/attendance/leave_manage.php?filter=' . ($_GET['filter'] ?? 'pending'));
        exit();
    }

    $reason = trim($_POST['reject_reason'] ?? '');

    // ── Giám đốc override đơn đã duyệt/từ chối ──
    if ($action === 'director_override') {
        if (!hasRole('director')) {
            setFlash('danger', '⛔ Bạn không có quyền thực hiện thao tác này.');
            header('Location: /erp/modules/attendance/leave_manage.php?filter=' . ($_GET['filter'] ?? 'approved'));
            exit();
        }

        $newStatus = $_POST['new_status'] ?? '';
        $note = trim($_POST['override_note'] ?? '');

        $ownerStmt = $pdo->prepare("
            SELECT lr.*, u.id AS owner_id, r.name AS owner_role
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE lr.id = ? AND lr.status IN ('approved', 'rejected')
        ");
        $ownerStmt->execute([$id]);
        $ownerRow = $ownerStmt->fetch();
        $ownerForCheck = $ownerRow ? ['id' => $ownerRow['owner_id'], 'role' => $ownerRow['owner_role']] : null;

        if ($ownerRow && $ownerForCheck && canApprove($user, $ownerForCheck) && in_array($newStatus, ['pending', 'rejected'], true)) {
            try {
                $pdo->beginTransaction();
                if ($newStatus === 'pending') {
                    $pdo->prepare("UPDATE leave_requests SET status = 'pending', approved_by = NULL, approved_at = NULL, reject_reason = NULL WHERE id = ?")
                        ->execute([$id]);
                } else {
                    $pdo->prepare("UPDATE leave_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), reject_reason = ? WHERE id = ?")
                        ->execute([$user['id'], $note, $id]);
                }

                $statusLabel = $newStatus === 'pending' ? 'thu hồi về chờ duyệt' : 'từ chối';
                $msg = "⚠️ Đơn nghỉ phép của bạn đã bị giám đốc {$statusLabel}" . ($note ? ": $note" : '.');
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Giám đốc đã cập nhật đơn nghỉ phép', ?, 'leave_request', ?)")
                    ->execute([$ownerRow['owner_id'], $msg, $id]);
                $pdo->commit();

                setFlash('success', '✅ Đã cập nhật trạng thái đơn nghỉ phép.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('danger', '❌ Không thể cập nhật đơn nghỉ phép.');
            }
        } else {
            setFlash('danger', '❌ Không thể thực hiện thao tác này.');
        }
        header('Location: /erp/modules/attendance/leave_manage.php?filter=' . ($_GET['filter'] ?? 'approved'));
        exit();
    }

    // ── Kiểm tra quyền đa cấp: chỉ được duyệt cấp dưới, không tự duyệt ──
    $ownerStmt = $pdo->prepare("
        SELECT u.id, r.name AS role
        FROM leave_requests lr
        JOIN users u ON lr.user_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE lr.id = ? AND lr.status = 'pending'
    ");
    $ownerStmt->execute([$id]);
    $owner = $ownerStmt->fetch();

    if (!$owner || !canApprove($user, $owner)) {
        setFlash('danger', '⛔ Bạn không có quyền duyệt đơn này.');
        header('Location: /erp/modules/attendance/leave_manage.php');
        exit();
    }

    $stmt = $pdo->prepare("UPDATE leave_requests SET status=?, approved_by=?, approved_at=NOW(), reject_reason=? WHERE id=? AND status='pending'");
    $stmt->execute([$action, $user['id'], $reason, $id]);

    if ($stmt->rowCount()) {
        $msg = $action==='approved' ? '✅ Đơn xin nghỉ phép của bạn đã được duyệt.' : '❌ Đơn xin nghỉ phép bị từ chối: '.$reason;
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Kết quả đơn nghỉ phép', ?, 'leave_request', ?)");
        $notif->execute([$owner['id'], $msg, $id]);
        setFlash('success', 'Đã xử lý đơn nghỉ phép.');
    }
    header('Location: /erp/modules/attendance/leave_manage.php');
    exit();
}

$filter = $_GET['filter'] ?? 'pending';
$myLevel = getRoleLevel($user['role']);

// Chỉ hiển thị đơn của cấp dưới mình và không phải đơn của chính mình
$stmt = $pdo->prepare("
    SELECT lr.*, u.full_name, u.employee_code, r.name AS requester_role,
           d.name as department_name,
           a.full_name as approver_name
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN users a ON lr.approved_by = a.id
    WHERE (? = 'all' OR lr.status = ?)
      AND lr.user_id != ?
      AND (
           (r.name = 'employee'   AND ? >= 2)
        OR (r.name = 'production' AND ? >= 3)
        OR (r.name = 'manager'    AND ? >= 4)
        OR (r.name = 'accountant' AND ? >= 5)
      )
    ORDER BY lr.created_at DESC
    LIMIT 200
");
$stmt->execute([$filter, $filter, $user['id'], $myLevel, $myLevel, $myLevel, $myLevel]);
$requests = $stmt->fetchAll();

// Lấy danh sách nhân viên cho form tạo thủ công
$empStmt = $pdo->query("
    SELECT u.id, u.full_name, u.employee_code, r.name AS role, d.name AS department_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.is_active = 1
    ORDER BY u.full_name ASC
");
$allEmployees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">📋 Duyệt đơn nghỉ phép</h4>
        <button class="btn btn-primary btn-sm" onclick="showManualCreate()">
            <i class="fas fa-plus me-1"></i> Tạo đơn thủ công
        </button>
    </div>
    <?php showFlash(); ?>

    <!-- Filter -->
    <div class="btn-group mb-3">
        <a href="?filter=pending" class="btn btn-sm <?= $filter==='pending'?'btn-warning':'btn-outline-warning' ?>">⌛ Chờ duyệt</a>
        <a href="?filter=approved" class="btn btn-sm <?= $filter==='approved'?'btn-success':'btn-outline-success' ?>">✅ Đã duyệt</a>
        <a href="?filter=rejected" class="btn btn-sm <?= $filter==='rejected'?'btn-danger':'btn-outline-danger' ?>">❌ Từ chối</a>
        <a href="?filter=all" class="btn btn-sm <?= $filter==='all'?'btn-secondary':'btn-outline-secondary' ?>">Tất cả</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr><th>Nhân viên</th><th>Loại</th><th>Từ Ngày</th><th>Đến Ngày</th><th>Số Ngày Nghỉ</th><th>Lý do</th><th>Ngày tạo</th><th>Trạng thái</th><th>Thao tác</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                            <small class="text-muted"><?= $r['employee_code'] ?> &bull; <?= htmlspecialchars($r['department_name'] ?? '') ?></small>
                        </td>
                        <td><?= ['annual'=>'Phép năm','sick'=>'Ốm','unpaid'=>'KL','other'=>'Khác'][$r['leave_type']] ?? $r['leave_type'] ?></td>
                        <td><?= formatDate($r['start_date']) ?></td>
                        <td><?= formatDate($r['end_date']) ?></td>
                        <td><?= $r['total_days'] ?></td>
                        <td><small><?= htmlspecialchars($r['reason']) ?></small></td>
                        <td><small><?= formatDate($r['created_at'], 'd/m H:i') ?></small></td>
                        <td>
                            <?php $badges=['pending'=>'warning','approved'=>'success','rejected'=>'danger'];
                                  $labels=['pending'=>'Chờ','approved'=>'Duyệt','rejected'=>'Từ chối']; ?>
                            <span class="badge bg-<?= $badges[$r['status']] ?>"><?= $labels[$r['status']] ?></span>
                        </td>
                        <td>
                        <?php
                        $requesterForCheck = ['id' => $r['user_id'], 'role' => $r['requester_role']];
                        if ($r['status'] === 'pending' && canApprove($user, $requesterForCheck)):
                        ?>
                            <div class="d-flex gap-1">
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <input type="hidden" name="action" value="approved">
                                    <button class="btn btn-success btn-sm" onclick="return confirm('Duyệt đơn này?')">✅</button>
                                </form>
                                <button class="btn btn-danger btn-sm" onclick="showRejectForm(<?= $r['id'] ?>)">❌</button>
                            </div>
                        <?php else: ?>
                            <?php if (hasRole('director') && in_array($r['status'], ['approved', 'rejected'])): ?>
                                <div class="d-flex gap-1 align-items-center">
                                    <small class="text-muted me-1"><?= $r['approver_name'] ? htmlspecialchars($r['approver_name']) : '-' ?></small>
                                    <button class="btn btn-outline-warning btn-sm" style="font-size:11px;"
                                            onclick='showOverrideLeave(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($r["full_name"]), ENT_QUOTES, "UTF-8") ?>)'>
                                        ✏️ Override
                                    </button>
                                </div>
                            <?php else: ?>
                                <small class="text-muted"><?= $r['approver_name'] ? htmlspecialchars($r['approver_name']) : '-' ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Không có đơn nào</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal từ chối -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="request_id" id="rejectId">
                <input type="hidden" name="action" value="rejected">
                <div class="modal-header">
                    <h6 class="modal-title">❌ Từ chối đơn nghỉ phép</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Lý do từ chối</label>
                    <textarea name="reject_reason" class="form-control" rows="3" required placeholder="Nhập lý do..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Override (Giám đốc) -->
<div class="modal fade" id="overrideLeaveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="request_id" id="overrideLeaveId">
                <input type="hidden" name="action" value="director_override">
                <div class="modal-header bg-warning bg-opacity-10">
                    <h6 class="modal-title fw-bold">✏️ Giám đốc Override — <span id="overrideLeaveEmp"></span></h6>
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-warning fw-bold">Xác nhận Override</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tạo đơn thủ công -->
<div class="modal fade" id="manualCreateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="manualCreateForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="request_id" value="0">
                <input type="hidden" name="action" value="manual_create">
                <div class="modal-header bg-primary bg-opacity-10">
                    <h6 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i>Tạo đơn nghỉ phép thủ công</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Nhân viên <span class="text-danger">*</span></label>
                            <select name="target_user_id" id="manualUserId" class="form-select" required>
                                <option value="">-- Chọn nhân viên --</option>
                                <?php foreach ($allEmployees as $emp): ?>
                                <option value="<?= (int)$emp['id'] ?>">
                                    <?= htmlspecialchars($emp['full_name']) ?>
                                    (<?= htmlspecialchars($emp['employee_code'] ?? '') ?>)
                                    <?= $emp['department_name'] ? '— ' . htmlspecialchars($emp['department_name']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loại nghỉ <span class="text-danger">*</span></label>
                            <select name="leave_type" class="form-select" required>
                                <option value="annual">📅 Phép năm</option>
                                <option value="sick">🤒 Nghỉ ốm</option>
                                <option value="unpaid">💸 Không lương</option>
                                <option value="other">📋 Khác</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Trạng thái</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="auto_approve" id="autoApproveCheck" checked>
                                <label class="form-check-label" for="autoApproveCheck">Duyệt ngay (không cần chờ)</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Từ ngày <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="manualStartDate" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Đến ngày <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="manualEndDate" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Lý do <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required placeholder="Nhập lý do nghỉ phép..."></textarea>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-info py-2 mb-0" id="manualDaysPreview" style="display:none;">
                                <i class="fas fa-info-circle me-1"></i>
                                Số ngày nghỉ: <strong id="manualDaysCount">0</strong> ngày
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="fas fa-save me-1"></i>Tạo đơn
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showRejectForm(id) {
    document.getElementById('rejectId').value = id;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function showOverrideLeave(id, empName) {
    document.getElementById('overrideLeaveId').value = id;
    document.getElementById('overrideLeaveEmp').textContent = empName;
    document.querySelector('#overrideLeaveModal textarea').value = '';
    new bootstrap.Modal(document.getElementById('overrideLeaveModal')).show();
}

function showManualCreate() {
    new bootstrap.Modal(document.getElementById('manualCreateModal')).show();
}

// Tính số ngày khi chọn ngày
function calcManualDays() {
    const s = document.getElementById('manualStartDate').value;
    const e = document.getElementById('manualEndDate').value;
    const preview = document.getElementById('manualDaysPreview');
    const count   = document.getElementById('manualDaysCount');
    if (s && e && s <= e) {
        const diff = Math.round((new Date(e) - new Date(s)) / 86400000) + 1;
        count.textContent = diff;
        preview.style.display = '';
    } else {
        preview.style.display = 'none';
    }
}
document.getElementById('manualStartDate').addEventListener('change', calcManualDays);
document.getElementById('manualEndDate').addEventListener('change', calcManualDays);
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
