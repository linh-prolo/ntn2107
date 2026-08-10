<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once __DIR__ . '/common.php';
requireLogin();

$user = currentUser();
$pdo = getDBConnection();

$slipsStmt = $pdo->prepare("
    SELECT ps.id, ps.period_id,
           ps.gross_salary, ps.actual_work_days, ps.standard_work_days,
           ps.basic_salary, ps.basic_salary_received,
           ps.meal_received, ps.clothes_received, ps.phone_received,
           ps.transport_received, ps.housing_received,
           ps.responsibility_allowance_received, ps.seniority_allowance_received,
           ps.performance_bonus, ps.attendance_bonus,
           ps.total_ot_amount, ps.ot_weekday, ps.ot_weekend, ps.ot_holiday,
           ps.si_employee, ps.pit_amount, ps.other_deductions, ps.net_salary,
           ps.cash_advance, ps.bank_transfer, ps.remark,
           pp.period_month, pp.period_year, pp.period_from, pp.period_to,
           u.full_name, u.employee_code,
           d.name AS department_name,
           ep.bank_account, ep.bank_name, ep.bank_branch
    FROM payroll_slips ps
    JOIN payroll_periods pp ON ps.period_id = pp.id
    JOIN users u ON u.id = ps.user_id
    LEFT JOIN employee_profiles ep ON ep.user_id = ps.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE ps.user_id = ?
      AND pp.status IN ('approved', 'locked')
    ORDER BY pp.period_year DESC, pp.period_month DESC
");
$slipsStmt->execute([$user['id']]);
$slips = $slipsStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedPeriodId = (int)($_GET['period_id'] ?? ($slips[0]['period_id'] ?? 0));
$selectedSlip = null;
foreach ($slips as $slip) {
    if ((int)$slip['period_id'] === $selectedPeriodId) {
        $selectedSlip = $slip;
        break;
    }
}
if (!$selectedSlip && !empty($slips)) {
    $selectedSlip = $slips[0];
    $selectedPeriodId = (int)$selectedSlip['period_id'];
}

mobilePageStart('Phiếu lương', $user);
?>

<?php if (empty($slips)): ?>
<div class="summary-item text-center text-muted">
    Chưa có phiếu lương nào được duyệt.
</div>
<?php else: ?>
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <form method="GET">
            <label class="form-label fw-semibold">Chọn kỳ lương</label>
            <select name="period_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($slips as $slip): ?>
                <option value="<?= (int)$slip['period_id'] ?>" <?= (int)$slip['period_id'] === $selectedPeriodId ? 'selected' : '' ?>>
                    Tháng <?= e((string)$slip['period_month']) ?>/<?= e((string)$slip['period_year']) ?>
                    (<?= e(formatDate($slip['period_from'])) ?> – <?= e(formatDate($slip['period_to'])) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($selectedSlip): ?>
<?php
$allowanceItems = [
    'Ăn uống'      => (float)($selectedSlip['meal_received'] ?? 0),
    'May mặc'      => (float)($selectedSlip['clothes_received'] ?? 0),
    'Điện thoại'   => (float)($selectedSlip['phone_received'] ?? 0),
    'Xăng xe'      => (float)($selectedSlip['transport_received'] ?? 0),
    'Nhà ở'        => (float)($selectedSlip['housing_received'] ?? 0),
    'Trách nhiệm'  => (float)($selectedSlip['responsibility_allowance_received'] ?? 0),
    'Thâm niên'    => (float)($selectedSlip['seniority_allowance_received'] ?? 0),
    'Thưởng hiệu suất' => (float)($selectedSlip['performance_bonus'] ?? 0),
    'Thưởng chuyên cần' => (float)($selectedSlip['attendance_bonus'] ?? 0),
];
$allowanceTotal = array_sum($allowanceItems);
$otTotal = (float)($selectedSlip['total_ot_amount'] ?? 0);
$deductionTotal = (float)($selectedSlip['si_employee'] ?? 0)
    + (float)($selectedSlip['pit_amount'] ?? 0)
    + (float)($selectedSlip['other_deductions'] ?? 0);
?>

<!-- I. Thông tin nhân viên -->
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">I. Thông tin nhân viên</div>
        <div class="list-compact">
            <div class="d-flex justify-content-between"><span class="label-muted">Họ tên</span><span class="fw-semibold"><?= e($selectedSlip['full_name'] ?? '') ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Mã NV</span><span><?= e($selectedSlip['employee_code'] ?? '') ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Bộ phận</span><span><?= e($selectedSlip['department_name'] ?? '') ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Kỳ lương</span><span><?= e(formatDate($selectedSlip['period_from'])) ?> – <?= e(formatDate($selectedSlip['period_to'])) ?></span></div>
        </div>
    </div>
</div>

<!-- II. Công & Lương cơ bản -->
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">II. Công &amp; Lương cơ bản</div>
        <div class="list-compact">
            <div class="d-flex justify-content-between"><span class="label-muted">Ngày công thực tế / Chuẩn</span><span><?= (int)($selectedSlip['actual_work_days'] ?? 0) ?> / <?= (int)($selectedSlip['standard_work_days'] ?? 0) ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Lương cơ bản (gross)</span><span><?= e(formatCurrency($selectedSlip['basic_salary'] ?? 0)) ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Lương cơ bản thực nhận</span><span class="fw-bold"><?= e(formatCurrency($selectedSlip['basic_salary_received'] ?? 0)) ?></span></div>
        </div>
    </div>
</div>

<!-- III. Trợ cấp & Thưởng -->
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">III. Trợ cấp &amp; Thưởng</div>
        <div class="list-compact">
            <?php foreach ($allowanceItems as $aLabel => $aVal): ?>
            <?php if ($aVal > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted"><?= e($aLabel) ?></span><span><?= e(formatCurrency($aVal)) ?></span></div>
            <?php endif; ?>
            <?php endforeach; ?>
            <div class="d-flex justify-content-between border-top pt-2 mt-1"><span class="fw-bold">Tổng trợ cấp</span><span class="fw-bold text-primary"><?= e(formatCurrency($allowanceTotal)) ?></span></div>
        </div>
    </div>
</div>

<!-- IV. OT -->
<?php if ($otTotal > 0): ?>
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">IV. Làm thêm giờ (OT)</div>
        <div class="list-compact">
            <?php if ((float)($selectedSlip['ot_weekday'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">OT ngày thường</span><span><?= e(formatCurrency($selectedSlip['ot_weekday'])) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($selectedSlip['ot_weekend'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">OT cuối tuần</span><span><?= e(formatCurrency($selectedSlip['ot_weekend'])) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($selectedSlip['ot_holiday'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">OT ngày lễ</span><span><?= e(formatCurrency($selectedSlip['ot_holiday'])) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between border-top pt-2 mt-1"><span class="fw-bold">Tổng OT</span><span class="fw-bold text-primary"><?= e(formatCurrency($otTotal)) ?></span></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- V. Các khoản trừ -->
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">V. Các khoản trừ</div>
        <div class="list-compact">
            <div class="d-flex justify-content-between"><span class="label-muted">BHXH nhân viên</span><span><?= e(formatCurrency($selectedSlip['si_employee'] ?? 0)) ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Thuế TNCN</span><span><?= e(formatCurrency($selectedSlip['pit_amount'] ?? 0)) ?></span></div>
            <?php if ((float)($selectedSlip['other_deductions'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Khác</span><span><?= e(formatCurrency($selectedSlip['other_deductions'])) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between border-top pt-2 mt-1"><span class="fw-bold">Tổng khấu trừ</span><span class="fw-bold text-danger"><?= e(formatCurrency($deductionTotal)) ?></span></div>
        </div>
    </div>
</div>

<!-- VI. Thực nhận -->
<div class="card mobile-card mb-3 border-success">
    <div class="card-body p-4 bg-success-subtle">
        <div class="fw-bold mb-2">VI. Thực nhận</div>
        <div class="list-compact">
            <div class="d-flex justify-content-between"><span class="label-muted">Gross salary</span><span><?= e(formatCurrency($selectedSlip['gross_salary'] ?? 0)) ?></span></div>
            <div class="summary-item border border-success-subtle">
                <div class="label-muted">NET (thực nhận)</div>
                <div class="payslip-amount"><?= e(formatCurrency($selectedSlip['net_salary'] ?? 0)) ?></div>
            </div>
            <?php if ((float)($selectedSlip['cash_advance'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Ứng trước</span><span class="text-danger"><?= e(formatCurrency($selectedSlip['cash_advance'])) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between"><span class="fw-bold">Nhận chuyển khoản</span><span class="fw-bold"><?= e(formatCurrency($selectedSlip['bank_transfer'] ?? 0)) ?></span></div>
            <?php if (!empty($selectedSlip['bank_name']) || !empty($selectedSlip['bank_account'])): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Ngân hàng</span><span><?= e(($selectedSlip['bank_name'] ?? '') . ' ' . ($selectedSlip['bank_account'] ?? '')) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($selectedSlip['remark'])): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Ghi chú</span><span><?= e($selectedSlip['remark']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mb-3">
    <a href="/erp/modules/payroll/slip_print.php?id=<?= (int)$selectedSlip['id'] ?>" target="_blank" class="btn btn-outline-secondary w-100">
        <i class="fas fa-print me-2"></i>In / Xuất PDF
    </a>
</div>
<?php endif; ?>
<?php endif; ?>

<?php mobilePageEnd(); ?>
