<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db();

    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    json_response([
        'success' => true,
        'message' => 'Подключение к базе работает',
        'database' => DB_NAME,
        'tables_count' => count($tables),
        'tables' => $tables,
        'server_time' => date('Y-m-d H:i:s')
    ]);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Ошибка подключения к базе',
        'error' => $e->getMessage()
    ], 500);
}
