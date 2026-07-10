<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

$data = get_json_input();

$name = app_clean($data['name'] ?? '');
$agency = app_clean($data['agency'] ?? '');
$phone = app_normalize_phone(app_clean($data['phone'] ?? ''));
$password = (string)($data['password'] ?? '');
$passwordRepeat = (string)($data['password_repeat'] ?? '');

if ($name === '' || mb_strlen($name) < 2) json_response(['success' => false, 'message' => 'Введите имя'], 422);
if ($agency === '' || mb_strlen($agency) < 2) json_response(['success' => false, 'message' => 'Введите агентство'], 422);
if ($phone === '' || mb_strlen(app_phone_digits($phone)) < 10) json_response(['success' => false, 'message' => 'Введите корректный телефон'], 422);
if (mb_strlen($password) < 6) json_response(['success' => false, 'message' => 'Пароль должен быть не короче 6 символов'], 422);
if ($password !== $passwordRepeat) json_response(['success' => false, 'message' => 'Пароли не совпадают'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $check = $pdo->prepare("SELECT id FROM participants WHERE phone = :phone LIMIT 1");
    $check->execute([':phone' => $phone]);
    if ($check->fetch()) json_response(['success' => false, 'message' => 'Участник с таким телефоном уже зарегистрирован'], 409);

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $token = app_token();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO participants (name, phone, agency, password_hash, token, campaign, source, status) VALUES (:name, :phone, :agency, :password_hash, :token, 'shishki_realtors_2026', 'register_form', 'active')");
    $stmt->execute([':name' => $name, ':phone' => $phone, ':agency' => $agency, ':password_hash' => $passwordHash, ':token' => $token]);

    $participantId = (int)$pdo->lastInsertId();
    $subStmt = $pdo->prepare("INSERT INTO subscriptions (participant_id, vk_status, max_status, telegram_status, telegram_agents_status, instagram_status) VALUES (:participant_id, 'not_requested', 'not_requested', 'not_requested', 'not_requested', 'not_requested')");
    $subStmt->execute([':participant_id' => $participantId]);

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
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['success' => false, 'message' => 'Ошибка регистрации', 'error' => $e->getMessage()], 500);
}
