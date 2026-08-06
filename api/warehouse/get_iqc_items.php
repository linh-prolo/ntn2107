<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLoginApi();
requireRoleApi('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$receiptId = (int)($_GET['receipt_id'] ?? 0);
if ($receiptId <= 0) {
    echo json_encode(['ok' => false, 'items' => []]);
    exit;
}

$items = fetchAllSafe($pdo, "SELECT i.id, i.product_code_id, i.qty, i.unit, i.note, pc.product_code, pc.description,
                                   cp.process_step
                            FROM iqc_receipt_items i
                            JOIN product_codes pc ON pc.id = i.product_code_id
                            LEFT JOIN customer_prices cp ON cp.product_code_id = i.product_code_id
                                AND cp.customer_id = (SELECT customer_id FROM iqc_receipts WHERE id = i.receipt_id)
                                AND cp.effective_date <= CURDATE()
                                AND (cp.expired_date IS NULL OR cp.expired_date >= CURDATE())
                            WHERE i.receipt_id = ?
                            ORDER BY i.id", [$receiptId]);

echo json_encode(['ok' => true, 'items' => $items]);
