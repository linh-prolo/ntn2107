<?php
/**
 * GET  /erp/api/mobile/ot?month=&year=  – danh sách đơn OT của nhân viên theo tháng
 * POST /erp/api/mobile/ot               – tạo đơn OT
 *      Body JSON: { ot_date: "Y-m-d", ot_type, start_time: "H:i", hours, reason }
 * Quy tắc giống mobile/ot.php.
 */
require_once __DIR__ . '/_bootstrap.php';

const MOBILE_OT_TYPES = ['weekday', 'weekend', 'holiday', 'night_weekday', 'night_weekend', 'night_holiday'];

mobileApiInit();
$method = mobileRequireMethod('GET', 'POST');

$pdo  = getDBConnection();
$user = mobileRequireAuth($pdo);

function mobileOtRow(array $r): array {
    return [
        'id'            => (int)$r['id'],
        'ot_date'       => $r['ot_date'],
        'ot_type'       => $r['ot_type'],
        'start_time'    => substr((string)$r['start_time'], 0, 5),
        'end_time'      => substr((string)$r['end_time'], 0, 5),
        'hours'         => (float)$r['hours'],
        'reason'        => (string)$r['reason'],
        'status'        => $r['status'] ?? 'pending',
        'reject_reason' => $r['reject_reason'] ?? null,
        'approver_name' => $r['approver_name'] ?? null,
        'approved_at'   => $r['approved_at'] ?? null,
        'created_at'    => $r['created_at'] ?? null,
    ];
}

if ($method === 'GET') {
    [$month, $year] = mobileMonthYearFromQuery();
    $stmt = $pdo->prepare("
        SELECT o.*, u.full_name AS approver_name
        FROM overtime_requests o
        LEFT JOIN users u ON u.id = o.approved_by
        WHERE o.user_id = ? AND MONTH(o.ot_date) = ? AND YEAR(o.ot_date) = ?
        ORDER BY o.ot_date DESC, o.created_at DESC
    ");
    $stmt->execute([$user['id'], $month, $year]);
    mobileOk([
        'month' => $month,
        'year'  => $year,
        'items' => array_map('mobileOtRow', $stmt->fetchAll(PDO::FETCH_ASSOC)),
    ]);
}

// ── POST: tạo đơn OT ──────────────────────────────────────────────────
$otDate    = mobileInputString('ot_date');
$otType    = mobileInputString('ot_type');
$startTime = mobileInputString('start_time');
$hoursRaw  = mobileInputString('hours');
$hours     = is_numeric($hoursRaw) ? (float)$hoursRaw : 0.0;
$reason    = mobileInputString('reason');
$errors    = [];

if ($otDate === '') {
    $errors[] = 'Vui lòng chọn ngày OT.';
} elseif (!mobileValidDate($otDate)) {
    $errors[] = 'Ngày OT không hợp lệ.';
} elseif (DateTime::createFromFormat('!Y-m-d', $otDate) < new DateTime('today')) {
    $errors[] = 'Không thể đăng ký OT cho ngày đã qua.';
}
if (!in_array($otType, MOBILE_OT_TYPES, true)) $errors[] = 'Loại OT không hợp lệ.';
if ($startTime === '') $errors[] = 'Vui lòng nhập giờ bắt đầu.';
if ($hours <= 0) $errors[] = 'Số giờ OT phải lớn hơn 0.';
if ($hours > 12) $errors[] = 'OT không được vượt quá 12 giờ/ngày.';
if ($reason === '') $errors[] = 'Vui lòng nhập lý do OT.';

$endTime = '';
if (empty($errors)) {
    $start = DateTime::createFromFormat('!Y-m-d H:i', $otDate . ' ' . substr($startTime, 0, 5));
    if (!$start || $start->format('H:i') !== substr($startTime, 0, 5)) {
        $errors[] = 'Giờ bắt đầu không hợp lệ.';
    } else {
        $minutes   = (int)round($hours * 60);
        $end       = (clone $start)->modify('+' . $minutes . ' minutes');
        $endTime   = $end->format('H:i:s');
        $startTime = $start->format('H:i:s');
    }
}

if (empty($errors)) {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM overtime_requests WHERE user_id = ? AND ot_date = ? AND status != 'rejected'");
    $chk->execute([$user['id'], $otDate]);
    if ((int)$chk->fetchColumn() > 0) {
        $errors[] = 'Bạn đã có đơn OT cho ngày này rồi.';
    }
}

if (!empty($errors)) {
    mobileError(implode("\n", $errors), 422, ['errors' => $errors]);
}

$shiftStmt = $pdo->prepare("
    SELECT ws.id AS shift_id
    FROM employee_shifts es
    JOIN work_shifts ws ON es.shift_id = ws.id
    WHERE es.user_id = ? AND es.effective_date <= ?
      AND (es.end_date IS NULL OR es.end_date >= ?)
    ORDER BY es.effective_date DESC
    LIMIT 1
");
$shiftStmt->execute([$user['id'], $otDate, $otDate]);
$shiftId = $shiftStmt->fetchColumn() ?: null;

$pdo->prepare("
    INSERT INTO overtime_requests (user_id, ot_date, start_time, end_time, hours, reason, ot_type, shift_id, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
")->execute([$user['id'], $otDate, $startTime, $endTime, $hours, $reason, $otType, $shiftId]);
$requestId = (int)$pdo->lastInsertId();

mobileNotifyManagers(
    $pdo,
    '📋 Đơn đăng ký OT mới',
    $user['full_name'] . ' đăng ký OT ngày ' . formatDate($otDate) . ' (' . substr($startTime, 0, 5) . ' – ' . substr($endTime, 0, 5) . ', ' . number_format($hours, 2) . ' giờ)',
    'ot_request',
    $requestId
);

$row = $pdo->prepare("SELECT o.*, NULL AS approver_name FROM overtime_requests o WHERE o.id = ? AND o.user_id = ?");
$row->execute([$requestId, $user['id']]);

mobileOk(mobileOtRow($row->fetch(PDO::FETCH_ASSOC)), 'Đã gửi đơn đăng ký OT thành công!', 201);
