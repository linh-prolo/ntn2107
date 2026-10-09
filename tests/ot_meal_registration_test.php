<?php

// Run with: php tests/ot_meal_registration_test.php
set_error_handler(function (int $severity, string $message): never {
    throw new RuntimeException($message);
});

function mealRegistrationEqual(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("$label: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

foreach (['modules/attendance/ot_request.php', 'mobile/ot.php', 'modules/attendance/ot_manage.php'] as $path) {
    $source = file_get_contents(__DIR__ . '/../' . $path);
    // Exercise the validation and dropdown from the trusted endpoint source without auth/DB.
    if (!preg_match('/if \(!in_array\(\$otMealRegistered, \[\'0\', \'1\'\], true\)\) \{.*?\}/s', $source, $validation)) {
        throw new RuntimeException("$path: missing strict meal validation");
    }
    foreach (['0', '1', '', null, '2', 'yes', 0, 1, ['1']] as $value) {
        $otMealRegistered = $value;
        $errors = [];
        eval($validation[0]);
        mealRegistrationEqual(in_array($value, ['0', '1'], true), empty($errors), "$path validation");
    }
    if (!preg_match('/<select\b[^>]*name="ot_meal_registered"[^>]*>.*?<\/select>/s', $source, $dropdown)) {
        throw new RuntimeException("$path: missing meal dropdown");
    }
    $submittedValues = $path === 'modules/attendance/ot_manage.php' ? [''] : ['', '0', '1'];
    foreach ($submittedValues as $value) {
        $_POST = $value === '' ? [] : ['ot_meal_registered' => $value];
        ob_start();
        eval('?>' . $dropdown[0]);
        $html = ob_get_clean();
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $select = $dom->getElementsByTagName('select')->item(0);
        mealRegistrationEqual(true, $select->hasAttribute('required'), "$path required dropdown");
        $options = iterator_to_array($select->getElementsByTagName('option'));
        mealRegistrationEqual(['', '1', '0'], array_map(fn($option) => $option->getAttribute('value'), $options), "$path options");
        mealRegistrationEqual(['-- Chọn --', 'Có', 'Không'], array_map(fn($option) => trim($option->textContent), $options), "$path labels");
        $selected = array_values(array_filter($options, fn($option) => $option->hasAttribute('selected')));
        mealRegistrationEqual($value, ($selected[0] ?? $options[0])->getAttribute('value'), "$path selected meal");
    }
}

$source = file_get_contents(__DIR__ . '/../modules/attendance/import_ot.php');
if (!preg_match('/\$rawMeal = trim\(\$row\[\'E\'\] \?\? \'\'\);/', $source, $readMeal)
    || !preg_match('/if \(\$rawMeal === \'\'.*?(?=\s*\/\/ Parse date)/s', $source, $parseMeal)) {
    throw new RuntimeException('Import: missing meal parser');
}
foreach ([
    [[], 0], [['E' => ''], 0], [['E' => '  '], 0],
    [['E' => 'Có'], 1], [['E' => 'CÓ'], 1], [['E' => 'cÓ'], 1],
    [['E' => 'Không'], 0], [['E' => 'KHÔNG'], 0], [['E' => 'khÔNg'], 0],
    [['E' => ' Có '], 1], [['E' => '1'], 1], [['E' => '0'], 0],
    [['E' => 1], 1], [['E' => 0], 0],
    [['E' => 'yes'], null], [['E' => 'no'], null], [['E' => '2'], null],
    [['E' => 'co'], null], [['E' => 'khong'], null], [['E' => 'Có ăn'], null],
] as [$row, $expected]) {
    $otMealRegistered = null;
    $rowResult = ['status' => '', 'msg' => ''];
    $results = [];
    $failed = 0;
    eval($readMeal[0]);
    // Keep the endpoint's continue statement intact inside a single-iteration loop.
    eval('do {' . $parseMeal[0] . '} while (false);');
    $label = 'Import meal ' . json_encode($row, JSON_UNESCAPED_UNICODE);
    if ($expected === null) {
        mealRegistrationEqual('error', $rowResult['status'], "$label row error");
        mealRegistrationEqual(true, $rowResult['msg'] !== '', "$label error message");
        mealRegistrationEqual(1, $failed, "$label failed count");
        mealRegistrationEqual([$rowResult], $results, "$label error result");
    } else {
        mealRegistrationEqual($expected, $otMealRegistered, "$label parsed value");
        mealRegistrationEqual(0, $failed, "$label failed count");
        mealRegistrationEqual([], $results, "$label no error result");
    }
}

$source = file_get_contents(__DIR__ . '/../modules/attendance/ot_manage.php');
if (!preg_match('/if \(\$action === \'director_edit_hours\'\).*?(?=\/\/ ── Duyệt 1 đơn)/s', $source, $editHours)
    || !preg_match_all('/UPDATE overtime_requests SET .*?WHERE id = \?/s', $editHours[0], $updates)
    || !preg_match('/if \((!in_array\(\$otMealRegistered, \[\'0\', \'1\'\], true\))\) \{/', $editHours[0], $editValidation)) {
    throw new RuntimeException('Director edit: missing hours update SQL');
}
mealRegistrationEqual(1, count($updates[0]), 'Director edit hours update count');
mealRegistrationEqual(true, str_contains($updates[0][0], 'ot_meal_registered = ?'), 'Director edit updates meal registration');
mealRegistrationEqual(true, str_contains($editHours[0], 'SELECT user_id, ot_date, status, ot_meal_registered'), 'Director edit reads stored meal registration');
foreach (['0', '1', '', null, '2', 'yes', 0, 1, ['1']] as $value) {
    $otMealRegistered = $value;
    eval('$editValidationFailed = ' . $editValidation[1] . ';');
    mealRegistrationEqual(!in_array($value, ['0', '1'], true), $editValidationFailed, 'Director edit meal validation');
}
mealRegistrationEqual(3, substr_count($source, 'htmlspecialchars(json_encode((string)($ot["ot_meal_registered"] ?? 0)), ENT_QUOTES, "UTF-8")'), 'Director edit buttons pass existing meal registration');
mealRegistrationEqual(true, str_contains($source, 'function showEditHours(id, name, startTime, endTime, hours, otType, otMealRegistered)'), 'Director edit JS receives meal registration');
mealRegistrationEqual(true, str_contains($source, "document.getElementById('editHoursMeal').value = String(otMealRegistered ?? 0);"), 'Director edit JS selects existing meal registration');
mealRegistrationEqual(true, str_contains($source, 'if ((int)$otRow[\'ot_meal_registered\'] !== (int)$otMealRegistered)'), 'Director edit only notifies on meal changes');
mealRegistrationEqual(true, str_contains($source, 'Đăng ký ăn tăng ca: '), 'Director edit notification includes changed meal');

echo "OT meal registration checks passed.\n";
