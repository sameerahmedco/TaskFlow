<?php
/** CLI-only probe for local ClamAV Unix-socket protocol integration tests. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once __DIR__.'/../includes/attachment_scan.php';
$action=$argv[1]??'';
if ($action==='ping') {echo taskflow_attachment_scanner_ping()?'PONG':'UNAVAILABLE';exit(0);}
if ($action!=='scan'||!isset($argv[2]))exit(2);
try { taskflow_attachment_scan_file((string)$argv[2]);echo 'CLEAN';exit(0); }
catch(DomainException) {echo 'DETECTED';exit(3);}
catch(Throwable) {echo 'UNAVAILABLE';exit(4);}
