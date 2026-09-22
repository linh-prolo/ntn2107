<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse');
$pdo = getDBConnection();

$categoryId = (int)($_GET['category_id'] ?? 0);
$keyword = trim((string)($_GET['keyword'] ?? ''));
$lowStockOnly = (string)($_GET['low_stock_only'] ?? '') === '1';

$categories = fetchAllSafe($pdo, 'SELECT id, name FROM wa_categories WHERE is_active = 1 ORDER BY name');

$where = ['i.is_active = 1'];
$params = [];
if ($categoryId > 0) {
    $where[] = 'i.category_id = ?';
    $params[] = $categoryId;
}
if ($keyword !== '') {
    $where[] = '(i.item_code LIKE ? OR i.item_name LIKE ?)';
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}

$stocks = fetchAllSafe($pdo, "
    SELECT s.*
    FROM (
        SELECT i.id, i.item_code, i.item_name, i.unit, COALESCE(i.min_stock, 0) AS min_stock, c.name AS category_name,
               COALESCE(SUM(CASE WHEN t.type='import' THEN t.qty WHEN t.type='export' THEN -t.qty ELSE 0 END), 0) AS stock
        FROM wa_items i
        LEFT JOIN wa_categories c ON c.id = i.category_id
        LEFT JOIN wa_transactions t ON t.item_id = i.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY i.id, i.item_code, i.item_name, i.unit, COALESCE(i.min_stock, 0), c.name
    ) s
    " . ($lowStockOnly ? 'WHERE s.stock <= COALESCE(s.min_stock, 0)' : '') . "
    ORDER BY
        CASE
            WHEN s.stock <= 0 THEN 0
            WHEN s.stock <= COALESCE(s.min_stock, 0) THEN 1
            ELSE 2
        END ASC,
        s.stock ASC,
        s.item_name ASC
", $params);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content"><div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-boxes me-2 text-primary"></i>Tồn kho</h4>
            <p class="text-muted mb-0">Theo dõi tồn kho hiện tại và cảnh báo vật tư sắp hết/hết hàng.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="get">
                <div class="col-md-3">
                    <select name="category_id" class="form-select form-select-sm">
                        <option value="">-- Nhóm vật tư --</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="keyword" class="form-control form-control-sm" value="<?= e($keyword) ?>" placeholder="Tìm mã / tên vật tư">
                </div>
                <div class="col-md-auto">
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" value="1" id="lowStockOnly" name="low_stock_only" <?= $lowStockOnly ? 'checked' : '' ?>>
                        <label class="form-check-label" for="lowStockOnly">Chỉ hiện vật tư sắp hết/hết hàng (bao gồm tồn = 0)</label>
                    </div>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Lọc</button>
                </div>
                <div class="col-auto">
                    <a href="/erp/modules/warehouse_admin/stock.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Mã vật tư</th>
                        <th>Tên vật tư</th>
                        <th>Nhóm</th>
                        <th>ĐVT</th>
                        <th class="text-end">Tồn hiện tại</th>
                        <th class="text-end">Tồn tối thiểu</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$stocks): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Không có dữ liệu vật tư phù hợp</td></tr>
                <?php endif; ?>
                <?php foreach ($stocks as $s):
                    $stock = (float)$s['stock'];
                    $minStock = (float)$s['min_stock'];
                    $rowClass = '';
                    $statusBadge = 'success';
                    $statusText = 'Bình thường';
                    $stockTextClass = 'text-success';
                    if ($stock <= 0) {
                        $rowClass = 'table-danger';
                        $statusBadge = 'danger';
                        $statusText = 'Hết hàng';
                        $stockTextClass = 'text-danger';
                    } elseif ($stock <= $minStock) {
                        $rowClass = 'table-warning';
                        $statusBadge = 'warning text-dark';
                        $statusText = 'Sắp hết';
                        $stockTextClass = 'text-danger';
                    }
                ?>
                    <tr class="stock-row <?= $rowClass ?>" data-item-id="<?= (int)$s['id'] ?>">
                        <td class="fw-semibold"><?= e($s['item_code']) ?></td>
                        <td><?= e($s['item_name']) ?></td>
                        <td><?= e($s['category_name'] ?? '') ?></td>
                        <td><?= e($s['unit']) ?></td>
                        <td class="text-end fw-bold <?= $stockTextClass ?>"><?= e(number_format($stock, 2, ',', '.')) ?></td>
                        <td class="text-end"><?= e(number_format($minStock, 2, ',', '.')) ?></td>
                        <td><span class="badge bg-<?= $statusBadge ?>"><?= $statusText ?></span></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary btn-item-history" data-item-id="<?= (int)$s['id'] ?>" aria-label="Xem lịch sử vật tư <?= e($s['item_code']) ?> - <?= e($s['item_name']) ?>">
                                <i class="fas fa-history me-1"></i>Lịch sử
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div></div>

<div class="modal fade" id="modalItemHistory" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-history me-2 text-primary"></i>Lịch sử xuất / nhập vật tư</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="fw-semibold" id="historyItemTitle">--</div>
                    <div class="text-muted">Tồn hiện tại: <strong id="historyCurrentStock">0</strong></div>
                    <div class="small text-muted" id="historyLimitNote"></div>
                </div>
                <div class="alert alert-danger d-none" id="historyError"></div>
                <div id="historyLoading" class="text-muted py-3">Đang tải dữ liệu...</div>
                <div class="table-responsive d-none" id="historyTableWrap">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Ngày</th>
                                <th>Loại</th>
                                <th class="text-end">Số lượng</th>
                                <th>Số chứng từ</th>
                                <th>Ghi chú</th>
                                <th>Người thực hiện</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.stock-row { cursor: pointer; }
</style>
<script>
window.addEventListener('load', function() {
    const modalEl = document.getElementById('modalItemHistory');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const historyLoading = document.getElementById('historyLoading');
    const historyError = document.getElementById('historyError');
    const historyTableWrap = document.getElementById('historyTableWrap');
    const historyTableBody = document.getElementById('historyTableBody');
    const historyItemTitle = document.getElementById('historyItemTitle');
    const historyCurrentStock = document.getElementById('historyCurrentStock');
    const historyLimitNote = document.getElementById('historyLimitNote');

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[ch];
        });
    }

    function formatQty(value) {
        return Number(value || 0).toLocaleString('vi-VN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDateTime(value) {
        if (!value) return '';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return String(value);
        return parsed.toLocaleString('vi-VN');
    }

    async function loadHistory(itemId) {
        historyLoading.textContent = 'Đang tải dữ liệu...';
        historyLoading.classList.remove('d-none');
        historyError.classList.add('d-none');
        historyError.textContent = '';
        historyTableWrap.classList.add('d-none');
        historyTableBody.innerHTML = '';
        historyItemTitle.textContent = '--';
        historyCurrentStock.textContent = '0';
        historyLimitNote.textContent = '';

        try {
            const res = await fetch('/erp/api/warehouse_admin/get_item_history.php?item_id=' + encodeURIComponent(itemId));
            if (!res.ok) {
                throw new Error('Lỗi server (HTTP ' + res.status + ')');
            }
            const data = await res.json();
            if (!data.ok) {
                throw new Error(data.msg || 'Không thể tải lịch sử vật tư');
            }

            historyItemTitle.textContent = (data.item.item_code || '') + ' - ' + (data.item.item_name || '');
            historyCurrentStock.textContent = formatQty(data.item.stock) + ' ' + (data.item.unit || '');
            if (Number(data.history_limit || 0) > 0) {
                historyLimitNote.textContent = 'Hiển thị tối đa ' + Number(data.history_limit).toLocaleString('vi-VN') + ' giao dịch gần nhất.';
            }

            const history = Array.isArray(data.history) ? data.history : [];
            if (history.length === 0) {
                historyTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Chưa có giao dịch</td></tr>';
            } else {
                historyTableBody.innerHTML = history.map(function(row) {
                    const isImport = row.type === 'import';
                    const badgeClass = isImport ? 'badge bg-success' : 'badge bg-warning text-dark';
                    return '<tr>'
                        + '<td>' + escapeHtml(formatDateTime(row.transacted_at)) + '</td>'
                        + '<td><span class="' + badgeClass + '">' + (isImport ? 'Nhập' : 'Xuất') + '</span></td>'
                        + '<td class="text-end fw-semibold">' + formatQty(row.qty) + '</td>'
                        + '<td>' + escapeHtml(row.ref_no || '') + '</td>'
                        + '<td>' + escapeHtml(row.note || '') + '</td>'
                        + '<td>' + escapeHtml(row.full_name || '') + '</td>'
                        + '</tr>';
                }).join('');
            }

            historyLoading.classList.add('d-none');
            historyTableWrap.classList.remove('d-none');
        } catch (err) {
            historyLoading.classList.add('d-none');
            historyError.textContent = err.message || 'Không thể tải lịch sử giao dịch';
            historyError.classList.remove('d-none');
            historyTableWrap.classList.add('d-none');
            historyTableBody.innerHTML = '';
        }
    }

    function openHistory(itemId) {
        modal.show();
        loadHistory(itemId);
    }

    document.querySelectorAll('.btn-item-history').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openHistory(this.dataset.itemId || '0');
        });
    });

    document.querySelectorAll('.stock-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target.closest('.btn-item-history')) return;
            openHistory(this.dataset.itemId || '0');
        });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
