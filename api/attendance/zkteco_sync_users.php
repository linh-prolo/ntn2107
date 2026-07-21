<?php
/**
 * ZKTeco — Đồng bộ danh sách nhân viên từ ERP lên máy chấm công.
 *
 * POST /erp/api/attendance/zkteco_sync_users.php
 * Body JSON: { "device_ip": "192.168.1.201", "device_port": 4370 }
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
header('Content-Type: application/json; charset=utf-8');

requireRoleApi('director', 'accountant', 'manager');

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$deviceIp  = trim($body['device_ip']   ?? '');
$devicePort= (int)($body['device_port'] ?? 4370);

if ($deviceIp === '') {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu địa chỉ IP máy chấm công']);
    exit;
}

// Lấy danh sách nhân viên active
$pdo  = getDBConnection();
$stmt = $pdo->query("SELECT id, employee_code, full_name FROM users WHERE is_active = 1 ORDER BY id");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$synced = 0;
$errors = [];

foreach ($users as $u) {
    $pin  = $u['employee_code'];
    $name = $u['full_name'];

    if (empty($pin)) continue;

    // Gọi ZKTeco HTTP API để thêm / cập nhật user
    // Endpoint chuẩn ADMS: POST http://{ip}:{port}/iclock/cdata
    $url     = "http://{$deviceIp}:{$devicePort}/iclock/cdata";
    $payload = "CMD=DATA_UPDATE&table=USERINFO&Punch=0&PIN={$pin}&Name=" . rawurlencode($name);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
            'timeout'       => 5,
            'ignore_errors' => true,
        ],
    ]);

    $resp = @file_get_contents($url, false, $ctx);

    if ($resp === false) {
        $errors[] = "PIN {$pin}: không gửi được lệnh";
        continue;
    }

    $resp = trim($resp);
    if (stripos($resp, 'OK') !== false || $resp === '') {
        $synced++;
    } else {
        $errors[] = "PIN {$pin}: phản hồi máy = {$resp}";
    }
}

if ($synced === 0 && count($errors) === count($users)) {
    echo json_encode(['ok' => false, 'msg' => 'Không kết nối được máy', 'errors' => $errors]);
} else {
    echo json_encode(['ok' => true, 'synced' => $synced, 'errors' => $errors]);
}
