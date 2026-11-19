<?php
/** TaskFlow 1.14.0: custom UTC workweeks, half-day leave and team holidays. */
declare(strict_types=1);
require_once __DIR__ . '/time_off.php';

/** Bit 0 is Monday, bit 6 is Sunday. 31 preserves the pre-1.14 Mon-Fri default. */
function taskflow_schedule_mask(mixed $value): int {
    if (!is_int($value) && !(is_string($value) && preg_match('/\A[0-9]+\z/D', $value))) {
        throw new InvalidArgumentException('Workdays must be selected from Monday through Sunday.');
    }
    $mask = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 127]]);
    if ($mask === false) throw new InvalidArgumentException('Select at least one workday.');
    return (int)$mask;
}

function taskflow_leave_portion(mixed $value): string {
    if (!is_string($value) || !in_array($value, ['full','am','pm'], true)) {
        throw new InvalidArgumentException('Choose full day, morning, or afternoon.');
    }
    return $value;
}

function taskflow_leave_portion_valid(string $start, string $end, string $portion): bool {
    return $portion === 'full' || ($start === $end && in_array($portion, ['am','pm'], true));
}

/**
 * Compute available minutes without double-counting holidays and leave.
 * Each scheduled day receives an equal share of baseline weekly minutes.
 * Half-days are strictly for one date; the DB constrains that as well.
 * All date boundaries are UTC and the interval is a Monday-Sunday week.
 */
function taskflow_schedule_availability(int $weeklyMinutes, int $workdaysMask,
                                       array $approvedLeave, array $holidays,
                                       string $weekStart, string $weekEnd): array {
    if ($weeklyMinutes < 0 || $weeklyMinutes > 4800) throw new InvalidArgumentException('Invalid weekly capacity.');
    $mask = taskflow_schedule_mask($workdaysMask);
    $start = taskflow_time_off_date($weekStart);
    $end = taskflow_time_off_date($weekEnd);
    if (!$start || !$end || $end < $start || (int)$start->diff($end)->days !== 6 || $start->format('N') !== '1') {
        throw new InvalidArgumentException('Expected a UTC Monday-Sunday week.');
    }
    $scheduledDates = [];
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $bit = 1 << ((int)$day->format('N') - 1);
        if ($mask & $bit) $scheduledDates[$day->format('Y-m-d')] = 0;
    }
    $scheduledDays = count($scheduledDates);
    $holidayDates = [];
    foreach ($holidays as $holiday) {
        $date = is_array($holiday) ? ($holiday['holiday_date'] ?? null) : $holiday;
        if (is_string($date) && isset($scheduledDates[$date])) $holidayDates[$date] = true;
    }
    // Use two bits per date: morning = 1, afternoon = 2, whole day = 3.
    foreach ($approvedLeave as $range) {
        $from = taskflow_time_off_date($range['start_date'] ?? null);
        $to = taskflow_time_off_date($range['end_date'] ?? null);
        if (!$from || !$to || $from > $to) continue;
        $portion = $range['day_portion'] ?? 'full';
        if (!in_array($portion, ['full','am','pm'], true) ||
            !taskflow_leave_portion_valid($from->format('Y-m-d'), $to->format('Y-m-d'), $portion)) continue;
        $left = $from > $start ? $from : $start;
        $right = $to < $end ? $to : $end;
        $portionMask = $portion === 'am' ? 1 : ($portion === 'pm' ? 2 : 3);
        for ($d = $left; $d <= $right; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            if (array_key_exists($key, $scheduledDates)) $scheduledDates[$key] |= $portionMask;
        }
    }
    $leaveUnits = 0.0;
    $holidayDays = 0;
    foreach ($scheduledDates as $date => $dayMask) {
        if (isset($holidayDates[$date])) { $holidayDays++; continue; }
        $leaveUnits += $dayMask === 3 ? 1 : ($dayMask === 1 || $dayMask === 2 ? 0.5 : 0);
    }
    $effectiveMinutes = max(0, (int)round($weeklyMinutes * ($scheduledDays - $holidayDays - $leaveUnits) / $scheduledDays));
    return [
        'workdays_mask' => $mask,
        'scheduled_days' => $scheduledDays,
        'approved_leave_days' => $leaveUnits,
        'holiday_days' => $holidayDays,
        'effective_capacity_minutes' => $effectiveMinutes,
    ];
}

function taskflow_holiday_title(mixed $value): string {
    if (!is_string($value)) throw new InvalidArgumentException('Enter a holiday title.');
    $title = trim($value);
    if ($title === '' || strlen($title) > 100 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
        throw new InvalidArgumentException('Holiday title must be 1-100 bytes without control characters.');
    }
    return $title;
}

function taskflow_holiday_year(mixed $year, ?int $currentYear = null): int {
    $current = $currentYear ?? (int)gmdate('Y');
    if (!is_int($year) && !(is_string($year) && preg_match('/\A[0-9]{4}\z/D', $year))) {
        throw new InvalidArgumentException('Invalid calendar year.');
    }
    $value = (int)$year;
    if ($value < $current - 2 || $value > $current + 2) {
        throw new InvalidArgumentException('Holiday year is outside the planning window.');
    }
    return $value;
}
