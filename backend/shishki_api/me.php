<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('GET');

function shk_default_subscriptions(): array
{
    return [
        'vk_status' => 'not_requested',
        'max_status' => 'not_requested',
        'telegram_status' => 'not_requested',
        'telegram_agents_status' => 'not_requested',
        'instagram_status' => 'not_requested',
    ];
}

function shk_get_subscriptions(PDO $pdo, int $participantId): array
{
    if (!app_table_exists($pdo, 'subscriptions')) {
        return shk_default_subscriptions();
    }

    $telegramAgentsSelect = app_column_exists($pdo, 'subscriptions', 'telegram_agents_status')
        ? 'telegram_agents_status'
        : "'not_requested' AS telegram_agents_status";

    $stmt = $pdo->prepare("SELECT vk_status, max_status, telegram_status, {$telegramAgentsSelect}, instagram_status FROM subscriptions WHERE participant_id = :participant_id LIMIT 1");
    $stmt->execute([':participant_id' => $participantId]);
    $row = $stmt->fetch();

    return $row ? array_merge(shk_default_subscriptions(), $row) : shk_default_subscriptions();
}

function shk_fetch_rows(PDO $pdo, string $table, int $participantId, string $select, int $limit): array
{
    if (!app_table_exists($pdo, $table)) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM `{$table}` WHERE participant_id = :participant_id ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([':participant_id' => $participantId]);
    return $stmt->fetchAll();
}

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $participant = app_current_participant($pdo);
    $participantId = (int)$participant['id'];

    app_sync_socials_ticket($pdo, $participantId);
    app_sync_publications_ticket($pdo, $participantId);

    $subscriptions = shk_get_subscriptions($pdo, $participantId);

    $publications = shk_fetch_rows(
        $pdo,
        'publications',
        $participantId,
        'id, publish_date, platform, url, comment, status, admin_comment, created_at',
        100
    );

    $deals = shk_fetch_rows(
        $pdo,
        'deals',
        $participantId,
        'id, client_name, client_phone, plot_number, deal_date, status, comment, admin_comment, created_at',
        100
    );

    $ticketsSelect = app_column_exists($pdo, 'tickets', 'source_id')
        ? 'id, ticket_number, reason, source_id, admin_comment, created_at'
        : 'id, ticket_number, reason, NULL AS source_id, admin_comment, created_at';

    $tickets = shk_fetch_rows($pdo, 'tickets', $participantId, $ticketsSelect, 200);

    $confirmedPublications = app_table_exists($pdo, 'publications')
        ? app_count($pdo, "SELECT COUNT(DISTINCT publish_date) FROM publications WHERE participant_id = :participant_id AND status = 'confirmed'", [':participant_id' => $participantId])
        : 0;

    $confirmedDeals = app_table_exists($pdo, 'deals')
        ? app_count($pdo, "SELECT COUNT(*) FROM deals WHERE participant_id = :participant_id AND status = 'confirmed'", [':participant_id' => $participantId])
        : 0;

    $ticketsCount = app_table_exists($pdo, 'tickets')
        ? app_count($pdo, "SELECT COUNT(*) FROM tickets WHERE participant_id = :participant_id", [':participant_id' => $participantId])
        : 0;

    $publications30Status = $confirmedPublications >= 30 ? 'available' : 'pending';

    json_response([
        'success' => true,
        'participant' => [
            'id' => $participantId,
            'name' => $participant['name'] ?? '',
            'phone' => $participant['phone'] ?? '',
            'agency' => $participant['agency'] ?? '',
            'campaign' => $participant['campaign'] ?? '',
            'source' => $participant['source'] ?? '',
            'status' => $participant['status'] ?? '',
            'created_at' => $participant['created_at'] ?? ''
        ],
        'stats' => [
            'tickets_count' => $ticketsCount,
            'publications_confirmed' => $confirmedPublications,
            'publications_total' => count($publications),
            'deals_confirmed' => $confirmedDeals,
            'deals_total' => count($deals),
            'draw_date' => '30.09.2026',
            'countdown_target' => '2026-09-29T23:59:00+05:00',
            'fuel20_status' => $confirmedPublications >= 20 ? 'available' : 'pending',
            'publications30_status' => $publications30Status,
            'fuel30_status' => $publications30Status,
            'ozon20_status' => 'draw',
            'ozon30_status' => 'draw',
            'plot_status' => 'draw'
        ],
        'subscriptions' => [
            'vk' => $subscriptions['vk_status'] ?? 'not_requested',
            'max' => $subscriptions['max_status'] ?? 'not_requested',
            'telegram' => $subscriptions['telegram_status'] ?? 'not_requested',
            'telegram_agents' => $subscriptions['telegram_agents_status'] ?? 'not_requested',
            'instagram' => $subscriptions['instagram_status'] ?? 'not_requested'
        ],
        'tickets' => $tickets,
        'publications' => $publications,
        'deals' => $deals
    ]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка загрузки кабинета', 'error' => $e->getMessage()], 500);
}
