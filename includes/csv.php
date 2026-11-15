<?php
/** Prevent formula execution when a user-supplied CSV value is opened in Excel/Sheets. */
declare(strict_types=1);
function taskflow_csv_safe($value): string {
    $value = (string) ($value ?? '');
    return preg_match('/\A[\x00-\x20]*[=+@\-]/', $value) ? "'" . $value : $value;
}
