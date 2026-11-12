<?php
/**
 * Bootstrap dùng chung cho API của ứng dụng di động (Flutter) – /erp/api/mobile/*
 *
 * - Xác thực bằng token (kiểu "Bearer") gửi qua header Authorization
 *   (không dùng session/cookie, để chạy được từ Flutter web khác origin và app Android/iOS).
 * - CORS cho các origin được phép (cấu hình qua biến môi trường
 *   MOBILE_API_ALLOWED_ORIGINS, phân tách bằng dấu phẩy).
 * - Luôn trả JSON: { "ok": true, "data": ... } hoặc { "ok": false, "msg": "..." }
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

const MOBILE_TOKEN_TTL_DAYS = 30;

/**
 * Khởi tạo request API: header JSON, tắt hiển thị lỗi (tránh làm hỏng JSON), CORS + preflight.
 */
function mobileApiInit(): void {
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    mobileApiCors();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

function mobileAllowedOrigins(): array {
    $env = getenv('MOBILE_API_ALLOWED_ORIGINS');
    if ($env !== false && trim($env) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $env))));
    }
    return ['https://ntnvn.com', 'https://www.ntnvn.com'];
}

function mobileIsOriginAllowed(string $origin): bool {
    if ($origin === '') {
        return false;
    }
    if (in_array($origin, mobileAllowedOrigins(), true)) {
        return true;
    }
    // Cho phép Flutter web chạy local khi phát triển (flutter run -d chrome)
    return (bool)preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?$#', $origin);
}

function mobileApiCors(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    header('Vary: Origin');
    if (!mobileIsOriginAllowed($origin)) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Auth-Token');
    header('Access-Control-Max-Age: 600');
}

function mobileRespond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function mobileOk($data = null, ?string $msg = null, int $status = 200): never {
    $payload = ['ok' => true, 'data' => $data];
    if ($msg !== null) {
        $payload['msg'] = $msg;
    }
    mobileRespond($payload, $status);
}

function mobileError(string $msg, int $status = 400, array $extra = []): never {
    mobileRespond(array_merge(['ok' => false, 'msg' => $msg], $extra), $status);
}

function mobileRequireMethod(string ...$methods): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        mobileError('Phương thức không được hỗ trợ.', 405);
    }
    return $method;
}

/**
 * Đọc body JSON (ưu tiên) hoặc form-urlencoded.
 */
function mobileInput(): array {
    static $input = null;
    if ($input !== null) {
        return $input;
    }
    $raw = file_get_contents('php://input');
    $input = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }
    return $input;
}

function mobileInputString(string $key, string $default = ''): string {
    $value = mobileInput()[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function mobileValidDate(string $value): bool {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

/**
 * Lấy tháng/năm từ query (?month=&year=), mặc định là tháng hiện tại.
 */
function mobileMonthYearFromQuery(): array {
    $month = (int)($_GET['month'] ?? date('n'));
    $year  = (int)($_GET['year'] ?? date('Y'));
    if ($month < 1 || $month > 12) {
        $month = (int)date('n');
    }
    if ($year < 2020 || $year > 2100) {
        $year = (int)date('Y');
    }
    return [$month, $year];
}

// ───────────────────────── Token ─────────────────────────

function mobileEnsureTokenTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mobile_api_tokens (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_name VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY uq_mobile_api_tokens_hash (token_hash),
            KEY idx_mobile_api_tokens_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function mobileHashToken(string $token): string {
    return hash('sha256', $token);
}

/**
 * Tạo token mới cho user, chỉ lưu SHA-256 của token trong DB.
 *
 * @return array{token: string, expires_at: string}
 */
function mobileIssueToken(PDO $pdo, int $userId, ?string $deviceName): array {
    mobileEnsureTokenTable($pdo);
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + MOBILE_TOKEN_TTL_DAYS * 86400);
    $deviceName = ($deviceName !== null && $deviceName !== '') ? mb_substr($deviceName, 0, 100) : null;

    // Dọn token đã hết hạn / thu hồi của user
    try {
        $pdo->prepare("DELETE FROM mobile_api_tokens WHERE user_id = ? AND (expires_at < NOW() OR revoked_at IS NOT NULL)")
            ->execute([$userId]);
    } catch (Throwable $e) {
    }

    $pdo->prepare("
        INSERT INTO mobile_api_tokens (user_id, token_hash, device_name, created_at, last_used_at, expires_at)
        VALUES (?, ?, ?, NOW(), NOW(), ?)
    ")->execute([$userId, mobileHashToken($token), $deviceName, $expiresAt]);

    return ['token' => $token, 'expires_at' => $expiresAt];
}

function mobileBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $header = (string)$value;
                break;
            }
        }
    }
    if ($header !== '' && preg_match('/^Bearer\s+([0-9a-f]{64})$/i', trim($header), $m)) {
        return strtolower($m[1]);
    }
    // Fallback khi máy chủ không chuyển tiếp header Authorization
    $alt = trim((string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
    if (preg_match('/^[0-9a-f]{64}$/i', $alt)) {
        return strtolower($alt);
    }
    return null;
}

/**
 * Bắt buộc đăng nhập bằng token. Trả về thông tin user (giống currentUser()).
 */
function mobileRequireAuth(PDO $pdo): array {
    $token = mobileBearerToken();
    if ($token === null) {
        mobileError('Chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.', 401);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT t.id AS token_id,
                   u.id, u.employee_code, u.full_name, u.username, u.department_id, u.is_active,
                   r.name AS role, r.display_name AS role_name
            FROM mobile_api_tokens t
            JOIN users u ON u.id = t.user_id
            JOIN roles r ON r.id = u.role_id
            WHERE t.token_hash = ?
              AND t.revoked_at IS NULL
              AND t.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([mobileHashToken($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }

    if (!$row) {
        mobileError('Chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.', 401);
    }
    if (!(int)$row['is_active']) {
        mobileError('Tài khoản của bạn đã bị khóa. Liên hệ quản trị viên.', 401);
    }

    try {
        $pdo->prepare("UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE id = ?")->execute([$row['token_id']]);
    } catch (Throwable $e) {
    }

    return [
        'id'            => (int)$row['id'],
        'token_id'      => (int)$row['token_id'],
        'employee_code' => (string)$row['employee_code'],
        'full_name'     => (string)$row['full_name'],
        'username'      => (string)$row['username'],
        'role'          => (string)$row['role'],
        'role_name'     => (string)$row['role_name'],
        'department_id' => $row['department_id'] !== null ? (int)$row['department_id'] : null,
    ];
}

/**
 * Thông tin hồ sơ nhân viên (giống mobile/me.php).
 */
function mobileUserProfile(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT u.id, u.employee_code, u.full_name, u.username, u.email, u.phone, u.department_id,
               r.name AS role, r.display_name AS role_name, d.name AS department_name,
               ep.mobile_phone, ep.date_of_birth, ep.date_joined,
               ep.identity_no, ep.bank_account, ep.bank_name, ep.bank_branch
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN employee_profiles ep ON ep.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'id'              => (int)($p['id'] ?? $userId),
        'employee_code'   => $p['employee_code'] ?? '',
        'full_name'       => $p['full_name'] ?? '',
        'username'        => $p['username'] ?? '',
        'email'           => $p['email'] ?? null,
        'phone'           => ($p['mobile_phone'] ?? null) ?: ($p['phone'] ?? null),
        'role'            => $p['role'] ?? '',
        'role_name'       => $p['role_name'] ?? '',
        'department_id'   => isset($p['department_id']) ? (int)$p['department_id'] : null,
        'department_name' => $p['department_name'] ?? null,
        'date_of_birth'   => $p['date_of_birth'] ?? null,
        'date_joined'     => $p['date_joined'] ?? null,
        'identity_no'     => $p['identity_no'] ?? null,
        'bank_account'    => $p['bank_account'] ?? null,
        'bank_name'       => $p['bank_name'] ?? null,
        'bank_branch'     => $p['bank_branch'] ?? null,
    ];
}

/**
 * Gửi thông báo tới quản lý (production/manager/director) – giống mobile/leave.php, mobile/ot.php.
 */
function mobileNotifyManagers(PDO $pdo, string $title, string $message, string $type, int $referenceId): void {
    try {
        $managers = $pdo->query("SELECT id FROM users WHERE role_id IN (SELECT id FROM roles WHERE name IN ('production','manager','director')) AND is_active = 1")
            ->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, ?, ?, ?, ?)");
        foreach ($managers as $mgrId) {
            $stmt->execute([$mgrId, $title, $message, $type, $referenceId]);
        }
    } catch (Throwable $e) {
        error_log('mobile api notify managers failed: ' . $e->getMessage());
    }
}
