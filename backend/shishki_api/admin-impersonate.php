<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

try {
    $pdo = db();
    app_ensure_core_schema($pdo);
    $admin = app_current_admin($pdo);
    $data = get_json_input();
    $participantId = (int)($data['participant_id'] ?? 0);

    if ($participantId <= 0) {
        json_response(['success' => false, 'message' => 'Участник не указан'], 422);
    }

    $stmt = $pdo->prepare("SELECT id, name, status FROM participants WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $participantId]);
    $participant = $stmt->fetch();

    if (!$participant || ($participant['status'] ?? '') !== 'active') {
        json_response(['success' => false, 'message' => 'Активный участник не найден'], 404);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_impersonation_tokens` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
        `admin_id` bigint unsigned NOT NULL,
        `participant_id` bigint unsigned NOT NULL,
        `expires_at` datetime NOT NULL,
        `used_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_token_hash` (`token_hash`),
        KEY `idx_participant_id` (`participant_id`),
        KEY `idx_expires_at` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("DELETE FROM admin_impersonation_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $insert = $pdo->prepare("INSERT INTO admin_impersonation_tokens (token_hash, admin_id, participant_id, expires_at) VALUES (:token_hash, :admin_id, :participant_id, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
    $insert->execute([
        ':token_hash' => $tokenHash,
        ':admin_id' => (int)$admin['id'],
        ':participant_id' => $participantId,
    ]);

    json_response([
        'success' => true,
        'token' => $rawToken,
        'participant_name' => $participant['name'] ?? '',
        'expires_in' => 300,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Не удалось открыть кабинет участника', 'error' => $e->getMessage()], 500);
}
