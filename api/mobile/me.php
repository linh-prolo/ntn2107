<?php
/**
 * GET /erp/api/mobile/me – hồ sơ nhân viên đang đăng nhập.
 */
require_once __DIR__ . '/_bootstrap.php';

mobileApiInit();
mobileRequireMethod('GET');

$pdo  = getDBConnection();
$user = mobileRequireAuth($pdo);

mobileOk(mobileUserProfile($pdo, $user['id']));
