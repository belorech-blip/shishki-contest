<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    json_response(['success' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Метод не разрешен'
    ], 405);
}

function get_bearer_token(): string
{
    $headers = [];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    $authorization = '';

    foreach ($headers as $key => $value) {
        if (mb_strtolower($key) === 'authorization') {
            $authorization = $value;
            break;
        }
    }

    if (!$authorization && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!$authorization && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function current_participant(PDO $pdo): array
{
    $token = get_bearer_token();

    if ($token === '') {
        json_response([
            'success' => false,
            'message' => 'Токен не передан'
        ], 401);
    }

    $stmt = $pdo->prepare("\n        SELECT id, name, phone, agency, status\n        FROM participants\n        WHERE token = :token\n          AND status = 'active'\n        LIMIT 1\n    ");

    $stmt->execute([
        ':token' => $token
    ]);

    $participant = $stmt->fetch();

    if (!$participant) {
        json_response([
            'success' => false,
            'message' => 'Участник не найден или токен устарел'
        ], 401);
    }

    return $participant;
}

$data = get_json_input();
$platform = trim((string)($data['platform'] ?? ''));

$columns = [
    'vk' => 'vk_status',
    'max' => 'max_status',
    'telegram' => 'telegram_status',
    'telegram_agents' => 'telegram_agents_status',
    'instagram' => 'instagram_status'
];

if (!isset($columns[$platform])) {
    json_response([
        'success' => false,
        'message' => 'Неизвестная соцсеть'
    ], 422);
}

try {
    $pdo = db();
    $participant = current_participant($pdo);
    $participantId = (int)$participant['id'];
    $column = $columns[$platform];

    $check = $pdo->prepare("\n        SELECT participant_id, {$column} AS current_status\n        FROM subscriptions\n        WHERE participant_id = :participant_id\n        LIMIT 1\n    ");

    $check->execute([
        ':participant_id' => $participantId
    ]);

    $row = $check->fetch();

    if (!$row) {
        $insert = $pdo->prepare("\n            INSERT INTO subscriptions (\n                participant_id,\n                vk_status,\n                max_status,\n                telegram_status,\n                telegram_agents_status,\n                instagram_status\n            ) VALUES (\n                :participant_id,\n                'not_requested',\n                'not_requested',\n                'not_requested',\n                'not_requested',\n                'not_requested'\n            )\n        ");

        $insert->execute([
            ':participant_id' => $participantId
        ]);

        $row = ['current_status' => 'not_requested'];
    }

    if ($row['current_status'] === 'confirmed') {
        json_response([
            'success' => true,
            'message' => 'Подписка уже подтверждена',
            'platform' => $platform,
            'status' => 'confirmed'
        ]);
    }

    $update = $pdo->prepare("\n        UPDATE subscriptions\n        SET {$column} = 'pending'\n        WHERE participant_id = :participant_id\n        LIMIT 1\n    ");

    $update->execute([
        ':participant_id' => $participantId
    ]);

    json_response([
        'success' => true,
        'message' => 'Запрос отправлен администратору на проверку',
        'platform' => $platform,
        'status' => 'pending'
    ]);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Ошибка отправки запроса на проверку',
        'error' => $e->getMessage()
    ], 500);
}
