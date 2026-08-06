<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLoginApi();
requireRoleApi('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$customerId = (int)($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    echo json_encode(['ok' => false, 'products' => []]);
    exit;
}

$products = fetchAllSafe($pdo, "
    SELECT cp.id AS price_id,
           pc.id,
           pc.product_code,
           pc.description,
           pc.unit,
           cp.process_step,
           cp.unit_price
    FROM customer_prices cp
    JOIN product_codes pc ON cp.product_code_id = pc.id
    WHERE cp.customer_id = ?
      AND pc.is_active = 1
      AND cp.effective_date <= CURDATE()
      AND (cp.expired_date IS NULL OR cp.expired_date >= CURDATE())
    ORDER BY pc.product_code, cp.process_step
", [$customerId]);

echo json_encode(['ok' => true, 'products' => $products]);
