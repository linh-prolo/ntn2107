<?php
/**
 * GET  /erp/api/mobile/attendance?month=&year=  – trạng thái hôm nay + thống kê + lịch sử chấm công theo tháng
 * POST /erp/api/mobile/attendance/checkin        – chấm công vào (?action=checkin)
 * POST /erp/api/mobile/attendance/checkout       – chấm công ra  (?action=checkout)
 *      Body JSON: { lat, lng, device_id (sha256 hex), photo_data ("data:image/jpeg;base64,...") }
 *
 * Quy tắc nghiệp vụ giống mobile/index.php: bắt buộc GPS + ảnh xác nhận, kiểm tra phạm vi
 * theo chính sách phòng ban / cài đặt chung, ca làm việc, đi trễ/về sớm, ca đêm,
 * cảnh báo chấm công hộ (cùng thiết bị).
 */
require_once __DIR__ . '/_bootstrap.php';

mobileApiInit();
$method = mobileRequireMethod('GET', 'POST');

$pdo  = getDBConnection();
$user = mobileRequireAuth($pdo);

// ── Ca làm việc của user tại ngày (fallback ca HANHCHINH) ─────────────────────
function mobileAttShiftAtDate(PDO $pdo, int $userId, string $date): ?array {
    try {
        $st = $pdo->prepare("
            SELECT ws.* FROM employee_shifts es
            JOIN work_shifts ws ON es.shift_id = ws.id
            WHERE es.user_id = ? AND es.effective_date <= ?
              AND (es.end_date IS NULL OR es.end_date >= ?)
            ORDER BY es.effective_date DESC LIMIT 1
        ");
        $st->execute([$userId, $date, $date]);
        $shift = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$shift) {
            try {
                $def = $pdo->prepare("SELECT * FROM work_shifts WHERE shift_code = 'HANHCHINH' AND is_active = 1 LIMIT 1");
                $def->execute();
                $shift = $def->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $shift = null;
            }
        }
        return $shift;
    } catch (Throwable $e) {
        return null;
    }
}

// ── Mốc reset cho log mở (ca đêm: end_time hôm sau + 2h; ca ngày: 23:59:59) ────
function mobileAttResetThreshold(?array $shift, string $workDate): int {
    if ((int)($shift['is_night_shift'] ?? 0) === 1) {
        $endTime = $shift['end_time'] ?? null;
        if ($endTime) {
            $baseTs = strtotime($workDate . ' +1 day ' . $endTime);
            if ($baseTs !== false && $baseTs > 0) {
                return (int)($baseTs + 7200);
            }
        }
        return (int)strtotime($workDate . ' +1 day 08:00:00');
    }
    return (int)strtotime($workDate . ' 23:59:59');
}

function mobileAttMarkMissingCheckout(PDO $pdo, int $logId): void {
    try {
        $pdo->prepare("
            UPDATE attendance_logs
            SET missing_checkout = 1,
                missing_checkout_note = 'Quên chấm công ra (tự động đánh dấu)',
                auto_closed_at = NOW()
            WHERE id = ? AND check_out IS NULL
        ")->execute([$logId]);
    } catch (Throwable $e) {
        error_log('mobile api attMarkMissingCheckout failed: ' . $e->getMessage());
    }
}

function mobileAttDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthR = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    return $earthR * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Cấu hình vị trí áp dụng cho user: chính sách phòng ban > cài đặt chung.
 */
function mobileAttLocationConfig(PDO $pdo, ?int $departmentId): array {
    try {
        $locStmt = $pdo->query("SELECT * FROM attendance_location_settings LIMIT 1");
        $locSetting = $locStmt ? ($locStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    } catch (Throwable $e) {
        $locSetting = null;
    }

    $deptPolicy = null;
    if ($departmentId) {
        try {
            $dp = $pdo->prepare("SELECT * FROM attendance_department_policies WHERE department_id = ? AND is_active = 1 LIMIT 1");
            $dp->execute([$departmentId]);
            $deptPolicy = $dp->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $deptPolicy = null;
        }
    }

    if ($deptPolicy) {
        if (($deptPolicy['location_mode'] ?? 'flexible') !== 'strict') {
            return ['enabled' => false];
        }
        return [
            'enabled' => true,
            'lat'     => $deptPolicy['latitude'] !== null ? (float)$deptPolicy['latitude'] : (float)($locSetting['latitude'] ?? 0),
            'lng'     => $deptPolicy['longitude'] !== null ? (float)$deptPolicy['longitude'] : (float)($locSetting['longitude'] ?? 0),
            'radius'  => $deptPolicy['radius_meters'] !== null ? (int)$deptPolicy['radius_meters'] : (int)($locSetting['radius_meters'] ?? 200),
            'name'    => ($deptPolicy['policy_name'] ?? '') !== '' ? $deptPolicy['policy_name'] : ($locSetting['location_name'] ?? 'Công ty'),
        ];
    }
    if ($locSetting) {
        return [
            'enabled' => (bool)(int)$locSetting['is_enabled'],
            'lat'     => (float)$locSetting['latitude'],
            'lng'     => (float)$locSetting['longitude'],
            'radius'  => (int)$locSetting['radius_meters'],
            'name'    => $locSetting['location_name'],
        ];
    }
    return ['enabled' => false];
}

function mobileAttLogRow(array $log): array {
    return [
        'id'                     => (int)$log['id'],
        'work_date'              => $log['work_date'],
        'check_in'               => $log['check_in'] ?? null,
        'check_out'              => $log['check_out'] ?? null,
        'work_hours'             => (float)($log['work_hours'] ?? 0),
        'is_late'                => !empty($log['is_late']),
        'late_minutes'           => (int)($log['late_minutes'] ?? 0),
        'early_leave'            => !empty($log['early_leave']),
        'early_leave_minutes'    => (int)($log['early_leave_minutes'] ?? 0),
        'missing_checkout'       => !empty($log['missing_checkout']),
        'check_in_location_flag' => $log['check_in_location_flag'] ?? null,
        'source'                 => $log['source'] ?? null,
    ];
}

/**
 * Log "hôm nay" (gồm ca đêm của hôm qua còn đang mở) – giống mobile/index.php.
 */
function mobileAttTodayLog(PDO $pdo, int $userId, string $today): ?array {
    $st = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND work_date = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$userId, $today]);
    $todayLog = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($todayLog) {
        return $todayLog;
    }

    $st = $pdo->prepare("
        SELECT * FROM attendance_logs
        WHERE user_id = ?
          AND work_date = DATE_SUB(?, INTERVAL 1 DAY)
          AND check_in IS NOT NULL
          AND check_out IS NULL
          AND (missing_checkout = 0 OR missing_checkout IS NULL)
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$userId, $today]);
    $yestLog = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($yestLog) {
        $yestShift = mobileAttShiftAtDate($pdo, $userId, $yestLog['work_date']);
        if (time() <= mobileAttResetThreshold($yestShift, $yestLog['work_date'])) {
            return $yestLog;
        }
        mobileAttMarkMissingCheckout($pdo, (int)$yestLog['id']);
    }
    return null;
}

function mobileAttStatus(PDO $pdo, array $user, int $month, int $year): array {
    $userId = (int)$user['id'];
    $today = date('Y-m-d');
    $todayLog = mobileAttTodayLog($pdo, $userId, $today);

    $shiftStmt = $pdo->prepare("
        SELECT ws.shift_name, ws.start_time, ws.end_time, ws.is_night_shift
        FROM employee_shifts es
        JOIN work_shifts ws ON es.shift_id = ws.id
        WHERE es.user_id = ? AND es.effective_date <= ?
          AND (es.end_date IS NULL OR es.end_date >= ?)
        ORDER BY es.effective_date DESC
        LIMIT 1
    ");
    $shiftStmt->execute([$userId, $today, $today]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Thống kê tháng hiện tại
    $curMonth = (int)date('n');
    $curYear = (int)date('Y');
    $monthStmt = $pdo->prepare("SELECT check_in, work_hours, is_late FROM attendance_logs WHERE user_id = ? AND MONTH(work_date) = ? AND YEAR(work_date) = ?");
    $monthStmt->execute([$userId, $curMonth, $curYear]);
    $workDays = 0;
    $workHours = 0.0;
    $lateDays = 0;
    foreach ($monthStmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
        if (!empty($log['check_in'])) {
            $workDays++;
            $workHours += (float)($log['work_hours'] ?? 0);
            if (!empty($log['is_late'])) {
                $lateDays++;
            }
        }
    }

    $leaveStmt = $pdo->prepare("SELECT start_date, end_date FROM leave_requests WHERE user_id = ? AND status = 'approved' AND start_date <= LAST_DAY(?) AND end_date >= ?");
    $monthStart = sprintf('%04d-%02d-01', $curYear, $curMonth);
    $leaveStmt->execute([$userId, $monthStart, $monthStart]);
    $leaveDays = [];
    foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
        for ($d = strtotime($leave['start_date']); $d <= strtotime($leave['end_date']); $d += 86400) {
            if ((int)date('n', $d) === $curMonth && (int)date('Y', $d) === $curYear) {
                $leaveDays[date('Y-m-d', $d)] = true;
            }
        }
    }

    $historyStmt = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND MONTH(work_date) = ? AND YEAR(work_date) = ? ORDER BY work_date DESC, id DESC");
    $historyStmt->execute([$userId, $month, $year]);

    return [
        'server_time'   => date('Y-m-d H:i:s'),
        'today_date'    => $today,
        'today'         => $todayLog ? mobileAttLogRow($todayLog) : null,
        'can_check_in'  => !$todayLog || !$todayLog['check_in'],
        'can_check_out' => $todayLog && $todayLog['check_in'] && !$todayLog['check_out'],
        'shift'         => $shift ? [
            'shift_name'     => $shift['shift_name'],
            'start_time'     => substr((string)$shift['start_time'], 0, 5),
            'end_time'       => substr((string)$shift['end_time'], 0, 5),
            'is_night_shift' => (bool)(int)$shift['is_night_shift'],
        ] : null,
        'summary' => [
            'work_days'  => $workDays,
            'work_hours' => round($workHours, 2),
            'late_days'  => $lateDays,
            'leave_days' => count($leaveDays),
        ],
        'location_config' => mobileAttLocationConfig($pdo, $user['department_id']),
        'month' => $month,
        'year'  => $year,
        'logs'  => array_map('mobileAttLogRow', $historyStmt->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

if ($method === 'GET') {
    [$month, $year] = mobileMonthYearFromQuery();
    mobileOk(mobileAttStatus($pdo, $user, $month, $year));
}

// ───────────────────────── POST: check-in / check-out ─────────────────────────
$actionRaw = (string)($_GET['action'] ?? mobileInputString('action'));
$action = match ($actionRaw) {
    'checkin', 'check_in'   => 'check_in',
    'checkout', 'check_out' => 'check_out',
    default                 => '',
};
if ($action === '') {
    mobileError('Yêu cầu chấm công không hợp lệ.', 400);
}

$userId = (int)$user['id'];
$today  = date('Y-m-d');
$now    = date('Y-m-d H:i:s');

// ── GPS ─────────────────────────────────────────────────────────────────────
$latRaw = mobileInputString('lat');
$lngRaw = mobileInputString('lng');
$lat = ($latRaw !== '' && is_numeric($latRaw)) ? (float)$latRaw : null;
$lng = ($lngRaw !== '' && is_numeric($lngRaw)) ? (float)$lngRaw : null;
if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
if ($lng !== null && ($lng < -180 || $lng > 180)) $lng = null;
if ($lat === null || $lng === null) {
    mobileError('📍 Không thể chấm công: Vui lòng bật định vị (GPS) và cho phép ứng dụng truy cập vị trí.', 422);
}

// ── Trạng thái hiện tại (tránh chấm trùng) ─────────────────────────────────
$todayLog = mobileAttTodayLog($pdo, $userId, $today);
if ($action === 'check_in' && $todayLog && $todayLog['check_in']) {
    mobileError('Bạn đã chấm công vào ca rồi.', 409);
}
if ($action === 'check_out' && !($todayLog && $todayLog['check_in'] && !$todayLog['check_out'])) {
    mobileError('Không có ca đang mở để chấm công ra.', 409);
}

// ── Kiểm tra phạm vi vị trí ────────────────────────────────────────────────
$locCfg = mobileAttLocationConfig($pdo, $user['department_id']);
if (!empty($locCfg['enabled'])) {
    $dist = mobileAttDistance((float)$locCfg['lat'], (float)$locCfg['lng'], $lat, $lng);
    if ($dist > (int)$locCfg['radius']) {
        mobileError(sprintf(
            '❌ Bạn chưa có mặt tại vị trí %s. Khoảng cách hiện tại: %dm (cho phép trong %dm).',
            $locCfg['name'],
            (int)round($dist),
            (int)$locCfg['radius']
        ), 422);
    }
}

$ip = getClientIp();

// ── Device fingerprint ─────────────────────────────────────────────────────
$deviceId = strtolower(mobileInputString('device_id'));
if (!preg_match('/^[0-9a-f]{64}$/', $deviceId)) {
    $deviceId = null;
}

$sameDeviceAlert = 0;
$sameDeviceUsers = [];
if ($deviceId && $action === 'check_in') {
    try {
        $devStmt = $pdo->prepare("
            SELECT al.user_id, u.full_name, u.employee_code
            FROM attendance_logs al
            JOIN users u ON u.id = al.user_id
            WHERE al.device_id = ? AND al.work_date = ? AND al.user_id != ? AND al.check_in IS NOT NULL
        ");
        $devStmt->execute([$deviceId, $today, $userId]);
        $sameDeviceUsers = $devStmt->fetchAll(PDO::FETCH_ASSOC);
        $sameDeviceAlert = empty($sameDeviceUsers) ? 0 : 1;
    } catch (Throwable $e) {
        error_log('mobile api device check error: ' . $e->getMessage());
    }
}

// ── Ảnh xác nhận ───────────────────────────────────────────────────────────
$photoData = mobileInputString('photo_data');
$minPhotoBytes = 1000;
$maxPhotoBytes = 800000;
$maxCompressedPhotoBytes = 300000;

if ($photoData === '') {
    mobileError('📸 Không thể chấm công: Vui lòng chụp ảnh xác nhận trước khi chấm công.', 422);
}
if (preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#', $photoData, $pm)) {
    $photoData = substr($photoData, strlen($pm[0]));
}
$imgBinary = base64_decode($photoData, true);
if ($imgBinary === false || strlen($imgBinary) < $minPhotoBytes || strlen($imgBinary) > $maxPhotoBytes) {
    mobileError('📸 Ảnh chụp không hợp lệ hoặc quá lớn. Vui lòng thử lại.', 422);
}
$imgInfo = @getimagesizefromstring($imgBinary);
if ($imgInfo === false || !in_array($imgInfo['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
    mobileError('📸 Ảnh chụp không hợp lệ. Vui lòng thử lại.', 422);
}
if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
    mobileError('📸 Máy chủ chưa hỗ trợ xử lý ảnh chấm công. Liên hệ quản trị viên.', 500);
}
$image = @imagecreatefromstring($imgBinary);
if ($image === false) {
    mobileError('📸 Không thể xử lý ảnh chụp. Vui lòng thử lại.', 422);
}
ob_start();
imagejpeg($image, null, 80);
$compressedBinary = ob_get_clean();
imagedestroy($image);
if (!is_string($compressedBinary) || strlen($compressedBinary) < $minPhotoBytes || strlen($compressedBinary) > $maxCompressedPhotoBytes) {
    mobileError('📸 Ảnh chụp không hợp lệ. Vui lòng chụp lại gần khuôn mặt hơn.', 422);
}

// ── Kiểm tra log mở của ngày trước (trước khi lưu ảnh) ────────────────────
if ($action === 'check_in') {
    try {
        $priorStmt = $pdo->prepare("
            SELECT * FROM attendance_logs
            WHERE user_id = ? AND check_in IS NOT NULL AND check_out IS NULL
              AND (missing_checkout = 0 OR missing_checkout IS NULL)
              AND work_date < ?
            ORDER BY work_date DESC
            LIMIT 1
        ");
        $priorStmt->execute([$userId, $today]);
        $priorOpenLog = $priorStmt->fetch(PDO::FETCH_ASSOC);
        if ($priorOpenLog) {
            $priorShift = mobileAttShiftAtDate($pdo, $userId, $priorOpenLog['work_date']);
            if (time() <= mobileAttResetThreshold($priorShift, $priorOpenLog['work_date'])) {
                $shiftName = $priorShift['shift_name'] ?? 'ca trước';
                mobileError('⚠️ Bạn chưa chấm công ra ' . $shiftName . ' ngày ' . date('d/m/Y', strtotime($priorOpenLog['work_date'])) . '. Vui lòng chấm công ra trước khi bắt đầu ca mới.', 409);
            }
            mobileAttMarkMissingCheckout($pdo, (int)$priorOpenLog['id']);
        }
    } catch (Throwable $e) {
        error_log('mobile api prior open log check failed: ' . $e->getMessage());
    }
}

$uploadDate = date('Y-m-d');
$uploadDir = dirname(__DIR__, 2) . '/uploads/attendance/' . $uploadDate . '/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    mobileError('📸 Không thể tạo thư mục lưu ảnh. Liên hệ quản trị viên.', 500);
}
$filename = $userId . '_' . ($action === 'check_in' ? 'in' : 'out') . '_' . date('Ymd_His') . '.jpg';
$photoPath = '/erp/uploads/attendance/' . $uploadDate . '/' . $filename;
if (file_put_contents($uploadDir . $filename, $compressedBinary) === false) {
    mobileError('📸 Không thể lưu ảnh. Liên hệ quản trị viên.', 500);
}

// ── Cờ vị trí (company_location_config) ────────────────────────────────────
$locationFlag = 'unknown';
try {
    $cfg = [];
    foreach ($pdo->query("SELECT config_key, config_value FROM company_location_config")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cfg[$row['config_key']] = $row['config_value'];
    }
    $dist = mobileAttDistance((float)($cfg['lat'] ?? 0), (float)($cfg['lng'] ?? 0), $lat, $lng);
    $locationFlag = ($dist <= (float)($cfg['radius_meters'] ?? 500)) ? 'verified' : 'outside';
} catch (Throwable $e) {
    $locationFlag = 'unknown';
}
$flagMsg = match ($locationFlag) {
    'verified' => ' ✅ Vị trí đã xác minh',
    'outside'  => ' ⚠️ Ngoài phạm vi công ty',
    default    => '',
};

if ($action === 'check_in') {
    $isLate = 0;
    $lateMinutes = 0;
    $shift = mobileAttShiftAtDate($pdo, $userId, $today);
    if ($shift) {
        $shiftStart = strtotime($today . ' ' . $shift['start_time']);
        $threshold  = $shiftStart + ((int)($shift['late_threshold'] ?? 0)) * 60;
        $actualIn   = strtotime($now);
        if ($actualIn > $threshold) {
            $isLate = 1;
            $lateMinutes = (int)(($actualIn - $shiftStart) / 60);
        }
    }

    $existStmt = $pdo->prepare("SELECT id, check_in FROM attendance_logs WHERE user_id = ? AND work_date = ?");
    $existStmt->execute([$userId, $today]);
    $existing = $existStmt->fetch(PDO::FETCH_ASSOC);

    try {
        if ($existing) {
            $pdo->prepare("UPDATE attendance_logs
                SET check_in = ?, source = 'manual',
                    check_in_ip = ?, check_in_lat = ?, check_in_lng = ?, check_in_location_flag = ?,
                    device_id = ?, same_device_alert = ?, check_in_photo = ?, is_late = ?, late_minutes = ?
                WHERE id = ? AND check_in IS NULL")
                ->execute([$now, $ip, $lat, $lng, $locationFlag, $deviceId, $sameDeviceAlert, $photoPath, $isLate, $lateMinutes, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO attendance_logs
                (user_id, check_in, work_date, source, check_in_ip, check_in_lat, check_in_lng, check_in_location_flag, device_id, same_device_alert, check_in_photo, is_late, late_minutes)
                VALUES (?, ?, ?, 'manual', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$userId, $now, $today, $ip, $lat, $lng, $locationFlag, $deviceId, $sameDeviceAlert, $photoPath, $isLate, $lateMinutes]);
        }
    } catch (Throwable $e) {
        error_log('mobile api check_in failed: ' . $e->getMessage());
        mobileError('Không thể lưu chấm công. Vui lòng thử lại.', 500);
    }

    $message = 'Chấm công vào ca thành công lúc ' . date('H:i') . $flagMsg;

    if ($sameDeviceAlert && !empty($sameDeviceUsers)) {
        try {
            $otherNames = implode(', ', array_map(fn($u) => $u['full_name'] . ' (' . $u['employee_code'] . ')', $sameDeviceUsers));
            $alertMsg = '⚠️ Cảnh báo chấm công hộ: ' . $user['full_name'] . ' (' . $user['employee_code'] . ") dùng cùng thiết bị với {$otherNames} vào ngày " . date('d/m/Y') . '. Vui lòng kiểm tra!';
            $managers = $pdo->query("
                SELECT u.id FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name IN ('director', 'manager', 'accountant') AND u.is_active = 1
            ")->fetchAll(PDO::FETCH_COLUMN);
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, reference_id, created_at)
                VALUES (?, '⚠️ Nghi vấn chấm công hộ', ?, 'same_device_alert', ?, NOW())
            ");
            foreach ($managers as $mgr) {
                $notifStmt->execute([$mgr, $alertMsg, $userId]);
            }

            $otherIds = array_column($sameDeviceUsers, 'user_id');
            $placeholders = implode(',', array_fill(0, count($otherIds), '?'));
            $pdo->prepare("UPDATE attendance_logs SET same_device_alert = 1 WHERE work_date = ? AND user_id IN ($placeholders)")
                ->execute(array_merge([$today], $otherIds));
        } catch (Throwable $e) {
            error_log('mobile api same_device_alert error: ' . $e->getMessage());
        }
        $message = '⚠️ Cảnh báo: Thiết bị của bạn đã được dùng để chấm công bởi nhân viên khác trong ngày hôm nay. Quản lý đã được thông báo để kiểm tra.';
    }
} else {
    $logWorkDate = $todayLog['work_date'];
    $earlyLeave = 0;
    $earlyMinutes = 0;
    $shiftEarly = mobileAttShiftAtDate($pdo, $userId, $logWorkDate);
    if ($shiftEarly && !empty($shiftEarly['start_time']) && !empty($shiftEarly['end_time'])) {
        $shiftEnd = strtotime($logWorkDate . ' ' . $shiftEarly['end_time']);
        if ($shiftEarly['end_time'] < $shiftEarly['start_time']) {
            $shiftEnd += 86400;
        }
        $earlyDiff = $shiftEnd - strtotime($now);
        if ($earlyDiff >= 60) {
            $earlyMinutes = (int)($earlyDiff / 60);
            $earlyLeave = 1;
        }
    }

    try {
        $pdo->prepare("UPDATE attendance_logs
            SET check_out = ?,
                work_hours = ROUND(TIMESTAMPDIFF(MINUTE, check_in, ?) / 60, 2),
                early_leave = ?, early_leave_minutes = ?,
                check_out_ip = ?, check_out_lat = ?, check_out_lng = ?, check_out_location_flag = ?, check_out_photo = ?
            WHERE id = ? AND user_id = ? AND check_out IS NULL")
            ->execute([$now, $now, $earlyLeave, $earlyMinutes, $ip, $lat, $lng, $locationFlag, $photoPath, $todayLog['id'], $userId]);
    } catch (Throwable $e) {
        error_log('mobile api check_out failed: ' . $e->getMessage());
        mobileError('Không thể lưu chấm công. Vui lòng thử lại.', 500);
    }

    $message = 'Chấm công ra ca thành công lúc ' . date('H:i') . $flagMsg;
}

[$month, $year] = mobileMonthYearFromQuery();
mobileOk(mobileAttStatus($pdo, $user, $month, $year), $message);
