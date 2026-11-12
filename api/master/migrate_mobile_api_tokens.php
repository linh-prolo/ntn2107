<?php
/**
 * One-time migration: create mobile_api_tokens table (token đăng nhập cho app Flutter / API /erp/api/mobile/*).
 * Bảng cũng được tự tạo ở lần đăng nhập app đầu tiên; script này giúp tạo trước.
 *
 * Usage: php api/master/migrate_mobile_api_tokens.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This migration is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../mobile/_bootstrap.php';

try {
    mobileEnsureTokenTable(getDBConnection());
    echo "Migration successful: table 'mobile_api_tokens' is ready.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
