<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once __DIR__ . '/common.php';
requireLogin();

$user = currentUser();
$pdo = getDBConnection();

$slipsStmt = $pdo->prepare("
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
$s = $selectedSlip; // shorthand

// ── Trợ cấp ──────────────────────────────────────────────────────────
$allowanceItems = [
    'Ăn uống'           => (float)($s['meal_received'] ?? 0),
    'May mặc'           => (float)($s['clothes_received'] ?? 0),
    'Điện thoại'        => (float)($s['phone_received'] ?? 0),
    'Xăng xe'           => (float)($s['transport_received'] ?? 0),
    'Nhà ở'             => (float)($s['housing_received'] ?? 0),
    'PC Trách nhiệm'    => (float)($s['responsibility_allowance_received'] ?? 0),
    'PC Thâm niên'      => (float)($s['seniority_allowance_received'] ?? 0),
    'Thưởng hiệu suất'  => (float)($s['performance_bonus'] ?? 0),
    'Thưởng chuyên cần' => (float)($s['attendance_bonus'] ?? 0),
];
$allowanceTotal = array_sum($allowanceItems);

// ── OT ───────────────────────────────────────────────────────────────
$otTotal = (float)($s['total_ot_amount'] ?? 0);

// ── Khoản cộng thêm ──────────────────────────────────────────────────
$nightShiftBonus = (float)($s['night_shift_bonus'] ?? 0);
$kpiBonus        = (float)($s['kpi_bonus'] ?? 0);
$otherIncome     = (float)($s['other_income'] ?? 0);

// ── Khoản trừ ────────────────────────────────────────────────────────
$siEmployee    = (float)($s['si_employee'] ?? 0);
$pitAmount     = (float)($s['pit_amount'] ?? 0);
$otherDeduct   = (float)($s['other_deductions'] ?? 0);
$lateDeduction = (float)($s['late_early_deduction'] ?? $s['late_deduction'] ?? 0);
$kpiDeduction  = (float)($s['kpi_deduction'] ?? 0);
$cashAdvance   = (float)($s['cash_advance'] ?? 0);

$deductionTotal = $siEmployee + $pitAmount + $otherDeduct + $lateDeduction + $kpiDeduction;
?>

<!-- I. Thông tin nhân viên -->
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">I. Thông tin nhân viên</div>
        <div class="list-compact">
            <div class="d-flex justify-content-between"><span class="label-muted">Họ tên</span><span class="fw-semibold"><?= e($s['full_name'] ?? '') ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Mã NV</span><span><?= e($s['employee_code'] ?? '') ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Bộ phận</span><span><?= e($s['department_name'] ?? '') ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Kỳ lương</span><span><?= e(formatDate($s['period_from'])) ?> – <?= e(formatDate($s['period_to'])) ?></span></div>
        </div>
    </div>
</div>

<!-- II. Công & Lương cơ bản -->
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">II. Công &amp; Lương cơ bản</div>
        <div class="list-compact">
            <?php if (isset($s['actual_workdays']) || isset($s['working_days_standard'])): ?>
            <div class="d-flex justify-content-between">
                <span class="label-muted">Ngày công thực tế / Chuẩn</span>
                <span><?= number_format((float)($s['actual_workdays'] ?? 0), 1) ?> / <?= (int)($s['working_days_standard'] ?? 0) ?></span>
            </div>
            <?php endif; ?>
            <?php if ((float)($s['basic_salary'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Lương cơ bản (gross)</span><span><?= e(formatCurrency($s['basic_salary'])) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Lương cơ bản thực nhận</span><span class="fw-bold"><?= e(formatCurrency($s['basic_salary_received'] ?? 0)) ?></span></div>
        </div>
    </div>
</div>

<!-- III. Trợ cấp & Thưởng -->
<?php if ($allowanceTotal > 0 || $nightShiftBonus > 0 || $kpiBonus > 0 || $otherIncome > 0): ?>
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">III. Trợ cấp &amp; Thưởng</div>
        <div class="list-compact">
            <?php foreach ($allowanceItems as $aLabel => $aVal): ?>
            <?php if ($aVal > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted"><?= e($aLabel) ?></span><span><?= e(formatCurrency($aVal)) ?></span></div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($nightShiftBonus > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">🌙 Phụ trội làm đêm</span><span><?= e(formatCurrency($nightShiftBonus)) ?></span></div>
            <?php endif; ?>
            <?php if ($kpiBonus > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">🎯 Thưởng KPI</span><span><?= e(formatCurrency($kpiBonus)) ?></span></div>
            <?php endif; ?>
            <?php if ($otherIncome > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Thu nhập khác</span><span><?= e(formatCurrency($otherIncome)) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between border-top pt-2 mt-1"><span class="fw-bold">Tổng trợ cấp</span><span class="fw-bold text-primary"><?= e(formatCurrency($allowanceTotal + $nightShiftBonus + $kpiBonus + $otherIncome)) ?></span></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- IV. OT -->
<?php if ($otTotal > 0): ?>
<div class="card mobile-card mb-3">
    <div class="card-body p-4">
        <div class="fw-bold mb-2">IV. Làm thêm giờ (OT)</div>
        <div class="list-compact">
            <?php if ((float)($s['ot_weekday_amount'] ?? $s['ot_weekday'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">OT ngày thường</span><span><?= e(formatCurrency($s['ot_weekday_amount'] ?? $s['ot_weekday'] ?? 0)) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($s['ot_weekend_amount'] ?? $s['ot_weekend'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">OT cuối tuần</span><span><?= e(formatCurrency($s['ot_weekend_amount'] ?? $s['ot_weekend'] ?? 0)) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($s['ot_holiday_amount'] ?? $s['ot_holiday'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">OT ngày lễ</span><span><?= e(formatCurrency($s['ot_holiday_amount'] ?? $s['ot_holiday'] ?? 0)) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($s['ot_night_weekday_amount'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">🌙 OT đêm thường</span><span><?= e(formatCurrency($s['ot_night_weekday_amount'])) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($s['ot_night_weekend_amount'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">🌙 OT đêm cuối tuần</span><span><?= e(formatCurrency($s['ot_night_weekend_amount'])) ?></span></div>
            <?php endif; ?>
            <?php if ((float)($s['ot_night_holiday_amount'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">🌙 OT đêm ngày lễ</span><span><?= e(formatCurrency($s['ot_night_holiday_amount'])) ?></span></div>
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
            <div class="d-flex justify-content-between"><span class="label-muted">BHXH nhân viên</span><span><?= e(formatCurrency($siEmployee)) ?></span></div>
            <div class="d-flex justify-content-between"><span class="label-muted">Thuế TNCN</span><span><?= e(formatCurrency($pitAmount)) ?></span></div>
            <?php if ($lateDeduction > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted text-danger">⚠️ Trừ đi muộn/về sớm</span><span class="text-danger">-<?= e(formatCurrency($lateDeduction)) ?></span></div>
            <?php endif; ?>
            <?php if ($kpiDeduction > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted text-danger">⚠️ Trừ KPI</span><span class="text-danger">-<?= e(formatCurrency($kpiDeduction)) ?></span></div>
            <?php endif; ?>
            <?php if ($otherDeduct > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Khác</span><span><?= e(formatCurrency($otherDeduct)) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between border-top pt-2 mt-1"><span class="fw-bold">Tổng khấu trừ</span><span class="fw-bold text-danger">-<?= e(formatCurrency($deductionTotal)) ?></span></div>
        </div>
    </div>
</div>

<!-- VI. Thực nhận -->
<div class="card mobile-card mb-3 border-success">
    <div class="card-body p-4 bg-success-subtle">
        <div class="fw-bold mb-2">VI. Thực nhận</div>
        <div class="list-compact">
            <?php if ((float)($s['gross_salary'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Gross salary</span><span><?= e(formatCurrency($s['gross_salary'])) ?></span></div>
            <?php endif; ?>
            <div class="summary-item border border-success-subtle bg-white rounded mb-2">
                <div class="label-muted small">NET (thực nhận)</div>
                <div class="payslip-amount"><?= e(formatCurrency($s['net_salary'] ?? 0)) ?></div>
            </div>
            <?php if ($cashAdvance > 0): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Ứng trước</span><span class="text-danger">-<?= e(formatCurrency($cashAdvance)) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between border-top pt-2 mt-1">
                <span class="fw-bold">Nhận chuyển khoản</span>
                <span class="fw-bold text-success"><?= e(formatCurrency($s['bank_transfer'] ?? $s['net_salary'] ?? 0)) ?></span>
            </div>
            <?php if (!empty($s['bank_name']) || !empty($s['bank_account'])): ?>
            <div class="d-flex justify-content-between"><span class="label-muted">Ngân hàng</span><span><?= e(trim(($s['bank_name'] ?? '') . ' ' . ($s['bank_account'] ?? ''))) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($s['remark'])): ?>
            <div class="mt-2 p-2 bg-white rounded border small text-muted"><?= nl2br(e($s['remark'])) ?></div>
            <?php endif; ?>
        </div>

        <!-- Kiểm tra công thức -->
        <?php
        $calcNet = (float)($s['basic_salary_received'] ?? 0)
            + $allowanceTotal + $nightShiftBonus + $kpiBonus + $otherIncome
            + $otTotal
            - $deductionTotal;
        $dbNet = (float)($s['net_salary'] ?? 0);
        $diff  = abs($calcNet - $dbNet);
        ?>
        <?php if ($diff > 1): ?>
        <div class="alert alert-warning py-2 mt-2 small mb-0">
            ⚠️ Lưu ý: Tổng các khoản hiển thị (<?= e(formatCurrency($calcNet)) ?>) có thể chênh lệch do một số khoản điều chỉnh không được liệt kê chi tiết.
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="mb-3">
    <a href="/erp/modules/payroll/slip_print.php?id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-outline-secondary w-100">
        <i class="fas fa-print me-2"></i>In / Xuất PDF
    </a>
</div>
<?php endif; ?>
<?php endif; ?>

<?php mobilePageEnd(); ?>
