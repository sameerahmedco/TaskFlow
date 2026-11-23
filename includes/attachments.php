<?php
/** Private task file policy: no public files, executable formats, or inline previews. */
declare(strict_types=1);
const TASKFLOW_MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;
const TASKFLOW_MAX_TASK_ATTACHMENTS = 15;

function taskflow_attachment_name(string $value): string {
    $value = str_replace('\\', '/', $value);
    $value = basename($value);
    $value = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '');
    if ($value === '' || $value === '.' || $value === '..' || str_starts_with($value,'.')) {
        throw new InvalidArgumentException('Invalid attachment filename.');
    }
    if (strlen($value) > 180) throw new InvalidArgumentException('Filename exceeds 180 bytes.');
    return $value;
}
function taskflow_attachment_is_allowed(string $filename, string $mime): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        'pdf'=>['application/pdf'], 'png'=>['image/png'], 'jpg'=>['image/jpeg'],
        'jpeg'=>['image/jpeg'], 'webp'=>['image/webp'], 'txt'=>['text/plain'],
        'csv'=>['text/plain','text/csv','application/csv','application/vnd.ms-excel'],
        'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];
    return isset($types[$ext]) && in_array($mime,$types[$ext],true);
}
/** Must point to a real writable directory outside the web document root. */
function taskflow_attachment_storage(): string {
    $value = getenv('TASKFLOW_ATTACHMENTS_DIR');
    if (!is_string($value) || $value === '' || !str_starts_with($value,'/')) {
        throw new RuntimeException('Private attachment storage is not configured.');
    }
    $base=realpath($value);
    if (!$base || $base === '/' || !is_dir($base) || !is_writable($base)) {
        throw new RuntimeException('Private attachment storage is unavailable.');
    }
    if (((int)fileperms($base) & 0007) !== 0) {
        throw new RuntimeException('Private attachment storage has unsafe permissions.');
    }
    $protected=[realpath(dirname(__DIR__)), realpath($_SERVER['DOCUMENT_ROOT'] ?? '/not-a-real-docroot')];
    foreach($protected as $root){
        if ($root && ($base===$root || str_starts_with($base,$root.'/'))) {
            throw new RuntimeException('Private attachment storage must be outside the document root.');
        }
    }
    return $base;
}
function taskflow_attachment_path(string $base, string $key, bool $createDir=false): string {
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$key)) throw new InvalidArgumentException('Invalid attachment key.');
    $dir=$base.'/'.substr($key,0,2);
    if ($createDir && !is_dir($dir) && !mkdir($dir,0700) && !is_dir($dir)) {
        throw new RuntimeException('Could not create private attachment directory.');
    }
    if ($createDir && is_dir($dir)) chmod($dir,0700);
    if (is_link($dir)) throw new RuntimeException('Unsafe private attachment directory.');
    return $dir.'/'.$key.'.bin';
}


/** Validate IDs before any SQL query; never coerce booleans or decimal strings. */
function taskflow_attachment_id(mixed $raw): int {
    if ((!is_int($raw) && !is_string($raw)) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$raw)) {
        throw new InvalidArgumentException('Invalid attachment or task ID.');
    }
    $id=filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id===false) throw new InvalidArgumentException('Invalid attachment or task ID.');
    return (int)$id;
}

/** Verify the on-disk bytes match the committed record before returning Content-Length. */
function taskflow_attachment_integrity($handle, int $recordedSize): bool {
    if (!is_resource($handle) || $recordedSize < 1 || $recordedSize > TASKFLOW_MAX_ATTACHMENT_BYTES) return false;
    $stat=fstat($handle);
    return is_array($stat) && isset($stat['size']) && (int)$stat['size']===$recordedSize;
}

/**
 * Move a private attachment aside before deleting its DB row. Should the
 * transaction roll back, the original file can be restored. A crash between
 * commit and unlink can leave a private orphan; report and clean in staging.
 */
function taskflow_attachment_quarantine(string $path): ?string {
    if (is_link($path)) throw new RuntimeException('Unsafe private attachment path.');
    if (!file_exists($path)) return null; // stale DB row: allow record cleanup
    if (!is_file($path)) throw new RuntimeException('Attachment is not a regular file.');
    $moved=$path.'.deleting-'.bin2hex(random_bytes(12));
    if (!rename($path,$moved)) throw new RuntimeException('Could not stage private attachment removal.');
    // rename() preserves an old upload's mtime. Stamp the staging time so
    // the sweeper's 48-hour grace starts on deletion, not original upload.
    if (!@touch($moved)) {
        if (!@rename($moved,$path))error_log('Private staged attachment needs manual restore.');
        throw new RuntimeException('Could not timestamp staged private attachment.');
    }
    return $moved;
}

function taskflow_attachment_restore(string $moved, string $path): bool {
    if (!is_file($moved) || is_link($moved) || file_exists($path) || is_link($path)) return false;
    return rename($moved,$path);
}


/** Only our exact private staged-delete format is eligible for GC inspection. */
function taskflow_attachment_quarantine_key(string $filename): ?string {
    if (preg_match('/\A([a-f0-9]{64})\.bin\.deleting-[a-f0-9]{24}\z/D',$filename,$match)!==1) return null;
    return $match[1];
}
