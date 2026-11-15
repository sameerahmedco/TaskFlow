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
