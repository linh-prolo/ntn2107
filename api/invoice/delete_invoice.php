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
        SELECT id, invoice_no, status, bkav_invoice_no, bkav_status, bkav_raw_response, is_locked
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
    if ($bkavIssued) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Hoá đơn đã xuất BKAV, không thể xoá']); exit;
    }
    if (!empty($invoice['is_locked'])) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Hoá đơn đã bị khoá, không thể xoá']); exit;
    }
    if (($invoice['status'] ?? '') !== 'unpaid') {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Chỉ được xoá hoá đơn chưa thanh toán']); exit;
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
        $candidateConditions = [];
        $candidateParams = [$invoiceId];
        foreach ($deliveryIds as $deliveryId) {
            $candidateConditions[] = "bkav_raw_response LIKE ?";
            $candidateParams[] = '%' . $deliveryId . '%';
        }

        $stillLinked = [];
        if (!empty($candidateConditions)) {
            $otherInvoices = $pdo->prepare("
                SELECT bkav_raw_response
                FROM invoices
                WHERE id <> ?
                  AND bkav_raw_response IS NOT NULL
                  AND bkav_raw_response <> ''
                  AND (" . implode(' OR ', $candidateConditions) . ")
            ");
            $otherInvoices->execute($candidateParams);

            foreach ($otherInvoices->fetchAll(PDO::FETCH_COLUMN) as $rawMeta) {
                $otherMeta = json_decode($rawMeta, true);
                if (!is_array($otherMeta) || ($otherMeta['source'] ?? '') !== 'oqc' || empty($otherMeta['delivery_ids']) || !is_array($otherMeta['delivery_ids'])) {
                    continue;
                }
                foreach ($otherMeta['delivery_ids'] as $otherDeliveryId) {
                    $otherDeliveryId = (int)$otherDeliveryId;
                    if ($otherDeliveryId > 0) {
                        $stillLinked[$otherDeliveryId] = true;
                    }
                }
            }
        }

        $reopenDeliveryIds = array_values(array_filter($deliveryIds, static fn($id) => empty($stillLinked[$id])));
        if (!empty($reopenDeliveryIds)) {
            $ph = implode(',', array_fill(0, count($reopenDeliveryIds), '?'));
            $pdo->prepare("UPDATE oqc_deliveries SET status='draft' WHERE status='delivered' AND id IN ($ph)")->execute($reopenDeliveryIds);
        }
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
