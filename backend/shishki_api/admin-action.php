<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

$data = get_json_input();
$action = app_clean($data['action'] ?? '');
$entity = app_clean($data['entity'] ?? '');
$id = (int)($data['id'] ?? 0);
$platform = app_clean($data['platform'] ?? '');
$comment = app_clean($data['comment'] ?? '');

if ($action === '' || $entity === '' || $id <= 0) json_response(['success' => false, 'message' => 'Некорректные параметры действия'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);
    $admin = app_current_admin($pdo);
    $adminId = (int)$admin['id'];

    $pdo->beginTransaction();

    if ($entity === 'subscription') {
        $column = app_platform_column($platform);
        if (!$column) json_response(['success' => false, 'message' => 'Неизвестная соцсеть'], 422);
        $status = $action === 'confirm' ? 'confirmed' : ($action === 'reject' ? 'rejected' : null);
        if (!$status) json_response(['success' => false, 'message' => 'Неизвестное действие для подписки'], 422);
        app_get_subscriptions($pdo, $id);
        $stmt = $pdo->prepare("UPDATE subscriptions SET {$column} = :status, admin_comment = :admin_comment WHERE participant_id = :participant_id LIMIT 1");
        $stmt->execute([':status' => $status, ':admin_comment' => $comment ?: null, ':participant_id' => $id]);
        if ($status === 'confirmed') app_ensure_participant_rewards($pdo, $id);

    } elseif ($entity === 'publication') {
        $status = $action === 'confirm' ? 'confirmed' : ($action === 'reject' ? 'rejected' : null);
        if (!$status) json_response(['success' => false, 'message' => 'Неизвестное действие для публикации'], 422);
        $stmt = $pdo->prepare("UPDATE publications SET status = :status, admin_comment = :admin_comment, verified_at = NOW(), verified_by = :verified_by WHERE id = :id LIMIT 1");
        $stmt->execute([':status' => $status, ':admin_comment' => $comment ?: null, ':verified_by' => $adminId, ':id' => $id]);
        $participantStmt = $pdo->prepare("SELECT participant_id FROM publications WHERE id = :id LIMIT 1");
        $participantStmt->execute([':id' => $id]);
        $participantId = (int)$participantStmt->fetchColumn();
        if ($participantId && $status === 'confirmed') app_ensure_participant_rewards($pdo, $participantId);

    } elseif ($entity === 'deal') {
        $status = $action === 'confirm' ? 'confirmed' : ($action === 'reject' ? 'rejected' : null);
        if (!$status) json_response(['success' => false, 'message' => 'Неизвестное действие для сделки'], 422);
        $dealStmt = $pdo->prepare("SELECT participant_id, ticket_created FROM deals WHERE id = :id LIMIT 1");
        $dealStmt->execute([':id' => $id]);
        $deal = $dealStmt->fetch();
        if (!$deal) json_response(['success' => false, 'message' => 'Сделка не найдена'], 404);
        $stmt = $pdo->prepare("UPDATE deals SET status = :status, admin_comment = :admin_comment, verified_at = NOW(), verified_by = :verified_by WHERE id = :id LIMIT 1");
        $stmt->execute([':status' => $status, ':admin_comment' => $comment ?: null, ':verified_by' => $adminId, ':id' => $id]);
        if ($status === 'confirmed' && (int)$deal['ticket_created'] !== 1) {
            app_create_ticket($pdo, (int)$deal['participant_id'], 'deal', $id, '3 билета за подтверждённую сделку', 3);
            $mark = $pdo->prepare("UPDATE deals SET ticket_created = 1 WHERE id = :id LIMIT 1");
            $mark->execute([':id' => $id]);
        }

    } elseif ($entity === 'participant' && $action === 'manual_ticket') {
        $exists = $pdo->prepare("SELECT id FROM participants WHERE id = :id LIMIT 1");
        $exists->execute([':id' => $id]);
        if (!$exists->fetch()) json_response(['success' => false, 'message' => 'Участник не найден'], 404);
        app_create_ticket($pdo, $id, 'manual', null, $comment ?: 'Ручное начисление билета администратором', 1);

    } elseif ($entity === 'prize' && $action === 'issue') {
        $stmt = $pdo->prepare("UPDATE prizes SET status = 'issued', admin_comment = :admin_comment, issued_at = NOW() WHERE id = :id LIMIT 1");
        $stmt->execute([':admin_comment' => $comment ?: null, ':id' => $id]);

    } else {
        json_response(['success' => false, 'message' => 'Действие не поддерживается'], 422);
    }

    $pdo->commit();
    json_response(['success' => true, 'message' => 'Действие выполнено']);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['success' => false, 'message' => 'Ошибка выполнения действия', 'error' => $e->getMessage()], 500);
}
