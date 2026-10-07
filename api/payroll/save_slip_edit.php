<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/modules/payroll/engine/PayrollEngine.php';
requireLoginApi();
requireRoleApi('director', 'accountant');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF không hợp lệ']); exit;
}

$pdo    = getDBConnection();
$user   = currentUser();
$slipId = (int)($_POST['slip_id'] ?? 0);

if (!$slipId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu slip_id']); exit;
}

// Kiểm tra slip + period status
$stmt = $pdo->prepare("
    SELECT ps.*, pp.status AS period_status
    FROM payroll_slips ps
    JOIN payroll_periods pp ON ps.period_id = pp.id
    WHERE ps.id = ?
");
$stmt->execute([$slipId]);
$slip = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$slip) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu lương']); exit;
}
if ($slip['period_status'] === 'locked') {
    echo json_encode(['ok' => false, 'msg' => '🔒 Kỳ lương đã lock!']); exit;
}

// Lấy các giá trị chỉnh sửa
$otherIncome     = (float)($_POST['other_income']      ?? $slip['other_income']);
$perfBonus       = (float)($_POST['performance_bonus'] ?? $slip['performance_bonus']);
$otherBonus      = (float)($_POST['other_bonus']       ?? $slip['other_bonus']);
$adjustment      = (float)($_POST['adjustment']        ?? $slip['adjustment']);
$responsibility  = (float)($_POST['responsibility_allowance_received'] ?? ($slip['responsibility_allowance_received'] ?? 0));
$seniority       = (float)($_POST['seniority_allowance_received']      ?? ($slip['seniority_allowance_received'] ?? 0));
$advancePayment  = (float)($_POST['advance_payment']   ?? $slip['advance_payment']);
$pitAdjustment   = (float)($_POST['pit_adjustment']    ?? $slip['pit_adjustment']);
$remark          = trim($_POST['remark']               ?? $slip['remark']);

// Tính lại gross & net
$totals = PayrollEngine::calculateSlipTotals(array_replace($slip, [
    'other_income' => $otherIncome,
    'performance_bonus' => $perfBonus,
    'other_bonus' => $otherBonus,
    'adjustment' => $adjustment,
    'responsibility_allowance_received' => $responsibility,
    'seniority_allowance_received' => $seniority,
    'advance_payment' => $advancePayment,
    'pit_adjustment' => $pitAdjustment,
]));

try {
    $pdo->prepare("
        UPDATE payroll_slips SET
            other_income       = ?,
            performance_bonus  = ?,
            other_bonus        = ?,
            adjustment         = ?,
            responsibility_allowance_received = ?,
            seniority_allowance_received      = ?,
            advance_payment    = ?,
            pit_adjustment     = ?,
            remark             = ?,
            gross_salary       = ?,
            net_salary         = ?,
            bank_transfer      = ?,
            manually_adjusted  = 1,
            updated_at         = NOW()
        WHERE id = ?
    ")->execute([
        $otherIncome, $perfBonus, $otherBonus,
        $adjustment, $responsibility, $seniority,
        $advancePayment, $pitAdjustment,
        $remark,
        $totals['gross_salary'], $totals['net_salary'], $totals['bank_transfer'],
        $slipId
    ]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}