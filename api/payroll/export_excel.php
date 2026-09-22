<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

requireRoleApi('director', 'accountant');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$pdo      = getDBConnection();
$periodId = (int)($_GET['period_id'] ?? 0);

if (!$periodId) { http_response_code(400); echo 'Thiếu period_id'; exit; }

$stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$stmt->execute([$periodId]);
$period = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$period) { http_response_code(404); echo 'Không tìm thấy kỳ lương'; exit; }

$stmt = $pdo->prepare("
    SELECT ps.*,
           u.full_name, u.employee_code,
           d.name        AS department_name,
           ep.identity_no,
           ep.personal_tax_code,
           ep.social_book_no,
           ep.bank_account,
           ep.bank_name,
           ep.date_joined,
           ep.dependants AS ep_dependants
    FROM payroll_slips ps
    JOIN users u                   ON ps.user_id      = u.id
    LEFT JOIN employee_profiles ep ON ep.user_id      = u.id
    LEFT JOIN departments d        ON u.department_id = d.id
    WHERE ps.period_id = ?
    ORDER BY u.employee_code
");
$stmt->execute([$periodId]);
$slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════════════
// CẤU TRÚC CỘT — bám theo slip_list.php, thêm "Ăn ca OT" ở cuối để tránh lệch mapping cột
// ════════════════════════════════════════════════════════════════════
// A  #
// B  Mã NV
// C  Nhân viên
// D  Phòng ban
// E  Ngày công thực tế
// F  Nghỉ có lương
// G  Nghỉ không lương
// H  Lương CB thực nhận
// I  OT Thường
// J  OT CN
// K  OT Lễ
// L  🌙 OT Đêm ngày thường (ot_night_weekday_amount)
// M  🌙 OT Đêm cuối tuần (ot_night_weekend_amount)
// N  🌙 OT Đêm ngày lễ (ot_night_holiday_amount)
// O  Trang phục
// P  Điện thoại
// Q  Đi lại
// R  Hiệu quả (performance_bonus)
// S  Nhà ở (housing_received)
// T  Trách nhiệm (responsibility_allowance_received)
// U  Thâm niên (seniority_allowance_received)
// V  Độc hại (pit_adjustment)
// W  Chuyên cần (attendance_bonus)
// X  🌙 Phụ trội làm đêm (night_shift_bonus)
// Y  BHXH NV
// Z  Thuế TNCN
// AA Trừ muộn/sớm
// AB Thu nhập khác (other_income — tay)
// AC Thưởng hiệu suất (tay)
// AD Thưởng khác (tay)
// AE Tạm ứng
// AF Gross
// AG Trừ KPI
// AH Thực nhận
// AI Chuyển khoản
// AJ Ăn ca OT
// AK Ghi chú

$lastCol = 'AK';

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Bảng lương');

// ── Helper style ─────────────────────────────────────────────────────
$styleFn = fn($fg, $bg, $bold=true, $sz=9, $wrap=false, $halign=Alignment::HORIZONTAL_CENTER) => [
    'font'      => ['bold'=>$bold, 'size'=>$sz, 'color'=>['rgb'=>$fg]],
    'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$bg]],
    'alignment' => ['horizontal'=>$halign, 'vertical'=>Alignment::VERTICAL_CENTER, 'wrapText'=>$wrap],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'FFFFFF']]],
];

// ════════════════════════════════════════════════════════════════════
// DÒNG 1 — Tiêu đề
// ════════════════════════════════════════════════════════════════════
$sheet->mergeCells("A1:{$lastCol}1");
$sheet->setCellValue('A1',
    'BẢNG THANH TOÁN LƯƠNG — THÁNG ' . $period['period_month'] . '/' . $period['period_year']
);
$sheet->getStyle('A1')->applyFromArray($styleFn('FFFFFF','1a5276',true,14));
$sheet->getRowDimension(1)->setRowHeight(32);

// ════════════════════════════════════════════════════════════════════
// DÒNG 2 — Phụ đề
// ════════════════════════════════════════════════════════════════════
$sheet->mergeCells("A2:{$lastCol}2");
$sheet->setCellValue('A2',
    'Từ ' . date('d/m/Y', strtotime($period['period_from']))
    . ' đến ' . date('d/m/Y', strtotime($period['period_to']))
    . '   |   Ngày công chuẩn: ' . $period['working_days'] . ' ngày'
    . '   |   ' . count($slips) . ' nhân viên'
    . '   |   Xuất lúc: ' . date('d/m/Y H:i')
);
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['italic'=>true, 'size'=>9, 'color'=>['rgb'=>'555555']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(16);

// ════════════════════════════════════════════════════════════════════
// DÒNG 3 — Nhóm cột
// ════════════════════════════════════════════════════════════════════
$groups = [
    'A3:D3'   => ['',                    '2c3e50'],
    'E3:G3'   => ['NGÀY CÔNG',           '1f618d'],
    'H3:N3'   => ['LƯƠNG & OT',          '1a6b8a'],
    'O3:Q3'   => ['TRỢ CẤP',             '1e8449'],
    'R3:X3'   => ['PHỤ CẤP & THƯỞNG',   '9a7d0a'],
    'Y3:AA3'  => ['KHẤU TRỪ TỰ ĐỘNG',   '922b21'],
    'AB3:AE3' => ['ĐIỀU CHỈNH THỦ CÔNG','6e2f1a'],
    'AF3:AH3' => ['KẾT QUẢ',             '1e8449'],
    'AI3:AK3' => ['THÔNG TIN',           '555555'],
];
foreach ($groups as $range => [$label, $color]) {
    [$sc] = explode(':', $range);
    $sheet->mergeCells($range);
    $sheet->setCellValue($sc, $label);
    $sheet->getStyle($range)->applyFromArray($styleFn('FFFFFF', $color, true, 9));
}
$sheet->getRowDimension(3)->setRowHeight(18);

// ════════════════════════════════════════════════════════════════════
// DÒNG 4 — Header chi tiết
// ════════════════════════════════════════════════════════════════════
$headers = [
    'A'  => '#',
    'B'  => 'Mã NV',
    'C'  => 'Nhân viên',
    'D'  => 'Phòng ban',
    // Ngày công
    'E'  => 'Thực tế',
    'F'  => 'Nghỉ CL',
    'G'  => 'Nghỉ KL',
    // Lương & OT
    'H'  => 'Lương CB',
    'I'  => 'OT Thường',
    'J'  => 'OT CN',
    'K'  => 'OT Lễ',
    'L'  => '🌙 OT Đêm TT',
    'M'  => '🌙 OT Đêm CN',
    'N'  => '🌙 OT Đêm Lễ',
    // Trợ cấp
    'O'  => 'Trang phục',
    'P'  => 'Điện thoại',
    'Q'  => 'Đi lại',
    // Phụ cấp & Thưởng
    'R'  => 'Hiệu quả',
    'S'  => 'Nhà ở',
    'T'  => 'Trách nhiệm',
    'U'  => 'Thâm niên',
    'V'  => 'Độc hại',
    'W'  => 'Chuyên cần',
    'X'  => '🌙 Phụ trội đêm',
    // Khấu trừ tự động
    'Y'  => 'BHXH NV',
    'Z'  => 'Thuế TNCN',
    'AA' => 'Trừ muộn',
    // Điều chỉnh tay
    'AB' => 'Thu nhập khác',
    'AC' => 'Thưởng HS',
    'AD' => 'Thưởng khác',
    'AE' => 'Tạm ứng',
    // Kết quả
    'AF' => 'Gross',
    'AG' => 'Trừ KPI',
    'AH' => 'Thực nhận',
    // Thông tin
    'AI' => 'Chuyển khoản',
    'AJ' => 'Ăn ca OT',
    'AK' => 'Ghi chú',
];

foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '4', $label);
}
$sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
    'font'      => ['bold'=>true, 'size'=>9, 'color'=>['rgb'=>'FFFFFF']],
    'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>'2c3e50']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                    'vertical'  =>Alignment::VERTICAL_CENTER, 'wrapText'=>true],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                   'color'=>['rgb'=>'7f8c8d']]],
]);
$sheet->getRowDimension(4)->setRowHeight(32);

// ════════════════════════════════════════════════════════════════════
// DỮ LIỆU
// ════════════════════════════════════════════════════════════════════
$moneyFmt = '#,##0';
$numFmt   = '#,##0.0';

// Cột cần format tiền
$moneyCols = ['H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X',
              'Y','Z','AA','AB','AC','AD','AE','AF','AG','AH','AI','AJ'];

// Cột tổng cộng
$totalCols = array_fill_keys($moneyCols, 0);

$row = 5;
foreach ($slips as $i => $s) {
    $paidLeave = (float)$s['paid_leave_days'] + (float)$s['other_paid_leave_days'];
    $bgColor   = ($i % 2 === 0) ? 'f8f9fa' : 'ffffff';

    $data = [
        'A'  => $i + 1,
        'B'  => $s['employee_code'] ?? '',
        'C'  => $s['full_name'],
        'D'  => $s['department_name'] ?? '',
        // Ngày công
        'E'  => (float)$s['actual_workdays'],
        'F'  => $paidLeave,
        'G'  => (float)$s['unpaid_leave_days'],
        // Lương & OT
        'H'  => (float)$s['basic_salary_received'],
        'I'  => (float)$s['ot_weekday_amount'],
        'J'  => (float)$s['ot_weekend_amount'],
        'K'  => (float)$s['ot_holiday_amount'],
        'L'  => (float)($s['ot_night_weekday_amount'] ?? 0),
        'M'  => (float)($s['ot_night_weekend_amount'] ?? 0),
        'N'  => (float)($s['ot_night_holiday_amount'] ?? 0),
        // Trợ cấp
        'O'  => (float)$s['clothes_received'],
        'P'  => (float)$s['phone_received'],
        'Q'  => (float)$s['transport_received'],
        // Phụ cấp & Thưởng (tự động)
        'R'  => (float)$s['performance_bonus'],
        'S'  => (float)($s['housing_received'] ?? 0),
        'T'  => (float)($s['responsibility_allowance_received'] ?? 0),
        'U'  => (float)($s['seniority_allowance_received'] ?? 0),
        'V'  => (float)$s['pit_adjustment'],
        'W'  => (float)$s['attendance_bonus'],
        'X'  => (float)($s['night_shift_bonus'] ?? 0),
        // Khấu trừ tự động
        'Y'  => (float)$s['si_employee'],
        'Z'  => (float)$s['pit_amount'],
        'AA' => (float)$s['late_deduction'],
        // Điều chỉnh tay
        'AB' => (float)$s['other_income'],
        'AC' => (float)$s['performance_bonus'],
        'AD' => (float)$s['other_bonus'],
        'AE' => (float)$s['advance_payment'],
        // Kết quả
        'AF' => (float)$s['gross_salary'],
        'AG' => (float)$s['kpi_deduction'],
        'AH' => (float)$s['net_salary'],
        // Thông tin
        'AI' => (float)$s['bank_transfer'],
        'AJ' => (float)($s['ot_meal_bonus'] ?? 0),
        'AK' => $s['remark'] ?? '',
    ];

    foreach ($data as $col => $val) {
        $sheet->setCellValue($col . $row, $val);
    }

    // Cộng tổng
    foreach ($totalCols as $col => $_) {
        if (isset($data[$col]) && is_numeric($data[$col]))
            $totalCols[$col] += $data[$col];
    }

    // Style nền
    $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
        'fill'    => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$bgColor]],
        'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                     'color'=>['rgb'=>'dee2e6']]],
        'font'    => ['size'=>9],
    ]);

    // Format tiền
    foreach ($moneyCols as $col) {
        $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode($moneyFmt);
        $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
    // Format ngày công
    foreach (['E','F','G'] as $col) {
        $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode($numFmt);
        $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // Highlight thực nhận xanh đậm
    $sheet->getStyle("AH{$row}")->getFont()->setBold(true);
    $sheet->getStyle("AH{$row}")->getFont()->getColor()->setRGB('1e8449');

    // Nghỉ không lương → đỏ
    if ((float)$s['unpaid_leave_days'] > 0) {
        $sheet->getStyle("G{$row}")->getFont()->getColor()->setRGB('e74c3c');
        $sheet->getStyle("G{$row}")->getFont()->setBold(true);
    }
    // Về sớm → cam
    if ($s['is_late_warning']) {
        $sheet->getStyle("C{$row}")->getFont()->getColor()->setRGB('e67e22');
    }
    // OT đêm → tô màu tím nhấn mạnh
    foreach (['L','M','N','X'] as $col) {
        if ((float)($data[$col] ?? 0) > 0)
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('6c3483');
    }
    // Khấu trừ → đỏ
    foreach (['Y','Z','AA','AG'] as $col) {
        if ((float)($data[$col] ?? 0) > 0)
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('c0392b');
    }
    // Điều chỉnh tay → xanh lá
    foreach (['AB','AC','AD'] as $col) {
        if ((float)($data[$col] ?? 0) > 0)
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('1e8449');
    }
    // Tạm ứng → đỏ
    if ((float)$s['advance_payment'] > 0)
        $sheet->getStyle("AE{$row}")->getFont()->getColor()->setRGB('c0392b');

    $sheet->getRowDimension($row)->setRowHeight(16);
    $row++;
}

// ════════════════════════════════════════════════════════════════════
// DÒNG TỔNG CỘNG
// ════════════════════════════════════════════════════════════════════
$sheet->mergeCells("A{$row}:D{$row}");
$sheet->setCellValue("A{$row}", 'TỔNG CỘNG (' . count($slips) . ' nhân viên)');

foreach ($totalCols as $col => $val) {
    $sheet->setCellValue($col . $row, $val);
    $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode($moneyFmt);
}

$sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
    'font'      => ['bold'=>true, 'size'=>10, 'color'=>['rgb'=>'FFFFFF']],
    'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>'1a5276']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT,
                    'vertical'  =>Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_MEDIUM,
                                   'color'=>['rgb'=>'1a5276']]],
]);
$sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension($row)->setRowHeight(22);

// ════════════════════════════════════════════════════════════════════
// ĐỘ RỘNG CỘT
// ════════════════════════════════════════════════════════════════════
$colWidths = [
    'A'  =>  5,   'B'  => 10,   'C'  => 22,   'D'  => 15,
    'E'  =>  8,   'F'  =>  8,   'G'  =>  8,
    'H'  => 13,   'I'  => 11,   'J'  => 11,   'K'  => 11,
    'L'  => 12,   'M'  => 12,   'N'  => 12,
    'O'  => 11,   'P'  => 11,   'Q'  => 11,
    'R'  => 11,   'S'  => 11,   'T'  => 11,   'U'  => 11,
    'V'  => 11,   'W'  => 11,   'X'  => 12,
    'Y'  => 11,   'Z'  => 11,   'AA' => 11,
    'AB' => 13,   'AC' => 11,   'AD' => 11,   'AE' => 11,
    'AF' => 13,   'AG' => 11,   'AH' => 13,
    'AI' => 13,   'AJ' => 11,   'AK' => 28,
];
foreach ($colWidths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ════════════════════════════════════════════════════════════════════
// FREEZE & FILTER
// ════════════════════════════════════════════════════════════════════
$sheet->freezePane('E5');       // Ghim 4 cột đầu + 4 dòng header
$sheet->setAutoFilter("A4:{$lastCol}4");

// ════════════════════════════════════════════════════════════════════
// XUẤT FILE
// ════════════════════════════════════════════════════════════════════
$filename = 'BangLuong_' . $period['period_month'] . '_' . $period['period_year'] . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
