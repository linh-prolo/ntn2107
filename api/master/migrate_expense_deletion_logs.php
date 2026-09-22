<?php
/**
 * One-time migration: create expense_deletion_logs table for approved expense deletion audit.
 * Run this script once via CLI.
 *
 * Usage: php api/master/migrate_expense_deletion_logs.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This migration is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../../config/database.php';

$pdo = getDBConnection();

try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'expense_deletion_logs'")->fetchColumn();
    if ($tableExists) {
        echo "Table 'expense_deletion_logs' already exists. Nothing to do.\n";
        exit;
    }
} catch (Throwable $e) {
    echo "Migration pre-check failed: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expense_deletion_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_expense_id INT NOT NULL,
            request_no VARCHAR(50) NOT NULL,
            category_id INT NULL,
            category_name VARCHAR(100) NULL,
            amount DECIMAL(15,2) NOT NULL,
            expense_date DATE NOT NULL,
            purpose TEXT NULL,
            has_invoice TINYINT(1) DEFAULT 0,
            invoice_no VARCHAR(100) NULL,
            invoice_date DATE NULL,
            invoice_company VARCHAR(255) NULL,
            payment_method VARCHAR(20) NULL,
            note TEXT NULL,
            status_before_delete VARCHAR(20) NOT NULL,
            requested_by INT NULL,
            requested_name VARCHAR(150) NULL,
            approved_by INT NULL,
            approved_name VARCHAR(150) NULL,
            approved_at DATETIME NULL,
            paid_amount DECIMAL(15,2) DEFAULT 0,
            payments_snapshot TEXT NULL COMMENT 'JSON snapshot của các khoản đã thanh toán',
            deleted_by INT NOT NULL,
            deleted_name VARCHAR(150) NULL,
            delete_reason TEXT NOT NULL,
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_edl_original_expense (original_expense_id),
            KEY idx_edl_expense_date (expense_date),
            KEY idx_edl_deleted_at (deleted_at),
            KEY idx_edl_deleted_by (deleted_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    echo "Migration successful: table 'expense_deletion_logs' is ready.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
