<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLoginApi();
requireRoleApi('director', 'accountant');

$pdo    = getDBConnection();
$user   = currentUser();
$action = trim($_POST['action'] ?? 'add');

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF invalid']); exit;
}

function manualInvoiceJsonExit(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function invoiceColumnExists(PDO $pdo, string $columnName): bool
{
    static $cache = [];

    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?
         LIMIT 1'
    );
    $stmt->execute(['invoices', $columnName]);

    return $cache[$columnName] = (bool)$stmt->fetchColumn();
}

if (!in_array($action, ['add', 'edit'], true)) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Thao tác không hợp lệ']);
}

$bkavInvoiceNo = trim($_POST['bkav_invoice_no'] ?? '');
$customerId    = (int)($_POST['customer_id'] ?? 0);
$invoiceDate   = trim($_POST['invoice_date'] ?? date('Y-m-d'));
$subtotal      = (float)($_POST['subtotal'] ?? 0);
$vatRate       = (float)($_POST['vat_rate'] ?? 0);
$note          = trim($_POST['note'] ?? '') ?: null;

if ($bkavInvoiceNo === '') {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Vui lòng nhập số hoá đơn']);
}
if (!$customerId) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Vui lòng chọn khách hàng']);
}
if ($subtotal <= 0) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Tổng số tiền chưa VAT phải lớn hơn 0']);
}
if ($vatRate < 0 || $vatRate > 100) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'VAT không hợp lệ']);
}

$d = DateTime::createFromFormat('Y-m-d', $invoiceDate);
if (!$d || $d->format('Y-m-d') !== $invoiceDate) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Ngày hoá đơn không hợp lệ']);
}

$custStmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND is_active = 1");
$custStmt->execute([$customerId]);
if (!$custStmt->fetchColumn()) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Khách hàng không tồn tại hoặc đã ngưng hoạt động']);
}

if ($action === 'edit') {
    $invoiceId   = (int)($_POST['invoice_id'] ?? 0);
    $totalAmount = (float)($_POST['total_amount'] ?? 0);

    if (!$invoiceId) {
        manualInvoiceJsonExit(['ok' => false, 'msg' => 'Thiếu invoice_id']);
    }
    if ($totalAmount <= 0) {
        manualInvoiceJsonExit(['ok' => false, 'msg' => 'Tổng tiền thanh toán phải lớn hơn 0']);
    }
    if ($totalAmount < $subtotal) {
        manualInvoiceJsonExit(['ok' => false, 'msg' => 'Tổng tiền thanh toán không được nhỏ hơn tiền chưa VAT']);
    }

    try {
        $pdo->beginTransaction();

        $invoiceStmt = $pdo->prepare("
            SELECT id, is_manual, is_locked
            FROM invoices
            WHERE id = ?
            FOR UPDATE
        ");
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            $pdo->rollBack();
            manualInvoiceJsonExit(['ok' => false, 'msg' => 'Không tìm thấy hoá đơn']);
        }
        if (empty($invoice['is_manual'])) {
            $pdo->rollBack();
            manualInvoiceJsonExit(['ok' => false, 'msg' => 'Chỉ được sửa hoá đơn tạo thủ công']);
        }
        if (empty($invoice['is_locked'])) {
            $pdo->rollBack();
            manualInvoiceJsonExit(['ok' => false, 'msg' => 'Chỉ được sửa hoá đơn thủ công đã khoá']);
        }

        $dupStmt = $pdo->prepare("
            SELECT id
            FROM invoices
            WHERE bkav_invoice_no = ?
              AND id <> ?
            LIMIT 1
            FOR UPDATE
        ");
        $dupStmt->execute([$bkavInvoiceNo, $invoiceId]);
        if ($dupStmt->fetchColumn()) {
            $pdo->rollBack();
            manualInvoiceJsonExit(['ok' => false, 'msg' => 'Số hoá đơn này đã tồn tại']);
        }

        $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?");
        $paidStmt->execute([$invoiceId]);
        $paidAmount = (float)$paidStmt->fetchColumn();
        if ($totalAmount < $paidAmount) {
            $pdo->rollBack();
            manualInvoiceJsonExit(['ok' => false, 'msg' => 'Tổng tiền mới không được nhỏ hơn số tiền đã thu']);
        }

        $vatAmount = round($totalAmount - $subtotal);
        $hasUpdatedAt = invoiceColumnExists($pdo, 'updated_at');

        $sql = "
            UPDATE invoices
            SET bkav_invoice_no = ?,
                invoice_date = ?,
                customer_id = ?,
                subtotal = ?,
                vat_rate = ?,
                vat_amount = ?,
                total_amount = ?,
                note = ?,
                locked_bkav_no = ?,
                locked_bkav_date = ?";
        if ($hasUpdatedAt) {
            $sql .= ",
                updated_at = NOW()";
        }
        $sql .= "
            WHERE id = ?";

        $pdo->prepare($sql)->execute([
            $bkavInvoiceNo,
            $invoiceDate,
            $customerId,
            $subtotal,
            $vatRate,
            $vatAmount,
            $totalAmount,
            $note,
            $bkavInvoiceNo,
            $invoiceDate,
            $invoiceId,
        ]);

        $pdo->commit();
        manualInvoiceJsonExit(['ok' => true, 'msg' => 'Đã cập nhật hoá đơn thủ công']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($e->getMessage());
        manualInvoiceJsonExit(['ok' => false, 'msg' => 'Lỗi hệ thống']);
    }
}

$paymentStatus = trim($_POST['payment_status'] ?? 'unpaid');
$paidAmount    = (float)($_POST['paid_amount'] ?? 0);

if (!in_array($paymentStatus, ['unpaid', 'partial', 'paid'], true)) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Trạng thái thanh toán không hợp lệ']);
}

$vatAmount   = round($subtotal * $vatRate / 100);
$totalAmount = $subtotal + $vatAmount;

if ($paymentStatus === 'partial' && ($paidAmount <= 0 || $paidAmount >= $totalAmount)) {
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Số tiền đã thu phải lớn hơn 0 và nhỏ hơn tổng tiền']);
}
if ($paymentStatus === 'paid') {
    $paidAmount = $totalAmount;
}
if ($paymentStatus === 'unpaid') {
    $paidAmount = 0;
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

    $dupStmt = $pdo->prepare("SELECT id FROM invoices WHERE bkav_invoice_no = ? LIMIT 1 FOR UPDATE");
    $dupStmt->execute([$bkavInvoiceNo]);
    if ($dupStmt->fetchColumn()) {
        throw new RuntimeException('MANUAL_INVOICE_DUPLICATE');
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
             note, status, is_manual, created_by, bkav_invoice_no, bkav_status, bkav_issued_at,
             is_locked, locked_bkav_no, locked_bkav_date, locked_at, locked_by)
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, NOW(),
            1, ?, ?, NOW(), ?
        )
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
        1,
        $user['id'],
        $bkavInvoiceNo,
        'issued',
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

    $pdo->commit();
    $releaseStmt->execute([$lockName]);
    manualInvoiceJsonExit([
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
    if ($e instanceof RuntimeException && $e->getMessage() === 'MANUAL_INVOICE_DUPLICATE') {
        manualInvoiceJsonExit(['ok' => false, 'msg' => 'Số hoá đơn này đã tồn tại']);
    }
    error_log($e->getMessage());
    manualInvoiceJsonExit(['ok' => false, 'msg' => 'Lỗi hệ thống']);
}
