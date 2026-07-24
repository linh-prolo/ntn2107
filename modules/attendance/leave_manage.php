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
            SELECT lr.*, u.id AS owner_id
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            WHERE lr.id = ? AND lr.status IN ('approved', 'rejected')
        ");
        $ownerStmt->execute([$id]);
        $ownerRow = $ownerStmt->fetch();

        if ($ownerRow && in_array($newStatus, ['pending', 'rejected'], true)) {
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

            setFlash('success', '✅ Đã cập nhật trạng thái đơn nghỉ phép.');
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
    LIMIT 50
");
$stmt->execute([$filter, $filter, $user['id'], $myLevel, $myLevel, $myLevel, $myLevel]);
$requests = $stmt->fetchAll();

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <h4 class="mb-4">📋 Duyệt đơn nghỉ phép</h4>
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
                                            onclick='showOverrideLeave(<?= $r['id'] ?>, <?= json_encode($r["full_name"]) ?>)'>
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
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
