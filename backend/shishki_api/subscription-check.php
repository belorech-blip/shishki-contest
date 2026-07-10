<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

$data = get_json_input();
$platform = app_clean($data['platform'] ?? '');
$column = app_platform_column($platform);

if (!$column) json_response(['success' => false, 'message' => 'Неизвестная соцсеть'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $participant = app_current_participant($pdo);
    $participantId = (int)$participant['id'];

    app_get_subscriptions($pdo, $participantId);

    $check = $pdo->prepare("SELECT {$column} AS current_status FROM subscriptions WHERE participant_id = :participant_id LIMIT 1");
    $check->execute([':participant_id' => $participantId]);
    $row = $check->fetch();

    if (($row['current_status'] ?? '') === 'confirmed') {
        json_response(['success' => true, 'message' => 'Подписка уже подтверждена', 'platform' => $platform, 'status' => 'confirmed']);
    }

    $update = $pdo->prepare("UPDATE subscriptions SET {$column} = 'pending' WHERE participant_id = :participant_id LIMIT 1");
    $update->execute([':participant_id' => $participantId]);

    json_response(['success' => true, 'message' => 'Запрос отправлен администратору на проверку', 'platform' => $platform, 'status' => 'pending']);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка отправки запроса на проверку', 'error' => $e->getMessage()], 500);
}
