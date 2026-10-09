<?php

// Run with: php tests/payroll_manual_columns_test.php
set_error_handler(function (int $severity, string $message): never {
    throw new RuntimeException($message);
});

function manualColumnsEqual(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("$label: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

// Evaluate only array definitions and the table template from trusted repository files.
function manualExportArray(string $source, string $name, array $s = [], int $i = 0): array
{
    $paidLeave = (float)($s['paid_leave_days'] ?? 0);
    if (!preg_match('/\$' . preg_quote($name, '/') . '\s*=\s*(\[.*?\]);/s', $source, $match)) {
        throw new RuntimeException("Missing export array: $name");
    }
    return eval('return ' . $match[1] . ';');
}

$list = file_get_contents(__DIR__ . '/../modules/payroll/slip_list.php');
$export = file_get_contents(__DIR__ . '/../api/payroll/export_excel.php');
$fields = ['other_income','performance_bonus','other_bonus','adjustment','advance_payment','pit_adjustment'];
$labels = ['Thu nhập khác','Thưởng HS','Thưởng khác','Điều chỉnh +/-','Tạm ứng','Điều chỉnh PIT'];
preg_match_all('/\$s\[\'([a-z_]+)\'\]/', $list . $export, $keys);
$fixture = array_fill_keys(array_unique($keys[1]), 0);
$fixture['full_name'] = 'Test employee';
$fixture['remark'] = 'Test remark';
$slips = [
    array_replace($fixture, array_combine($fields, [100, 200, 300, -400, 500, -600])),
    array_replace($fixture, array_combine($fields, [10, 20, 30, 40, -50, 60])),
    $fixture,
];
$missing = $fixture;
foreach ($fields as $field) {
    unset($missing[$field]);
}
$slips[] = $missing;

// Render the actual table, including its totals, without authentication or a database.
$totalsStart = strpos($list, '$totalGross');
$totalsEnd = strpos($list, '$statusMap');
eval(substr($list, $totalsStart, $totalsEnd - $totalsStart));
$tableStart = strpos($list, '<table class="slip-table"');
$tableEnd = strpos($list, '</table>', $tableStart) + strlen('</table>');
ob_start();
eval('?>' . substr($list, $tableStart, $tableEnd - $tableStart));
$html = ob_get_clean();
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);
$group = $xpath->query('//thead/tr[1]/th[@class="grp-manual"]')->item(0);
manualColumnsEqual('6', $group->getAttribute('colspan'), 'Manual group span');
$manualHeaders = $xpath->query('//thead/tr[2]/th[@class="grp-manual"]');
manualColumnsEqual($labels, array_map(fn($node) => trim($node->textContent), iterator_to_array($manualHeaders)), 'Manual header order');
$columnCount = 0;
foreach ($xpath->query('//thead/tr[1]/th') as $header) {
    $columnCount += (int)($header->getAttribute('colspan') ?: 1);
}
$manualStart = $columnCount - 8;
foreach ($xpath->query('//tbody/tr') as $index => $row) {
    $cells = $row->getElementsByTagName('td');
    manualColumnsEqual($columnCount, $cells->length, "Body row $index column count");
    foreach ($fields as $offset => $field) {
        $cell = $cells->item($manualStart + $offset);
        $value = (float)($slips[$index][$field] ?? 0);
        manualColumnsEqual($value != 0 ? number_format($value) : '—', trim($cell->textContent), "$field row $index value");
        $danger = $value != 0 && ($field === 'advance_payment'
            || (in_array($field, ['adjustment', 'pit_adjustment'], true) && $value < 0));
        manualColumnsEqual($danger, str_contains($cell->getAttribute('class'), 'c-danger'), "$field row $index color");
        if ($value == 0) {
            manualColumnsEqual('c-muted', $cell->getElementsByTagName('span')->item(0)->getAttribute('class'), "$field zero color");
        }
    }
}
$footer = $xpath->query('//tfoot/tr/td');
manualColumnsEqual($columnCount, $footer->length, 'Footer column count');
foreach ($fields as $offset => $field) {
    manualColumnsEqual(number_format(array_sum(array_column($slips, $field))),
        trim($footer->item($manualStart + $offset)->textContent), "$field footer total");
}

$headers = manualExportArray($export, 'headers');
$widths = manualExportArray($export, 'colWidths');
$moneyCols = manualExportArray($export, 'moneyCols');
$groups = manualExportArray($export, 'groups');
$tailFields = array_merge($fields, ['gross_salary','kpi_deduction','net_salary','bank_transfer','ot_meal_bonus','remark']);
$tailLabels = array_merge($labels, ['Gross','Trừ KPI','Thực nhận','Chuyển khoản','Ăn ca OT','Ghi chú']);
$tailCols = ['AB','AC','AD','AE','AF','AG','AH','AI','AJ','AK','AL','AM'];
manualColumnsEqual(39, count($headers), 'Excel column count');
manualColumnsEqual(array_keys($headers), array_keys($widths), 'Widths cover every Excel column');
manualColumnsEqual(array_slice(array_keys($headers), 7, -1), $moneyCols, 'Money formats and totals cover H:AL');
manualColumnsEqual(true, str_contains($export, '$lastCol = \'AM\';'), 'Excel last column');
manualColumnsEqual(['ĐIỀU CHỈNH THỦ CÔNG','6e2f1a'], $groups['AB3:AG3'], 'Six-column manual merge');
manualColumnsEqual(['KẾT QUẢ','1e8449'], $groups['AH3:AJ3'], 'Shifted result merge');
manualColumnsEqual(['THÔNG TIN','555555'], $groups['AK3:AM3'], 'Shifted information merge');
$s = array_replace($slips[0], array_combine(array_slice($tailFields, 6), [700, 800, 900, 1000, 1100, 'Test remark']));
$data = manualExportArray($export, 'data', $s);
manualColumnsEqual(array_keys($headers), array_keys($data), 'Excel data matches headers');
foreach ($tailCols as $offset => $col) {
    manualColumnsEqual($tailLabels[$offset], $headers[$col], "$col header");
    $expected = $col === 'AM' ? $s[$tailFields[$offset]] : (float)$s[$tailFields[$offset]];
    manualColumnsEqual($expected, $data[$col], "$col value");
}
foreach (['adjustment' => 'AE', 'pit_adjustment' => 'AG'] as $field => $col) {
    $withoutAdjustment = $s;
    unset($withoutAdjustment[$field]);
    manualColumnsEqual(0.0, manualExportArray($export, 'data', $withoutAdjustment)[$col], "$field missing Excel value");
    $total = 0.0;
    foreach (array_slice($slips, 0, 3) as $slip) {
        $total += manualExportArray($export, 'data', $slip)[$col];
    }
    manualColumnsEqual((float)array_sum(array_column($slips, $field)), $total, "$field Excel total");
}

echo "Payroll manual column checks passed.\n";
