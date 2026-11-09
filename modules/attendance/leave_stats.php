<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireRole('production', 'manager', 'director', 'accountant');

$user = currentUser();
$pdo = getDBConnection();
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$myLevel = getRoleLevel($user['role']);

// Phép năm được cấp: giống PayrollEngine::calcAnnualLeave (tính theo tháng vào làm nếu vào trong năm)
function leaveEntitlement(?string $dateJoined, int $perYear, int $year): int {
    if (!$dateJoined) return $perYear;
    $joined = new DateTime($dateJoined);
    $joinYear = (int)$joined->format('Y');
    if ($joinYear < $year) return $perYear;
    if ($joinYear > $year) return 0;
    $startMonth = ((int)$joined->format('d') > 1) ? (int)$joined->format('m') + 1 : (int)$joined->format('m');
    return (int)round(max(0, 12 - $startMonth + 1) * $perYear / 12);
}

$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.employee_code, r.name AS role_name, d.name AS department_name,
           ep.date_joined, COALESCE(ep.annual_leave_total, 12) AS annual_leave_total,
           COALESCE(SUM(CASE WHEN lr.leave_type = 'annual'  THEN lr.total_days END), 0) AS used_annual,
           COALESCE(SUM(CASE WHEN lr.leave_type = 'sick'    THEN lr.total_days END), 0) AS used_sick,
           COALESCE(SUM(CASE WHEN lr.leave_type = 'unpaid'  THEN lr.total_days END), 0) AS used_unpaid,
           COALESCE(SUM(CASE WHEN lr.leave_type = 'other'   THEN lr.total_days END), 0) AS used_other
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN employee_profiles ep ON ep.user_id = u.id
    LEFT JOIN leave_requests lr ON lr.user_id = u.id AND lr.status = 'approved'
         AND lr.start_date >= ? AND lr.start_date < ?
    WHERE u.is_active = 1
      AND (u.id = ? OR
           (r.name = 'employee'   AND ? >= 2)
        OR (r.name = 'production' AND ? >= 3)
        OR (r.name = 'manager'    AND ? >= 4)
        OR (r.name = 'accountant' AND ? >= 5))
    GROUP BY u.id, u.full_name, u.employee_code, r.name, d.name, ep.date_joined, ep.annual_leave_total
    ORDER BY u.full_name ASC
");
$stmt->execute([sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1),
    $user['id'], $myLevel, $myLevel, $myLevel, $myLevel]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['total'] = leaveEntitlement($r['date_joined'], (int)$r['annual_leave_total'], $year);
    $r['remaining'] = max(0, $r['total'] - (float)$r['used_annual']);
}
unset($r);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">📊 Thống kê nghỉ phép năm <?= $year ?></h4>
        <a href="/erp/modules/attendance/leave_manage.php" class="btn btn-outline-secondary btn-sm">‹ Duyệt đơn nghỉ phép</a>
    </div>
    <form method="GET" class="d-flex align-items-center gap-2 mb-3">
        <a class="btn btn-outline-secondary btn-sm" href="?year=<?= $year - 1 ?>">‹ Năm trước</a>
        <input type="number" name="year" class="form-control form-control-sm" aria-label="Năm" min="2000" max="2100" value="<?= $year ?>" style="width:100px">
        <button class="btn btn-primary btn-sm" type="submit">Xem</button>
        <a class="btn btn-outline-secondary btn-sm" href="?year=<?= $year + 1 ?>">Năm sau ›</a>
    </form>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Nhân viên</th><th>Phòng ban</th><th>Ngày vào làm</th>
                            <th class="text-end">Phép năm được cấp</th>
                            <th class="text-end">Phép năm đã nghỉ</th>
                            <th class="text-end">Phép năm còn tồn</th>
                            <th class="text-end">Nghỉ ốm</th>
                            <th class="text-end">Nghỉ KL</th>
                            <th class="text-end">Khác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['employee_code']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($r['department_name'] ?? '') ?></td>
                            <td><?= $r['date_joined'] ? formatDate($r['date_joined']) : '-' ?></td>
                            <td class="text-end"><?= $r['total'] ?></td>
                            <td class="text-end"><?= (float)$r['used_annual'] ?></td>
                            <td class="text-end fw-bold <?= $r['remaining'] <= 0 ? 'text-danger' : 'text-success' ?>"><?= (float)$r['remaining'] ?></td>
                            <td class="text-end"><?= (float)$r['used_sick'] ?></td>
                            <td class="text-end"><?= (float)$r['used_unpaid'] ?></td>
                            <td class="text-end"><?= (float)$r['used_other'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <small class="text-muted d-block mt-2">Chỉ tính các đơn đã duyệt, theo năm của ngày bắt đầu nghỉ. Phép năm còn tồn = được cấp − đã nghỉ (khớp cách tính bảng lương).</small>
</div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
