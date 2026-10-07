<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/modules/payroll/engine/PayrollEngine.php';
requireLoginApi();
requireRoleApi('director');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'CSRF không hợp lệ']);
    exit;
}

$requestId = (int)($_POST['request_id'] ?? 0);
$newLeaveType = $_POST['leave_type'] ?? '';
$validTypes = ['annual', 'sick', 'unpaid', 'other'];
if ($requestId <= 0 || !in_array($newLeaveType, $validTypes, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Thông tin loại phép không hợp lệ']);
    exit;
}

$pdo = getDBConnection();

try {
    $pdo->beginTransaction();

    $requestStmt = $pdo->prepare('SELECT * FROM leave_requests WHERE id = ? FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $leave = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$leave) {
        throw new RuntimeException('Không tìm thấy đơn nghỉ phép');
    }
    if ($leave['leave_type'] === $newLeaveType) {
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Loại phép không thay đổi']);
        exit;
    }

    $periods = [];
    if ($leave['status'] === 'approved') {
        // Leave dates are the source of truth for attendance and payroll calculations.
        $periodStmt = $pdo->prepare("
            SELECT id, status
            FROM payroll_periods
            WHERE period_from <= ? AND period_to >= ?
            FOR UPDATE
        ");
        $periodStmt->execute([$leave['end_date'], $leave['start_date']]);
        $periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($periods as $period) {
            if ($period['status'] === 'locked') {
                throw new RuntimeException('🔒 Kỳ lương liên quan đã lock, không thể đổi loại phép');
            }
        }
    }

    $pdo->prepare('UPDATE leave_requests SET leave_type = ? WHERE id = ?')
        ->execute([$newLeaveType, $requestId]);

    if ($leave['status'] === 'approved' && $periods) {
        $slipStmt = $pdo->prepare("
            SELECT ps.*
            FROM payroll_slips ps
            JOIN payroll_periods pp ON pp.id = ps.period_id
            WHERE ps.user_id = ?
              AND pp.period_from <= ? AND pp.period_to >= ?
              AND pp.status != 'locked'
        ");
        $slipStmt->execute([$leave['user_id'], $leave['end_date'], $leave['start_date']]);

        $hasLumpSumColumn = (bool)$pdo->query(
            "SHOW COLUMNS FROM payroll_slips WHERE Field = 'is_lump_sum'"
        )->fetch(PDO::FETCH_ASSOC);
        $engine = new PayrollEngine($pdo);
        foreach ($slipStmt->fetchAll(PDO::FETCH_ASSOC) as $slip) {
            $data = $engine->calculate((int)$slip['period_id'], (int)$leave['user_id']);
            if (!$hasLumpSumColumn) {
                unset($data['is_lump_sum']);
            }

            if ($slip['manually_adjusted']) {
                $data = PayrollEngine::calculateAdjustedFields($data, $slip);
            }
            $set = implode(' = ?, ', array_keys($data)) . ' = ?';
            $pdo->prepare("UPDATE payroll_slips SET $set WHERE id = ?")
                ->execute(array_merge(array_values($data), [$slip['id']]));
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật loại phép']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Update leave type failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
