<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLoginApi();
requireRoleApi('director', 'accountant', 'manager', 'warehouse');

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu vật tư']);
    exit;
}

$pdo = getDBConnection();

$item = fetchOneSafe($pdo, "
    SELECT i.id, i.item_code, i.item_name, i.unit, i.min_stock,
           COALESCE(SUM(CASE WHEN t.type='import' THEN t.qty ELSE -t.qty END), 0) AS stock
    FROM wa_items i
    LEFT JOIN wa_transactions t ON t.item_id = i.id
    WHERE i.id = ? AND i.is_active = 1
    GROUP BY i.id, i.item_code, i.item_name, i.unit, i.min_stock
", [$itemId]);

if (!$item) {
    echo json_encode(['ok' => false, 'msg' => 'Vật tư không tồn tại hoặc đã ngừng sử dụng']);
    exit;
}

$history = fetchAllSafe($pdo, "
    SELECT t.transacted_at, t.type, t.qty, t.ref_no, t.note, u.full_name
    FROM wa_transactions t
    LEFT JOIN users u ON u.id = t.transacted_by
    WHERE t.item_id = ?
    ORDER BY t.transacted_at DESC, t.id DESC
    LIMIT 100
", [$itemId]);

echo json_encode([
    'ok' => true,
    'item' => $item,
    'history' => $history,
], JSON_UNESCAPED_UNICODE);
