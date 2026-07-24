<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

try {
    $pdo = db();
    $data = get_json_input();
    $rawToken = app_clean($data['token'] ?? '');

    if ($rawToken === '' || strlen($rawToken) < 40) {
        json_response(['success' => false, 'message' => 'Ссылка входа недействительна'], 422);
    }

    if (!app_table_exists($pdo, 'admin_impersonation_tokens')) {
        json_response(['success' => false, 'message' => 'Ссылка входа недействительна или устарела'], 404);
    }

    $pdo->beginTransaction();
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("SELECT ait.id, ait.admin_id, ait.participant_id, ait.expires_at, ait.used_at, p.name, p.token AS participant_token, p.status
        FROM admin_impersonation_tokens ait
        INNER JOIN participants p ON p.id = ait.participant_id
        WHERE ait.token_hash = :token_hash
        LIMIT 1
        FOR UPDATE");
    $stmt->execute([':token_hash' => $tokenHash]);
    $row = $stmt->fetch();

    if (!$row || $row['used_at'] !== null || strtotime((string)$row['expires_at']) < time()) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Ссылка уже использована или устарела'], 410);
    }

    if (($row['status'] ?? '') !== 'active' || empty($row['participant_token'])) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'Кабинет участника недоступен'], 403);
    }

    $update = $pdo->prepare("UPDATE admin_impersonation_tokens SET used_at = NOW() WHERE id = :id LIMIT 1");
    $update->execute([':id' => (int)$row['id']]);
    $pdo->commit();

    json_response([
        'success' => true,
        'participant_token' => $row['participant_token'],
        'participant_name' => $row['name'] ?? '',
        'admin_id' => (int)$row['admin_id'],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => 'Не удалось выполнить вход в кабинет', 'error' => $e->getMessage()], 500);
}
