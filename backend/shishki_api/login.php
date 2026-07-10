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

$login = clean_string($data['login'] ?? '');
$password = (string)($data['password'] ?? '');

$phone = normalize_phone($login);

if ($phone === '' || mb_strlen(phone_digits($phone)) < 10) {
    json_response([
        'success' => false,
        'message' => 'Введите корректный телефон'
    ], 422);
}

if (mb_strlen($password) < 6) {
    json_response([
        'success' => false,
        'message' => 'Введите пароль'
    ], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            phone,
            agency,
            password_hash,
            status
        FROM participants
        WHERE phone = :phone
        LIMIT 1
    ");

    $stmt->execute([
        ':phone' => $phone
    ]);

    $participant = $stmt->fetch();

    if (!$participant) {
        json_response([
            'success' => false,
            'message' => 'Участник не найден'
        ], 401);
    }

    if ($participant['status'] !== 'active') {
        json_response([
            'success' => false,
            'message' => 'Доступ к кабинету закрыт'
        ], 403);
    }

    if (!password_verify($password, $participant['password_hash'])) {
        json_response([
            'success' => false,
            'message' => 'Неверный пароль'
        ], 401);
    }

    $token = make_token();

    $update = $pdo->prepare("
        UPDATE participants
        SET token = :token
        WHERE id = :id
        LIMIT 1
    ");

    $update->execute([
        ':token' => $token,
        ':id' => (int)$participant['id']
    ]);

    json_response([
        'success' => true,
        'message' => 'Вход выполнен',
        'participant_id' => (int)$participant['id'],
        'name' => $participant['name'],
        'phone' => $participant['phone'],
        'agency' => $participant['agency'],
        'token' => $token,
        'redirect' => 'https://шишки.рус/shishki-cabinet'
    ]);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Ошибка входа',
        'error' => $e->getMessage()
    ], 500);
}
