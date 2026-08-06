<?php
/**
 * One-time migration: add process_step column to customer_prices table.
 * Run this script once via CLI or browser (requires director role).
 *
 * Usage (CLI): php api/master/migrate_process_step.php
 * Usage (web): visit /erp/api/master/migrate_process_step.php as director
 */
if (php_sapi_name() !== 'cli') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
    requireLogin();
    requireRole('director');
    header('Content-Type: text/plain; charset=utf-8');
} else {
    // CLI mode — load config relative to this file
    require_once __DIR__ . '/../../config/database.php';
}

$pdo = getDBConnection();

// Check if column already exists
$stmt = $pdo->query("SHOW COLUMNS FROM customer_prices LIKE 'process_step'");
if ($stmt->fetch()) {
    echo "Column 'process_step' already exists in customer_prices. Nothing to do.\n";
    exit;
}

try {
    $pdo->exec("ALTER TABLE customer_prices
        ADD COLUMN process_step VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Công đoạn sản xuất (chỉ dùng nội bộ, không in lên hóa đơn)'
        AFTER note");
    echo "Migration successful: column 'process_step' added to customer_prices.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
