<?php
/**
 * GET  /erp/api/mobile/leave   – danh sách đơn nghỉ phép gần nhất của nhân viên
 * POST /erp/api/mobile/leave   – tạo đơn nghỉ phép
 *      Body JSON: { leave_type, start_date: "Y-m-d", end_date: "Y-m-d", reason }
 * Quy tắc giống mobile/leave.php (phép năm tối đa 1 ngày/tháng).
 */
require_once __DIR__ . '/_bootstrap.php';

const MOBILE_LEAVE_TYPES = ['annual', 'sick', 'unpaid', 'other'];

mobileApiInit();
$method = mobileRequireMethod('GET', 'POST');

$pdo  = getDBConnection();
$user = mobileRequireAuth($pdo);

function mobileLeaveRow(array $r): array {
    return [
        'id'            => (int)$r['id'],
        'leave_type'    => $r['leave_type'],
        'start_date'    => $r['start_date'],
        'end_date'      => $r['end_date'],
        'total_days'    => (float)$r['total_days'],
        'reason'        => (string)$r['reason'],
        'status'        => $r['status'] ?? 'pending',
        'reject_reason' => $r['reject_reason'] ?? null,
        'approver_name' => $r['approver_name'] ?? null,
        'approved_at'   => $r['approved_at'] ?? null,
        'created_at'    => $r['created_at'] ?? null,
    ];
}

if ($method === 'GET') {
    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit < 1 || $limit > 200) {
        $limit = 50;
    }
    $stmt = $pdo->prepare("
        SELECT lr.*, u.full_name AS approver_name
        FROM leave_requests lr
        LEFT JOIN users u ON lr.approved_by = u.id
        WHERE lr.user_id = ?
        ORDER BY lr.created_at DESC, lr.id DESC
        LIMIT $limit
    ");
    $stmt->execute([$user['id']]);
    mobileOk(['items' => array_map('mobileLeaveRow', $stmt->fetchAll(PDO::FETCH_ASSOC))]);
}

// ── POST: tạo đơn nghỉ phép ───────────────────────────────────────────
$type   = mobileInputString('leave_type');
$start  = mobileInputString('start_date');
$end    = mobileInputString('end_date');
$reason = mobileInputString('reason');
$errors = [];

if (!in_array($type, MOBILE_LEAVE_TYPES, true)) {
    $errors[] = 'Loại nghỉ phép không hợp lệ.';
}
if ($start === '' || $end === '') {
    $errors[] = 'Vui lòng chọn đầy đủ ngày bắt đầu và kết thúc.';
} elseif (!mobileValidDate($start) || !mobileValidDate($end)) {
    $errors[] = 'Ngày nghỉ không hợp lệ.';
} elseif ($start > $end) {
    $errors[] = 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.';
}
if ($reason === '') {
    $errors[] = 'Vui lòng nhập lý do.';
}

$days = 0;
if (empty($errors)) {
    $days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;

    // Hạn mức phép năm: tối đa 1 ngày/tháng
    if ($type === 'annual') {
        $checkMonth = date('Y-m', strtotime($start));
        $quotaStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_days), 0)
            FROM leave_requests
            WHERE user_id = ?
              AND leave_type = 'annual'
              AND status IN ('pending', 'approved')
              AND DATE_FORMAT(start_date, '%Y-%m') = ?
        ");
        $quotaStmt->execute([$user['id'], $checkMonth]);
        $usedDays = (float)$quotaStmt->fetchColumn();

        if ($usedDays >= 1) {
            $errors[] = 'Bạn đã sử dụng hết 1 ngày phép năm trong tháng ' . date('m/Y', strtotime($start)) . '. Không thể đăng ký thêm.';
        } elseif ($days > 1) {
            $errors[] = 'Phép năm chỉ được tối đa 1 ngày mỗi tháng.';
        }
    }
}

if (!empty($errors)) {
    mobileError(implode("\n", $errors), 422, ['errors' => $errors]);
}

$pdo->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, total_days, reason) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([$user['id'], $type, $start, $end, $days, $reason]);
$requestId = (int)$pdo->lastInsertId();

mobileNotifyManagers(
    $pdo,
    'Đơn xin nghỉ phép mới',
    $user['full_name'] . ' xin nghỉ từ ' . formatDate($start) . ' đến ' . formatDate($end),
    'leave_request',
    $requestId
);

$row = $pdo->prepare("SELECT lr.*, NULL AS approver_name FROM leave_requests lr WHERE lr.id = ? AND lr.user_id = ?");
$row->execute([$requestId, $user['id']]);

mobileOk(mobileLeaveRow($row->fetch(PDO::FETCH_ASSOC)), 'Đã gửi đơn xin nghỉ phép thành công!', 201);
