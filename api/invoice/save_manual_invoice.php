<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLoginApi();
requireRoleApi('director', 'accountant');

$pdo  = getDBConnection();
$user = currentUser();

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF invalid']); exit;
}

$bkavInvoiceNo = trim($_POST['bkav_invoice_no'] ?? '');
$customerId    = (int)($_POST['customer_id'] ?? 0);
$invoiceDate   = trim($_POST['invoice_date'] ?? date('Y-m-d'));
$subtotal      = (float)($_POST['subtotal'] ?? 0);
$vatRate       = (float)($_POST['vat_rate'] ?? 0);
$paymentStatus = trim($_POST['payment_status'] ?? 'unpaid');
$paidAmount    = (float)($_POST['paid_amount'] ?? 0);
$note          = trim($_POST['note'] ?? '') ?: null;

if ($bkavInvoiceNo === '') {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập số hoá đơn']); exit;
}
if (!$customerId) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng chọn khách hàng']); exit;
}
if ($subtotal <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Tổng số tiền chưa VAT phải lớn hơn 0']); exit;
}
if ($vatRate < 0 || $vatRate > 100) {
    echo json_encode(['ok' => false, 'msg' => 'VAT không hợp lệ']); exit;
}
if (!in_array($paymentStatus, ['unpaid', 'partial', 'paid'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Trạng thái thanh toán không hợp lệ']); exit;
}

$d = DateTime::createFromFormat('Y-m-d', $invoiceDate);
if (!$d || $d->format('Y-m-d') !== $invoiceDate) {
    echo json_encode(['ok' => false, 'msg' => 'Ngày hoá đơn không hợp lệ']); exit;
}

$vatAmount   = round($subtotal * $vatRate / 100);
$totalAmount = $subtotal + $vatAmount;

if ($paymentStatus === 'partial' && ($paidAmount <= 0 || $paidAmount >= $totalAmount)) {
    echo json_encode(['ok' => false, 'msg' => 'Số tiền đã thu phải lớn hơn 0 và nhỏ hơn tổng tiền']); exit;
}
if ($paymentStatus === 'paid') {
    $paidAmount = $totalAmount;
}
if ($paymentStatus === 'unpaid') {
    $paidAmount = 0;
}

$custStmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND is_active = 1");
$custStmt->execute([$customerId]);
if (!$custStmt->fetchColumn()) {
    echo json_encode(['ok' => false, 'msg' => 'Khách hàng không tồn tại hoặc đã ngưng hoạt động']); exit;
}

try {
    $pdo->beginTransaction();

    $lockName = 'invoice_create';
    $lockStmt = $pdo->prepare("SELECT GET_LOCK(?, 10)");
    $releaseStmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
    $lockStmt->execute([$lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Không thể khoá thao tác lưu hoá đơn thủ công');
    }

    $dupStmt = $pdo->prepare("SELECT id FROM invoices WHERE TRIM(bkav_invoice_no) = ? LIMIT 1 FOR UPDATE");
    $dupStmt->execute([$bkavInvoiceNo]);
    if ($dupStmt->fetchColumn()) {
        $releaseStmt->execute([$lockName]);
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Số hoá đơn này đã tồn tại']); exit;
    }

    $pdo->prepare("
        INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES ('INV',?,1)
        ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
    ")->execute([$invoiceDate]);
    $seqStmt = $pdo->prepare("
        SELECT last_seq FROM document_sequences WHERE doc_type='INV' AND doc_date=?
    ");
    $seqStmt->execute([$invoiceDate]);
    $seq = $seqStmt->fetchColumn();
    $invoiceNo = 'INV-' . date('Ymd', strtotime($invoiceDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

    $pdo->prepare("
        INSERT INTO invoices
            (invoice_no, invoice_date, customer_id, subtotal, vat_rate, vat_amount, total_amount,
             note, status, created_by, bkav_invoice_no, bkav_status, bkav_issued_at, bkav_raw_response,
             is_locked, locked_bkav_no, locked_bkav_date, locked_at, locked_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,1,?,?,NOW(),?)
    ")->execute([
        $invoiceNo,
        $invoiceDate,
        $customerId,
        $subtotal,
        $vatRate,
        $vatAmount,
        $totalAmount,
        $note,
        $paymentStatus,
        $user['id'],
        $bkavInvoiceNo,
        'issued',
        json_encode(['source' => 'manual_entry'], JSON_UNESCAPED_UNICODE),
        $bkavInvoiceNo,
        $invoiceDate,
        $user['id'],
    ]);
    $invoiceId = (int)$pdo->lastInsertId();

    if ($paidAmount > 0) {
        $pdo->prepare("
            INSERT INTO payments (invoice_id, payment_date, amount, payment_method, note, created_by)
            VALUES (?,?,?,?,?,?)
        ")->execute([
            $invoiceId,
            $invoiceDate,
            $paidAmount,
            'transfer',
            'Thanh toán khi nhập hoá đơn thủ công',
            $user['id'],
        ]);
    }

    $releaseStmt->execute([$lockName]);
    $pdo->commit();
    echo json_encode([
        'ok' => true,
        'msg' => 'Đã thêm hoá đơn thủ công',
        'invoice_no' => $invoiceNo,
        'id' => $invoiceId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!empty($releaseStmt) && !empty($lockName)) {
        try {
            $releaseStmt->execute([$lockName]);
        } catch (Throwable $releaseErr) {
        }
    }
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống']);
}
