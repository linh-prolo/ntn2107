<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

requireRole('director', 'accountant', 'manager', 'production');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$pdo = getDBConnection();

$viewMonth  = (int)($_GET['month']   ?? date('m'));
$viewYear   = (int)($_GET['year']    ?? date('Y'));
$filterDept = (int)($_GET['dept']    ?? 0);
$filterUser = (int)($_GET['user_id'] ?? 0);

if ($viewMonth < 1)  { $viewMonth = 12; $viewYear--; }
if ($viewMonth > 12) { $viewMonth = 1;  $viewYear++; }

$daysInMon = (int) date('t', mktime(0, 0, 0, $viewMonth, 1, $viewYear));

// ── Nhân viên ────────────────────────────────────────────────────────────────
$empSQL = "SELECT u.id, u.full_name, u.employee_code, d.name AS dept_name,
                  ws.shift_name
           FROM users u
           LEFT JOIN departments d ON u.department_id = d.id
           LEFT JOIN roles r ON u.role_id = r.id
           LEFT JOIN employee_shifts es ON es.id = (
               SELECT id FROM employee_shifts es2
               WHERE es2.user_id = u.id
                 AND es2.effective_date <= LAST_DAY(?)
                 AND (es2.end_date IS NULL OR es2.end_date >= ?)
               ORDER BY es2.effective_date DESC
               LIMIT 1
           )
           LEFT JOIN work_shifts ws ON es.shift_id = ws.id
           WHERE u.is_active = 1 AND r.name != 'director'";
$periodStart = "$viewYear-$viewMonth-01";
$empParams = [$periodStart, $periodStart];

if ($filterDept) { $empSQL .= " AND u.department_id = ?"; $empParams[] = $filterDept; }
if ($filterUser) { $empSQL .= " AND u.id = ?";            $empParams[] = $filterUser; }
$empSQL .= " ORDER BY u.employee_code";

$empStmt = $pdo->prepare($empSQL);
$empStmt->execute($empParams);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Chấm công ────────────────────────────────────────────────────────────────
$attStmt = $pdo->prepare("
    SELECT al.*
    FROM attendance_logs al
    WHERE MONTH(al.work_date) = ? AND YEAR(al.work_date) = ?
    ORDER BY al.work_date
");
$attStmt->execute([$viewMonth, $viewYear]);
$attMap = [];
foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $attMap[$a['user_id']][$a['work_date']] = $a;
}

// ── Nghỉ phép ────────────────────────────────────────────────────────────────
$leaveStmt = $pdo->prepare("
    SELECT user_id, start_date, end_date, leave_type
    FROM leave_requests
    WHERE status = 'approved'
      AND ((MONTH(start_date)=? AND YEAR(start_date)=?)
        OR (MONTH(end_date)=?   AND YEAR(end_date)=?))
");
$leaveStmt->execute([$viewMonth, $viewYear, $viewMonth, $viewYear]);
$leaveMap = [];
foreach ($leaveStmt->fetchAll(PDO::FETCH_ASSOC) as $lv) {
    $s = strtotime($lv['start_date']);
    $e = strtotime($lv['end_date']);
    for ($d = $s; $d <= $e; $d += 86400) {
        $leaveMap[$lv['user_id']][date('Y-m-d', $d)] = $lv['leave_type'];
    }
}

// ── OT ───────────────────────────────────────────────────────────────────────
$otStmt = $pdo->prepare("
    SELECT user_id, ot_date, hours, ot_type
    FROM overtime_requests
    WHERE status = 'approved'
      AND MONTH(ot_date) = ? AND YEAR(ot_date) = ?
");
$otStmt->execute([$viewMonth, $viewYear]);
$otMap = [];
foreach ($otStmt->fetchAll(PDO::FETCH_ASSOC) as $ot) {
    $otMap[$ot['user_id']][$ot['ot_date']] = $ot;
}

// ── Ngày lễ ──────────────────────────────────────────────────────────────────
$holidays = $pdo->prepare("SELECT holiday_date FROM holidays WHERE MONTH(holiday_date)=? AND YEAR(holiday_date)=?");
$holidays->execute([$viewMonth, $viewYear]);
$holidayDates = array_column($holidays->fetchAll(PDO::FETCH_ASSOC), 'holiday_date');

// ── Hàm tính thống kê ────────────────────────────────────────────────────────
function calcStatsExport($userId, $attMap, $leaveMap, $otMap, $viewMonth, $viewYear, $daysInMon, $holidayDates) {
    $stats = [
        'work_days'=>0,'absent_days'=>0,'leave_days'=>0,
        'late_count'=>0,'late_minutes'=>0,
        'early_count'=>0,'early_minutes'=>0,
        'total_hours'=>0,
        'ot_hours'=>0,'ot_weekday'=>0,'ot_weekend'=>0,'ot_holiday'=>0,
        'sunday_work'=>0,
        'missing_checkout_count'=>0,
        'total_deduct_minutes'=>0,
    ];
    for ($d = 1; $d <= $daysInMon; $d++) {
        $dateStr  = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $d);
        $dow      = date('N', strtotime($dateStr));
        $isSun    = ($dow == 7);
        $isFuture = ($dateStr > date('Y-m-d'));
        if ($isFuture) continue;

        $att   = $attMap[$userId][$dateStr]   ?? null;
        $leave = $leaveMap[$userId][$dateStr] ?? null;
        $ot    = $otMap[$userId][$dateStr]    ?? null;

        if ($ot) {
            $stats['ot_hours'] += $ot['hours'];
            if ($ot['ot_type'] === 'holiday')     $stats['ot_holiday'] += $ot['hours'];
            elseif ($ot['ot_type'] === 'weekend') $stats['ot_weekend'] += $ot['hours'];
            else                                  $stats['ot_weekday'] += $ot['hours'];
        }

        if ($isSun) {
            if ($att && $att['check_in']) $stats['sunday_work']++;
            continue;
        }

        if ($leave && !$att) {
            $stats['leave_days']++;
        } elseif ($att && $att['check_in']) {
            $stats['work_days']++;
            $stats['total_hours'] += $att['work_hours'];
            if ($att['is_late']) {
                $stats['late_count']++;
                $stats['late_minutes'] += $att['late_minutes'];
            }
            if ($att['early_leave']) {
                $stats['early_count']++;
                $stats['early_minutes'] += $att['early_leave_minutes'] ?? 0;
            }
            if (!empty($att['missing_checkout'])) {
                $stats['missing_checkout_count']++;
            }
        } else {
            $stats['absent_days']++;
        }
    }
    $stats['total_deduct_minutes'] = $stats['late_minutes'] + $stats['early_minutes'];
    return $stats;
}

// date('N') returns 1 (Mon) … 7 (Sun); index 0 is unused placeholder
$dowNames = [0 => '', 1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];

$sourceLabels = [
    'device'  => 'Máy ZKTeco',
    'machine' => 'Máy chấm',
    'manual'  => 'Thủ công',
    'web'     => 'Web/App',
    'system'  => 'Hệ thống',
];

// ════════════════════════════════════════════════════════════════════════════════
// TẠO SPREADSHEET
// ════════════════════════════════════════════════════════════════════════════════
$spreadsheet = new Spreadsheet();

$headerStyle = [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1e3a5f']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color' => ['rgb' => 'FFFFFF']]],
];

// ════════════════════════════════════════════════════════════════════════════════
// SHEET 1: TỔNG HỢP
// ════════════════════════════════════════════════════════════════════════════════
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle("Tổng hợp T{$viewMonth}/{$viewYear}");

$lastColS1 = 'T';

// Dòng 1: Tiêu đề
$sheet1->mergeCells("A1:{$lastColS1}1");
$sheet1->setCellValue('A1', "BẢNG TỔNG HỢP CHẤM CÔNG THÁNG {$viewMonth}/{$viewYear}");
$sheet1->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1e3a5f']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$sheet1->getRowDimension(1)->setRowHeight(32);

// Dòng 2: Header
$headers1 = [
    'A' => 'STT',
    'B' => 'Mã NV',
    'C' => 'Họ tên',
    'D' => 'Phòng ban',
    'E' => 'Ca làm việc',
    'F' => 'Ngày công',
    'G' => 'Giờ làm (h)',
    'H' => 'Nghỉ phép',
    'I' => 'Vắng',
    'J' => 'Đi trễ (lần)',
    'K' => 'Phút trễ',
    'L' => 'Về sớm (lần)',
    'M' => 'Phút về sớm',
    'N' => 'Tổng phút trừ',
    'O' => 'OT thường (h)',
    'P' => 'OT T7/CN (h)',
    'Q' => 'OT lễ (h)',
    'R' => 'Tổng OT (h)',
    'S' => 'Làm CN (ngày)',
    'T' => 'Quên chấm ra',
];
foreach ($headers1 as $col => $label) {
    $sheet1->setCellValue($col . '2', $label);
}
$sheet1->getStyle("A2:{$lastColS1}2")->applyFromArray($headerStyle);
$sheet1->getRowDimension(2)->setRowHeight(36);

// Dữ liệu
$row = 3;
$stt = 0;
$prevDept = null;
$grandTotals = array_fill_keys([
    'work_days','total_hours','leave_days','absent_days',
    'late_count','late_minutes','early_count','early_minutes',
    'total_deduct_minutes','ot_weekday','ot_weekend','ot_holiday',
    'ot_hours','sunday_work','missing_checkout_count',
], 0);

foreach ($employees as $emp) {
    // Dòng phòng ban
    if ($emp['dept_name'] !== $prevDept) {
        $prevDept = $emp['dept_name'];
        $sheet1->mergeCells("A{$row}:{$lastColS1}{$row}");
        $sheet1->setCellValue("A{$row}", $emp['dept_name'] ?? 'Chưa phân phòng ban');
        $sheet1->getStyle("A{$row}:{$lastColS1}{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '333333']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'e2e8f0']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                            'vertical'   => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                             'color' => ['rgb' => 'cbd5e1']]],
        ]);
        $sheet1->getRowDimension($row)->setRowHeight(18);
        $row++;
    }

    $st = calcStatsExport($emp['id'], $attMap, $leaveMap, $otMap, $viewMonth, $viewYear, $daysInMon, $holidayDates);
    foreach ($grandTotals as $k => $_) $grandTotals[$k] += $st[$k];

    $stt++;
    $bgColor = ($stt % 2 === 0) ? 'f8f9fa' : 'ffffff';

    $data = [
        'A' => $stt,
        'B' => $emp['employee_code'] ?? '',
        'C' => $emp['full_name'],
        'D' => $emp['dept_name'] ?? '',
        'E' => $emp['shift_name'] ?? '',
        'F' => $st['work_days'],
        'G' => round($st['total_hours'], 1),
        'H' => $st['leave_days'],
        'I' => $st['absent_days'],
        'J' => $st['late_count'],
        'K' => $st['late_minutes'],
        'L' => $st['early_count'],
        'M' => $st['early_minutes'],
        'N' => $st['total_deduct_minutes'],
        'O' => round($st['ot_weekday'], 1),
        'P' => round($st['ot_weekend'], 1),
        'Q' => round($st['ot_holiday'], 1),
        'R' => round($st['ot_hours'], 1),
        'S' => $st['sunday_work'],
        'T' => $st['missing_checkout_count'],
    ];

    foreach ($data as $col => $val) {
        $sheet1->setCellValue($col . $row, $val);
    }

    $sheet1->getStyle("A{$row}:{$lastColS1}{$row}")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                         'color' => ['rgb' => 'dee2e6']]],
        'font'      => ['size' => 9],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    // Căn giữa số
    foreach (array_keys($data) as $col) {
        if ($col !== 'C' && $col !== 'D' && $col !== 'E') {
            $sheet1->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    // Tô đỏ nhẹ nếu có vắng
    if ($st['absent_days'] > 0) {
        $sheet1->getStyle("I{$row}")->getFont()->getColor()->setRGB('dc2626');
        $sheet1->getStyle("I{$row}")->getFont()->setBold(true);
    }
    // Tô vàng nếu có trễ
    if ($st['late_count'] > 0) {
        $sheet1->getStyle("J{$row}")->getFont()->getColor()->setRGB('d97706');
    }

    $sheet1->getRowDimension($row)->setRowHeight(16);
    $row++;
}

// Dòng tổng cộng
$sheet1->mergeCells("A{$row}:E{$row}");
$sheet1->setCellValue("A{$row}", 'TỔNG CỘNG (' . count($employees) . ' nhân viên)');
$totals1 = [
    'F' => $grandTotals['work_days'],
    'G' => round($grandTotals['total_hours'], 1),
    'H' => $grandTotals['leave_days'],
    'I' => $grandTotals['absent_days'],
    'J' => $grandTotals['late_count'],
    'K' => $grandTotals['late_minutes'],
    'L' => $grandTotals['early_count'],
    'M' => $grandTotals['early_minutes'],
    'N' => $grandTotals['total_deduct_minutes'],
    'O' => round($grandTotals['ot_weekday'], 1),
    'P' => round($grandTotals['ot_weekend'], 1),
    'Q' => round($grandTotals['ot_holiday'], 1),
    'R' => round($grandTotals['ot_hours'], 1),
    'S' => $grandTotals['sunday_work'],
    'T' => $grandTotals['missing_checkout_count'],
];
foreach ($totals1 as $col => $val) {
    $sheet1->setCellValue($col . $row, $val);
}
$sheet1->getStyle("A{$row}:{$lastColS1}{$row}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '000000']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'fef9c3']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM,
                                     'color' => ['rgb' => 'ca8a04']]],
]);
$sheet1->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet1->getRowDimension($row)->setRowHeight(20);

// Độ rộng cột sheet 1
$colWidths1 = [
    'A' =>  5, 'B' => 10, 'C' => 25, 'D' => 18, 'E' => 14,
    'F' =>  9, 'G' =>  9, 'H' =>  9, 'I' =>  8,
    'J' => 10, 'K' =>  9, 'L' => 10, 'M' => 11,
    'N' => 12, 'O' => 12, 'P' => 11, 'Q' =>  9,
    'R' => 10, 'S' => 12, 'T' => 12,
];
foreach ($colWidths1 as $col => $w) {
    $sheet1->getColumnDimension($col)->setWidth($w);
}

$sheet1->freezePane('F3');

// ════════════════════════════════════════════════════════════════════════════════
// SHEET 2: CHI TIẾT
// ════════════════════════════════════════════════════════════════════════════════
$spreadsheet->createSheet();
$sheet2 = $spreadsheet->getSheet(1);
$sheet2->setTitle("Chi tiết T{$viewMonth}/{$viewYear}");

$lastColS2 = 'O';

// Dòng 1: Tiêu đề
$sheet2->mergeCells("A1:{$lastColS2}1");
$sheet2->setCellValue('A1', "BẢNG CHI TIẾT CHẤM CÔNG THÁNG {$viewMonth}/{$viewYear}");
$sheet2->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1e3a5f']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$sheet2->getRowDimension(1)->setRowHeight(32);

// Dòng 2: Header
$headers2 = [
    'A' => 'STT',
    'B' => 'Mã NV',
    'C' => 'Họ tên',
    'D' => 'Phòng ban',
    'E' => 'Ngày',
    'F' => 'Thứ',
    'G' => 'Giờ vào',
    'H' => 'Giờ ra',
    'I' => 'Số giờ',
    'J' => 'Đi trễ',
    'K' => 'Phút trễ',
    'L' => 'Về sớm',
    'M' => 'Phút về sớm',
    'N' => 'Nguồn',
    'O' => 'Ghi chú',
];
foreach ($headers2 as $col => $label) {
    $sheet2->setCellValue($col . '2', $label);
}
$sheet2->getStyle("A2:{$lastColS2}2")->applyFromArray($headerStyle);
$sheet2->getRowDimension(2)->setRowHeight(36);

// Dữ liệu chi tiết
$row2 = 3;
$stt2 = 0;
foreach ($employees as $emp) {
    for ($d = 1; $d <= $daysInMon; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $d);
        $att = $attMap[$emp['id']][$dateStr] ?? null;

        if (!$att || !$att['check_in']) continue;

        $dow    = (int) date('N', strtotime($dateStr));
        $isSun  = ($dow == 7);

        $stt2++;
        $checkIn  = date('H:i', strtotime($att['check_in']));
        $checkOut = $att['check_out'] ? date('H:i', strtotime($att['check_out'])) : '';
        $source   = $sourceLabels[$att['source'] ?? ''] ?? ($att['source'] ?? '');

        $data2 = [
            'A' => $stt2,
            'B' => $emp['employee_code'] ?? '',
            'C' => $emp['full_name'],
            'D' => $emp['dept_name'] ?? '',
            'E' => $dateStr,
            'F' => $dowNames[$dow] ?? '',
            'G' => $checkIn,
            'H' => $checkOut,
            'I' => round($att['work_hours'] ?? 0, 1),
            'J' => $att['is_late'] ? 'Có' : '',
            'K' => $att['is_late'] ? (int)$att['late_minutes'] : '',
            'L' => $att['early_leave'] ? 'Có' : '',
            'M' => $att['early_leave'] ? (int)($att['early_leave_minutes'] ?? 0) : '',
            'N' => $source,
            'O' => $att['note'] ?? '',
        ];

        foreach ($data2 as $col => $val) {
            $sheet2->setCellValue($col . $row2, $val);
        }

        // Background: CN → tím nhạt, trễ → vàng nhạt, mặc định xen kẽ
        if ($isSun) {
            $bgColor2 = 'f3e8ff';
        } elseif ($att['is_late']) {
            $bgColor2 = 'fffbeb';
        } else {
            $bgColor2 = ($stt2 % 2 === 0) ? 'f8f9fa' : 'ffffff';
        }

        $sheet2->getStyle("A{$row2}:{$lastColS2}{$row2}")->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor2]],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                             'color' => ['rgb' => 'dee2e6']]],
            'font'      => ['size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Căn giữa các cột số / thứ
        foreach (['A','B','F','G','H','I','J','K','L','M'] as $col) {
            $sheet2->getStyle($col . $row2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet2->getRowDimension($row2)->setRowHeight(16);
        $row2++;
    }
}

// Độ rộng cột sheet 2
$colWidths2 = [
    'A' =>  5, 'B' => 10, 'C' => 25, 'D' => 18,
    'E' => 12, 'F' =>  7, 'G' =>  9, 'H' =>  9,
    'I' =>  8, 'J' =>  8, 'K' =>  9, 'L' =>  9,
    'M' => 11, 'N' => 14, 'O' => 25,
];
foreach ($colWidths2 as $col => $w) {
    $sheet2->getColumnDimension($col)->setWidth($w);
}

$sheet2->freezePane('E3');

// ════════════════════════════════════════════════════════════════════════════════
// XUẤT FILE
// ════════════════════════════════════════════════════════════════════════════════
$spreadsheet->setActiveSheetIndex(0);

$filename = 'ChamCong_T' . $viewMonth . '_' . $viewYear . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
