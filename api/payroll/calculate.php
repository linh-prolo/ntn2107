<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/modules/payroll/engine/PayrollEngine.php';
header('Content-Type: application/json');
requireRoleApi('director', 'accountant');

$pdo   = getDBConnection();
$user  = currentUser();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$periodId = (int)($input['period_id'] ?? 0);
if (!$periodId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu period_id']); exit;
}

$stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$stmt->execute([$periodId]);
$period = $stmt->fetch();

if (!$period) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy kỳ lương']); exit;
}
if ($period['status'] === 'locked') {
    echo json_encode(['ok' => false, 'msg' => '🔒 Kỳ lương đã lock, không thể tính lại']); exit;
}

// Lấy nhân viên active HOẶC nhân viên có ngày nghỉ việc nằm trong kỳ lương này
// (để vẫn tính lương + khấu trừ thuế TNCN cho tháng họ nghỉ việc, dù is_active = 0)
$stmtUsers = $pdo->prepare("
    SELECT DISTINCT u.id, u.full_name
    FROM users u
    LEFT JOIN employee_profiles ep ON ep.user_id = u.id
    WHERE u.is_active = 1
       OR (ep.resignation_date IS NOT NULL
           AND ep.resignation_date BETWEEN :period_from AND :period_to)
    ORDER BY u.full_name
");
$stmtUsers->execute([
    ':period_from' => $period['period_from'],
    ':period_to'   => $period['period_to'],
]);
$users = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

$engine  = new PayrollEngine($pdo);
$success = 0;
$errors  = [];

foreach ($users as $uid) {
    try {
        $data = $engine->calculate($periodId, (int)$uid);

        // Kiểm tra slip đã tồn tại chưa
        $chk = $pdo->prepare("SELECT id, manually_adjusted FROM payroll_slips WHERE period_id = ? AND user_id = ?");
        $chk->execute([$periodId, $uid]);
        $slip = $chk->fetch();

        if ($slip) {
            if ($slip['manually_adjusted']) {
                // Giữ lại phần KT nhập tay
                $keepFields = [
                    'other_income', 'adjustment', 'other_bonus',
                    'advance_payment', 'remark', 'performance_bonus',
                    'annual_leave_payout', 'pit_adjustment',
                ];
                $autoFields = array_diff_key($data, array_flip($keepFields));
                $set = implode('=?, ', array_keys($autoFields)) . '=?';
                $pdo->prepare("UPDATE payroll_slips SET $set WHERE id = ?")
                    ->execute(array_merge(array_values($autoFields), [$slip['id']]));
            } else {
                $set = implode('=?, ', array_keys($data)) . '=?';
                $pdo->prepare("UPDATE payroll_slips SET $set WHERE period_id = ? AND user_id = ?")
                    ->execute(array_merge(array_values($data), [$periodId, $uid]));
            }
        } else {
            $cols = implode(', ', array_keys($data));
            $vals = implode(', ', array_fill(0, count($data), '?'));
            $pdo->prepare("INSERT INTO payroll_slips ($cols) VALUES ($vals)")
                ->execute(array_values($data));
        }
        $success++;
    } catch (Throwable $e) {
        $errors[] = "User #$uid: " . $e->getMessage();
        error_log("Payroll calculate error user #$uid: " . $e->getMessage());
    }
}

echo json_encode([
    'ok'      => true,
    'success' => $success,
    'errors'  => $errors,
    'msg'     => "✅ Đã tính lương cho $success nhân viên"
                . (count($errors) ? ', có ' . count($errors) . ' lỗi' : ''),
]);