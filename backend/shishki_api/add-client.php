<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

try {
    $pdo = db();
    app_ensure_core_schema($pdo);
    $participant = app_current_participant($pdo);
    $participantId = (int)$participant['id'];
    $data = get_json_input();

    $clientName = app_clean($data['client_name'] ?? '');
    $clientPhoneRaw = app_clean($data['client_phone'] ?? '');
    $comment = app_clean($data['comment'] ?? '');
    $clientPhone = app_normalize_phone($clientPhoneRaw);
    $phoneDigits = app_phone_digits($clientPhone);

    if (mb_strlen($clientName) < 2 || mb_strlen($clientName) > 255) {
        json_response(['success' => false, 'message' => 'Укажите имя клиента'], 422);
    }

    if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
        json_response(['success' => false, 'message' => 'Укажите корректный телефон клиента'], 422);
    }

    if (mb_strlen($comment) > 2000) {
        json_response(['success' => false, 'message' => 'Комментарий слишком длинный'], 422);
    }

    $existing = $pdo->prepare('SELECT id, participant_id FROM agent_clients WHERE phone_digits = :phone_digits LIMIT 1');
    $existing->execute([':phone_digits' => $phoneDigits]);
    $row = $existing->fetch();

    if ($row) {
        if ((int)$row['participant_id'] === $participantId) {
            json_response(['success' => false, 'message' => 'Этот клиент уже закреплён за вами'], 409);
        }
        json_response(['success' => false, 'message' => 'Клиент с таким номером уже закреплён в системе'], 409);
    }

    $stmt = $pdo->prepare("INSERT INTO agent_clients (participant_id, client_name, client_phone, phone_digits, comment, created_at, updated_at)
        VALUES (:participant_id, :client_name, :client_phone, :phone_digits, :comment, NOW(), NOW())");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':client_name' => $clientName,
        ':client_phone' => $clientPhone,
        ':phone_digits' => $phoneDigits,
        ':comment' => $comment,
    ]);

    json_response([
        'success' => true,
        'message' => 'Клиент закреплён за вами',
        'client' => [
            'id' => (int)$pdo->lastInsertId(),
            'client_name' => $clientName,
            'client_phone' => $clientPhone,
            'comment' => $comment,
        ],
    ]);
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        json_response(['success' => false, 'message' => 'Клиент с таким номером уже закреплён в системе'], 409);
    }
    json_response(['success' => false, 'message' => 'Не удалось закрепить клиента', 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Не удалось закрепить клиента', 'error' => $e->getMessage()], 500);
}
