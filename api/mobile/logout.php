<?php
/**
 * POST /erp/api/mobile/logout – thu hồi token hiện tại.
 */
require_once __DIR__ . '/_bootstrap.php';

mobileApiInit();
mobileRequireMethod('POST');

$pdo  = getDBConnection();
$user = mobileRequireAuth($pdo);

$pdo->prepare("UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE id = ? AND user_id = ?")
    ->execute([$user['token_id'], $user['id']]);

mobileOk(null, 'Đã đăng xuất.');
