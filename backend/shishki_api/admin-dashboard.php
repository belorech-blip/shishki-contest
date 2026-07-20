<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('GET');

function app_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function app_table_count_safe(PDO $pdo, string $table): int
{
    if (!app_table_exists($pdo, $table)) return 0;
    return app_count($pdo, "SELECT COUNT(*) FROM `{$table}`");
}

function app_subscriptions_select(PDO $pdo): string
{
    $telegramAgentsSelect = app_column_exists($pdo, 'subscriptions', 'telegram_agents_status')
        ? 's.telegram_agents_status'
        : "'not_requested' AS telegram_agents_status";

    return "p.id AS participant_id, p.name AS participant_name, p.phone, p.agency, s.vk_status, s.max_status, s.telegram_status, {$telegramAgentsSelect}, s.instagram_status, s.created_at, s.updated_at";
}

function app_flatten_subscriptions(array $rows, array $statuses): array
{
    $platformLabels = [
        'vk' => 'ВКонтакте',
        'max' => 'MAX',
        'telegram' => 'Telegram КП',
        'telegram_agents' => 'Telegram для агентов',
        'instagram' => 'Instagram',
    ];

    $items = [];
    foreach ($rows as $row) {
        foreach ($platformLabels as $platform => $label) {
            $status = $row[$platform . '_status'] ?? 'not_requested';
            if (!in_array($status, $statuses, true)) continue;

            $date = $row['updated_at'] ?: $row['created_at'];
            $items[] = [
                'id' => (int)$row['participant_id'],
                'participant_id' => (int)$row['participant_id'],
                'participant_name' => $row['participant_name'],
                'phone' => $row['phone'],
                'agency' => $row['agency'],
                'platform' => $platform,
                'platform_label' => $label,
                'status' => $status,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'date' => $date,
            ];
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
    });

    return $items;
}

try {
    $pdo = db();
    app_ensure_core_schema($pdo);
    $admin = app_current_admin($pdo);

    $participants = [];
    if (app_table_exists($pdo, 'participants')) {
        $participants = app_fetch_all($pdo, "SELECT p.id, p.name, p.phone, p.agency, p.status, p.created_at, COALESCE(pub.confirmed_count, 0) AS publications_confirmed, COALESCE(deal.confirmed_count, 0) AS deals_confirmed, COALESCE(ticket.ticket_count, 0) AS tickets_count FROM participants p LEFT JOIN (SELECT participant_id, COUNT(DISTINCT publish_date) AS confirmed_count FROM publications WHERE status = 'confirmed' GROUP BY participant_id) pub ON pub.participant_id = p.id LEFT JOIN (SELECT participant_id, COUNT(*) AS confirmed_count FROM deals WHERE status = 'confirmed' GROUP BY participant_id) deal ON deal.participant_id = p.id LEFT JOIN (SELECT participant_id, COUNT(*) AS ticket_count FROM tickets GROUP BY participant_id) ticket ON ticket.participant_id = p.id ORDER BY p.created_at DESC, p.id DESC LIMIT 500");
    }

    $subscriptionsPending = [];
    $subscriptionsHistory = [];
    if (app_table_exists($pdo, 'subscriptions')) {
        $select = app_subscriptions_select($pdo);
        $rows = app_fetch_all($pdo, "SELECT {$select} FROM subscriptions s INNER JOIN participants p ON p.id = s.participant_id ORDER BY COALESCE(s.updated_at, s.created_at) DESC, s.id DESC LIMIT 500");
        $subscriptionsPending = app_flatten_subscriptions($rows, ['pending']);
        $subscriptionsHistory = app_flatten_subscriptions($rows, ['confirmed', 'rejected']);
    }

    $publicationsPending = [];
    $publicationsHistory = [];
    if (app_table_exists($pdo, 'publications')) {
        $publicationBase = "SELECT pub.id, pub.participant_id, pub.publish_date, pub.platform, pub.url, pub.comment, pub.status, pub.admin_comment, pub.created_at, pub.verified_at, p.name AS participant_name, p.phone AS participant_phone, p.agency AS participant_agency FROM publications pub INNER JOIN participants p ON p.id = pub.participant_id";
        $publicationsPending = app_fetch_all($pdo, "{$publicationBase} WHERE pub.status = 'pending' ORDER BY pub.created_at DESC, pub.id DESC LIMIT 300");
        $publicationsHistory = app_fetch_all($pdo, "{$publicationBase} WHERE pub.status IN ('confirmed','rejected') ORDER BY COALESCE(pub.verified_at, pub.created_at) DESC, pub.id DESC LIMIT 300");
    }

    $dealsPending = [];
    $dealsHistory = [];
    if (app_table_exists($pdo, 'deals')) {
        $dealBase = "SELECT d.id, d.participant_id, d.client_name, d.client_phone, d.plot_number, d.deal_date, d.status, d.comment, d.admin_comment, d.created_at, d.verified_at, p.name AS participant_name, p.phone AS participant_phone, p.agency AS participant_agency FROM deals d INNER JOIN participants p ON p.id = d.participant_id";
        $dealsPending = app_fetch_all($pdo, "{$dealBase} WHERE d.status = 'pending' ORDER BY d.created_at DESC, d.id DESC LIMIT 300");
        $dealsHistory = app_fetch_all($pdo, "{$dealBase} WHERE d.status IN ('confirmed','rejected') ORDER BY COALESCE(d.verified_at, d.created_at) DESC, d.id DESC LIMIT 300");
    }

    $tickets = [];
    if (app_table_exists($pdo, 'tickets')) {
        $ticketsSelect = "t.id, t.participant_id, t.ticket_number, t.reason, t.admin_comment, t.created_at, p.name AS participant_name, p.phone AS participant_phone, p.agency AS participant_agency";
        $tickets = app_fetch_all($pdo, "SELECT {$ticketsSelect} FROM tickets t INNER JOIN participants p ON p.id = t.participant_id ORDER BY t.created_at DESC, t.id DESC LIMIT 500");
    }

    json_response([
        'success' => true,
        'admin' => [
            'id' => (int)$admin['id'],
            'name' => $admin['name'] ?: $admin['login'],
            'login' => $admin['login'],
            'role' => $admin['role'],
        ],
        'stats' => [
            'participants' => app_table_count_safe($pdo, 'participants'),
            'subscriptions_pending' => count($subscriptionsPending),
            'publications_pending' => count($publicationsPending),
            'deals_pending' => count($dealsPending),
            'tickets_total' => app_table_count_safe($pdo, 'tickets'),
            'history_total' => count($subscriptionsHistory) + count($publicationsHistory) + count($dealsHistory),
        ],
        'participants' => $participants,
        'subscriptions' => $subscriptionsPending,
        'subscriptions_history' => $subscriptionsHistory,
        'publications' => $publicationsPending,
        'publications_history' => $publicationsHistory,
        'deals' => $dealsPending,
        'deals_history' => $dealsHistory,
        'tickets' => $tickets,
    ]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка загрузки админ-панели', 'error' => $e->getMessage()], 500);
}
