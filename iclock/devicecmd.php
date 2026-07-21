<?php
/**
 * iclock/devicecmd.php
 * ZKTeco ADMS protocol endpoint.
 *
 * POST /erp/iclock/devicecmd?SN=xxx → Máy gửi kết quả thực thi lệnh
 *
 * KHÔNG dùng requireRole() hay session — máy gọi trực tiếp không có session.
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');

function zkLog(string $msg): void {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/erp/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/zkteco_debug.log', date('Y-m-d H:i:s') . ' | ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

$sn = $_GET['SN'] ?? $_GET['sn'] ?? '';
zkLog("DEVICECMD | SN=$sn");

http_response_code(200);
echo 'OK';
