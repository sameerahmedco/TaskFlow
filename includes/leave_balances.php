<?php
/** Annual, advisory leave balances. Scheduled workdays count; holidays do not. */
declare(strict_types=1);
require_once __DIR__ . '/scheduling.php';

/** Leave allowances are stored as integer half-day units, avoiding rounding. */
function taskflow_leave_allowance_units(mixed $value): int {
    if (!is_int($value) && !(is_string($value) && preg_match('/\A[0-9]+\z/D', $value))) {
        throw new InvalidArgumentException('Enter an allowance in whole half-day units.');
    }
    $units = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 732]]);
    if ($units === false) throw new InvalidArgumentException('Allowance must be between 0 and 366 days.');
    return (int)$units;
}

/**
 * Count approved leave in a UTC calendar year, deduplicating day portions.
 * Excludes team holidays and dates outside an individual's scheduled workdays.
 * A full day is two half-day units. Does not enforce a leave/payroll policy.
 */
function taskflow_annual_leave_units(int $year, int $workdaysMask, array $approvedLeave, array $holidays): int {
    if ($year < 2000 || $year > 2100) throw new InvalidArgumentException('Invalid balance year.');
    $mask = taskflow_schedule_mask($workdaysMask);
    $start = new DateTimeImmutable(sprintf('%04d-01-01', $year), new DateTimeZone('UTC'));
    $end = $start->modify('+1 year')->modify('-1 day');
    $holidaySet = [];
    foreach ($holidays as $holiday) {
        $date = is_array($holiday) ? ($holiday['holiday_date'] ?? null) : $holiday;
        if (is_string($date) && taskflow_time_off_date($date)) $holidaySet[$date] = true;
    }
    $bits = [];
    foreach ($approvedLeave as $request) {
        $from = taskflow_time_off_date($request['start_date'] ?? null);
        $to = taskflow_time_off_date($request['end_date'] ?? null);
        $portion = $request['day_portion'] ?? 'full';
        if (!$from || !$to || $to < $from || !in_array($portion, ['full','am','pm'], true)
            || !taskflow_leave_portion_valid($from->format('Y-m-d'), $to->format('Y-m-d'), $portion)) continue;
        $left = $from > $start ? $from : $start;
        $right = $to < $end ? $to : $end;
        if ($left > $right) continue;
        $portionBits = $portion === 'am' ? 1 : ($portion === 'pm' ? 2 : 3);
        for ($day = $left; $day <= $right; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if (($mask & (1 << ((int)$day->format('N') - 1))) === 0 || isset($holidaySet[$date])) continue;
            $bits[$date] = ($bits[$date] ?? 0) | $portionBits;
        }
    }
    $used = 0;
    foreach ($bits as $value) $used += ($value & 1 ? 1 : 0) + ($value & 2 ? 1 : 0);
    return $used;
}

function taskflow_leave_balance(?int $allowanceUnits, int $usedUnits): array {
    if ($usedUnits < 0) throw new InvalidArgumentException('Invalid used leave.');
    return ['allowance_half_days' => $allowanceUnits,
            'approved_half_days' => $usedUnits,
            'remaining_half_days' => $allowanceUnits === null ? null : $allowanceUnits - $usedUnits];
}

/** Strict UTC month selector constrained to the existing holiday planning horizon. */
function taskflow_leave_calendar_month(mixed $input, ?int $currentYear = null): array {
    if (!is_string($input) || !preg_match('/\A[0-9]{4}-[0-9]{2}\z/D', $input)) {
        throw new InvalidArgumentException('Choose a valid YYYY-MM month.');
    }
    $date = taskflow_time_off_date($input . '-01');
    if (!$date) throw new InvalidArgumentException('Invalid calendar month.');
    taskflow_holiday_year((int)$date->format('Y'), $currentYear);
    return ['month' => $input, 'from' => $date->format('Y-m-d'),
            'to' => $date->modify('last day of this month')->format('Y-m-d')];
}
