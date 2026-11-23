<?php
/**
 * TaskFlow v3 deployment preflight (CLI only).
 * Usage: php bin/release-preflight.php [--json] [--source]
 * --source validates the distributable source, not a configured installation.
 * The default deployment mode fails closed on missing DB/schema/runtime setup.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once __DIR__ . '/../includes/release_health.php';
require_once __DIR__ . '/../includes/attachment_scan.php';

$args = array_slice($argv, 1);
$unknown = array_diff($args, ['--json', '--source', '--help']);
if ($unknown) { fwrite(STDERR, "Unknown option. Use --help.\n"); exit(2); }
if (in_array('--help', $args, true)) {
    echo "Usage: php bin/release-preflight.php [--json] [--source]\n";
    echo "Source mode checks a release package; default mode verifies configured production infrastructure.\n";
    exit(0);
}
$json = in_array('--json', $args, true);
$source = in_array('--source', $args, true);
$root = dirname(__DIR__);
$checks = [];
$record = static function (string $id, bool $ok, string $message, bool $mandatory=true)
    use (&$checks): void {
    $checks[] = ['id'=>$id, 'result'=>$ok?'pass':($mandatory?'fail':'warn'), 'message'=>$message];
};

$record('php_version', PHP_VERSION_ID >= 80300, 'PHP 8.3 or newer required.');
foreach (['pdo','json','openssl','sodium','fileinfo'] as $extension) {
    $record('extension_'.$extension, extension_loaded($extension), "PHP extension: $extension");
}
$record('source_structure', is_file($root.'/schema.sql') && is_file($root.'/api/task.php')
    && is_file($root.'/api/task_reviews.php') && is_file($root.'/config/config.php')
    && is_file($root.'/includes/transport_policy.php') && is_file($root.'/includes/remember_cookie.php')
    && is_file($root.'/includes/attachment_scan.php'),
    'Core source files are present.');
$record('upgrade_scripts', is_file($root.'/docs/migrations/v3-discussions.sql')
    && is_file($root.'/docs/migrations/v3-task-reviews.sql'),
    'All required v3 migration scripts are present.');
$record('apache_rules', is_file($root.'/.htaccess')
    && str_contains((string)@file_get_contents($root.'/.htaccess'), 'RewriteRule'),
    'Apache source-file denial rules present.');
$record('nginx_example', is_file($root.'/docs/deployment/nginx-taskflow.conf.example'),
    'Secure Nginx deployment example included.');
$record('source_secrets', !is_file($root.'/config/config.local.php') && !is_file($root.'/config/.installed')
    && !is_file($root.'/config/.setup-key'),
    'No local production secrets or installer locks included in distributable source.');

if (!$source) {
    require_once $root.'/config/config.php';
    $record('mysql_driver', extension_loaded('pdo_mysql'), 'PDO MySQL extension installed.');
    $record('mbstring', extension_loaded('mbstring'), 'mbstring extension installed.');
    $record('installed', APP_INSTALLED, 'First-time installer locked after configuration.');
    $record('app_environment', APP_ENV === 'production', 'TASKFLOW_APP_ENV=production.');
    $record('canonical_url', taskflow_release_url_valid(APP_URL), 'TASKFLOW_APP_URL is a canonical HTTPS hostname.');
    $record('app_key', taskflow_release_key_valid(APP_KEY), 'Application encryption key is exactly 32 random bytes (base64).');
    $configPath = $root.'/config/config.local.php';
    if (is_file($configPath)) {
        $record('config_permissions', taskflow_release_file_private($configPath),
            'Private configuration has owner-only read/write access (mode 0600 or stricter).');
    }
    $record('errors_hidden', ini_get('display_errors') === '0', 'Browser error display is disabled.');
    $record('uploads_limit', taskflow_release_ini_bytes(ini_get('upload_max_filesize'))>=8*1048576,
        'PHP upload_max_filesize is at least 8 MiB.');
    $record('post_limit', taskflow_release_ini_bytes(ini_get('post_max_size'))>=9*1048576,
        'PHP post_max_size is at least 9 MiB.');
    $record('opcache', extension_loaded('Zend OPcache') && ini_get('opcache.enable') === '1',
        'OPcache is enabled for PHP request speed.', false);
    $record('php_memory', taskflow_release_ini_bytes(ini_get('memory_limit'))>=128*1048576,
        'PHP memory_limit is at least 128 MiB.', false);
    $record('debug_extension', !extension_loaded('xdebug'), 'Xdebug is disabled in production.');
    $storage = getenv('TASKFLOW_ATTACHMENTS_DIR');
    if ($storage === false || $storage === '') {
        $record('attachment_storage', false,
            'TASKFLOW_ATTACHMENTS_DIR is not configured; private attachments cannot be uploaded.',false);
    } else {
        $base = is_string($storage) && str_starts_with($storage,'/') ? realpath($storage) : false;
        $mode = $base ? fileperms($base) : false;
        $outside = $base && $base!==$root && !str_starts_with($base,$root.'/');
        $safe = $base && is_dir($base) && is_writable($base) && !is_link($storage)
            && $outside && $mode !== false && ($mode & 0007)===0;
        $record('attachment_storage', (bool)$safe,
            'Private attachment storage is writable, restricted and outside web root.');
        // Uploaded documents can contain malicious payloads even when MIME matches.
        // Production requires an active local ClamAV daemon before enabling uploads.
        $record('attachment_malware_scanner', taskflow_attachment_scanner_ping(),
            'Local clamd socket must respond to PING before production uploads are enabled.');
    }
    // Never call get_pdo(): its browser-oriented failures may exit with status 0.
    if (extension_loaded('pdo_mysql') && defined('DB_NAME') && DB_NAME !== '' && DB_USER !== '') {
        try {
            $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>4]);
            $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $record('mysql_version', taskflow_release_mysql_version_valid($version),
                'MySQL 8 or newer is required; MariaDB compatibility is not certified.');
            $offset=(int)$pdo->query('SELECT TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),NOW())')->fetchColumn();
            $record('db_timezone',abs($offset)<=1,
                'MySQL session time zone is UTC (or within one minute of UTC).');
            $sqlMode=(string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $record('db_strict_mode',taskflow_release_sql_mode_valid($sqlMode),
                'MySQL session SQL mode must include STRICT_TRANS_TABLES or STRICT_ALL_TABLES.');
            $fkChecks=(int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn();
            $record('db_foreign_key_checks',$fkChecks===1,
                'MySQL session must enforce foreign key constraints.');
            $tables=$pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_ASSOC);
            $wrongEngines=[];
            foreach($tables as $table){
                if(strtoupper((string)$table['ENGINE'])!=='INNODB')$wrongEngines[]=(string)$table['TABLE_NAME'];
            }
            $record('db_engine',!$wrongEngines,
                $wrongEngines ? 'Tables not using InnoDB: '.implode(', ',array_slice($wrongEngines,0,5)) :
                'All application tables use InnoDB for row-locking and transactions.');
            $missing = taskflow_release_check_schema($pdo);
            $record('schema', count($missing)===0,
                $missing ? 'Required schema is incomplete: '.implode(', ',array_slice($missing,0,16)) :
                'Latest TaskFlow v3 tables, columns, indexes and foreign-key delete rules are present.');
        } catch (Throwable $e) {
            error_log('TaskFlow preflight database issue: '.get_class($e));
            $record('database',false,'Database connection or metadata validation failed. Verify DB connectivity and migration.');
        }
    } else {
        $record('database',false,'Database settings or PDO MySQL driver missing.');
    }
    $record('web_server', false,
        'Verify TLS, web-root rules, denied private directories, scheduled maintenance and trusted proxy settings with a real HTTPS request.', false);
}
$errors=count(array_filter($checks,static fn($c)=>$c['result']==='fail'));
$warnings=count(array_filter($checks,static fn($c)=>$c['result']==='warn'));
$report=['mode'=>$source?'source':'deployment',
    'preflight_passed'=>$errors===0,
    'ready'=>!$source && $errors===0 && $warnings===0,
    'source_valid'=>$source && $errors===0,'errors'=>$errors,'warnings'=>$warnings,'checks'=>$checks];
if ($json) {
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
} else {
    foreach ($checks as $check) echo strtoupper($check['result']).' '.$check['id'].': '.$check['message']."\n";
    echo $errors===0?($source?"Source checks passed. This does not certify a deployment.\n":"Deployment checks passed; verify live HTTP and staged testing.\n"):
        "Preflight failed ($errors release-blocking issue(s), $warnings warning(s)).\n";
}
exit($errors>0?1:0);
