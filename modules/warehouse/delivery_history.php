<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');
$pdo = getDBConnection();

$filterType = trim((string)($_GET['filter_type'] ?? 'today'));
if (!in_array($filterType, ['today', 'week', 'month', 'custom'], true)) {
    $filterType = 'today';
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
$dateTo = trim((string)($_GET['date_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}
if ($filterType === 'custom' && (!$dateFrom || !$dateTo)) {
    $filterType = 'today';
}

$customerId = (int)($_GET['customer_id'] ?? 0);

$customers = fetchAllSafe($pdo, "SELECT id, customer_name FROM customers ORDER BY customer_name");

$where = ['1=1'];
$params = [];

if ($filterType === 'today') {
    $where[] = 'DATE(d.delivery_date) = CURDATE()';
} elseif ($filterType === 'week') {
    $where[] = 'DATE(d.delivery_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
} elseif ($filterType === 'month') {
    $where[] = 'YEAR(d.delivery_date) = YEAR(CURDATE()) AND MONTH(d.delivery_date) = MONTH(CURDATE())';
} elseif ($filterType === 'custom' && $dateFrom && $dateTo) {
    $where[] = 'DATE(d.delivery_date) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;
}

if ($customerId > 0) {
    $where[] = 'd.customer_id = ?';
    $params[] = $customerId;
}

$whereSql = implode(' AND ', $where);

$rows = fetchAllSafe($pdo, "SELECT d.id, d.delivery_no, d.delivery_date, d.sender_name, d.driver_name, d.vehicle_plate, d.note, d.status,
                            c.customer_name,
                            COALESCE(SUM(CASE WHEN di.type='done' THEN di.qty_deliver ELSE 0 END),0) AS total_done,
                            COALESCE(SUM(CASE WHEN di.type='error' THEN di.qty_deliver ELSE 0 END),0) AS total_error
                            FROM oqc_deliveries d
                            LEFT JOIN customers c ON c.id = d.customer_id
                            LEFT JOIN oqc_delivery_items di ON di.delivery_id = d.id
                            WHERE $whereSql
                            GROUP BY d.id
                            ORDER BY d.id DESC", $params);

$totalDeliveries = count($rows);
$totalDone = 0.0;
$totalError = 0.0;
foreach ($rows as $row) {
    $totalDone += (float)$row['total_done'];
    $totalError += (float)$row['total_error'];
}

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
$csrf = generateCSRF();
?>
<div class="main-content"><div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-history me-2 text-primary"></i>Lịch sử giao hàng</h4>
            <p class="text-muted mb-0">Xem lại biên bản giao hàng theo ngày.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
        <form class="row g-2 align-items-center" method="get" id="filterForm">
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-primary quick-filter-btn<?= $filterType === 'today' ? ' active' : '' ?>" data-filter="today">Hôm nay</button>
                    <button type="button" class="btn btn-outline-primary quick-filter-btn<?= $filterType === 'week' ? ' active' : '' ?>" data-filter="week">7 ngày</button>
                    <button type="button" class="btn btn-outline-primary quick-filter-btn<?= $filterType === 'month' ? ' active' : '' ?>" data-filter="month">Tháng này</button>
                    <button type="button" class="btn btn-outline-primary quick-filter-btn<?= $filterType === 'custom' ? ' active' : '' ?>" data-filter="custom">Tuỳ chọn</button>
                </div>
                <input type="hidden" name="filter_type" id="filterTypeInput" value="<?= e($filterType) ?>">
            </div>
            <div class="col-auto">
                <input type="date" name="date_from" id="dateFrom" class="form-control form-control-sm" value="<?= e($dateFrom) ?>" <?= $filterType === 'custom' ? '' : 'disabled' ?>>
            </div>
            <div class="col-auto">
                <input type="date" name="date_to" id="dateTo" class="form-control form-control-sm" value="<?= e($dateTo) ?>" <?= $filterType === 'custom' ? '' : 'disabled' ?>>
            </div>
            <div class="col-auto">
                <select name="customer_id" class="form-select form-select-sm">
                    <option value="0">-- Tất cả khách hàng --</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int)$customer['id'] ?>" <?= $customerId === (int)$customer['id'] ? 'selected' : '' ?>><?= e($customer['customer_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
            </div>
            <div class="col-auto">
                <a href="/erp/modules/warehouse/delivery_history.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div></div>

    <div class="alert alert-info d-flex flex-wrap gap-3 align-items-center mb-3">
        <div><strong>Tổng phiếu giao:</strong> <?= e(number_format($totalDeliveries, 0, ',', '.')) ?></div>
        <div><strong>Tổng SL thành phẩm:</strong> <span class="text-success"><?= e(number_format($totalDone, 2, ',', '.')) ?></span></div>
        <div><strong>Tổng SL lỗi:</strong> <span class="text-danger"><?= e(number_format($totalError, 2, ',', '.')) ?></span></div>
    </div>

    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Số phiếu</th>
                    <th>Ngày giao</th>
                    <th>Khách hàng</th>
                    <th>Người nhận</th>
                    <th>Tài xế</th>
                    <th class="text-end">SL Thành phẩm</th>
                    <th class="text-end">SL Lỗi</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Không có biên bản giao hàng</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= e($row['delivery_no']) ?></td>
                    <td><?= e(formatDate($row['delivery_date'])) ?></td>
                    <td><?= e($row['customer_name'] ?? '—') ?></td>
                    <td><?= e($row['sender_name'] ?? '—') ?></td>
                    <td><?= e($row['driver_name'] ?? '—') ?></td>
                    <td class="text-end text-success fw-semibold"><?= e(number_format((float)$row['total_done'], 2, ',', '.')) ?></td>
                    <td class="text-end text-danger fw-semibold"><?= e(number_format((float)$row['total_error'], 2, ',', '.')) ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="/erp/modules/warehouse/print_delivery.php?id=<?= (int)$row['id'] ?>">
                            <i class="fas fa-print"></i>
                        </a>
                        <?php if (hasRole('director')): ?>
                        <button class="btn btn-sm btn-outline-danger btn-delete-delivery ms-1"
                            data-id="<?= (int)$row['id'] ?>"
                            data-no="<?= e($row['delivery_no']) ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div></div>
<script>
document.querySelectorAll('.quick-filter-btn').forEach(btn => btn.addEventListener('click', function () {
    const filter = this.dataset.filter;
    document.getElementById('filterTypeInput').value = filter;
    document.getElementById('dateFrom').disabled = filter !== 'custom';
    document.getElementById('dateTo').disabled = filter !== 'custom';
    if (filter !== 'custom') {
        document.getElementById('filterForm').submit();
    }
}));
</script>
<?php if (hasRole('director')): ?>
<script>
document.querySelectorAll('.btn-delete-delivery').forEach(btn => btn.addEventListener('click', async function () {
    const id = this.dataset.id;
    const no = this.dataset.no;
    if (!confirm(`Xoá phiếu giao hàng ${no}?\nThao tác này sẽ xoá toàn bộ dữ liệu liên quan và không thể khôi phục.`)) return;
    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('csrf_token', <?= json_encode($csrf) ?>);
        const res  = await fetch('/erp/api/warehouse/delete_delivery.php', { method: 'POST', body: fd });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch (e) {
            console.error('[delete_delivery] Non-JSON response:', text);
            alert('Lỗi hệ thống: Phản hồi không hợp lệ từ máy chủ. Vui lòng tải lại trang.');
            return;
        }
        if (data.ok) {
            location.reload();
        } else {
            alert('Lỗi: ' + (data.msg || 'Không thể xoá phiếu giao hàng'));
        }
    } catch (err) {
        alert('Lỗi kết nối: ' + err.message);
    }
}));
</script>
<?php endif; ?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
