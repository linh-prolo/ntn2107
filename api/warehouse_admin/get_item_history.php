<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLoginApi();
requireRoleApi('director', 'accountant', 'manager', 'warehouse');

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Thiếu vật tư'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDBConnection();
$historyLimit = 50;
$historyLimitSql = (int)$historyLimit;

$item = fetchOneSafe($pdo, "
    SELECT i.id, i.item_code, i.item_name, i.unit, i.min_stock
    FROM wa_items i
    WHERE i.id = ? AND i.is_active = 1
", [$itemId]);

if (!$item) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Vật tư không tồn tại hoặc đã ngừng sử dụng'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stock = (float)fetchScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(CASE WHEN type='import' THEN qty WHEN type='export' THEN -qty ELSE 0 END), 0) FROM wa_transactions WHERE item_id = ?",
    [$itemId],
    0
);
$item['stock'] = $stock;

$history = fetchAllSafe($pdo, "
    SELECT t.transacted_at, t.type, t.qty, t.ref_no, t.note, u.full_name
    FROM wa_transactions t
    LEFT JOIN users u ON u.id = t.transacted_by
    WHERE t.item_id = ?
    ORDER BY t.transacted_at DESC, t.id DESC
    LIMIT " . $historyLimitSql . "
", [$itemId]);

echo json_encode([
    'ok' => true,
    'item' => $item,
    'history' => $history,
    'history_limit' => $historyLimit,
], JSON_UNESCAPED_UNICODE);
