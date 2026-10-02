<?php
/**
 * GET   /erp/api/mobile/notifications             – danh sách thông báo của nhân viên
 *       ?unread=1 chỉ lấy chưa đọc; ?limit= (mặc định 50, tối đa 200)
 * PATCH /erp/api/mobile/notifications/{id}/read   – đánh dấu 1 thông báo đã đọc (?id=&action=read)
 * PATCH /erp/api/mobile/notifications/read-all    – đánh dấu tất cả đã đọc (?action=read_all)
 * (POST cũng được chấp nhận thay cho PATCH với máy chủ không hỗ trợ PATCH.)
 */
require_once __DIR__ . '/_bootstrap.php';

mobileApiInit();
$method = mobileRequireMethod('GET', 'PATCH', 'POST');

$pdo  = getDBConnection();
$user = mobileRequireAuth($pdo);

$action = (string)($_GET['action'] ?? '');

if ($method === 'GET') {
    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit < 1 || $limit > 200) {
        $limit = 50;
    }
    $unreadOnly = !empty($_GET['unread']);
    $sql = "SELECT id, title, message, type, reference_id, is_read, created_at
            FROM notifications
            WHERE user_id = ?" . ($unreadOnly ? " AND is_read = 0" : "") . "
            ORDER BY created_at DESC, id DESC
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user['id']]);
    $items = array_map(static fn(array $r) => [
        'id'           => (int)$r['id'],
        'title'        => (string)$r['title'],
        'message'      => (string)$r['message'],
        'type'         => $r['type'] ?? 'general',
        'reference_id' => $r['reference_id'] !== null ? (int)$r['reference_id'] : null,
        'is_read'      => (bool)(int)$r['is_read'],
        'created_at'   => $r['created_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$user['id']]);

    mobileOk(['items' => $items, 'unread_count' => (int)$unreadStmt->fetchColumn()]);
}

if ($action === 'read_all') {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user['id']]);
    mobileOk(null, 'Đã đánh dấu tất cả là đã đọc.');
}

$id = (int)($_GET['id'] ?? 0);
if ($action !== 'read' || $id <= 0) {
    mobileError('Yêu cầu không hợp lệ.', 400);
}

$stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$id, $user['id']]);
if (!$stmt->fetchColumn()) {
    mobileError('Không tìm thấy thông báo.', 404);
}

$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
mobileOk(['id' => $id, 'is_read' => true], 'Đã đánh dấu đã đọc.');
