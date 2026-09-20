<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'manager');

$pdo = getDBConnection();
$statusMap = [
    'active' => ['bg-success', 'Đang dùng'],
    'maintenance' => ['bg-warning text-dark', 'Bảo dưỡng'],
    'disposed' => ['bg-secondary', 'Thanh lý'],
];
$requestedMonth = trim($_GET['month'] ?? '');
$filterMonthDate = DateTime::createFromFormat('!Y-m', $requestedMonth);
$filterMonth = ($filterMonthDate && $filterMonthDate->format('Y-m') === $requestedMonth) ? $requestedMonth : date('Y-m');
$monthDate = DateTime::createFromFormat('!Y-m', $filterMonth);
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->format('Y-m-t');

$reportPageUrl = static function (array $overrides = []) use ($filterMonth): string {
    $params = [
        'month' => $overrides['month'] ?? $filterMonth,
    ];
    return 'modules/admin/vehicle_report.php?' . http_build_query($params);
};

$rows = fetchAllSafe(
    $pdo,
    "SELECT
        v.id,
        v.plate_number,
        v.vehicle_name,
        v.status
    FROM vehicles v
    ORDER BY
        CASE v.status
            WHEN 'active' THEN 0
            WHEN 'maintenance' THEN 1
            ELSE 2
        END,
        v.plate_number ASC,
        v.id DESC"
);

$fuelByVehicle = [];
$maintenanceByVehicle = [];
$tripByVehicle = [];

if ($rows) {
    $vehicleIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));

    $fuelRows = fetchAllSafe(
        $pdo,
        "SELECT
            vehicle_id,
            SUM(COALESCE(liters, 0)) AS total_liters,
            SUM(COALESCE(amount, 0)) AS total_fuel_amount
        FROM vehicle_fuel
        WHERE vehicle_id IN ($placeholders) AND fuel_date BETWEEN ? AND ?
        GROUP BY vehicle_id",
        array_merge($vehicleIds, [$monthStart, $monthEnd])
    );
    foreach ($fuelRows as $fuelRow) {
        $fuelByVehicle[(int)$fuelRow['vehicle_id']] = $fuelRow;
    }

    $maintenanceRows = fetchAllSafe(
        $pdo,
        "SELECT
            vehicle_id,
            SUM(COALESCE(amount, 0)) AS total_maintenance_amount
        FROM vehicle_maintenance
        WHERE vehicle_id IN ($placeholders) AND maintenance_date BETWEEN ? AND ?
        GROUP BY vehicle_id",
        array_merge($vehicleIds, [$monthStart, $monthEnd])
    );
    foreach ($maintenanceRows as $maintenanceRow) {
        $maintenanceByVehicle[(int)$maintenanceRow['vehicle_id']] = $maintenanceRow;
    }

    $tripRows = fetchAllSafe(
        $pdo,
        "SELECT
            vehicle_id,
            SUM(CASE
                WHEN km_start IS NOT NULL AND km_end IS NOT NULL AND km_end >= km_start THEN km_end - km_start
                ELSE 0
            END) AS total_km,
            SUM(COALESCE(toll_fee, 0)) AS total_toll_fee
        FROM vehicle_trips
        WHERE vehicle_id IN ($placeholders) AND trip_date BETWEEN ? AND ?
        GROUP BY vehicle_id",
        array_merge($vehicleIds, [$monthStart, $monthEnd])
    );
    foreach ($tripRows as $tripRow) {
        $tripByVehicle[(int)$tripRow['vehicle_id']] = $tripRow;
    }
}

$reportRows = [];
$totalLiters = 0.0;
$totalKm = 0;
$totalCost = 0.0;
$hasMonthlyActivity = false;

foreach ($rows as $row) {
    $vehicleId = (int)$row['id'];
    $fuelData = $fuelByVehicle[$vehicleId] ?? null;
    $maintenanceData = $maintenanceByVehicle[$vehicleId] ?? null;
    $tripData = $tripByVehicle[$vehicleId] ?? null;
    $liters = (float)($fuelData['total_liters'] ?? 0);
    $km = (int)round((float)($tripData['total_km'] ?? 0));
    $totalVehicleCost = (float)($fuelData['total_fuel_amount'] ?? 0)
        + (float)($maintenanceData['total_maintenance_amount'] ?? 0)
        + (float)($tripData['total_toll_fee'] ?? 0);

    $reportRows[] = [
        'plate_number' => $row['plate_number'],
        'vehicle_name' => $row['vehicle_name'],
        'status' => $row['status'],
        'total_liters' => $liters,
        'total_km' => $km,
        'avg_consumption' => $km > 0 ? ($liters / $km) * 100 : null,
        'total_cost' => $totalVehicleCost,
    ];

    $totalLiters += $liters;
    $totalKm += $km;
    $totalCost += $totalVehicleCost;
    if ($liters > 0 || $km > 0 || $totalVehicleCost > 0) {
        $hasMonthlyActivity = true;
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-chart-bar me-2 text-primary"></i>Báo cáo chi phí xe theo tháng</h4>
                <p class="text-muted mb-0">Tổng hợp đổ dầu, quãng đường vận hành và chi phí đội xe theo từng tháng.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-primary fs-6">Tháng <?= e(date('m/Y', strtotime($monthStart))) ?></span>
                <a href="/erp/modules/admin/vehicles.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Quay lại quản lý xe
                </a>
            </div>
        </div>

        <?php showFlash(); ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-md-3 col-lg-2">
                        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($filterMonth) ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                        <a href="/erp/<?= e($reportPageUrl(['month' => date('Y-m')])) ?>" class="btn btn-sm btn-outline-secondary ms-1">Tháng hiện tại</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Tổng chi phí đội xe</div>
                        <h4 class="mb-0 text-warning"><?= e(formatCurrency($totalCost)) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Tổng số lít dầu</div>
                        <h4 class="mb-0 text-primary"><?= e(number_format($totalLiters, 2, ',', '.')) ?> lít</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Tổng số km vận hành</div>
                        <h4 class="mb-0 text-success"><?= e(number_format($totalKm, 0, ',', '.')) ?> km</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body border-bottom">
                <div class="fw-semibold">Danh sách chi phí theo xe</div>
                <div class="text-muted small">Hiển thị toàn bộ xe để đối chiếu tổng quan, kể cả xe không có phát sinh trong tháng.</div>
            </div>
            <?php if ($reportRows && !$hasMonthlyActivity): ?>
                <div class="alert alert-info rounded-0 border-0 border-bottom mb-0">
                    Không có phát sinh chi phí, đổ dầu hoặc chuyến đi nào trong tháng đã chọn. Bảng dưới vẫn hiển thị toàn bộ xe để đối chiếu.
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                    <tr>
                        <th>Biển số xe</th>
                        <th>Tên xe</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Tổng lít dầu</th>
                        <th class="text-end">Tổng km</th>
                        <th class="text-end">Bình quân (lít/100km)</th>
                        <th class="text-end">Tổng chi phí</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Chưa có phương tiện nào.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $reportRow): ?>
                            <?php [$badgeClass, $statusLabel] = $statusMap[$reportRow['status']] ?? ['bg-secondary', $reportRow['status'] ?: '—']; ?>
                            <tr>
                                <td class="fw-semibold text-primary"><?= e($reportRow['plate_number']) ?></td>
                                <td><?= e($reportRow['vehicle_name']) ?></td>
                                <td><span class="badge <?= e($badgeClass) ?>"><?= e($statusLabel) ?></span></td>
                                <td class="text-end"><?= e(number_format($reportRow['total_liters'], 2, ',', '.')) ?></td>
                                <td class="text-end"><?= e(number_format($reportRow['total_km'], 0, ',', '.')) ?></td>
                                <td class="text-end"><?= $reportRow['avg_consumption'] !== null ? e(number_format($reportRow['avg_consumption'], 2, ',', '.')) : '—' ?></td>
                                <td class="text-end fw-semibold"><?= e(formatCurrency($reportRow['total_cost'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-warning fw-bold">
                            <td colspan="3">Tổng cộng</td>
                            <td class="text-end"><?= e(number_format($totalLiters, 2, ',', '.')) ?></td>
                            <td class="text-end"><?= e(number_format($totalKm, 0, ',', '.')) ?></td>
                            <td class="text-end"><?= $totalKm > 0 ? e(number_format(($totalLiters / $totalKm) * 100, 2, ',', '.')) : '—' ?></td>
                            <td class="text-end"><?= e(formatCurrency($totalCost)) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php';
