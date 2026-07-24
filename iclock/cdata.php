<?php
/**
 * iclock/cdata.php
 * ZKTeco ADMS protocol endpoint.
 *
 * GET  /erp/iclock/cdata?SN=xxx&options=all  → Handshake / trả cấu hình cho máy
 * POST /erp/iclock/cdata?SN=xxx&table=ATTLOG → Nhận log chấm công từ máy
 *
 * Logic lấy dữ liệu:
 *   - Lần chấm ĐẦU TIÊN trong ngày → lưu làm check_in
 *   - Lần chấm THỨ 2 trở đi        → luôn ghi đè check_out (giữ lại lần cuối cùng)
 *   - Chấp nhận MỌI giá trị status (kể cả 255) — không lọc theo status
 *
 * KHÔNG dùng requireRole() hay session — máy gọi trực tiếp không có session.
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');

const EARLY_LEAVE_THRESHOLD_SECONDS = 60;

require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';

function zkLog(string $msg): void {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/erp/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/zkteco_debug.log', date('Y-m-d H:i:s') . ' | ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

$sn     = $_GET['SN'] ?? $_GET['sn'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: Handshake — trả cấu hình cho máy ─────────────────────────────────
if ($method === 'GET') {
    zkLog("HANDSHAKE | SN=$sn");
    header('Content-Type: text/plain');
    echo "GET OPTION FROM: $sn\r\n";
    echo "ATTLOGStamp=9999\r\n";
    echo "OPERLOGStamp=9999\r\n";
    echo "ATTPHOTOStamp=9999\r\n";
    echo "ErrorDelay=30\r\n";
    echo "Delay=10\r\n";
    echo "TransTimes=00:00;14:05\r\n";
    echo "TransInterval=1\r\n";
    echo "TransFlag=TransData AttLog OpLog\r\n";
    echo "TimeZone=7\r\n";
    echo "Realtime=1\r\n";
    echo "Encrypt=None\r\n";
    echo "ServerVer=2.4.1 2015-04-14\r\n";
    echo "PushProtVer=2.4.1\r\n";
    exit;
}

// ── POST: Nhận ATTLOG ──────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
zkLog("ATTLOG_RAW | SN=$sn | " . $raw);

// Parse body — thử parse_str trước (firmware cũ gửi dạng form urlencoded)
parse_str($raw, $formData);
if (empty($formData['table'])) {
    $formData = $_POST;
}

$table    = $formData['table'] ?? $_GET['table'] ?? '';
$deviceSN = $formData['SErialNumber'] ?? $formData['sn'] ?? $sn;

// ── Xác định $dataStr ──────────────────────────────────────────────────────
// SpeedFace V5L có thể gửi theo nhiều cách:
//
// Cách 1 (form urlencoded, firmware chuẩn):
//   table=ATTLOG&SErialNumber=AJE...&data=1%092026-07-21%2021%3A04%3A42%09255%09...
//   → parse_str() decode thành $formData['data']
//
// Cách 2 (raw body, một số firmware):
//   ATTLOG
//   1	2026-07-21 21:04:42	255	15	0	0
//   → $table rỗng, raw body chứa trực tiếp các dòng
//
// Cách 3 (raw body không có key=value):
//   1 2026-07-21 21:04:42 255 15 0 0 0 0 0 58
//   → parse_str() không parse được, $formData rỗng

$dataStr = $formData['data'] ?? '';

// Nếu $dataStr rỗng → thử lấy từ raw body trực tiếp
if ($dataStr === '') {
    // Kiểm tra raw body có chứa dòng dữ liệu chấm công không
    // (dòng bắt đầu bằng số = PIN)
    $rawLines = explode("\n", trim($raw));
    $dataLines = [];
    foreach ($rawLines as $rl) {
        $rl = trim($rl);
        // Bỏ qua dòng header dạng "key=value" hoặc dòng trống
        if ($rl === '' || strpos($rl, 'table=') === 0 || strpos($rl, 'ATTLOG') === 0) continue;
        // Dòng hợp lệ: bắt đầu bằng chữ số (PIN)
        if (preg_match('/^\d/', $rl)) {
            $dataLines[] = $rl;
        }
    }
    $dataStr = implode("\n", $dataLines);
    zkLog("DATA_FROM_RAW | dataStr=" . $dataStr);
}

// Nếu table vẫn rỗng nhưng $dataStr có dữ liệu → vẫn xử lý
if ($table !== 'ATTLOG' && $dataStr === '') {
    http_response_code(200);
    echo 'OK';
    exit;
}

// ── Parse từng dòng chấm công ─────────────────────────────────────────────
// Format tab  : "PIN\tYYYY-MM-DD HH:MM:SS\tSTATUS\t..."
// Format space: "PIN YYYY-MM-DD HH:MM:SS STATUS ..."
//
// Log thực tế : "1 2026-07-21 20:56:42 255 15 0 0 0 0 0 55"
//   fields[0] = PIN
//   fields[1] = DATE (YYYY-MM-DD)
//   fields[2] = TIME (HH:MM:SS)
//   fields[3..] = bỏ qua

$records = [];
foreach (explode("\n", $dataStr) as $line) {
    $line = trim($line);
    if ($line === '') continue;

    if (strpos($line, "\t") !== false) {
        // Tab-separated
        $fields  = explode("\t", $line);
        $pin     = trim($fields[0] ?? '');
        $timeStr = trim($fields[1] ?? ''); // "YYYY-MM-DD HH:MM:SS"
    } else {
        // Space-separated: PIN DATE TIME STATUS ...
        $fields  = preg_split('/\s+/', $line);
        $pin     = trim($fields[0] ?? '');
        $date    = trim($fields[1] ?? '');
        $time    = trim($fields[2] ?? '');
        $timeStr = $date . ' ' . $time;
    }

    if ($pin === '' || trim($timeStr) === ' ' || trim($timeStr) === '') continue;

    // Validate datetime
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', trim($timeStr))) continue;

    $records[] = [
        'pin'  => $pin,
        'time' => trim($timeStr),
    ];
}

zkLog("PARSED_RECORDS | count=" . count($records) . " | " . json_encode($records));

if (empty($records)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// ── Xử lý từng bản ghi — logic ĐẦU/CUỐI ──────────────────────────────────
try {
    $pdo = getDBConnection();

    foreach ($records as $row) {
        $pin     = $row['pin'];
        $timeStr = $row['time'];

        if ($pin === '' || $timeStr === '') continue;

        $timeTs = strtotime($timeStr);
        if ($timeTs === false) {
            zkLog("PARSE_TIME_FAIL | pin=$pin | timeStr=$timeStr");
            continue;
        }
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

        // ── Xử lý ca đêm: nếu ngày hiện tại chưa có bản ghi (hoặc chưa có check_in),
        // kiểm tra ngày hôm trước có đang mở (check_in nhưng chưa check_out) không.
        // Nếu có → đây là check_out của ca đêm ngày hôm trước.
        if ((!$existing || empty($existing['check_in'])) && (int)date('H', $timeTs) < 12) {
            $prevDate = date('Y-m-d', strtotime($workDate . ' -1 day'));
            $stmtPrev = $pdo->prepare(
                "SELECT id, check_in, check_out FROM attendance_logs
                 WHERE user_id = ? AND work_date = ?
                   AND check_in IS NOT NULL AND check_out IS NULL
                 LIMIT 1"
            );
            $stmtPrev->execute([$userId, $prevDate]);
            $prevLog = $stmtPrev->fetch(PDO::FETCH_ASSOC);

            if ($prevLog) {
                // Đây là check_out ca đêm → gán về ngày hôm trước
                $workDate = $prevDate;
                $existing = $prevLog;
                zkLog("NIGHT_SHIFT_CHECKOUT | user=$userId | reassigned to workDate=$workDate");
            }
        }

        // ── Lần ĐẦU TIÊN: chưa có check_in → lưu làm check_in ──────────
        if (!$existing || empty($existing['check_in'])) {

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
                $pdo->prepare("
                    INSERT INTO attendance_logs
                        (user_id, work_date, check_in, source, device_sn, is_late, late_minutes, created_at)
                    VALUES (?, ?, ?, 'device', ?, ?, ?, NOW())
                ")->execute([$userId, $workDate, $timeStr, $deviceSN, $isLate, $lateMinutes]);
                zkLog("CHECK_IN_INSERT | user=$userId | time=$timeStr");
            }

        } else {
            // ── Lần THỨ 2 trở đi → ghi đè check_out ────────────────────
            $checkInTs  = strtotime($existing['check_in']);
            $checkOutTs = $timeTs;

            if ($checkOutTs < $checkInTs) {
                $checkOutTs += 86400;
            }

            if ($checkOutTs <= $checkInTs) {
                zkLog("CHECK_OUT_SKIP (time <= check_in) | user=$userId | time=$timeStr");
                continue;
            }

            $workHours    = round(($checkOutTs - $checkInTs) / 3600, 2);
            $earlyLeave   = 0;
            $earlyMinutes = 0;

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

http_response_code(200);
echo 'OK';
