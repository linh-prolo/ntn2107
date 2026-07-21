<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'config/functions.php';
requireLogin();

$user = currentUser();
$pdo = getDBConnection();
$today = date('Y-m-d');
$currentMonth = (int)date('m');
$currentYear  = (int)date('Y');

// ── Chấm công hôm nay (tất cả role) ─────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND work_date = ?");
$stmt->execute([$user['id'], $today]);
$todayAttendance = $stmt->fetch();

// ── Phiếu lương mới nhất của tôi ─────────────────────────────────────────
$myLatestSlip = null;
$stmtSlip = $pdo->prepare("
    SELECT ps.*, pp.period_month, pp.period_year, pp.status AS period_status
    FROM payroll_slips ps
    JOIN payroll_periods pp ON ps.period_id = pp.id
    WHERE ps.user_id = ? AND pp.status IN ('approved','locked')
    ORDER BY pp.period_year DESC, pp.period_month DESC
    LIMIT 1
");
$stmtSlip->execute([$user['id']]);
$myLatestSlip = $stmtSlip->fetch();

// ── Kỳ lương mới nhất ────────────────────────────────────────────────────
$latestPeriod = $pdo->query("
    SELECT * FROM payroll_periods
    ORDER BY period_year DESC, period_month DESC
    LIMIT 1
")->fetch();

// ── Số kỳ chờ duyệt (GĐ) ─────────────────────────────────────────────────
$pendingPayrolls = 0;
if (hasRole('director')) {
    $pendingPayrolls = (int)$pdo->query("
        SELECT COUNT(*) FROM payroll_periods WHERE status = 'submitted'
    ")->fetchColumn();
}

// ── KPI theo role ─────────────────────────────────────────────────────────
$kpi = [];

// --- Director + Accountant: Tài chính ---
if (hasRole('director', 'accountant')) {
    try {
        $stmtRev = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount),0) FROM invoices
            WHERE MONTH(invoice_date)=? AND YEAR(invoice_date)=?
              AND status NOT IN ('cancelled','draft')
        ");
        $stmtRev->execute([$currentMonth, $currentYear]);
        $kpi['revenue'] = (float)$stmtRev->fetchColumn();
    } catch (Exception $e) { $kpi['revenue'] = 0; }

    try {
        $kpi['unpaid_invoices'] = (int)$pdo->query("
            SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['unpaid_invoices'] = 0; }

    try {
        $kpi['receivable'] = (float)$pdo->query("
            SELECT COALESCE(SUM(i.total_amount),0) - COALESCE(SUM(p.paid),0)
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid FROM payments GROUP BY invoice_id
            ) p ON p.invoice_id = i.id
            WHERE i.status IN ('unpaid','partial')
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['receivable'] = 0; }
}

// --- Director: Lợi nhuận tạm tính ---
if (hasRole('director')) {
    try {
        $stmtSalary = $pdo->prepare("
            SELECT COALESCE(SUM(ps.gross_salary + COALESCE(ps.si_company,0)),0)
            FROM payroll_slips ps
            JOIN payroll_periods pp ON pp.id = ps.period_id
            WHERE pp.period_month=? AND pp.period_year=?
              AND pp.status IN ('approved','locked')
        ");
        $stmtSalary->execute([$currentMonth, $currentYear]);
        $kpi['salary_cost'] = (float)$stmtSalary->fetchColumn();
    } catch (Exception $e) { $kpi['salary_cost'] = 0; }

    try {
        $stmtExpAmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) FROM expense_requests
            WHERE status='approved'
              AND MONTH(expense_date)=? AND YEAR(expense_date)=?
        ");
        $stmtExpAmt->execute([$currentMonth, $currentYear]);
        $kpi['admin_cost'] = (float)$stmtExpAmt->fetchColumn();
    } catch (Exception $e) { $kpi['admin_cost'] = 0; }

    $kpi['profit'] = ($kpi['revenue'] ?? 0) - ($kpi['salary_cost'] ?? 0) - ($kpi['admin_cost'] ?? 0);
}

// --- Accountant: Chi phí HC + đề xuất chờ duyệt ---
if (hasRole('accountant')) {
    try {
        $stmtExpAmt2 = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) FROM expense_requests
            WHERE status='approved'
              AND MONTH(expense_date)=? AND YEAR(expense_date)=?
        ");
        $stmtExpAmt2->execute([$currentMonth, $currentYear]);
        $kpi['admin_cost'] = (float)$stmtExpAmt2->fetchColumn();
    } catch (Exception $e) { $kpi['admin_cost'] = 0; }

    try {
        $kpi['pending_expenses'] = (int)$pdo->query("
            SELECT COUNT(*) FROM expense_requests WHERE status='submitted'
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['pending_expenses'] = 0; }
}

// --- Director + Manager: Sản xuất ---
if (hasRole('director', 'manager')) {
    try {
        $kpi['orders_inprogress'] = (int)$pdo->query("
            SELECT COUNT(*) FROM production_orders WHERE status='in_progress'
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['orders_inprogress'] = 0; }

    try {
        $stmtDone = $pdo->prepare("
            SELECT COUNT(*) FROM production_orders
            WHERE status='done' AND MONTH(updated_at)=? AND YEAR(updated_at)=?
        ");
        $stmtDone->execute([$currentMonth, $currentYear]);
        $kpi['orders_done'] = (int)$stmtDone->fetchColumn();
    } catch (Exception $e) { $kpi['orders_done'] = 0; }
}

// --- Manager: Tỷ lệ lỗi + IQC ---
if (hasRole('manager')) {
    try {
        $stmtErr = $pdo->prepare("
            SELECT COALESCE(SUM(pi.qty_error),0), COALESCE(SUM(pi.qty_total),0)
            FROM production_items pi
            JOIN production_orders po ON po.id = pi.order_id
            WHERE MONTH(po.created_at)=? AND YEAR(po.created_at)=?
        ");
        $stmtErr->execute([$currentMonth, $currentYear]);
        $row = $stmtErr->fetch(\PDO::FETCH_NUM);
        [$qtyError, $qtyTotal] = $row ?: [0, 0];
        $kpi['error_rate'] = ($qtyTotal > 0) ? round($qtyError / $qtyTotal * 100, 1) : 0;
        $kpi['error_qty']  = (int)$qtyError;
        $kpi['total_qty']  = (int)$qtyTotal;
    } catch (Exception $e) { $kpi['error_rate'] = 0; $kpi['error_qty'] = 0; $kpi['total_qty'] = 0; }

    try {
        $kpi['iqc_open'] = (int)$pdo->query("
            SELECT COUNT(*) FROM iqc_receipts WHERE status='open'
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['iqc_open'] = 0; }
}

// --- Director + Manager: Nhân sự ---
if (hasRole('director', 'manager')) {
    try {
        $kpi['total_employees'] = (int)$pdo->query("
            SELECT COUNT(*) FROM users WHERE is_active=1
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['total_employees'] = 0; }

    try {
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE work_date=?");
        $stmtCheck->execute([$today]);
        $kpi['checked_today'] = (int)$stmtCheck->fetchColumn();
    } catch (Exception $e) { $kpi['checked_today'] = 0; }

    $kpi['absent_today'] = max(0, ($kpi['total_employees'] ?? 0) - ($kpi['checked_today'] ?? 0));

    try {
        $stmtLate = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE work_date=? AND is_late=1");
        $stmtLate->execute([$today]);
        $kpi['late_today'] = (int)$stmtLate->fetchColumn();
    } catch (Exception $e) { $kpi['late_today'] = 0; }
}

// --- Director + Manager: Nghỉ phép + OT chờ duyệt ---
if (hasRole('director', 'manager')) {
    try {
        $kpi['pending_leaves'] = (int)$pdo->query("
            SELECT COUNT(*) FROM leave_requests WHERE status='pending'
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['pending_leaves'] = 0; }

    try {
        $kpi['pending_ot'] = (int)$pdo->query("
            SELECT COUNT(*) FROM overtime_requests WHERE status='pending'
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['pending_ot'] = 0; }
}

// --- Director + Accountant: Kho ---
if (hasRole('director', 'accountant')) {
    try {
        $rowStock = $pdo->query("
            SELECT COUNT(DISTINCT wi.id) AS total_items,
                   COALESCE(SUM(CASE WHEN COALESCE(t.remaining,0) > 0 THEN 1 ELSE 0 END),0) AS items_with_stock
            FROM wa_items wi
            LEFT JOIN (
                SELECT item_id,
                       SUM(CASE WHEN type='import' THEN qty ELSE -qty END) AS remaining
                FROM wa_transactions GROUP BY item_id
            ) t ON t.item_id = wi.id
            WHERE wi.is_active=1
        ")->fetch();
        $kpi['total_items']      = (int)($rowStock['total_items'] ?? 0);
        $kpi['items_with_stock'] = (int)($rowStock['items_with_stock'] ?? 0);
    } catch (Exception $e) { $kpi['total_items'] = 0; $kpi['items_with_stock'] = 0; }

    try {
        $kpi['out_of_stock'] = (int)$pdo->query("
            SELECT COUNT(*) FROM wa_items wi
            LEFT JOIN (
                SELECT item_id,
                       SUM(CASE WHEN type='import' THEN qty ELSE -qty END) AS remaining
                FROM wa_transactions GROUP BY item_id
            ) t ON t.item_id = wi.id
            WHERE wi.is_active=1 AND (t.remaining IS NULL OR t.remaining <= 0)
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['out_of_stock'] = 0; }
}

// --- Accountant: Xe + Tài sản ---
if (hasRole('accountant')) {
    try {
        $kpi['vehicles_expiring'] = (int)$pdo->query("
            SELECT COUNT(DISTINCT vehicle_id) FROM vehicle_documents
            WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['vehicles_expiring'] = 0; }

    try {
        $kpi['assets_maintenance'] = (int)$pdo->query("
            SELECT COUNT(*) FROM company_assets WHERE status='maintenance'
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['assets_maintenance'] = 0; }
}

// --- Director: Xe hết hạn (cảnh báo) ---
if (hasRole('director')) {
    try {
        $kpi['vehicles_expiring'] = (int)$pdo->query("
            SELECT COUNT(DISTINCT vehicle_id) FROM vehicle_documents
            WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ")->fetchColumn();
    } catch (Exception $e) { $kpi['vehicles_expiring'] = 0; }
}

// --- Employee / Production: Ngày công + đơn của tôi ---
if (hasRole('employee', 'production')) {
    try {
        $stmtWd = $pdo->prepare("
            SELECT COUNT(*) FROM attendance_logs
            WHERE user_id=? AND MONTH(work_date)=? AND YEAR(work_date)=? AND check_in IS NOT NULL
        ");
        $stmtWd->execute([$user['id'], $currentMonth, $currentYear]);
        $kpi['my_working_days'] = (int)$stmtWd->fetchColumn();
    } catch (Exception $e) { $kpi['my_working_days'] = 0; }

    try {
        $stmtMyL = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id=? AND status='pending'");
        $stmtMyL->execute([$user['id']]);
        $kpi['my_pending_leaves'] = (int)$stmtMyL->fetchColumn();
    } catch (Exception $e) { $kpi['my_pending_leaves'] = 0; }

    try {
        $stmtMyO = $pdo->prepare("SELECT COUNT(*) FROM overtime_requests WHERE user_id=? AND status='pending'");
        $stmtMyO->execute([$user['id']]);
        $kpi['my_pending_ot'] = (int)$stmtMyO->fetchColumn();
    } catch (Exception $e) { $kpi['my_pending_ot'] = 0; }
}

// ── Helpers ───────────────────────────────────────────────────────────────
$statusPayrollMap = [
    'draft'     => ['secondary', '📝 Nháp'],
    'submitted' => ['warning',   '📤 Chờ duyệt'],
    'approved'  => ['success',   '✅ Đã duyệt'],
    'locked'    => ['dark',      '🔒 Đã lock'],
];

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- ── Header ── -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <?php $badge = getRoleBadge($user['role']); ?>
            <h4 class="mb-1">Xin chào, <strong><?= htmlspecialchars($user['full_name']) ?></strong> 👋</h4>
            <p class="text-muted mb-0">
                <span class="badge bg-<?= $badge['class'] ?>"><?= $badge['icon'] ?> <?= $badge['label'] ?></span>
                &nbsp; <i class="far fa-calendar me-1"></i><?= date('l, d/m/Y') ?>
                &nbsp; <i class="far fa-clock me-1"></i><span id="live-clock" aria-label="Giờ hiện tại"><?= date('H:i') ?></span>
            </p>
        </div>
    </div>

    <?php showFlash(); ?>

    <?php /* ════════════════════════════════════════════════════════════
           DIRECTOR
           ════════════════════════════════════════════════════════════ */ ?>
    <?php if (hasRole('director')): ?>

    <!-- KPI hàng 1: Tài chính -->
    <h6 class="text-uppercase text-muted fw-bold mb-2 small">💹 Tài chính tháng <?= $currentMonth ?>/<?= $currentYear ?></h6>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon">💵</div>
                <div class="kpi-label">Doanh thu tháng</div>
                <div class="kpi-value"><?= formatCurrency($kpi['revenue'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon">📄</div>
                <div class="kpi-label">Hóa đơn chưa TT</div>
                <div class="kpi-value"><?= number_format($kpi['unpaid_invoices'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon">💳</div>
                <div class="kpi-label">Công nợ phải thu</div>
                <div class="kpi-value"><?= formatCurrency($kpi['receivable'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <?php $profit = $kpi['profit'] ?? 0; ?>
            <div class="kpi-card <?= $profit >= 0 ? 'kpi-success' : 'kpi-danger' ?>">
                <div class="kpi-icon"><?= $profit >= 0 ? '📈' : '📉' ?></div>
                <div class="kpi-label">Lợi nhuận tạm tính</div>
                <div class="kpi-value"><?= formatCurrency($profit) ?></div>
                <div class="kpi-sub">DT - Lương - Chi phí HC</div>
            </div>
        </div>
    </div>

    <!-- KPI hàng 2: Sản xuất + Nhân sự -->
    <h6 class="text-uppercase text-muted fw-bold mb-2 small">🏭 Sản xuất &amp; Nhân sự</h6>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon">⚙️</div>
                <div class="kpi-label">Đơn đang gia công</div>
                <div class="kpi-value"><?= number_format($kpi['orders_inprogress'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">✅</div>
                <div class="kpi-label">Đơn hoàn thành tháng</div>
                <div class="kpi-value"><?= number_format($kpi['orders_done'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/attendance/all_attendance.php" class="text-decoration-none">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon">👥</div>
                <div class="kpi-label">Tổng NV / Có mặt</div>
                <div class="kpi-value"><?= ($kpi['total_employees'] ?? 0) ?> / <?= ($kpi['checked_today'] ?? 0) ?></div>
                <div class="kpi-sub">Vắng: <?= ($kpi['absent_today'] ?? 0) ?> &nbsp;|&nbsp; Trễ: <?= ($kpi['late_today'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <?php [$periodStatusClass, $periodStatusLabel] = $statusPayrollMap[$latestPeriod['status'] ?? ''] ?? ['secondary', '—']; ?>
            <a href="/erp/modules/payroll/index.php" class="text-decoration-none">
            <div class="kpi-card kpi-light <?= $pendingPayrolls > 0 ? 'kpi-border-warning' : '' ?>">
                <div class="kpi-icon">💰</div>
                <div class="kpi-label">Kỳ lương hiện tại</div>
                <div class="kpi-value fs-6"><?= $latestPeriod ? ('Tháng '.$latestPeriod['period_month'].'/'.$latestPeriod['period_year']) : '—' ?></div>
                <div class="kpi-sub"><span class="badge bg-<?= $periodStatusClass ?>"><?= $periodStatusLabel ?></span><?= $pendingPayrolls > 0 ? ' <span class="badge bg-warning text-dark ms-1">'.$pendingPayrolls.' chờ duyệt</span>' : '' ?></div>
            </div>
            </a>
        </div>
    </div>

    <!-- KPI hàng 3: Đơn chờ duyệt + Kho -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="/erp/modules/attendance/leave_manage.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['pending_leaves'] ?? 0) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">📝</div>
                <div class="kpi-label">Đơn nghỉ phép chờ duyệt</div>
                <div class="kpi-value"><?= number_format($kpi['pending_leaves'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/attendance/ot_manage.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['pending_ot'] ?? 0) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">⏱️</div>
                <div class="kpi-label">Đơn OT chờ duyệt</div>
                <div class="kpi-value"><?= number_format($kpi['pending_ot'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/warehouse/items.php" class="text-decoration-none">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon">📦</div>
                <div class="kpi-label">Mặt hàng tồn kho</div>
                <div class="kpi-value"><?= number_format($kpi['total_items'] ?? 0) ?></div>
                <div class="kpi-sub">Còn hàng: <?= number_format($kpi['items_with_stock'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/warehouse/items.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['out_of_stock'] ?? 0) > 0 ? 'kpi-danger' : 'kpi-light' ?>">
                <div class="kpi-icon">⚠️</div>
                <div class="kpi-label">Mặt hàng hết hàng</div>
                <div class="kpi-value"><?= number_format($kpi['out_of_stock'] ?? 0) ?></div>
                <div class="kpi-sub">Tồn kho = 0</div>
            </div>
            </a>
        </div>
    </div>

    <!-- Quick Actions: Director -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">⚡ Chức năng nhanh</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-2"><a href="/erp/modules/reports/finance.php" class="quick-action-btn">📊<span>Báo cáo TC</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/attendance/all_attendance.php" class="quick-action-btn">📋<span>Bảng chấm công</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/payroll/index.php" class="quick-action-btn">💰<span>Quản lý lương</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/production/orders.php" class="quick-action-btn">🏭<span>Đơn hàng SX</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/warehouse/items.php" class="quick-action-btn">📦<span>Quản lý kho</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/admin/assets.php" class="quick-action-btn">🖥️<span>Tài sản</span></a></div>
            </div>
        </div>
    </div>

    <?php /* ════════════════════════════════════════════════════════════
           ACCOUNTANT
           ════════════════════════════════════════════════════════════ */ ?>
    <?php elseif (hasRole('accountant')): ?>

    <!-- KPI hàng 1: Tài chính -->
    <h6 class="text-uppercase text-muted fw-bold mb-2 small">💹 Tài chính tháng <?= $currentMonth ?>/<?= $currentYear ?></h6>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon">💵</div>
                <div class="kpi-label">Doanh thu tháng</div>
                <div class="kpi-value"><?= formatCurrency($kpi['revenue'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon">📄</div>
                <div class="kpi-label">Hóa đơn chưa TT</div>
                <div class="kpi-value"><?= number_format($kpi['unpaid_invoices'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon">💳</div>
                <div class="kpi-label">Công nợ phải thu</div>
                <div class="kpi-value"><?= formatCurrency($kpi['receivable'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon">🧾</div>
                <div class="kpi-label">Chi phí HC tháng</div>
                <div class="kpi-value"><?= formatCurrency($kpi['admin_cost'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <!-- KPI hàng 2: Chi phí + Lương + Kho + Xe + Tài sản -->
    <h6 class="text-uppercase text-muted fw-bold mb-2 small">📋 Quản lý &amp; Theo dõi</h6>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="/erp/modules/admin/expenses.php?status=submitted" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['pending_expenses'] ?? 0) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">📤</div>
                <div class="kpi-label">Đề xuất chi phí chờ duyệt</div>
                <div class="kpi-value"><?= number_format($kpi['pending_expenses'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/payroll/index.php" class="text-decoration-none">
            <?php [$periodStatusClass, $periodStatusLabel] = $statusPayrollMap[$latestPeriod['status'] ?? ''] ?? ['secondary', '—']; ?>
            <div class="kpi-card kpi-light">
                <div class="kpi-icon">💰</div>
                <div class="kpi-label">Kỳ lương hiện tại</div>
                <div class="kpi-value fs-6"><?= $latestPeriod ? ('Tháng '.$latestPeriod['period_month'].'/'.$latestPeriod['period_year']) : '—' ?></div>
                <div class="kpi-sub"><span class="badge bg-<?= $periodStatusClass ?>"><?= $periodStatusLabel ?></span></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/payroll/my_payroll.php" class="text-decoration-none">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">🗒️</div>
                <div class="kpi-label">Phiếu lương của tôi</div>
                <div class="kpi-value fs-6"><?= $myLatestSlip ? formatCurrency($myLatestSlip['net_salary']) : '—' ?></div>
                <div class="kpi-sub"><?= $myLatestSlip ? ('Tháng '.$myLatestSlip['period_month'].'/'.$myLatestSlip['period_year']) : 'Chưa có' ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/warehouse/items.php" class="text-decoration-none">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon">📦</div>
                <div class="kpi-label">Mặt hàng tồn kho</div>
                <div class="kpi-value"><?= number_format($kpi['total_items'] ?? 0) ?></div>
                <div class="kpi-sub">Còn hàng: <?= number_format($kpi['items_with_stock'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/warehouse/items.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['out_of_stock'] ?? 0) > 0 ? 'kpi-danger' : 'kpi-light' ?>">
                <div class="kpi-icon">⚠️</div>
                <div class="kpi-label">Mặt hàng hết hàng</div>
                <div class="kpi-value"><?= number_format($kpi['out_of_stock'] ?? 0) ?></div>
                <div class="kpi-sub">Tồn kho = 0</div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/admin/vehicles.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['vehicles_expiring'] ?? 0) > 0 ? 'kpi-danger' : 'kpi-light' ?>">
                <div class="kpi-icon">🚗</div>
                <div class="kpi-label">Xe sắp hết hạn (30 ngày)</div>
                <div class="kpi-value"><?= number_format($kpi['vehicles_expiring'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/admin/assets.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['assets_maintenance'] ?? 0) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">🔧</div>
                <div class="kpi-label">Tài sản bảo dưỡng</div>
                <div class="kpi-value"><?= number_format($kpi['assets_maintenance'] ?? 0) ?></div>
            </div>
            </a>
        </div>
    </div>

    <!-- Quick Actions: Accountant -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">⚡ Chức năng nhanh</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-2"><a href="/erp/modules/reports/finance.php" class="quick-action-btn">📊<span>Báo cáo TC</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/payroll/index.php" class="quick-action-btn">💰<span>Bảng lương</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/admin/expenses.php" class="quick-action-btn">🧾<span>Chi phí</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/admin/assets.php" class="quick-action-btn">🖥️<span>Tài sản</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/admin/vehicles.php" class="quick-action-btn">🚗<span>Phương tiện</span></a></div>
                <div class="col-6 col-md-2"><a href="/erp/modules/invoices/index.php" class="quick-action-btn">📄<span>Hóa đơn</span></a></div>
            </div>
        </div>
    </div>

    <?php /* ════════════════════════════════════════════════════════════
           MANAGER
           ════════════════════════════════════════════════════════════ */ ?>
    <?php elseif (hasRole('manager')): ?>

    <!-- KPI hàng 1: Sản xuất -->
    <h6 class="text-uppercase text-muted fw-bold mb-2 small">🏭 Sản xuất tháng <?= $currentMonth ?>/<?= $currentYear ?></h6>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-info">
                <div class="kpi-icon">⚙️</div>
                <div class="kpi-label">Đơn đang gia công</div>
                <div class="kpi-value"><?= number_format($kpi['orders_inprogress'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">✅</div>
                <div class="kpi-label">Đơn hoàn thành tháng</div>
                <div class="kpi-value"><?= number_format($kpi['orders_done'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card <?= ($kpi['error_rate'] ?? 0) > 5 ? 'kpi-danger' : 'kpi-light' ?>">
                <div class="kpi-icon">🔍</div>
                <div class="kpi-label">Tỷ lệ lỗi SX</div>
                <div class="kpi-value"><?= number_format($kpi['error_rate'] ?? 0, 1) ?>%</div>
                <div class="kpi-sub"><?= number_format($kpi['error_qty'] ?? 0) ?> / <?= number_format($kpi['total_qty'] ?? 0) ?> sp</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/production/iqc.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['iqc_open'] ?? 0) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">📋</div>
                <div class="kpi-label">IQC chưa xử lý</div>
                <div class="kpi-value"><?= number_format($kpi['iqc_open'] ?? 0) ?></div>
            </div>
            </a>
        </div>
    </div>

    <!-- KPI hàng 2: Nhân sự + Duyệt đơn + Lương -->
    <h6 class="text-uppercase text-muted fw-bold mb-2 small">👥 Nhân sự &amp; Phê duyệt</h6>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="/erp/modules/attendance/all_attendance.php" class="text-decoration-none">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon">👥</div>
                <div class="kpi-label">Tổng NV / Có mặt</div>
                <div class="kpi-value"><?= ($kpi['total_employees'] ?? 0) ?> / <?= ($kpi['checked_today'] ?? 0) ?></div>
                <div class="kpi-sub">Vắng: <?= ($kpi['absent_today'] ?? 0) ?> &nbsp;|&nbsp; Trễ: <?= ($kpi['late_today'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/attendance/leave_manage.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['pending_leaves'] ?? 0) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">📝</div>
                <div class="kpi-label">Đơn nghỉ phép chờ duyệt</div>
                <div class="kpi-value"><?= number_format($kpi['pending_leaves'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/attendance/ot_manage.php" class="text-decoration-none">
            <div class="kpi-card <?= ($kpi['pending_ot'] ?? 0) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">⏱️</div>
                <div class="kpi-label">Đơn OT chờ duyệt</div>
                <div class="kpi-value"><?= number_format($kpi['pending_ot'] ?? 0) ?></div>
            </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="/erp/modules/payroll/my_payroll.php" class="text-decoration-none">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">🗒️</div>
                <div class="kpi-label">Phiếu lương của tôi</div>
                <div class="kpi-value fs-6"><?= $myLatestSlip ? formatCurrency($myLatestSlip['net_salary']) : '—' ?></div>
                <div class="kpi-sub"><?= $myLatestSlip ? ('Tháng '.$myLatestSlip['period_month'].'/'.$myLatestSlip['period_year']) : 'Chưa có' ?></div>
            </div>
            </a>
        </div>
    </div>

    <!-- Quick Actions: Manager -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">⚡ Chức năng nhanh</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3"><a href="/erp/modules/attendance/all_attendance.php" class="quick-action-btn">📋<span>Bảng chấm công</span></a></div>
                <div class="col-6 col-md-3"><a href="/erp/modules/attendance/leave_manage.php" class="quick-action-btn">📝<span>Duyệt nghỉ phép</span></a></div>
                <div class="col-6 col-md-3"><a href="/erp/modules/attendance/ot_manage.php" class="quick-action-btn">⏱️<span>Duyệt OT</span></a></div>
                <div class="col-6 col-md-3"><a href="/erp/modules/production/orders.php" class="quick-action-btn">🏭<span>Đơn hàng SX</span></a></div>
                <div class="col-6 col-md-3"><a href="/erp/modules/production/iqc.php" class="quick-action-btn">🔬<span>IQC</span></a></div>
            </div>
        </div>
    </div>

    <?php /* ════════════════════════════════════════════════════════════
           EMPLOYEE / PRODUCTION
           ════════════════════════════════════════════════════════════ */ ?>
    <?php else: ?>

    <!-- Chấm công hôm nay -->
    <h6 class="text-uppercase text-muted fw-bold mb-2 small">⏰ Hôm nay — <?= date('d/m/Y') ?></h6>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted small mb-1 fw-semibold">CHẤM CÔNG HÔM NAY</p>
                    <?php if ($todayAttendance): ?>
                        <h5 class="text-success mb-2">✅ Đã chấm công</h5>
                        <div class="d-flex gap-4">
                            <div><span class="text-muted small">Giờ vào</span><br><strong><?= $todayAttendance['check_in'] ? date('H:i', strtotime($todayAttendance['check_in'])) : '--:--' ?></strong></div>
                            <div><span class="text-muted small">Giờ ra</span><br><strong><?= $todayAttendance['check_out'] ? date('H:i', strtotime($todayAttendance['check_out'])) : '--:--' ?></strong></div>
                        </div>
                    <?php else: ?>
                        <h5 class="text-danger mb-2">❌ Chưa chấm công hôm nay</h5>
                        <p class="text-muted small mb-0">Vui lòng chấm công khi đến/rời làm</p>
                    <?php endif; ?>
                    <a href="/erp/modules/attendance/index.php" class="btn btn-sm btn-outline-primary mt-3">Xem bảng chấm công</a>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">📅</div>
                <div class="kpi-label">Ngày công tháng <?= $currentMonth ?></div>
                <div class="kpi-value"><?= number_format($kpi['my_working_days'] ?? 0) ?></div>
                <div class="kpi-sub">ngày</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card <?= (($kpi['my_pending_leaves'] ?? 0) + ($kpi['my_pending_ot'] ?? 0)) > 0 ? 'kpi-warning' : 'kpi-light' ?>">
                <div class="kpi-icon">⌛</div>
                <div class="kpi-label">Đơn đang chờ duyệt</div>
                <div class="kpi-value"><?= number_format(($kpi['my_pending_leaves'] ?? 0) + ($kpi['my_pending_ot'] ?? 0)) ?></div>
                <div class="kpi-sub">Nghỉ phép: <?= $kpi['my_pending_leaves'] ?? 0 ?> &nbsp;|&nbsp; OT: <?= $kpi['my_pending_ot'] ?? 0 ?></div>
            </div>
        </div>
    </div>

    <!-- Phiếu lương -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6">
            <a href="/erp/modules/payroll/my_payroll.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <p class="text-muted small mb-1 fw-semibold">PHIẾU LƯƠNG MỚI NHẤT</p>
                            <?php if ($myLatestSlip): ?>
                                <h4 class="mb-1 fw-bold text-success"><?= formatCurrency($myLatestSlip['net_salary']) ?></h4>
                                <div class="small text-muted">Tháng <?= $myLatestSlip['period_month'] ?>/<?= $myLatestSlip['period_year'] ?></div>
                            <?php else: ?>
                                <h5 class="mb-1 text-muted">Chưa có phiếu lương</h5>
                                <div class="small text-muted">Liên hệ Kế toán nếu cần</div>
                            <?php endif; ?>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-3 p-2 ms-2">
                            <i class="fas fa-file-invoice-dollar fa-2x text-success"></i>
                        </div>
                    </div>
                    <div class="mt-2 small text-primary"><i class="fas fa-arrow-right me-1"></i>Xem phiếu lương →</div>
                </div>
            </div>
            </a>
        </div>
    </div>

    <!-- Quick Actions: Employee -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">⚡ Chức năng nhanh</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3"><a href="/erp/modules/attendance/index.php" class="quick-action-btn">⏰<span>Chấm công</span></a></div>
                <div class="col-6 col-md-3"><a href="/erp/modules/attendance/leave_request.php" class="quick-action-btn">📝<span>Xin nghỉ phép</span></a></div>
                <div class="col-6 col-md-3"><a href="/erp/modules/attendance/ot_request.php" class="quick-action-btn">⏱️<span>Đăng ký OT</span></a></div>
                <div class="col-6 col-md-3"><a href="/erp/modules/payroll/my_payroll.php" class="quick-action-btn">💰<span>Phiếu lương</span></a></div>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div>
</div>

<style>
/* ── KPI Cards ── */
.kpi-card {
    background: #fff;
    border-radius: 12px;
    padding: 1rem 1.1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    position: relative;
    overflow: hidden;
    height: 100%;
}
.kpi-icon {
    font-size: 1.7rem;
    position: absolute;
    top: 0.8rem;
    right: 1rem;
    opacity: .55;
}
.kpi-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; font-weight: 600; margin-bottom: .25rem; }
.kpi-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
.kpi-sub   { font-size: .75rem; color: #6c757d; margin-top: .2rem; }

.kpi-primary { border-left: 4px solid #0d6efd; }
.kpi-primary .kpi-value { color: #0d6efd; }
.kpi-success { border-left: 4px solid #198754; }
.kpi-success .kpi-value { color: #198754; }
.kpi-danger  { border-left: 4px solid #dc3545; }
.kpi-danger  .kpi-value { color: #dc3545; }
.kpi-warning { border-left: 4px solid #ffc107; background: #fffdf0; }
.kpi-warning .kpi-value { color: #664d00; }
.kpi-info    { border-left: 4px solid #0dcaf0; }
.kpi-info    .kpi-value { color: #0a8fa8; }
.kpi-light   { border-left: 4px solid #dee2e6; }
.kpi-light   .kpi-value { color: #343a40; }
.kpi-border-warning { border: 2px solid #ffc107 !important; }

/* ── Quick Action Buttons ── */
.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    padding: .9rem .5rem;
    border: 1px solid #dee2e6;
    border-radius: 12px;
    text-decoration: none;
    color: #343a40;
    font-size: .8rem;
    font-weight: 600;
    transition: all .18s;
    text-align: center;
}
.quick-action-btn:hover { background: #f0f4ff; border-color: #0d6efd; color: #0d6efd; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(13,110,253,.12); }
.quick-action-btn > :first-child { font-size: 1.6rem; }
</style>

<script>
(function() {
    function pad(n) { return n.toString().padStart(2, '0'); }
    function tick() {
        var d = new Date();
        var el = document.getElementById('live-clock');
        if (el) el.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<?php include 'includes/footer.php'; ?>