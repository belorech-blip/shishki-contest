<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

$data = get_json_input();
$login = app_clean($data['login'] ?? '');
$password = (string)($data['password'] ?? '');
$phone = app_normalize_phone($login);

if ($phone === '' || mb_strlen(app_phone_digits($phone)) < 10) json_response(['success' => false, 'message' => 'Введите корректный телефон'], 422);
if (mb_strlen($password) < 6) json_response(['success' => false, 'message' => 'Введите пароль'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $stmt = $pdo->prepare("SELECT id, name, phone, agency, password_hash, status FROM participants WHERE phone = :phone LIMIT 1");
    $stmt->execute([':phone' => $phone]);
    $participant = $stmt->fetch();

    if (!$participant) json_response(['success' => false, 'message' => 'Участник не найден'], 401);
    if (($participant['status'] ?? '') !== 'active') json_response(['success' => false, 'message' => 'Доступ к кабинету закрыт'], 403);
    if (!password_verify($password, (string)$participant['password_hash'])) json_response(['success' => false, 'message' => 'Неверный пароль'], 401);

    $token = app_token();

    if (app_column_exists($pdo, 'participants', 'last_login_at')) {
        $update = $pdo->prepare("UPDATE participants SET token = :token, last_login_at = NOW() WHERE id = :id LIMIT 1");
    } else {
        $update = $pdo->prepare("UPDATE participants SET token = :token WHERE id = :id LIMIT 1");
    }

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
