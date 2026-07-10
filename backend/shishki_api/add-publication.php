<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

$data = get_json_input();
$publishDate = app_clean($data['publish_date'] ?? '');
$platform = app_clean($data['platform'] ?? '');
$url = app_clean($data['url'] ?? '');
$comment = app_clean($data['comment'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishDate)) json_response(['success' => false, 'message' => 'Введите дату публикации'], 422);
if (!in_array($platform, ['vk', 'max', 'telegram', 'instagram'], true)) json_response(['success' => false, 'message' => 'Выберите соцсеть'], 422);
if (!preg_match('#^https?://#i', $url)) json_response(['success' => false, 'message' => 'Ссылка должна начинаться с http или https'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $participant = app_current_participant($pdo);
    $participantId = (int)$participant['id'];

    $stmt = $pdo->prepare("INSERT INTO publications (participant_id, publish_date, platform, url, comment, status) VALUES (:participant_id, :publish_date, :platform, :url, :comment, 'pending')");
    $stmt->execute([':participant_id' => $participantId, ':publish_date' => $publishDate, ':platform' => $platform, ':url' => $url, ':comment' => $comment ?: null]);

    json_response(['success' => true, 'message' => 'Публикация отправлена на проверку', 'publication_id' => (int)$pdo->lastInsertId()]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка отправки публикации', 'error' => $e->getMessage()], 500);
}
