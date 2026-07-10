<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

$data = get_json_input();
$clientName = app_clean($data['client_name'] ?? '');
$clientPhone = app_normalize_phone(app_clean($data['client_phone'] ?? ''));
$plotNumber = app_clean($data['plot_number'] ?? '');
$dealDate = app_clean($data['deal_date'] ?? '');
$comment = app_clean($data['comment'] ?? '');

if ($clientName === '' || mb_strlen($clientName) < 2) json_response(['success' => false, 'message' => 'Введите ФИО клиента'], 422);
if ($clientPhone === '' || mb_strlen(app_phone_digits($clientPhone)) < 10) json_response(['success' => false, 'message' => 'Введите телефон клиента'], 422);
if ($dealDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dealDate)) json_response(['success' => false, 'message' => 'Введите корректную дату сделки'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $participant = app_current_participant($pdo);
    $participantId = (int)$participant['id'];

    $stmt = $pdo->prepare("INSERT INTO deals (participant_id, client_name, client_phone, plot_number, deal_date, status, comment) VALUES (:participant_id, :client_name, :client_phone, :plot_number, :deal_date, 'pending', :comment)");
    $stmt->execute([':participant_id' => $participantId, ':client_name' => $clientName, ':client_phone' => $clientPhone, ':plot_number' => $plotNumber ?: null, ':deal_date' => $dealDate ?: null, ':comment' => $comment ?: null]);

    json_response(['success' => true, 'message' => 'Сделка отправлена на проверку', 'deal_id' => (int)$pdo->lastInsertId()]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка отправки сделки', 'error' => $e->getMessage()], 500);
}
