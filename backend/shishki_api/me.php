<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('GET');

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $participant = app_current_participant($pdo);
    $participantId = (int)$participant['id'];

    app_ensure_participant_rewards($pdo, $participantId);
    $subscriptions = app_get_subscriptions($pdo, $participantId);

    $pubStmt = $pdo->prepare("SELECT id, publish_date, platform, url, comment, status, admin_comment, created_at FROM publications WHERE participant_id = :participant_id ORDER BY id DESC LIMIT 100");
    $pubStmt->execute([':participant_id' => $participantId]);
    $publications = $pubStmt->fetchAll();

    $dealStmt = $pdo->prepare("SELECT id, client_name, client_phone, plot_number, deal_date, status, comment, admin_comment, created_at FROM deals WHERE participant_id = :participant_id ORDER BY id DESC LIMIT 100");
    $dealStmt->execute([':participant_id' => $participantId]);
    $deals = $dealStmt->fetchAll();

    $ticketStmt = $pdo->prepare("SELECT id, ticket_number, reason, source_id, admin_comment, created_at FROM tickets WHERE participant_id = :participant_id ORDER BY id DESC LIMIT 200");
    $ticketStmt->execute([':participant_id' => $participantId]);
    $tickets = $ticketStmt->fetchAll();

    $confirmedPublications = app_count($pdo, "SELECT COUNT(DISTINCT publish_date) FROM publications WHERE participant_id = :participant_id AND status = 'confirmed'", [':participant_id' => $participantId]);
    $confirmedDeals = app_count($pdo, "SELECT COUNT(*) FROM deals WHERE participant_id = :participant_id AND status = 'confirmed'", [':participant_id' => $participantId]);
    $ticketsCount = app_count($pdo, "SELECT COUNT(*) FROM tickets WHERE participant_id = :participant_id", [':participant_id' => $participantId]);
    $publications30Status = $confirmedPublications >= 30 ? 'available' : 'pending';

    json_response([
        'success' => true,
        'participant' => [
            'id' => $participantId,
            'name' => $participant['name'],
            'phone' => $participant['phone'],
            'agency' => $participant['agency'],
            'campaign' => $participant['campaign'],
            'source' => $participant['source'],
            'status' => $participant['status'],
            'created_at' => $participant['created_at']
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
            'vk' => $subscriptions['vk_status'],
            'max' => $subscriptions['max_status'],
            'telegram' => $subscriptions['telegram_status'],
            'telegram_agents' => $subscriptions['telegram_agents_status'],
            'instagram' => $subscriptions['instagram_status']
        ],
        'tickets' => $tickets,
        'publications' => $publications,
        'deals' => $deals
    ]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка загрузки кабинета', 'error' => $e->getMessage()], 500);
}
