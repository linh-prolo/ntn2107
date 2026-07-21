<?php
/**
 * ZKTeco SpeedFace V5L — Push API Receiver
 * Máy chấm công tự đẩy dữ liệu về đây (không cần session).
 *
 * Cấu hình trên máy: Communication → Cloud Server Settings
 *   Server Address : http://[YOUR_DOMAIN]/erp/api/attendance/zkteco_push.php
 *   Token (query)  : ?token=ZKTECO_SECRET_TOKEN_2024
 *
 * ⚠️  Đổi token trước khi dùng production:
 *     - Đặt biến môi trường  ZKTECO_TOKEN  trên server, HOẶC
 *     - Thay chuỗi  ZKTECO_SECRET_TOKEN_2024  bên dưới.
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');

// Ngưỡng tính về sớm: nhân viên về trước giờ kết thúc ca ít nhất N giây thì ghi nhận về sớm
const EARLY_LEAVE_THRESHOLD_SECONDS = 60;

require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';

// ── Hàm ghi log debug ──────────────────────────────────────────────────────
function zkLog(string $msg): void {
    $logDir = $_SERVER['DOCUMENT_ROOT'] . '/erp/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents(
        $logDir . '/zkteco_debug.log',
        date('Y-m-d H:i:s') . ' | ' . $msg . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ── Xác thực token ─────────────────────────────────────────────────────────
$expectedToken = getenv('ZKTECO_TOKEN') ?: 'ZKTECO_SECRET_TOKEN_2024';
$receivedToken = $_GET['token']
    ?? $_SERVER['HTTP_X_TOKEN']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? '';

// Tương thích header "Authorization: ******"
$receivedToken = preg_replace('/^Bearer\s+/i', '', $receivedToken);

if (!hash_equals($expectedToken, $receivedToken)) {
    zkLog('AUTH_FAIL | ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' | token=' . $receivedToken);
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

// ── Đọc raw body và log ─────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
zkLog('RAW | ' . $raw);

// ── Parse body: thử JSON trước, fallback sang query-string ─────────────────
$records    = [];
$deviceSN   = $_GET['SErialNumber'] ?? $_GET['sn'] ?? '';

$json = json_decode($raw, true);
if (json_last_error() === JSON_ERROR_NONE && isset($json['table'])) {
    // Format JSON (firmware mới)
    if (($json['table'] ?? '') !== 'ATTLOG') {
        http_response_code(200);
        echo 'OK';
        exit;
    }
    $deviceSN = $json['sn'] ?? $deviceSN;
    foreach ((array)($json['data'] ?? []) as $row) {
        $records[] = [
            'pin'    => (string)($row['pin']    ?? ''),
            'time'   => (string)($row['time']   ?? ''),
            'status' => (int)($row['status']    ?? 0),
        ];
    }
} else {
    // Format query-string / form (firmware cũ ADMS)
    // body: table=ATTLOG&Stamp=9999&OpStamp=12345&ResType=CMD_DATA&SErialNumber=SERIAL&data=pin=001\ttime=2024-07-21 08:05:00\tstatus=0\tverify=1
    parse_str($raw, $formData);
    if (($formData['table'] ?? '') !== 'ATTLOG') {
        // Cũng thử $_POST
        if (($_POST['table'] ?? '') !== 'ATTLOG') {
            http_response_code(200);
            echo 'OK';
            exit;
        }
        $formData = $_POST;
    }
    $deviceSN = $formData['SErialNumber'] ?? $formData['sn'] ?? $deviceSN;

    // data: "pin=001\ttime=2024-07-21 08:05:00\tstatus=0\tverify=1"
    // Có thể nhiều dòng phân tách bằng "\n"
    $dataStr = $formData['data'] ?? '';
    foreach (explode("\n", $dataStr) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $fields = [];
        foreach (explode("\t", $line) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[trim($k)] = trim($v);
        }
        if (empty($fields['pin']) || empty($fields['time'])) continue;
        $records[] = [
            'pin'    => $fields['pin'],
            'time'   => $fields['time'],
            'status' => (int)($fields['status'] ?? 0),
        ];
    }
}

if (empty($records)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// ── Xử lý từng bản ghi ─────────────────────────────────────────────────────
try {
    $pdo = getDBConnection();

    foreach ($records as $row) {
        $pin      = $row['pin'];
        $timeStr  = $row['time'];
        $status   = $row['status']; // 0 = check-in, 1 = check-out

        if ($pin === '' || $timeStr === '') continue;

        $timeTs   = strtotime($timeStr);
        if ($timeTs === false) continue;
        $workDate = date('Y-m-d', $timeTs);

        // Tìm user theo employee_code
        $stmtU = $pdo->prepare(
            "SELECT id FROM users WHERE employee_code = ? AND is_active = 1 LIMIT 1"
        );
        $stmtU->execute([$pin]);
        $user = $stmtU->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            zkLog("USER_NOT_FOUND | pin=$pin");
            continue;
        }
        $userId = (int)$user['id'];

        // Tìm bản ghi chấm công ngày đó
        $stmtL = $pdo->prepare(
            "SELECT id, check_in, check_out FROM attendance_logs WHERE user_id = ? AND work_date = ? LIMIT 1"
        );
        $stmtL->execute([$userId, $workDate]);
        $existing = $stmtL->fetch(PDO::FETCH_ASSOC);

        if ($status === 0) {
            // ── CHECK-IN ────────────────────────────────────────────────────
            // Tính is_late / late_minutes từ ca làm việc
            $isLate      = 0;
            $lateMinutes = 0;

            $stmtS = $pdo->prepare("
                SELECT ws.start_time, ws.late_threshold
                FROM employee_shifts es
                JOIN work_shifts ws ON es.shift_id = ws.id
                WHERE es.user_id = ?
                  AND es.effective_date <= ?
                  AND (es.end_date IS NULL OR es.end_date >= ?)
                ORDER BY es.effective_date DESC
                LIMIT 1
            ");
            $stmtS->execute([$userId, $workDate, $workDate]);
            $shift = $stmtS->fetch(PDO::FETCH_ASSOC);

            // Fallback: ca hành chính mặc định
            if (!$shift) {
                try {
                    $stmtDef = $pdo->prepare(
                        "SELECT start_time, late_threshold FROM work_shifts WHERE shift_code = 'HANHCHINH' AND is_active = 1 LIMIT 1"
                    );
                    $stmtDef->execute();
                    $shift = $stmtDef->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $shift = null;
                }
            }

            if ($shift) {
                $shiftStart = strtotime($workDate . ' ' . $shift['start_time']);
                $threshold  = $shiftStart + ((int)($shift['late_threshold'] ?? 0) * 60);
                if ($timeTs > $threshold) {
                    $isLate      = 1;
                    $lateMinutes = (int)(($timeTs - $shiftStart) / 60);
                }
            }

            if ($existing) {
                if (empty($existing['check_in'])) {
                    $pdo->prepare("
                        UPDATE attendance_logs
                        SET check_in     = ?,
                            source       = 'device',
                            device_sn    = ?,
                            is_late      = ?,
                            late_minutes = ?,
                            updated_at   = NOW()
                        WHERE id = ?
                    ")->execute([$timeStr, $deviceSN, $isLate, $lateMinutes, $existing['id']]);
                    zkLog("CHECK_IN_UPDATE | user=$userId | time=$timeStr");
                } else {
                    zkLog("CHECK_IN_SKIP (already set) | user=$userId");
                }
            } else {
                $pdo->prepare("
                    INSERT INTO attendance_logs
                        (user_id, work_date, check_in, source, device_sn, is_late, late_minutes, created_at)
                    VALUES (?, ?, ?, 'device', ?, ?, ?, NOW())
                ")->execute([$userId, $workDate, $timeStr, $deviceSN, $isLate, $lateMinutes]);
                zkLog("CHECK_IN_INSERT | user=$userId | time=$timeStr");
            }

        } elseif ($status === 1) {
            // ── CHECK-OUT ────────────────────────────────────────────────────
            if (!$existing || empty($existing['check_in'])) {
                zkLog("CHECK_OUT_SKIP (no check_in) | user=$userId");
                continue;
            }

            $checkInTs  = strtotime($existing['check_in']);
            $checkOutTs = $timeTs;

            // Ca đêm: check_out < check_in → cộng 1 ngày
            if ($checkOutTs < $checkInTs) {
                $checkOutTs += 86400;
            }

            $workHours    = round(($checkOutTs - $checkInTs) / 3600, 2);
            $earlyLeave   = 0;
            $earlyMinutes = 0;

            // Tính về sớm từ ca
            $stmtS2 = $pdo->prepare("
                SELECT ws.start_time, ws.end_time
                FROM employee_shifts es
                JOIN work_shifts ws ON es.shift_id = ws.id
                WHERE es.user_id = ?
                  AND es.effective_date <= ?
                  AND (es.end_date IS NULL OR es.end_date >= ?)
                ORDER BY es.effective_date DESC
                LIMIT 1
            ");
            $stmtS2->execute([$userId, $workDate, $workDate]);
            $shift2 = $stmtS2->fetch(PDO::FETCH_ASSOC);

            if (!$shift2) {
                try {
                    $stmtDef2 = $pdo->prepare(
                        "SELECT start_time, end_time FROM work_shifts WHERE shift_code = 'HANHCHINH' AND is_active = 1 LIMIT 1"
                    );
                    $stmtDef2->execute();
                    $shift2 = $stmtDef2->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $shift2 = null;
                }
            }

            if ($shift2 && !empty($shift2['end_time'])) {
                $shiftStart2 = strtotime($workDate . ' ' . $shift2['start_time']);
                $shiftEnd    = strtotime($workDate . ' ' . $shift2['end_time']);
                // Ca đêm: end_time < start_time → +1 ngày
                if ($shiftEnd <= $shiftStart2) {
                    $shiftEnd += 86400;
                }
                if (($shiftEnd - $checkOutTs) >= EARLY_LEAVE_THRESHOLD_SECONDS) {
                    $earlyLeave   = 1;
                    $earlyMinutes = (int)(($shiftEnd - $checkOutTs) / 60);
                }
            }

            $pdo->prepare("
                UPDATE attendance_logs
                SET check_out           = ?,
                    work_hours          = ?,
                    early_leave         = ?,
                    early_leave_minutes = ?,
                    missing_checkout    = 0,
                    source              = 'device',
                    device_sn           = ?,
                    updated_at          = NOW()
                WHERE id = ?
            ")->execute([$timeStr, $workHours, $earlyLeave, $earlyMinutes, $deviceSN, $existing['id']]);
            zkLog("CHECK_OUT_UPDATE | user=$userId | time=$timeStr | hours=$workHours");
        }
    }
} catch (Throwable $e) {
    zkLog('ERROR | ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
}

// ── Phản hồi cho máy: bắt buộc HTTP 200 + body "OK" ───────────────────────
http_response_code(200);
echo 'OK';
