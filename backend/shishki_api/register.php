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

function clean_string(?string $value): string
{
    return trim((string)$value);
}

function normalize_phone(string $phone): string
{
    $phone = preg_replace('/[^\d+]/', '', $phone);

    if (str_starts_with($phone, '8')) {
        $phone = '+7' . substr($phone, 1);
    }

    if (!str_starts_with($phone, '+') && str_starts_with($phone, '7')) {
        $phone = '+' . $phone;
    }

    return $phone;
}

function phone_digits(string $phone): string
{
    return preg_replace('/\D/', '', $phone);
}

function make_token(): string
{
    return bin2hex(random_bytes(32));
}

$data = get_json_input();

$name = clean_string($data['name'] ?? '');
$agency = clean_string($data['agency'] ?? '');
$phone = normalize_phone(clean_string($data['phone'] ?? ''));

$password = (string)($data['password'] ?? '');
$passwordRepeat = (string)($data['password_repeat'] ?? '');

if ($name === '' || mb_strlen($name) < 2) {
    json_response([
        'success' => false,
        'message' => 'Введите имя'
    ], 422);
}

if ($agency === '' || mb_strlen($agency) < 2) {
    json_response([
        'success' => false,
        'message' => 'Введите агентство'
    ], 422);
}

if ($phone === '' || mb_strlen(phone_digits($phone)) < 10) {
    json_response([
        'success' => false,
        'message' => 'Введите корректный телефон'
    ], 422);
}

if (mb_strlen($password) < 6) {
    json_response([
        'success' => false,
        'message' => 'Пароль должен быть не короче 6 символов'
    ], 422);
}

if ($password !== $passwordRepeat) {
    json_response([
        'success' => false,
        'message' => 'Пароли не совпадают'
    ], 422);
}

try {
    $pdo = db();

    $check = $pdo->prepare("\n        SELECT id\n        FROM participants\n        WHERE phone = :phone\n        LIMIT 1\n    ");

    $check->execute([
        ':phone' => $phone
    ]);

    if ($check->fetch()) {
        json_response([
            'success' => false,
            'message' => 'Участник с таким телефоном уже зарегистрирован'
        ], 409);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $token = make_token();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("\n        INSERT INTO participants (\n            name,\n            phone,\n            agency,\n            password_hash,\n            token,\n            campaign,\n            source,\n            status\n        ) VALUES (\n            :name,\n            :phone,\n            :agency,\n            :password_hash,\n            :token,\n            'shishki_realtors_2026',\n            'register_form',\n            'active'\n        )\n    ");

    $stmt->execute([
        ':name' => $name,
        ':phone' => $phone,
        ':agency' => $agency,
        ':password_hash' => $passwordHash,
        ':token' => $token
    ]);

    $participantId = (int)$pdo->lastInsertId();

    $subStmt = $pdo->prepare("\n        INSERT INTO subscriptions (\n            participant_id,\n            vk_status,\n            max_status,\n            telegram_status,\n            telegram_agents_status,\n            instagram_status\n        ) VALUES (\n            :participant_id,\n            'not_requested',\n            'not_requested',\n            'not_requested',\n            'not_requested',\n            'not_requested'\n        )\n    ");

    $subStmt->execute([
        ':participant_id' => $participantId
    ]);

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'Регистрация успешна',
        'participant_id' => $participantId,
        'name' => $name,
        'agency' => $agency,
        'phone' => $phone,
        'token' => $token,
        'redirect' => 'https://шишки.рус/shishki-cabinet'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'success' => false,
        'message' => 'Ошибка регистрации',
        'error' => $e->getMessage()
    ], 500);
}
