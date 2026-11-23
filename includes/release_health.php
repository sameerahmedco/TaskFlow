<?php
/** TaskFlow v3 deployment checks. No secrets are ever returned to the client. */
declare(strict_types=1);

/** A production app key is the installer-generated base64 encoding of 32 random bytes. */
function taskflow_release_key_valid(mixed $key): bool {
    if (!is_string($key) || $key === '') return false;
    $decoded = base64_decode($key, true);
    return $decoded !== false && strlen($decoded) === 32
        && hash_equals(base64_encode($decoded), $key);
}

/** Public deployments need a canonical HTTPS URL, not an IP/localhost origin. */
function taskflow_release_url_valid(mixed $url): bool {
    if (!is_string($url) || strlen($url) > 2048 || $url === '') return false;
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return false;
    if (isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['fragment']) || isset($parts['query'])) return false;
    $host = strtolower((string) $parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP)) return false;
    return (bool) preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $host)
        && str_contains($host, '.') && !str_contains($host, '..');
}

/** Reject leaked configuration files and badly permissioned secrets. */
function taskflow_release_file_private(string $path): bool {
    if (!is_file($path) || is_link($path)) return false;
    $mode = fileperms($path);
    return $mode !== false && ($mode & 0077) === 0 && ($mode & 0111) === 0;
}

/** Require Oracle MySQL 8+; MariaDB reports a different version family. */
function taskflow_release_mysql_version_valid(mixed $version): bool {
    if (!is_string($version) || stripos($version, 'mariadb') !== false) return false;
    if (!preg_match('/\A([0-9]+)\.([0-9]+)\.([0-9]+)/D', $version, $matches)) return false;
    return (int) $matches[1] >= 8;
}

/** Production mutations require strict SQL mode: silent data truncation is unsafe. */
function taskflow_release_sql_mode_valid(mixed $value): bool {
    if (!is_string($value) || $value==='') return false;
    $modes=array_map('trim',explode(',',strtoupper($value)));
    return in_array('STRICT_TRANS_TABLES',$modes,true) || in_array('STRICT_ALL_TABLES',$modes,true);
}

/** Index and referential constraints needed for authorization, pagination and deletes. */
function taskflow_release_required_indexes(): array {
    return [
        'comments' => ['idx_comments_task_id', 'idx_comments_parent', 'idx_comments_author_time'],
        'notification_log' => ['idx_inbox_user_read_id'],
        'task_review_requests' => ['uq_task_review_task', 'idx_review_queue', 'idx_review_submitter'],
        'task_review_events' => ['idx_review_events'],
    ];
}

function taskflow_release_required_foreign_keys(): array {
    return [
        'comments' => ['fk_comments_parent' => 'SET NULL'],
        'task_review_requests' => [
            'fk_task_review_task' => 'CASCADE',
            'fk_task_review_team' => 'CASCADE',
            'fk_task_review_submitter' => 'SET NULL',
            'fk_task_review_reviewer' => 'SET NULL',
        ],
        'task_review_events' => [
            'fk_review_event_review' => 'CASCADE',
            'fk_review_event_actor' => 'SET NULL',
        ],
    ];
}

/** Exact index column order matters for the queries TaskFlow relies upon. */
function taskflow_release_required_index_signatures(): array {
    return [
        'comments' => [
            'idx_comments_task_id' => ['task_id','id'],
            'idx_comments_parent' => ['parent_comment_id'],
            'idx_comments_author_time' => ['user_id','created_at'],
        ],
        'notification_log' => ['idx_inbox_user_read_id' => ['user_id','is_read','id']],
        'task_review_requests' => [
            'uq_task_review_task' => ['task_id'],
            'idx_review_queue' => ['team_id','status','id'],
            'idx_review_submitter' => ['submitted_by','id'],
        ],
        'task_review_events' => ['idx_review_events' => ['review_id','id']],
    ];
}

/** FK identity must include the source/target columns, not merely its name/rule. */
function taskflow_release_required_fk_signatures(): array {
    return [
        'comments' => [
            'fk_comments_parent' => ['SET NULL',['parent_comment_id'],'comments',['id']],
        ],
        'task_review_requests' => [
            'fk_task_review_task' => ['CASCADE',['task_id'],'tasks',['id']],
            'fk_task_review_team' => ['CASCADE',['team_id'],'teams',['id']],
            'fk_task_review_submitter' => ['SET NULL',['submitted_by'],'users',['id']],
            'fk_task_review_reviewer' => ['SET NULL',['reviewed_by'],'users',['id']],
        ],
        'task_review_events' => [
            'fk_review_event_review' => ['CASCADE',['review_id'],'task_review_requests',['id']],
            'fk_review_event_actor' => ['SET NULL',['actor_id'],'users',['id']],
        ],
    ];
}

/** Pure metadata policy; intentionally fails closed when detailed signatures are missing. */
function taskflow_release_missing_schema(array $columns, array $indexes, array $foreignKeys): array {
    $missing = [];
    foreach (taskflow_release_required_schema() as $table => $names) {
        if (!isset($columns[$table])) { $missing[] = $table . ' table'; continue; }
        foreach ($names as $name) {
            if (!isset($columns[$table][$name])) $missing[] = "$table.$name";
        }
    }
    foreach (taskflow_release_required_index_signatures() as $table => $definitions) {
        foreach ($definitions as $name => $expectedColumns) {
            $actual=$indexes[$table][$name] ?? null;
            if (!is_array($actual)) { $missing[]="$table.index:$name (missing or unverifiable)"; continue; }
            if (($actual['columns'] ?? null) !== $expectedColumns || !empty($actual['partial'])) {
                $missing[]="$table.index:$name (incorrect column order or prefix)";
            }
            $requiredKind=$name==='uq_task_review_task'?'unique':'regular';
            if (($actual['kind'] ?? null) !== $requiredKind) {
                $missing[]="$table.index:$name ($requiredKind required)";
            }
        }
    }
    foreach (taskflow_release_required_fk_signatures() as $table => $definitions) {
        foreach ($definitions as $name => [$deleteRule,$sourceColumns,$targetTable,$targetColumns]) {
            $actual=$foreignKeys[$table][$name] ?? null;
            if (!is_array($actual) || ($actual['delete'] ?? null)!==$deleteRule
                || ($actual['columns'] ?? null)!==$sourceColumns
                || ($actual['ref_table'] ?? null)!==$targetTable
                || ($actual['ref_columns'] ?? null)!==$targetColumns) {
                $missing[]="$table.fk:$name (expected $deleteRule $targetTable; check linked columns)";
            }
        }
    }
    return $missing;
}

/** A feature's database migration is required when its table or columns are absent. */
function taskflow_release_required_schema(): array {
    return [
        'users' => ['id', 'auth_version'],
        'user_roles' => ['user_id', 'role'],
        'teams' => ['id'],
        'lists' => ['id', 'team_id'],
        'tasks' => ['id', 'status'],
        'comments' => ['parent_comment_id', 'edited_at', 'edit_version'],
        'notification_log' => ['user_id', 'is_read'],
        'task_review_requests' => ['id', 'task_id', 'team_id', 'version', 'status'],
        'task_review_events' => ['id', 'review_id', 'event_type'],
    ];
}

/** Inspect only information_schema metadata; no customer rows are selected. */
function taskflow_release_check_schema(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');
    $stmt->execute();
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']] = true;
    }
    $indexes = [];
    $stmt = $pdo->prepare('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
    $stmt->execute();
    while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        $table=(string)$row['TABLE_NAME'];$name=(string)$row['INDEX_NAME'];
        $indexes[$table][$name]['kind']=(int)$row['NON_UNIQUE']===0?'unique':'regular';
        $indexes[$table][$name]['columns'][(int)$row['SEQ_IN_INDEX']-1]=(string)$row['COLUMN_NAME'];
        if ($row['SUB_PART'] !== null) $indexes[$table][$name]['partial']=true;
    }
    foreach($indexes as &$tableIndexes){
        foreach($tableIndexes as &$definition){
            ksort($definition['columns'],SORT_NUMERIC);
            $definition['columns']=array_values($definition['columns']);
        }
        unset($definition);
    }
    unset($tableIndexes);
    $foreignKeys=[];
    $stmt=$pdo->prepare('SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,
          k.REFERENCED_COLUMN_NAME,k.ORDINAL_POSITION,r.DELETE_RULE
          FROM information_schema.KEY_COLUMN_USAGE k
          JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON k.CONSTRAINT_SCHEMA=r.CONSTRAINT_SCHEMA
            AND k.TABLE_NAME=r.TABLE_NAME AND k.CONSTRAINT_NAME=r.CONSTRAINT_NAME
          WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
          ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
    $stmt->execute();
    while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        $table=(string)$row['TABLE_NAME'];$name=(string)$row['CONSTRAINT_NAME'];
        $foreignKeys[$table][$name]['delete']=strtoupper((string)$row['DELETE_RULE']);
        $foreignKeys[$table][$name]['ref_table']=(string)$row['REFERENCED_TABLE_NAME'];
        $position=(int)$row['ORDINAL_POSITION']-1;
        $foreignKeys[$table][$name]['columns'][$position]=(string)$row['COLUMN_NAME'];
        $foreignKeys[$table][$name]['ref_columns'][$position]=(string)$row['REFERENCED_COLUMN_NAME'];
    }
    foreach($foreignKeys as &$tableFks){
        foreach($tableFks as &$definition){
            ksort($definition['columns'],SORT_NUMERIC);ksort($definition['ref_columns'],SORT_NUMERIC);
            $definition['columns']=array_values($definition['columns']);
            $definition['ref_columns']=array_values($definition['ref_columns']);
        }
        unset($definition);
    }
    unset($tableFks);
    return taskflow_release_missing_schema($columns,$indexes,$foreignKeys);
}

/** Parse familiar php.ini size values (K, M, G); -1 means unlimited. */
function taskflow_release_ini_bytes(mixed $value): int {
    $text = trim((string) $value);
    if ($text === '-1') return PHP_INT_MAX;
    if (!preg_match('/\A([0-9]+)([KMG]?)\z/iD', $text, $m)) return 0;
    $multiple = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];
    return (int) min((float) PHP_INT_MAX, (float) $m[1] * $multiple);
}
