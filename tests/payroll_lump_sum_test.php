<?php

// Run with: php tests/payroll_lump_sum_test.php
require_once __DIR__ . '/../modules/payroll/engine/PayrollEngine.php';

final class PayrollFixturePDO extends PDO
{
    public array $errors = [];
    public array $executed = [];
    private array $fixture;

    public function __construct(array $fixture)
    {
        $this->fixture = $fixture;
    }

    private static function normalize(string $sql): string
    {
        return preg_replace('/\s*([()])\s*/', '$1', trim(preg_replace('/\s+/', ' ', $sql)));
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // Exact whitelist: unknown SQL must fail even when the engine catches Throwable.
        $queries = [
            'period' => 'SELECT * FROM payroll_periods WHERE id = ?',
            'profile' => 'SELECT * FROM employee_profiles WHERE user_id = ?',
            'salary' => "SELECT es.id, es.component_id, es.custom_name, es.custom_name_en,
                es.amount, es.component_type, es.approval_status,
                sc.component_code, sc.component_name, sc.component_name_en AS sc_name_en,
                sc.component_type AS sc_type FROM employee_salaries es
                LEFT JOIN salary_components sc ON es.component_id = sc.id
                WHERE es.user_id = ? AND es.is_active = 1
                ORDER BY CASE WHEN es.approval_status = 'approved' THEN 0 ELSE 1 END ASC, es.id ASC",
            'holidays' => 'SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?',
            'leave' => "SELECT COALESCE(SUM(CASE WHEN leave_type = 'annual' THEN
                DATEDIFF(LEAST(end_date, :to1), GREATEST(start_date, :from1)) + 1
                ELSE 0 END), 0) AS paid_leave_days,
                0 AS other_paid_leave_days,
                COALESCE(SUM(CASE WHEN leave_type IN ('sick', 'unpaid', 'other') THEN
                DATEDIFF(LEAST(end_date, :to2), GREATEST(start_date, :from2)) + 1
                ELSE 0 END), 0) AS unpaid_leave_days
                FROM leave_requests WHERE user_id = :uid AND status = 'approved'
                AND start_date <= :to4 AND end_date >= :from4",
            'ot' => "SELECT
                COALESCE(SUM(CASE WHEN ot_type = 'weekday' THEN hours ELSE 0 END), 0) AS ot_weekday_hours,
                COALESCE(SUM(CASE WHEN ot_type = 'weekend' THEN hours ELSE 0 END), 0) AS ot_weekend_hours,
                COALESCE(SUM(CASE WHEN ot_type = 'holiday' THEN hours ELSE 0 END), 0) AS ot_holiday_hours,
                COALESCE(SUM(CASE WHEN ot_type = 'night_weekday' THEN hours ELSE 0 END), 0) AS ot_night_weekday_hours,
                COALESCE(SUM(CASE WHEN ot_type = 'night_weekend' THEN hours ELSE 0 END), 0) AS ot_night_weekend_hours,
                COALESCE(SUM(CASE WHEN ot_type = 'night_holiday' THEN hours ELSE 0 END), 0) AS ot_night_holiday_hours
                FROM overtime_requests WHERE user_id = ? AND status = 'approved' AND ot_date BETWEEN ? AND ?",
            'kpi' => 'SELECT kr.salary_actual, kr.salary_per_day, kr.is_deducted, ka.assign_date
                FROM kpi_results kr JOIN kpi_assignments ka ON kr.kpi_assignment_id = ka.id
                WHERE ka.user_id = ? AND ka.assign_date BETWEEN ? AND ?',
            'annual' => 'SELECT date_joined, annual_leave_total FROM employee_profiles WHERE user_id = ?',
            'used' => "SELECT COALESCE(SUM(total_days), 0) FROM leave_requests
                WHERE user_id = ? AND status = 'approved'
                AND leave_type = 'annual' AND YEAR(start_date) = ?",
            'night' => 'SELECT COUNT(*) FROM employee_shifts es JOIN work_shifts ws ON es.shift_id = ws.id
                WHERE es.user_id = ? AND ws.is_night_shift = 1 AND es.effective_date <= ?
                AND (es.end_date IS NULL OR es.end_date >= ?)',
            'night_ranges' => 'SELECT es.effective_date, COALESCE(es.end_date, :to1) AS end_date
                FROM employee_shifts es JOIN work_shifts ws ON es.shift_id = ws.id
                WHERE es.user_id = :uid AND ws.is_night_shift = 1 AND es.effective_date <= :to2
                AND (es.end_date IS NULL OR es.end_date >= :from1) ORDER BY es.effective_date ASC',
            'ot_meal' => "SELECT COUNT(*) AS meal_days FROM (
                SELECT ot_date, SUM(hours) AS total_hours FROM overtime_requests
                WHERE user_id = ? AND status = 'approved' AND ot_date BETWEEN ? AND ?
                AND DAYOFWEEK(ot_date) != 1 AND ot_date NOT IN ('0000-00-00')
                GROUP BY ot_date HAVING total_hours >= ?) AS daily_ot",
        ];
        $nonHoliday = empty($this->fixture['profile']['resignation_date'])
            ? " AND work_date NOT IN ('0000-00-00')" : '';
        $queries['attendance'] = "SELECT COUNT(CASE WHEN check_in IS NOT NULL
            AND DAYOFWEEK(work_date) != 1 $nonHoliday THEN 1 END) AS actual_workdays,
            COALESCE(SUM(CASE WHEN is_late = 1 AND DAYOFWEEK(work_date) != 1
            $nonHoliday THEN late_minutes ELSE 0 END), 0) AS total_late_minutes,
            COALESCE(SUM(CASE WHEN early_leave = 1 AND DAYOFWEEK(work_date) != 1
            $nonHoliday THEN early_leave_minutes ELSE 0 END), 0) AS total_early_minutes,
            GROUP_CONCAT(CASE WHEN (is_late = 1 OR early_leave = 1)
            AND DAYOFWEEK(work_date) != 1 $nonHoliday THEN CONCAT(
            DATE_FORMAT(work_date, '%%d/%%m'),
            CASE WHEN is_late = 1 AND early_leave = 1 THEN '(T+S)'
            WHEN is_late = 1 THEN '(T)' ELSE '(S)' END) END
            ORDER BY work_date SEPARATOR ', ') AS late_early_dates
            FROM attendance_logs WHERE user_id = ? AND work_date BETWEEN ? AND ?";

        foreach ($queries as $kind => $sql) {
            if (self::normalize($query) === self::normalize($sql)) {
                return new PayrollFixtureStatement($this, $kind);
            }
        }
        $this->reject('Unrecognized SQL: ' . self::normalize($query));
    }

    public function reject(string $message): never
    {
        $this->errors[] = $message;
        throw new RuntimeException($message);
    }

    public function rows(string $kind, ?array $params): array
    {
        $this->executed[] = $kind;
        [$from, $to] = $this->fixture['bounds'];
        $expected = match ($kind) {
            'period' => [7],
            'profile', 'salary', 'annual' => [42],
            'used' => [42, 2026],
            'holidays' => [$from, $to],
            'night' => [42, $to, $from],
            'night_ranges' => [':uid' => 42, ':to1' => $to, ':to2' => $to, ':from1' => $from],
            'leave' => [
                ':uid' => 42,
                ':from1' => $from, ':to1' => $to, ':from2' => $from, ':to2' => $to,
                ':from4' => $from, ':to4' => $to,
            ],
            'ot_meal' => [42, $from, $to, 3.0],
            default => [42, $from, $to],
        };
        if ($params !== $expected) {
            $this->reject("$kind parameters: expected " . json_encode($expected) . ', got ' . json_encode($params));
        }
        return match ($kind) {
            'period' => [$this->fixture['period']],
            'profile', 'annual' => [$this->fixture['profile']],
            'salary' => $this->fixture['salary'],
            'attendance' => [$this->fixture['attendance']],
            'leave' => [$this->fixture['leaveData'] ?? [
                'paid_leave_days' => 0, 'other_paid_leave_days' => 0, 'unpaid_leave_days' => 0,
            ]],
            'ot' => [[
                'ot_weekday_hours' => 0, 'ot_weekend_hours' => 0, 'ot_holiday_hours' => 0,
                'ot_night_weekday_hours' => 0, 'ot_night_weekend_hours' => 0, 'ot_night_holiday_hours' => 0,
            ]],
            'kpi' => $this->fixture['kpi'],
            'used', 'night', 'ot_meal' => [[0]],
            'holidays', 'night_ranges' => [],
        };
    }
}

final class PayrollFixtureStatement extends PDOStatement
{
    private PayrollFixturePDO $pdo;
    private string $kind;
    private array $rows = [];
    private int $position = 0;

    public function __construct(PayrollFixturePDO $pdo, string $kind)
    {
        $this->pdo = $pdo;
        $this->kind = $kind;
    }

    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->rows($this->kind, $params);
        $this->position = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->position++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);
        return $mode === PDO::FETCH_COLUMN
            ? array_map(static fn(array $row) => array_values($row)[$args[0] ?? 0], $rows) : $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
}

function payrollFixture(?int $flag = 1): array
{
    $profile = [
        'user_id' => 42, 'date_joined' => '2025-01-01', 'resignation_date' => null,
        'annual_leave_total' => 12, 'has_social_insurance' => 0, 'dependants' => 0,
    ];
    if ($flag !== null) {
        $profile['is_lump_sum'] = $flag;
    }
    $components = [
        'basic' => 20_000_000, 'meal' => 1_000_000, 'clothes' => 500_000,
        'phone' => 500_000, 'transport' => 1_000_000, 'housing' => 2_000_000,
        'responsibility' => 1_000_000, 'seniority' => 500_000,
        'performance' => 1_000_000, 'attendance_bonus' => 1_000_000,
        'custom_allowance' => 1_500_000,
    ];
    $salary = [];
    foreach ($components as $code => $amount) {
        $id = count($salary) + 1;
        $salary[] = [
            'id' => $id, 'component_id' => $id, 'component_code' => $code, 'amount' => $amount,
            'component_type' => in_array($code, ['performance', 'attendance_bonus']) ? 'bonus' : 'earning',
            'approval_status' => 'approved',
        ];
    }
    return [
        'period' => ['id' => 7, 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
            'period_year' => 2026, 'working_days' => 99],
        'profile' => $profile, 'salary' => $salary,
        'bounds' => ['2026-06-01', '2026-06-30'],
        'attendance' => ['actual_workdays' => 0, 'total_late_minutes' => 0,
            'total_early_minutes' => 0, 'late_early_dates' => ''],
        'leaveData' => ['paid_leave_days' => 0, 'other_paid_leave_days' => 0, 'unpaid_leave_days' => 0],
        'kpi' => [],
    ];
}

function payrollEqual(mixed $expected, mixed $actual, string $label): void
{
    $equal = is_numeric($expected) && is_numeric($actual)
        ? abs((float)$expected - (float)$actual) < 0.000001 : $expected === $actual;
    if (!$equal) {
        throw new RuntimeException("$label: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function payrollCalculate(array $fixture): array
{
    $pdo = new PayrollFixturePDO($fixture);
    $engine = new PayrollEngine($pdo);
    $result = $engine->calculate(7, 42);
    payrollEqual([], $pdo->errors, 'No swallowed fixture SQL/parameter errors');
    return [$result, $engine, $pdo];
}

function payrollFullContract(array $result): void
{
    foreach ([
        'gross_salary' => 30_000_000, 'basic_salary_received' => 20_000_000,
        'meal_received' => 1_000_000, 'clothes_received' => 500_000, 'phone_received' => 500_000,
        'transport_received' => 1_000_000, 'housing_received' => 2_000_000,
        'responsibility_allowance_received' => 1_000_000, 'seniority_allowance_received' => 500_000,
        'performance_bonus' => 1_000_000, 'attendance_bonus' => 1_000_000,
        'other_income' => 1_500_000, 'attendance_bonus_eligible' => 1,
    ] as $field => $expected) {
        payrollEqual($expected, $result[$field], $field);
    }
}

function payrollPenaltyFixture(?int $flag): array
{
    $fixture = payrollFixture($flag);
    $fixture['attendance'] = [
        'actual_workdays' => 13, 'total_late_minutes' => 36,
        'total_early_minutes' => 10, 'late_early_dates' => '02/06(T), 03/06(S)',
    ];
    $fixture['kpi'] = [
        ['salary_per_day' => 1_000_000, 'salary_actual' => 800_000, 'is_deducted' => 1, 'assign_date' => '2026-06-02'],
        ['salary_per_day' => 1_000_000, 'salary_actual' => 1_300_000, 'is_deducted' => 0, 'assign_date' => '2026-06-03'],
    ];
    return $fixture;
}

$tests = [];
foreach (['missing' => null, 'zero' => 0] as $name => $flag) {
    $tests["ordinary flag $name, no attendance"] = static function () use ($flag): void {
        [$result] = payrollCalculate(payrollFixture($flag));
        foreach (['is_lump_sum', 'total_paid_days', 'basic_salary_received', 'meal_received',
            'attendance_bonus', 'gross_salary', 'net_salary'] as $field) {
            payrollEqual(0, $result[$field], $field);
        }
        payrollEqual(26, $result['working_days_standard'], 'standard days');
    };
    $tests["ordinary flag $name, partial attendance and penalties"] = static function () use ($flag): void {
        [$result] = payrollCalculate(payrollPenaltyFixture($flag));
        foreach ([
            'is_lump_sum' => 0, 'working_days_standard' => 26, 'total_paid_days' => 13,
            'basic_salary_received' => 10_000_000, 'meal_received' => 0,
            'clothes_received' => 250_000, 'phone_received' => 250_000,
            'transport_received' => 500_000, 'housing_received' => 1_000_000,
            'responsibility_allowance_received' => 500_000, 'seniority_allowance_received' => 250_000,
            'performance_bonus' => 500_000, 'other_income' => 750_000,
            'attendance_bonus' => 0, 'attendance_bonus_eligible' => 0,
            'is_late_warning' => 1, 'late_early_hours' => 1, 'late_early_deduction' => 139_423,
            'late_deduction' => 139_423, 'kpi_deduction' => 200_000, 'kpi_bonus' => 300_000,
            'gross_salary' => 14_300_000, 'pit_amount' => 0, 'net_salary' => 13_960_577,
            'late_warning_note' => '02/06(T), 03/06(S)',
        ] as $field => $expected) {
            payrollEqual($expected, $result[$field], $field);
        }
    };
}

$tests['lump sum pays full contract without attendance'] = static function (): void {
    [$result, $engine] = payrollCalculate(payrollFixture());
    payrollFullContract($result);
    payrollEqual(1, $result['is_lump_sum'], 'lump sum flag');
    payrollEqual(0, $result['actual_workdays'], 'actual attendance stays zero');
    payrollEqual(26, $engine->calcWorkingDays('2026-06-01', '2026-06-30'), 'known calendar days');
    payrollEqual(26, $result['working_days_standard'], 'standard days ignores stored 99');
    payrollEqual(26, $result['total_paid_days'], 'full paid days');
    payrollEqual(1_425_000, $result['pit_amount'], 'progressive PIT');
    payrollEqual(28_575_000, $result['net_salary'], 'net after PIT');
};

$tests['lump sum suppresses late and KPI deductions but preserves KPI bonus'] = static function (): void {
    [$result] = payrollCalculate(payrollPenaltyFixture(1));
    foreach (['late_early_hours', 'late_early_deduction', 'late_deduction',
        'is_late_warning', 'kpi_deduction'] as $field) {
        payrollEqual(0, $result[$field], $field);
    }
    payrollEqual('', $result['late_warning_note'], 'no late warning note');
    payrollEqual(13, $result['actual_workdays'], 'actual attendance retained');
    payrollEqual(300_000, $result['kpi_bonus'], 'KPI bonus retained');
    payrollEqual(1_000_000, $result['attendance_bonus'], 'attendance bonus retained despite failed KPI');
    payrollEqual(30_300_000, $result['gross_salary'], 'contract plus KPI bonus');
    payrollEqual(28_830_000, $result['net_salary'], 'net without late/KPI deductions');
};

foreach ([
    'mid-period resignation' => ['2025-01-01', '2026-06-15', '2026-06-01', '2026-06-15', 13],
    'joining and resignation' => ['2026-06-10', '2026-06-20', '2026-06-10', '2026-06-20', 10],
] as $name => [$joined, $resigned, $from, $to, $days]) {
    $tests[$name] = static function () use ($joined, $resigned, $from, $to, $days): void {
        $fixture = payrollFixture();
        $fixture['profile']['date_joined'] = $joined;
        $fixture['profile']['resignation_date'] = $resigned;
        $fixture['bounds'] = [$from, $to];
        [$result, $engine] = payrollCalculate($fixture);
        payrollEqual($days, $engine->calcWorkingDays($from, $fixture['period']['period_to'], $resigned), 'calendar days');
        payrollEqual(26, $result['working_days_standard'], 'monthly standard days');
        payrollEqual($days, $result['total_paid_days'], 'employment-bounded paid days');
        payrollEqual(round(20_000_000 * $days / 26), $result['basic_salary_received'], 'basic salary prorated against monthly standard');
        if ($resigned !== null) {
            payrollEqual(round($result['gross_salary'] * 0.10), $result['pit_amount'], 'resignation PIT');
        }
    };
}

$tests['mid-period lump sum employee is prorated against monthly standard'] = static function (): void {
    $fixture = payrollFixture();
    $fixture['profile']['date_joined'] = '2026-06-16';
    $fixture['bounds'] = ['2026-06-16', '2026-06-30'];
    [$result] = payrollCalculate($fixture);
    payrollEqual(26, $result['working_days_standard'], 'monthly standard days');
    payrollEqual(13, $result['total_paid_days'], 'employment days paid');
    payrollEqual(round(20_000_000 * 13 / 26), $result['basic_salary_received'], 'half-month basic salary');
    payrollEqual(round(1_000_000 * 13 / 26), $result['meal_received'], 'lump sum allowance prorated');
    payrollEqual(0, $result['attendance_bonus_eligible'], 'mid-month hire is not eligible for attendance bonus');
};

$tests['mid-period ordinary employee is prorated against monthly standard'] = static function (): void {
    $fixture = payrollFixture(0);
    $fixture['profile']['date_joined'] = '2026-06-10';
    $fixture['bounds'] = ['2026-06-10', '2026-06-30'];
    $fixture['attendance']['actual_workdays'] = 18;
    [$result] = payrollCalculate($fixture);
    payrollEqual(26, $result['working_days_standard'], 'monthly standard days');
    payrollEqual(18, $result['total_paid_days'], 'actual paid days');
    payrollEqual(round(20_000_000 * 18 / 26), $result['basic_salary_received'], 'basic salary prorated against monthly standard');
    payrollEqual(0, $result['attendance_bonus_eligible'], 'mid-month hire is not eligible for attendance bonus');
};

$tests['sick and other leave are unpaid'] = static function (): void {
    $fixture = payrollFixture(0);
    $fixture['attendance']['actual_workdays'] = 12;
    $fixture['leaveData'] = ['paid_leave_days' => 0, 'other_paid_leave_days' => 0, 'unpaid_leave_days' => 3];
    [$result] = payrollCalculate($fixture);
    payrollEqual(3, $result['unpaid_leave_days'], 'unpaid sick/other leave days');
    payrollEqual(0, $result['other_paid_leave_days'], 'other paid leave compatibility field');
    payrollEqual(12, $result['total_paid_days'], 'sick/other leave excluded from paid days');
    payrollEqual(true, str_contains($result['remark'], 'Không có chuyên cần: nghỉ không lương 3 ngày'),
        'unpaid leave attendance note');
};

$tests['annual leave remains paid'] = static function (): void {
    $fixture = payrollFixture(0);
    $fixture['attendance']['actual_workdays'] = 12;
    $fixture['leaveData'] = ['paid_leave_days' => 2, 'other_paid_leave_days' => 0, 'unpaid_leave_days' => 0];
    [$result] = payrollCalculate($fixture);
    payrollEqual(14, $result['total_paid_days'], 'annual leave included in paid days');
    payrollEqual(round(20_000_000 * 14 / 26), $result['basic_salary_received'], 'annual leave remains paid');
};

foreach ([
    'joined after period' => ['2026-07-01', null],
    'resigned before period' => ['2025-01-01', '2026-05-31'],
] as $name => [$joined, $resigned]) {
    $tests[$name] = static function () use ($joined, $resigned): void {
        $fixture = payrollPenaltyFixture(1);
        $fixture['profile']['date_joined'] = $joined;
        $fixture['profile']['resignation_date'] = $resigned;
        [$result, , $pdo] = payrollCalculate($fixture);
        payrollEqual(26, $result['working_days_standard'], 'monthly standard days even outside employment');
        foreach (['total_paid_days', 'basic_salary_received', 'meal_received', 'attendance_bonus',
            'kpi_bonus', 'gross_salary', 'net_salary'] as $field) {
            payrollEqual(0, $result[$field], $field);
        }
        payrollEqual(['period', 'profile', 'salary', 'annual', 'used'], $pdo->executed, 'no queries outside employment');
    };
}

$tests['Sunday-only period pays no wages'] = static function (): void {
    $fixture = payrollFixture();
    $fixture['period']['period_from'] = $fixture['period']['period_to'] = '2026-06-07';
    $fixture['bounds'] = ['2026-06-07', '2026-06-07'];
    [$result, $engine] = payrollCalculate($fixture);
    payrollEqual(0, $engine->calcWorkingDays('2026-06-07', '2026-06-07'), 'Sunday working days');
    foreach (['working_days_standard', 'total_paid_days', 'basic_salary_received',
        'meal_received', 'attendance_bonus', 'gross_salary', 'net_salary'] as $field) {
        payrollEqual(0, $result[$field], $field);
    }
};

$tests['resignation PIT and social insurance retain existing rules'] = static function (): void {
    $fixture = payrollFixture(0);
    $fixture['profile']['resignation_date'] = '2026-06-15';
    $fixture['profile']['has_social_insurance'] = 1;
    $fixture['profile']['dependants'] = 2;
    $fixture['bounds'] = ['2026-06-01', '2026-06-15'];
    $fixture['attendance']['actual_workdays'] = 13;
    [$ordinary] = payrollCalculate($fixture);
    $fixture['profile']['is_lump_sum'] = 1;
    $fixture['attendance']['actual_workdays'] = 0;
    [$lump] = payrollCalculate($fixture);
    foreach ([$ordinary, $lump] as $result) {
        payrollEqual(2_257_500, $result['si_employee'], 'employee SI on basic + responsibility + seniority');
        payrollEqual(4_622_500, $result['si_company'], 'company SI');
        payrollEqual(round($result['gross_salary'] * 0.10), $result['pit_amount'], '10% PIT without personal/dependant deductions');
        payrollEqual($result['gross_salary'] - $result['si_employee'] - $result['pit_amount'],
            $result['net_salary'], 'net after SI and resignation PIT');
    }
    payrollEqual(14_000_000, $ordinary['gross_salary'], 'ordinary salary prorated against full-month standard');
    payrollEqual(14_500_000, $lump['gross_salary'], 'lump sum salary and contractual meals prorated');
    payrollEqual(1_400_000, $ordinary['pit_amount'], 'ordinary resignation PIT');
    payrollEqual(1_450_000, $lump['pit_amount'], 'lump sum resignation PIT');
    payrollEqual($ordinary['si_employee'], $lump['si_employee'], 'SI unchanged by lump sum');
};

$tests['shared totals match unchanged engine totals'] = static function (): void {
    foreach ([null, 0, 1] as $flag) {
        foreach ([payrollFixture($flag), payrollPenaltyFixture($flag)] as $fixture) {
            [$result] = payrollCalculate($fixture);
            foreach (PayrollEngine::calculateSlipTotals($result) as $field => $value) {
                payrollEqual($result[$field], $value, "engine parity: $field");
            }
        }
    }
};

$tests['manual income replaces engine income and advance reduces net'] = static function (): void {
    [$data] = payrollCalculate(payrollFixture(1));
    foreach ([
        ['advance_payment' => 3_000_000],
        ['other_income' => 19_725_000],
        ['other_income' => 19_725_000, 'advance_payment' => 3_000_000,
            'performance_bonus' => 2_000_000, 'other_bonus' => 500_000,
            'adjustment' => -250_000, 'annual_leave_payout' => 750_000,
            'pit_adjustment' => 100_000],
        ['other_income' => 0, 'performance_bonus' => 0],
    ] as $manual) {
        $gross = $data['gross_salary'];
        foreach (['other_income', 'performance_bonus', 'other_bonus',
            'adjustment', 'annual_leave_payout'] as $field) {
            if (array_key_exists($field, $manual)) {
                $gross += $manual[$field] - $data[$field];
            }
        }
        $merged = array_replace($data, $manual);
        $updated = PayrollEngine::calculateAdjustedFields($data, $merged);
        $totals = array_intersect_key($updated, array_flip(['gross_salary', 'net_salary', 'bank_transfer']));
        payrollEqual($gross, $totals['gross_salary'], 'gross uses manual-minus-engine difference');
        $net = max(0, round($gross - $data['si_employee'] - $data['pit_amount']
            - $merged['pit_adjustment'] - $data['late_deduction']
            - $data['kpi_deduction'] - $merged['advance_payment']));
        payrollEqual($net, $totals['net_salary'], 'net includes preserved advance and PIT adjustment');
        payrollEqual($net, $totals['bank_transfer'], 'bank equals net');
        payrollEqual($totals, PayrollEngine::calculateSlipTotals(array_replace($merged, $totals)),
            'saving unchanged recalculated slip keeps totals');
    }
};

$tests['repeated recalculation preserves manual flag and values but refreshes automatic fields'] = static function (): void {
    [$data] = payrollCalculate(payrollFixture(1));
    $manual = [
        'other_income' => 19_725_000, 'performance_bonus' => 2_000_000,
        'other_bonus' => 500_000, 'adjustment' => -250_000,
        'annual_leave_payout' => 750_000, 'advance_payment' => 3_000_000,
        'pit_adjustment' => 100_000, 'remark' => 'Giữ khoản tay', 'manually_adjusted' => 1,
    ];
    $slip = array_replace($data, $manual, ['basic_salary_received' => 1, 'gross_salary' => 1]);
    foreach ([90_000, 120_000] as $otMealBonus) {
        $fresh = array_replace($data, ['ot_meal_bonus' => $otMealBonus]);
        $updates = PayrollEngine::calculateAdjustedFields($fresh, $slip);
        foreach ($manual as $field => $value) {
            payrollEqual(false, array_key_exists($field, $updates), "$field excluded from automatic updates");
        }
        $slip = array_replace($slip, $updates);
        foreach ($manual as $field => $value) {
            payrollEqual($value, $slip[$field], "$field survives recalculation");
        }
        payrollEqual($fresh['basic_salary_received'], $slip['basic_salary_received'], 'basic salary refreshed');
        payrollEqual($otMealBonus, $slip['ot_meal_bonus'], 'OT meals refreshed');
        payrollEqual(50_225_000 + $otMealBonus, $slip['gross_salary'], 'new gross replaces stale total');
        payrollEqual(PayrollEngine::calculateSlipTotals($slip),
            array_intersect_key($updates, array_flip(['gross_salary', 'net_salary', 'bank_transfer'])),
            'saving after recalculation keeps totals');
    }
};

$tests['shared totals include OT meals night shifts KPI and contractual meals'] = static function (): void {
    [$data] = payrollCalculate(payrollFixture(1));
    $slip = array_replace($data, [
        'ot_meal_bonus' => 90_000, 'night_shift_bonus' => 600_000,
        'kpi_bonus' => 300_000, 'annual_leave_payout' => 150_000,
        'si_employee' => 2_257_500, 'pit_amount' => 500_000,
        'pit_adjustment' => 100_000, 'late_deduction' => 50_000,
        'kpi_deduction' => 200_000, 'advance_payment' => 3_000_000,
    ]);
    $totals = PayrollEngine::calculateSlipTotals($slip);
    payrollEqual(31_140_000, $totals['gross_salary'], 'all automatic earnings included once');
    payrollEqual(25_032_500, $totals['net_salary'], 'all deductions included once');
    payrollEqual($totals['net_salary'], $totals['bank_transfer'], 'bank equals net');
};

$tests['shared totals round net before clamping and tolerate missing optional fields'] = static function (): void {
    $totals = PayrollEngine::calculateSlipTotals([
        'basic_salary_received' => '100.4', 'adjustment' => '-0.1',
        'pit_adjustment' => '-0.3', 'advance_payment' => '10',
    ]);
    payrollEqual(100, $totals['gross_salary'], 'gross rounded');
    payrollEqual(91, $totals['net_salary'], 'net rounds unrounded gross minus deductions');
    $totals = PayrollEngine::calculateSlipTotals([
        'basic_salary_received' => 100, 'advance_payment' => 3_000_000,
    ]);
    payrollEqual(0, $totals['net_salary'], 'advance cannot make net negative');
    payrollEqual(0, $totals['bank_transfer'], 'bank cannot be negative');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: $name\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL: $name: {$error->getMessage()}\n");
    }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed === 0 ? 0 : 1);
