<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('GET');

function app_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

try {
    $pdo = db();
    app_ensure_core_schema($pdo);
    $admin = app_current_admin($pdo);

    $participants = app_fetch_all($pdo, "SELECT p.id, p.name, p.phone, p.agency, p.status, p.created_at, COALESCE(pub.confirmed_count, 0) AS publications_confirmed, COALESCE(deal.confirmed_count, 0) AS deals_confirmed, COALESCE(ticket.ticket_count, 0) AS tickets_count FROM participants p LEFT JOIN (SELECT participant_id, COUNT(DISTINCT publish_date) AS confirmed_count FROM publications WHERE status = 'confirmed' GROUP BY participant_id) pub ON pub.participant_id = p.id LEFT JOIN (SELECT participant_id, COUNT(*) AS confirmed_count FROM deals WHERE status = 'confirmed' GROUP BY participant_id) deal ON deal.participant_id = p.id LEFT JOIN (SELECT participant_id, COUNT(*) AS ticket_count FROM tickets GROUP BY participant_id) ticket ON ticket.participant_id = p.id ORDER BY p.id DESC LIMIT 300");

    $subscriptionsRaw = app_fetch_all($pdo, "SELECT p.id AS participant_id, p.name AS participant_name, p.phone, p.agency, s.vk_status, s.max_status, s.telegram_status, s.telegram_agents_status, s.instagram_status, s.updated_at FROM subscriptions s INNER JOIN participants p ON p.id = s.participant_id ORDER BY s.updated_at DESC, s.id DESC LIMIT 300");

    $platformLabels = ['vk' => 'ВКонтакте', 'max' => 'MAX', 'telegram' => 'Telegram КП', 'telegram_agents' => 'Telegram для агентов', 'instagram' => 'Instagram'];
    $subscriptions = [];
    foreach ($subscriptionsRaw as $row) {
        foreach ($platformLabels as $platform => $label) {
            $status = $row[$platform . '_status'] ?? 'not_requested';
            if ($status === 'not_requested') continue;
            $subscriptions[] = ['id' => (int)$row['participant_id'], 'participant_id' => (int)$row['participant_id'], 'participant_name' => $row['participant_name'], 'phone' => $row['phone'], 'agency' => $row['agency'], 'platform' => $platform, 'platform_label' => $label, 'status' => $status, 'updated_at' => $row['updated_at']];
        }
    }

    $publications = app_fetch_all($pdo, "SELECT pub.id, pub.publish_date, pub.platform, pub.url, pub.comment, pub.status, pub.admin_comment, pub.created_at, p.name AS participant_name, p.phone AS participant_phone, p.agency AS participant_agency FROM publications pub INNER JOIN participants p ON p.id = pub.participant_id WHERE pub.status = 'pending' ORDER BY pub.id DESC LIMIT 200");

    $deals = app_fetch_all($pdo, "SELECT d.id, d.client_name, d.client_phone, d.plot_number, d.deal_date, d.status, d.comment, d.admin_comment, d.created_at, p.name AS participant_name, p.phone AS participant_phone, p.agency AS participant_agency FROM deals d INNER JOIN participants p ON p.id = d.participant_id WHERE d.status = 'pending' ORDER BY d.id DESC LIMIT 200");

    $tickets = app_fetch_all($pdo, "SELECT t.id, t.ticket_number, t.reason, t.admin_comment, t.created_at, p.name AS participant_name FROM tickets t INNER JOIN participants p ON p.id = t.participant_id ORDER BY t.id DESC LIMIT 300");

    $prizes = app_fetch_all($pdo, "SELECT pr.id, pr.prize_type, pr.status, pr.admin_comment, pr.created_at, p.name AS participant_name FROM prizes pr INNER JOIN participants p ON p.id = pr.participant_id WHERE pr.status IN ('available', 'winner', 'pending') ORDER BY pr.id DESC LIMIT 200");

    json_response([
        'success' => true,
        'admin' => ['id' => (int)$admin['id'], 'name' => $admin['name'] ?: $admin['login'], 'login' => $admin['login'], 'role' => $admin['role']],
        'stats' => [
            'participants' => app_count($pdo, "SELECT COUNT(*) FROM participants"),
            'subscriptions_pending' => app_count($pdo, "SELECT COUNT(*) FROM subscriptions WHERE vk_status = 'pending' OR max_status = 'pending' OR telegram_status = 'pending' OR telegram_agents_status = 'pending' OR instagram_status = 'pending'"),
            'publications_pending' => app_count($pdo, "SELECT COUNT(*) FROM publications WHERE status = 'pending'"),
            'deals_pending' => app_count($pdo, "SELECT COUNT(*) FROM deals WHERE status = 'pending'"),
            'tickets_total' => app_count($pdo, "SELECT COUNT(*) FROM tickets"),
            'prizes_available' => app_count($pdo, "SELECT COUNT(*) FROM prizes WHERE status IN ('available','winner')")
        ],
        'participants' => $participants,
        'subscriptions' => $subscriptions,
        'publications' => $publications,
        'deals' => $deals,
        'tickets' => $tickets,
        'prizes' => $prizes
    ]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка загрузки админ-панели', 'error' => $e->getMessage()], 500);
}
