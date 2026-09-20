<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLoginApi();
requireRoleApi('director', 'accountant');

$pdo = getDBConnection();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCSRF($body['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF không hợp lệ']); exit;
}

$invoiceId = (int)($body['invoice_id'] ?? 0);
if (!$invoiceId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu invoice_id']); exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, invoice_no, delivery_id, bkav_invoice_no, bkav_status, bkav_raw_response, is_locked
        FROM invoices
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy hoá đơn']); exit;
    }

    $bkavIssued = !empty($invoice['bkav_invoice_no']) || (($invoice['bkav_status'] ?? '') === 'issued');
    $bkavMeta = json_decode($invoice['bkav_raw_response'] ?? '', true);
    $isManualEntry = is_array($bkavMeta) && (($bkavMeta['source'] ?? '') === 'manual_entry');
    if ($bkavIssued && !$isManualEntry) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Hoá đơn đã xuất BKAV, không thể xoá']); exit;
    }
    if (!empty($invoice['is_locked']) && !$isManualEntry) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Hoá đơn đã bị khoá, không thể xoá']); exit;
    }

    $paymentCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ?");
    $paymentCheck->execute([$invoiceId]);
    if ((int)$paymentCheck->fetchColumn() > 0) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Hoá đơn đã có ghi nhận thu tiền. Vui lòng xoá payment trước.']); exit;
    }

    $pdo->prepare("
        DELETE dp
        FROM debt_payments dp
        INNER JOIN debt_tracking dt ON dt.id = dp.debt_id
        WHERE dt.invoice_id = ?
    ")->execute([$invoiceId]);

    $deliveryIds = [];
    if (is_array($bkavMeta) && ($bkavMeta['source'] ?? '') === 'oqc' && !empty($bkavMeta['delivery_ids']) && is_array($bkavMeta['delivery_ids'])) {
        $deliveryIds = array_values(array_unique(array_filter(array_map('intval', $bkavMeta['delivery_ids']))));
    }
    if (!empty($deliveryIds)) {
        $ph = implode(',', array_fill(0, count($deliveryIds), '?'));
        $pdo->prepare("UPDATE oqc_deliveries SET status='draft' WHERE id IN ($ph)")->execute($deliveryIds);
    }

    $pdo->prepare("DELETE FROM debt_tracking WHERE invoice_id = ?")->execute([$invoiceId]);
    $pdo->prepare("DELETE FROM invoice_delivery_notes WHERE invoice_id = ?")->execute([$invoiceId]);
    $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoiceId]);
    $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoiceId]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'msg' => 'Đã xoá hoá đơn']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống']);
}
