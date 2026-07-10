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
    if (str_starts_with($phone, '8')) $phone = '+7' . substr($phone, 1);
    if (!str_starts_with($phone, '+') && str_starts_with($phone, '7')) $phone = '+' . $phone;
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
    if (!$authorization && isset($_SERVER['HTTP_AUTHORIZATION'])) $authorization = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if (!$authorization && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $authorization = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) return trim($matches[1]);
    return '';
}

function app_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
    $stmt->execute([':table_name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function app_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
    $stmt->execute([':column_name' => $column]);
    return (bool)$stmt->fetch();
}

function app_enum_values(PDO $pdo, string $table, string $column): array
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
    $stmt->execute([':column_name' => $column]);
    $row = $stmt->fetch();
    if (!$row || empty($row['Type'])) return [];
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string)$row['Type'], $matches);
    return array_map('stripcslashes', $matches[1] ?? []);
}

function app_enum_has(PDO $pdo, string $table, string $column, string $value): bool
{
    return in_array($value, app_enum_values($pdo, $table, $column), true);
}

function app_ensure_core_schema(PDO $pdo): void
{
    if (app_table_exists($pdo, 'admins') && !app_column_exists($pdo, 'admins', 'token')) {
        $pdo->exec("ALTER TABLE `admins` ADD `token` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `password_hash`");
    }
    if (app_table_exists($pdo, 'subscriptions') && !app_column_exists($pdo, 'subscriptions', 'telegram_agents_status')) {
        $pdo->exec("ALTER TABLE `subscriptions` ADD `telegram_agents_status` enum('not_requested','pending','confirmed','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_requested' AFTER `telegram_status`");
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

function app_ticket_reason(PDO $pdo, string $preferred): string
{
    return app_enum_has($pdo, 'tickets', 'reason', $preferred) ? $preferred : 'manual';
}

function app_create_ticket(PDO $pdo, int $participantId, string $preferredReason, ?int $sourceId, string $comment, int $quantity = 1): void
{
    $reason = app_ticket_reason($pdo, $preferredReason);
    if ($sourceId !== null) {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE participant_id = :participant_id AND source_id = :source_id AND reason = :reason");
        $exists->execute([':participant_id' => $participantId, ':source_id' => $sourceId, ':reason' => $reason]);
        $already = (int)$exists->fetchColumn();
    } else {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE participant_id = :participant_id AND reason = :reason AND admin_comment = :admin_comment");
        $exists->execute([':participant_id' => $participantId, ':reason' => $reason, ':admin_comment' => $comment]);
        $already = (int)$exists->fetchColumn();
    }
    $need = max(0, $quantity - $already);
    for ($i = 0; $i < $need; $i++) {
        $stmt = $pdo->prepare("INSERT INTO tickets (participant_id, ticket_number, reason, source_id, admin_comment) VALUES (:participant_id, NULL, :reason, :source_id, :admin_comment)");
        $stmt->execute([':participant_id' => $participantId, ':reason' => $reason, ':source_id' => $sourceId, ':admin_comment' => $comment]);
        $ticketId = (int)$pdo->lastInsertId();
        $ticketNumber = 'SH-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);
        $update = $pdo->prepare("UPDATE tickets SET ticket_number = :ticket_number WHERE id = :id LIMIT 1");
        $update->execute([':ticket_number' => $ticketNumber, ':id' => $ticketId]);
    }
}

function app_prize_type(PDO $pdo, string $preferred): string
{
    return app_enum_has($pdo, 'prizes', 'prize_type', $preferred) ? $preferred : 'manual';
}

function app_create_prize_if_missing(PDO $pdo, int $participantId, string $preferredType, string $status, string $comment): void
{
    $type = app_prize_type($pdo, $preferredType);
    $exists = $pdo->prepare("SELECT id FROM prizes WHERE participant_id = :participant_id AND prize_type = :prize_type LIMIT 1");
    $exists->execute([':participant_id' => $participantId, ':prize_type' => $type]);
    if ($exists->fetch()) return;
    $stmt = $pdo->prepare("INSERT INTO prizes (participant_id, prize_type, status, admin_comment) VALUES (:participant_id, :prize_type, :status, :admin_comment)");
    $stmt->execute([':participant_id' => $participantId, ':prize_type' => $type, ':status' => $status, ':admin_comment' => $comment]);
}

function app_get_subscriptions(PDO $pdo, int $participantId): array
{
    $stmt = $pdo->prepare("SELECT vk_status, max_status, telegram_status, telegram_agents_status, instagram_status FROM subscriptions WHERE participant_id = :participant_id LIMIT 1");
    $stmt->execute([':participant_id' => $participantId]);
    $subscriptions = $stmt->fetch();
    if ($subscriptions) return $subscriptions;
    $create = $pdo->prepare("INSERT INTO subscriptions (participant_id, vk_status, max_status, telegram_status, telegram_agents_status, instagram_status) VALUES (:participant_id, 'not_requested', 'not_requested', 'not_requested', 'not_requested', 'not_requested')");
    $create->execute([':participant_id' => $participantId]);
    return ['vk_status' => 'not_requested', 'max_status' => 'not_requested', 'telegram_status' => 'not_requested', 'telegram_agents_status' => 'not_requested', 'instagram_status' => 'not_requested'];
}

function app_ensure_participant_rewards(PDO $pdo, int $participantId): void
{
    $confirmedPublications = app_count($pdo, "SELECT COUNT(DISTINCT publish_date) FROM publications WHERE participant_id = :participant_id AND status = 'confirmed'", [':participant_id' => $participantId]);
    if ($confirmedPublications >= 20) app_create_prize_if_missing($pdo, $participantId, 'fuel_20', 'available', 'Топливная карта 3 000 ₽ за 20 дней публикаций');
    if ($confirmedPublications >= 30) app_create_ticket($pdo, $participantId, 'publications_30', null, 'Билет за 30 дней публикаций', 1);
    $subscriptions = app_get_subscriptions($pdo, $participantId);
    $allSocialsConfirmed = true;
    foreach (['vk_status', 'max_status', 'telegram_status', 'telegram_agents_status', 'instagram_status'] as $key) {
        if (($subscriptions[$key] ?? '') !== 'confirmed') { $allSocialsConfirmed = false; break; }
    }
    if ($allSocialsConfirmed) app_create_ticket($pdo, $participantId, 'socials_5', null, 'Билет за подтверждённую подписку на 5 соцсетей', 1);
}

function app_platform_column(string $platform): ?string
{
    $columns = ['vk' => 'vk_status', 'max' => 'max_status', 'telegram' => 'telegram_status', 'telegram_agents' => 'telegram_agents_status', 'instagram' => 'instagram_status'];
    return $columns[$platform] ?? null;
}
