<?php
/**
 * modules/attendance/zkteco_devices.php
 * Trang quản lý thiết bị chấm công ZKTeco — chỉ dành cho director/accountant/manager.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

requireRole('director', 'accountant', 'manager');

$pdo  = getDBConnection();
$user = currentUser();

// Thống kê số bản ghi source='device' tháng hiện tại
$month = date('Y-m');
$stmtStat = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM attendance_logs
    WHERE source = 'device'
      AND DATE_FORMAT(work_date, '%Y-%m') = ?
");
$stmtStat->execute([$month]);
$deviceTotal = (int)$stmtStat->fetchColumn();

// Đọc 50 dòng cuối file log
$logFile  = $_SERVER['DOCUMENT_ROOT'] . '/erp/logs/zkteco_debug.log';
$logLines = [];
if (file_exists($logFile)) {
    $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logLines = array_slice($allLines, -50);
    $logLines = array_reverse($logLines);
}

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>

<div class="main-content">
  <div class="container-fluid py-4">

    <div class="d-flex align-items-center mb-4 gap-2">
      <i class="fas fa-fingerprint fa-lg text-primary"></i>
      <h4 class="mb-0 fw-bold">Quản lý máy chấm công ZKTeco</h4>
    </div>

    <!-- Thống kê nhanh -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="rounded-circle bg-primary bg-opacity-10 p-3">
              <i class="fas fa-fingerprint text-primary fa-lg"></i>
            </div>
            <div>
              <div class="text-muted small">Bản ghi từ máy tháng <?= date('m/Y') ?></div>
              <div class="fs-4 fw-bold"><?= number_format($deviceTotal) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="rounded-circle bg-success bg-opacity-10 p-3">
              <i class="fas fa-file-alt text-success fa-lg"></i>
            </div>
            <div>
              <div class="text-muted small">Dòng log debug</div>
              <div class="fs-4 fw-bold"><?= count($logLines) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">

      <!-- Hướng dẫn cấu hình -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-bottom fw-semibold">
            <i class="fas fa-info-circle text-info me-2"></i>Hướng dẫn cấu hình máy ZKTeco SpeedFace V5L
          </div>
          <div class="card-body">
            <p class="mb-2 text-muted small">URL endpoint nhận dữ liệu push từ máy:</p>
            <div class="input-group mb-3">
              <input type="text" class="form-control form-control-sm font-monospace"
                     id="endpointUrl"
                     value="http://[YOUR_DOMAIN]/erp/api/attendance/zkteco_push.php?token=ZKTECO_SECRET_TOKEN_2024"
                     readonly>
              <button class="btn btn-outline-secondary btn-sm" onclick="copyEndpoint()" title="Sao chép">
                <i class="fas fa-copy"></i>
              </button>
            </div>
            <hr>
            <p class="fw-semibold mb-2"><i class="fas fa-list-ol me-1 text-primary"></i>Các bước cài đặt trên màn hình máy:</p>
            <ol class="mb-0 small lh-lg">
              <li>Vào <strong>Menu → Communication → Cloud Server Settings</strong></li>
              <li>Bật <strong>ADMS / Push Mode</strong></li>
              <li>Điền <strong>Server Address</strong> = URL endpoint ở trên</li>
              <li>Port: <strong>80</strong> (hoặc 443 nếu HTTPS)</li>
              <li>Chọn <strong>Enable Push</strong> → Lưu và khởi động lại máy</li>
              <li>Chấm công thử → kiểm tra log bên dưới</li>
            </ol>
            <div class="alert alert-warning mt-3 mb-0 py-2 small">
              <i class="fas fa-exclamation-triangle me-1"></i>
              <strong>Lưu ý bảo mật:</strong> Đổi token <code>ZKTECO_SECRET_TOKEN_2024</code>
              bằng cách đặt biến môi trường <code>ZKTECO_TOKEN</code> trên server hoặc
              chỉnh trực tiếp trong <code>api/attendance/zkteco_push.php</code>.
            </div>
          </div>
        </div>
      </div>

      <!-- Form test kết nối + đồng bộ -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white border-bottom fw-semibold">
            <i class="fas fa-network-wired text-success me-2"></i>Kiểm tra kết nối máy
          </div>
          <div class="card-body">
            <div class="input-group mb-3">
              <span class="input-group-text"><i class="fas fa-desktop"></i></span>
              <input type="text" id="deviceIp" class="form-control" placeholder="192.168.1.201" />
              <input type="number" id="devicePort" class="form-control" placeholder="4370" value="4370" style="max-width:100px;">
              <button class="btn btn-outline-primary" onclick="testConnection()">
                <i class="fas fa-plug me-1"></i>Kiểm tra
              </button>
            </div>
            <div id="pingResult" class="d-none small mt-2"></div>
          </div>
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom fw-semibold">
            <i class="fas fa-sync-alt text-primary me-2"></i>Đồng bộ nhân viên lên máy
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3">
              Gửi danh sách nhân viên từ ERP lên máy chấm công để máy nhận diện.
              Nhập IP và Port ở ô trên, sau đó bấm nút bên dưới.
            </p>
            <button class="btn btn-primary" onclick="syncUsers()">
              <i class="fas fa-cloud-upload-alt me-2"></i>Đồng bộ nhân viên lên máy
            </button>
            <div id="syncResult" class="d-none mt-3 small"></div>
          </div>
        </div>
      </div>

      <!-- Log debug -->
      <div class="col-12">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fas fa-terminal text-dark me-2"></i>Log debug (50 dòng gần nhất)</span>
            <span class="badge bg-secondary"><?= count($logLines) ?> dòng</span>
          </div>
          <div class="card-body p-0">
            <?php if (empty($logLines)): ?>
              <div class="text-center text-muted py-4">
                <i class="fas fa-file-alt fa-2x mb-2 d-block"></i>
                Chưa có log. File sẽ được tạo sau khi máy gửi dữ liệu lần đầu.
              </div>
            <?php else: ?>
              <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0">
                  <tbody>
                    <?php foreach ($logLines as $i => $line): ?>
                      <?php
                        $rowClass = '';
                        if (stripos($line, 'ERROR') !== false) $rowClass = 'table-danger';
                        elseif (stripos($line, 'AUTH_FAIL') !== false) $rowClass = 'table-warning';
                        elseif (stripos($line, 'CHECK_IN') !== false) $rowClass = 'table-success';
                        elseif (stripos($line, 'CHECK_OUT') !== false) $rowClass = 'table-info';
                      ?>
                      <tr class="<?= $rowClass ?>">
                        <td class="ps-3 py-1">
                          <code class="small"><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></code>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /row -->
  </div><!-- /container -->
</div><!-- /main-content -->

<script>
function copyEndpoint() {
  var el = document.getElementById('endpointUrl');
  var text = el.value;
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(function() {
      alert('Đã sao chép URL endpoint!');
    });
  } else {
    el.select();
    document.execCommand('copy');
    alert('Đã sao chép URL endpoint!');
  }
}

function getIpPort() {
  return {
    ip:   document.getElementById('deviceIp').value.trim(),
    port: document.getElementById('devicePort').value.trim() || '4370'
  };
}

function showResult(elId, html, type) {
  var el = document.getElementById(elId);
  el.className = 'mt-3 small alert alert-' + type;
  el.innerHTML = html;
}

function testConnection() {
  var d = getIpPort();
  if (!d.ip) { alert('Vui lòng nhập địa chỉ IP máy'); return; }
  showResult('pingResult', '<i class="fas fa-spinner fa-spin me-1"></i>Đang kiểm tra...', 'secondary');
  document.getElementById('pingResult').classList.remove('d-none');

  fetch('/erp/api/attendance/zkteco_sync_users.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({device_ip: d.ip, device_port: parseInt(d.port), ping_only: true})
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok || data.synced >= 0) {
      showResult('pingResult', '<i class="fas fa-check-circle me-1"></i>Kết nối thành công!', 'success');
    } else {
      showResult('pingResult', '<i class="fas fa-times-circle me-1"></i>' + (data.msg || 'Không kết nối được'), 'danger');
    }
  })
  .catch(() => showResult('pingResult', '<i class="fas fa-times-circle me-1"></i>Không kết nối được máy', 'danger'));
}

function syncUsers() {
  var d = getIpPort();
  if (!d.ip) { alert('Vui lòng nhập địa chỉ IP máy'); return; }
  if (!confirm('Đồng bộ toàn bộ nhân viên active lên máy ' + d.ip + ':' + d.port + '?')) return;

  showResult('syncResult', '<i class="fas fa-spinner fa-spin me-1"></i>Đang đồng bộ...', 'secondary');
  document.getElementById('syncResult').classList.remove('d-none');

  fetch('/erp/api/attendance/zkteco_sync_users.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({device_ip: d.ip, device_port: parseInt(d.port)})
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      var msg = '<i class="fas fa-check-circle me-1"></i>Đã đồng bộ <strong>' + data.synced + '</strong> nhân viên.';
      if (data.errors && data.errors.length) {
        msg += '<br><span class="text-danger">Lỗi: ' + data.errors.join('; ') + '</span>';
      }
      showResult('syncResult', msg, 'success');
    } else {
      showResult('syncResult', '<i class="fas fa-times-circle me-1"></i>' + (data.msg || 'Thất bại'), 'danger');
    }
  })
  .catch(() => showResult('syncResult', '<i class="fas fa-times-circle me-1"></i>Lỗi kết nối server', 'danger'));
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
