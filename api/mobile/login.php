<?php
/**
 * POST /erp/api/mobile/login
 * Body JSON: { "username": "<username hoặc mã NV>", "password": "...", "device_name": "..." }
 * Trả về: { ok, data: { token, expires_at, user } }
 */
require_once __DIR__ . '/_bootstrap.php';

mobileApiInit();
mobileRequireMethod('POST');

$username   = mobileInputString('username');
$password   = (string)(mobileInput()['password'] ?? '');
$deviceName = mobileInputString('device_name');

if ($username === '' || $password === '') {
    mobileError('Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.', 422);
}

$pdo = getDBConnection();

$stmt = $pdo->prepare("
    SELECT u.id, u.password_hash, u.is_active
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.username = ? OR u.employee_code = ?
    LIMIT 1
");
$stmt->execute([$username, $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    mobileError('Tên đăng nhập hoặc mật khẩu không đúng.', 401);
}
if (!(int)$user['is_active']) {
    mobileError('Tài khoản của bạn đã bị khóa. Liên hệ quản trị viên.', 403);
}

try {
    $issued = mobileIssueToken($pdo, (int)$user['id'], $deviceName);
} catch (Throwable $e) {
    error_log('mobile api login token error: ' . $e->getMessage());
    mobileError('Không thể tạo phiên đăng nhập. Vui lòng thử lại sau.', 500);
}

mobileOk([
    'token'      => $issued['token'],
    'expires_at' => $issued['expires_at'],
    'user'       => mobileUserProfile($pdo, (int)$user['id']),
], 'Đăng nhập thành công.');
