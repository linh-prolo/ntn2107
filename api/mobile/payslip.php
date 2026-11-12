<?php
/**
 * GET /erp/api/mobile/payslip        – danh sách phiếu lương (kỳ đã duyệt/khóa) của nhân viên
 * GET /erp/api/mobile/payslip/{id}   – chi tiết 1 phiếu lương (?id=)
 * Dữ liệu giống mobile/payslip.php. Nhân viên chỉ xem được phiếu lương của chính mình.
 */
require_once __DIR__ . '/_bootstrap.php';

mobileApiInit();
mobileRequireMethod('GET');

$pdo  = getDBConnection();
$user = mobileRequireAuth($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    $stmt = $pdo->prepare("
        SELECT ps.id, ps.period_id, ps.net_salary, ps.bank_transfer,
               pp.period_month, pp.period_year, pp.period_from, pp.period_to
        FROM payroll_slips ps
        JOIN payroll_periods pp ON ps.period_id = pp.id
        WHERE ps.user_id = ?
          AND pp.status IN ('approved', 'locked')
        ORDER BY pp.period_year DESC, pp.period_month DESC
    ");
    $stmt->execute([$user['id']]);
    $items = array_map(static fn(array $r) => [
        'id'            => (int)$r['id'],
        'period_id'     => (int)$r['period_id'],
        'period_month'  => (int)$r['period_month'],
        'period_year'   => (int)$r['period_year'],
        'period_from'   => $r['period_from'],
        'period_to'     => $r['period_to'],
        'net_salary'    => (float)($r['net_salary'] ?? 0),
        'bank_transfer' => (float)($r['bank_transfer'] ?? $r['net_salary'] ?? 0),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    mobileOk(['items' => $items]);
}

$stmt = $pdo->prepare("
    SELECT ps.*,
           pp.period_month, pp.period_year, pp.period_from, pp.period_to,
           u.full_name, u.employee_code,
           d.name AS department_name,
           ep.bank_account, ep.bank_name, ep.bank_branch
    FROM payroll_slips ps
    JOIN payroll_periods pp ON ps.period_id = pp.id
    JOIN users u ON u.id = ps.user_id
    LEFT JOIN employee_profiles ep ON ep.user_id = ps.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE ps.id = ?
      AND ps.user_id = ?
      AND pp.status IN ('approved', 'locked')
    LIMIT 1
");
$stmt->execute([$id, $user['id']]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) {
    mobileError('Không tìm thấy phiếu lương.', 404);
}

$num = static fn(...$keys): float => (float)(array_reduce($keys, static fn($carry, $k) => $carry ?? ($s[$k] ?? null), null) ?? 0);
$items = static fn(array $list): array => array_values(array_filter(
    array_map(static fn($label, $amount) => ['label' => $label, 'amount' => $amount], array_keys($list), $list),
    static fn($i) => $i['amount'] > 0
));

$allowances = [
    'Ăn uống'           => $num('meal_received'),
    'May mặc'           => $num('clothes_received'),
    'Điện thoại'        => $num('phone_received'),
    'Xăng xe'           => $num('transport_received'),
    'Nhà ở'             => $num('housing_received'),
    'PC Trách nhiệm'    => $num('responsibility_allowance_received'),
    'PC Thâm niên'      => $num('seniority_allowance_received'),
    'Thưởng hiệu suất'  => $num('performance_bonus'),
    'Thưởng chuyên cần' => $num('attendance_bonus'),
];
$allowanceTotal = array_sum($allowances);
$extras = [
    'Phụ trội làm đêm' => $num('night_shift_bonus'),
    'Thưởng KPI'       => $num('kpi_bonus'),
    'Thu nhập khác'    => $num('other_income'),
];
$extraTotal = array_sum($extras);

$ot = [
    'OT ngày thường'     => $num('ot_weekday_amount', 'ot_weekday'),
    'OT cuối tuần'       => $num('ot_weekend_amount', 'ot_weekend'),
    'OT ngày lễ'         => $num('ot_holiday_amount', 'ot_holiday'),
    'OT đêm thường'      => $num('ot_night_weekday_amount'),
    'OT đêm cuối tuần'   => $num('ot_night_weekend_amount'),
    'OT đêm ngày lễ'     => $num('ot_night_holiday_amount'),
];
$otTotal = $num('total_ot_amount');

$deductions = [
    'BHXH nhân viên'       => $num('si_employee'),
    'Thuế TNCN'            => $num('pit_amount'),
    'Trừ đi muộn/về sớm'   => $num('late_early_deduction', 'late_deduction'),
    'Trừ KPI'              => $num('kpi_deduction'),
    'Khác'                 => $num('other_deductions'),
];
$deductionTotal = array_sum($deductions);

$netSalary = $num('net_salary');

mobileOk([
    'id'                    => (int)$s['id'],
    'period_id'             => (int)$s['period_id'],
    'period_month'          => (int)$s['period_month'],
    'period_year'           => (int)$s['period_year'],
    'period_from'           => $s['period_from'],
    'period_to'             => $s['period_to'],
    'full_name'             => $s['full_name'],
    'employee_code'         => $s['employee_code'],
    'department_name'       => $s['department_name'],
    'actual_workdays'       => $num('actual_workdays'),
    'working_days_standard' => (int)($s['working_days_standard'] ?? 0),
    'basic_salary'          => $num('basic_salary'),
    'basic_salary_received' => $num('basic_salary_received'),
    'allowances'            => $items(array_merge($allowances, $extras)),
    'allowance_total'       => $allowanceTotal + $extraTotal,
    'ot_items'              => $items($ot),
    'ot_total'              => $otTotal,
    'deductions'            => $items($deductions),
    'deduction_total'       => $deductionTotal,
    'gross_salary'          => $num('gross_salary'),
    'net_salary'            => $netSalary,
    'advance_payment'       => $num('cash_advance', 'advance_payment'),
    'bank_transfer'         => $num('bank_transfer', 'net_salary'),
    'bank_name'             => $s['bank_name'] ?? null,
    'bank_account'          => $s['bank_account'] ?? null,
    'bank_branch'           => $s['bank_branch'] ?? null,
    'remark'                => $s['remark'] ?? null,
]);
