<?php
/**
 * db.php — single shared PDO connection.
 * Always use prepared statements ($pdo->prepare(...)->execute([...]))
 * — never concatenate user input into SQL strings.
 */
require_once __DIR__ . '/../config/config.php';

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_NAME === '' || DB_USER === '') {
            json_db_unavailable();
        }
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('DB connection failed: ' . $e->getMessage());
            die(json_encode(['error' => 'Database temporarily unavailable. Contact your administrator.']));
        }
    }
    return $pdo;
}

function json_db_unavailable(): never {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'TaskFlow database is not configured. Run the setup wizard.']));
}
