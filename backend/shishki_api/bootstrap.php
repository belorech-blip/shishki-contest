<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('json_response')) {
    function json_response(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

if (!function_exists('get_json_input')) {
    function get_json_input(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    json_response(['success' => true]);
}

function app_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        json_response(['success' => false, 'message' => 'Метод не разрешен'], 405);
    }
}

function app_clean(?string $value): string
{
    return trim((string)$value);
}

function app_normalize_phone(string $phone): string
{
    $phone = preg_replace('/[^\d+]/', '', $phone) ?: '';
    if (str_starts_with($phone, '8')) {
        $phone = '+7' . substr($phone, 1);
    }
    if (!str_starts_with($phone, '+') && str_starts_with($phone, '7')) {
        $phone = '+' . $phone;
    }
    return $phone;
}

function app_phone_digits(string $phone): string
{
    return preg_replace('/\D/', '', $phone) ?: '';
}

function app_token(): string
{
    return bin2hex(random_bytes(32));
}

function app_get_bearer_token(): string
{
    $authorization = '';

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (mb_strtolower((string)$key) === 'authorization') {
                $authorization = (string)$value;
                break;
            }
        }
    }

    if (!$authorization && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = (string)$_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!$authorization && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function app_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
    $stmt->execute([':table_name' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function app_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function app_column_type(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name LIMIT 1');
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (string)($stmt->fetchColumn() ?: '');
}

function app_enum_values(PDO $pdo, string $table, string $column): array
{
    $type = app_column_type($pdo, $table, $column);
    if ($type === '') return [];
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches);
    return array_map('stripcslashes', $matches[1] ?? []);
}

function app_enum_has(PDO $pdo, string $table, string $column, string $value): bool
{
    $values = app_enum_values($pdo, $table, $column);
    return !$values || in_array($value, $values, true);
}

function app_ensure_core_schema(PDO $pdo): void
{
    if (app_table_exists($pdo, 'admins') && !app_column_exists($pdo, 'admins', 'token')) {
        $pdo->exec("ALTER TABLE `admins` ADD `token` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `password_hash`");
    }

    if (app_table_exists($pdo, 'subscriptions') && !app_column_exists($pdo, 'subscriptions', 'telegram_agents_status')) {
        $pdo->exec("ALTER TABLE `subscriptions` ADD `telegram_agents_status` enum('not_requested','pending','confirmed','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_requested' AFTER `telegram_status`");
    }

    if (app_table_exists($pdo, 'subscriptions') && !app_column_exists($pdo, 'subscriptions', 'max_draw_status')) {
        $pdo->exec("ALTER TABLE `subscriptions` ADD `max_draw_status` enum('not_requested','pending','confirmed','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_requested' AFTER `max_status`");
    }

    if (app_table_exists($pdo, 'tickets') && !app_enum_has($pdo, 'tickets', 'reason', 'socials_5')) {
        $pdo->exec("ALTER TABLE `tickets` MODIFY `reason` enum('deal','publications_30','socials_5','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual'");
    }

    if (app_table_exists($pdo, 'prizes') && !app_enum_has($pdo, 'prizes', 'prize_type', 'ozon_20000')) {
        $pdo->exec("ALTER TABLE `prizes` MODIFY `prize_type` enum('fuel_20','fuel_30','ozon_10000','ozon_20000','ozon_30000','plot','manual') COLLATE utf8mb4_unicode_ci NOT NULL");
    }
}

function app_current_participant(PDO $pdo): array
{
    $token = app_get_bearer_token();
    if ($token === '') json_response(['success' => false, 'message' => 'Токен не передан'], 401);

    $stmt = $pdo->prepare("SELECT id, name, phone, agency, campaign, source, status, created_at FROM participants WHERE token = :token AND status = 'active' LIMIT 1");
    $stmt->execute([':token' => $token]);
    $participant = $stmt->fetch();
    if (!$participant) json_response(['success' => false, 'message' => 'Участник не найден или токен устарел'], 401);
    return $participant;
}

function app_current_admin(PDO $pdo): array
{
    $token = app_get_bearer_token();
    if ($token === '') json_response(['success' => false, 'message' => 'Токен администратора не передан'], 401);

    $stmt = $pdo->prepare("SELECT id, login, name, role, status FROM admins WHERE token = :token AND status = 'active' LIMIT 1");
    $stmt->execute([':token' => $token]);
    $admin = $stmt->fetch();
    if (!$admin) json_response(['success' => false, 'message' => 'Администратор не найден или токен устарел'], 401);
    return $admin;
}

function app_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function app_platform_column(string $platform): ?string
{
    $map = [
        'vk' => 'vk_status',
        'max' => 'max_status',
        'max_draw' => 'max_draw_status',
        'telegram' => 'telegram_status',
        'telegram_agents' => 'telegram_agents_status',
        'instagram' => 'instagram_status',
    ];
    return $map[$platform] ?? null;
}

function app_default_subscriptions(): array
{
    return [
        'vk_status' => 'not_requested',
        'max_status' => 'not_requested',
        'max_draw_status' => 'not_requested',
        'telegram_status' => 'not_requested',
        'telegram_agents_status' => 'not_requested',
        'instagram_status' => 'not_requested',
    ];
}

function app_get_subscriptions(PDO $pdo, int $participantId): array
{
    if (!app_table_exists($pdo, 'subscriptions')) return app_default_subscriptions();

    app_ensure_core_schema($pdo);
    $columns = ['vk_status', 'max_status', 'max_draw_status', 'telegram_status', 'instagram_status'];
    if (app_column_exists($pdo, 'subscriptions', 'telegram_agents_status')) {
        $columns[] = 'telegram_agents_status';
    }

    $selectParts = $columns;
    if (!in_array('telegram_agents_status', $columns, true)) {
        $selectParts[] = "'not_requested' AS telegram_agents_status";
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $selectParts) . ' FROM subscriptions WHERE participant_id = :participant_id LIMIT 1');
    $stmt->execute([':participant_id' => $participantId]);
    $row = $stmt->fetch();

    if (!$row) {
        $insertColumns = ['participant_id'];
        $placeholders = [':participant_id'];
        $params = [':participant_id' => $participantId];

        foreach ($columns as $column) {
            if (app_column_exists($pdo, 'subscriptions', $column)) {
                $insertColumns[] = $column;
                $placeholders[] = "'not_requested'";
            }
        }

        $sql = 'INSERT INTO subscriptions (`' . implode('`, `', $insertColumns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $insert = $pdo->prepare($sql);
        $insert->execute($params);
        return app_default_subscriptions();
    }

    return array_merge(app_default_subscriptions(), $row);
}

function app_ticket_reason(PDO $pdo, string $preferred): string
{
    return app_enum_has($pdo, 'tickets', 'reason', $preferred) ? $preferred : 'manual';
}

function app_next_ticket_number(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 80; $attempt++) {
        $number = 'SH-' . random_int(100000, 999999);
        $stmt = $pdo->prepare('SELECT id FROM tickets WHERE ticket_number = :ticket_number LIMIT 1');
        $stmt->execute([':ticket_number' => $number]);
        if (!$stmt->fetch()) return $number;
    }

    return 'SH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function app_create_ticket(PDO $pdo, int $participantId, string $preferredReason, ?int $sourceId, string $comment, int $quantity = 1): void
{
    if (!app_table_exists($pdo, 'tickets')) return;

    $reason = app_ticket_reason($pdo, $preferredReason);
    $quantity = max(1, $quantity);

    for ($i = 0; $i < $quantity; $i++) {
        $columns = ['participant_id', 'ticket_number', 'reason'];
        $placeholders = [':participant_id', ':ticket_number', ':reason'];
        $params = [
            ':participant_id' => $participantId,
            ':ticket_number' => app_next_ticket_number($pdo),
            ':reason' => $reason,
        ];

        if ($sourceId !== null && app_column_exists($pdo, 'tickets', 'source_id')) {
            $columns[] = 'source_id';
            $placeholders[] = ':source_id';
            $params[':source_id'] = $sourceId;
        }

        if (app_column_exists($pdo, 'tickets', 'admin_comment')) {
            $columns[] = 'admin_comment';
            $placeholders[] = ':admin_comment';
            $params[':admin_comment'] = $comment;
        }

        if (app_column_exists($pdo, 'tickets', 'created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        $sql = 'INSERT INTO tickets (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

function app_has_ticket(PDO $pdo, int $participantId, string $preferredReason): bool
{
    if (!app_table_exists($pdo, 'tickets')) return false;

    $reason = app_ticket_reason($pdo, $preferredReason);
    $stmt = $pdo->prepare('SELECT id FROM tickets WHERE participant_id = :participant_id AND reason = :reason LIMIT 1');
    $stmt->execute([':participant_id' => $participantId, ':reason' => $reason]);
    return (bool)$stmt->fetch();
}

function app_sync_socials_ticket(PDO $pdo, int $participantId): void
{
    if (!app_table_exists($pdo, 'subscriptions')) return;

    $row = app_get_subscriptions($pdo, $participantId);
    $allConfirmed =
        ($row['vk_status'] ?? '') === 'confirmed' &&
        ($row['max_status'] ?? '') === 'confirmed' &&
        ($row['max_draw_status'] ?? '') === 'confirmed' &&
        ($row['telegram_status'] ?? '') === 'confirmed' &&
        ($row['telegram_agents_status'] ?? '') === 'confirmed' &&
        ($row['instagram_status'] ?? '') === 'confirmed';

    if ($allConfirmed && !app_has_ticket($pdo, $participantId, 'socials_5')) {
        app_create_ticket($pdo, $participantId, 'socials_5', null, 'За 6 подтвержденных каналов', 1);
    }
}

function app_sync_publications_ticket(PDO $pdo, int $participantId): void
{
    if (!app_table_exists($pdo, 'publications')) return;

    $confirmedDays = app_count(
        $pdo,
        "SELECT COUNT(DISTINCT publish_date) FROM publications WHERE participant_id = :participant_id AND status = 'confirmed'",
        [':participant_id' => $participantId]
    );

    if ($confirmedDays >= 30 && !app_has_ticket($pdo, $participantId, 'publications_30')) {
        app_create_ticket($pdo, $participantId, 'publications_30', null, 'За 30 дней публикаций', 1);
    }
}
