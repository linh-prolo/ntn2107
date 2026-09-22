<?php
/**
 * One-time migration: add is_manual flag to invoices table.
 * Run this script once via CLI.
 *
 * Usage: php api/master/migrate_invoice_is_manual.php
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
    $checkStmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?
         LIMIT 1'
    );
    $checkStmt->execute(['invoices', 'is_manual']);
    $columnExists = (bool)$checkStmt->fetchColumn();
    if ($columnExists) {
        echo "Column 'is_manual' already exists on 'invoices'. Nothing to do.\n";
        exit;
    }
} catch (Throwable $e) {
    echo "Migration pre-check failed: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    echo "Migration successful: column 'is_manual' has been added to 'invoices'.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
