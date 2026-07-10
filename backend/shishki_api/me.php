<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    json_response(['success' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response([
        'success' => false,
        'message' => 'Метод не разрешен'
    ], 405);
}

function get_bearer_token(): string
{
    $headers = [];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    $authorization = '';

    foreach ($headers as $key => $value) {
        if (mb_strtolower($key) === 'authorization') {
            $authorization = $value;
            break;
        }
    }

    if (!$authorization && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!$authorization && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function get_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function create_ticket(PDO $pdo, int $participantId, string $reason, ?int $sourceId, string $comment): void
{
    $exists = $pdo->prepare("\n        SELECT id\n        FROM tickets\n        WHERE participant_id = :participant_id\n          AND reason = :reason\n        LIMIT 1\n    ");

    $exists->execute([
        ':participant_id' => $participantId,
        ':reason' => $reason
    ]);

    if ($exists->fetch()) {
        return;
    }

    $stmt = $pdo->prepare("\n        INSERT INTO tickets (\n            participant_id,\n            ticket_number,\n            reason,\n            source_id,\n            admin_comment\n        ) VALUES (\n            :participant_id,\n            NULL,\n            :reason,\n            :source_id,\n            :admin_comment\n        )\n    ");

    $stmt->execute([
        ':participant_id' => $participantId,
        ':reason' => $reason,
        ':source_id' => $sourceId,
        ':admin_comment' => $comment
    ]);

    $ticketId = (int)$pdo->lastInsertId();
    $ticketNumber = 'SH-' . str_pad((string)$ticketId, 6, '0', STR_PAD_LEFT);

    $update = $pdo->prepare("\n        UPDATE tickets\n        SET ticket_number = :ticket_number\n        WHERE id = :id\n        LIMIT 1\n    ");

    $update->execute([
        ':ticket_number' => $ticketNumber,
        ':id' => $ticketId
    ]);
}

function ensure_social_ticket(PDO $pdo, int $participantId, array $subscriptions): void
{
    $required = [
        $subscriptions['vk_status'] ?? 'not_requested',
        $subscriptions['max_status'] ?? 'not_requested',
        $subscriptions['telegram_status'] ?? 'not_requested',
        $subscriptions['telegram_agents_status'] ?? 'not_requested',
        $subscriptions['instagram_status'] ?? 'not_requested'
    ];

    foreach ($required as $status) {
        if ($status !== 'confirmed') {
            return;
        }
    }

    create_ticket(
        $pdo,
        $participantId,
        'socials_5',
        null,
        'Билет за подтверждённую подписку на 5 соцсетей'
    );
}

try {
    $token = get_bearer_token();

    if ($token === '') {
        json_response([
            'success' => false,
            'message' => 'Токен не передан'
        ], 401);
    }

    $pdo = db();

    $stmt = $pdo->prepare("\n        SELECT\n            id,\n            name,\n            phone,\n            agency,\n            campaign,\n            source,\n            status,\n            created_at\n        FROM participants\n        WHERE token = :token\n          AND status = 'active'\n        LIMIT 1\n    ");

    $stmt->execute([
        ':token' => $token
    ]);

    $participant = $stmt->fetch();

    if (!$participant) {
        json_response([
            'success' => false,
            'message' => 'Участник не найден или токен устарел'
        ], 401);
    }

    $participantId = (int)$participant['id'];

    $subStmt = $pdo->prepare("\n        SELECT\n            vk_status,\n            max_status,\n            telegram_status,\n            telegram_agents_status,\n            instagram_status\n        FROM subscriptions\n        WHERE participant_id = :participant_id\n        LIMIT 1\n    ");

    $subStmt->execute([
        ':participant_id' => $participantId
    ]);

    $subscriptions = $subStmt->fetch();

    if (!$subscriptions) {
        $createSubs = $pdo->prepare("\n            INSERT INTO subscriptions (\n                participant_id,\n                vk_status,\n                max_status,\n                telegram_status,\n                telegram_agents_status,\n                instagram_status\n            ) VALUES (\n                :participant_id,\n                'not_requested',\n                'not_requested',\n                'not_requested',\n                'not_requested',\n                'not_requested'\n            )\n        ");

        $createSubs->execute([
            ':participant_id' => $participantId
        ]);

        $subscriptions = [
            'vk_status' => 'not_requested',
            'max_status' => 'not_requested',
            'telegram_status' => 'not_requested',
            'telegram_agents_status' => 'not_requested',
            'instagram_status' => 'not_requested'
        ];
    }

    ensure_social_ticket($pdo, $participantId, $subscriptions);

    $pubStmt = $pdo->prepare("\n        SELECT\n            id,\n            publish_date,\n            platform,\n            url,\n            comment,\n            status,\n            admin_comment,\n            created_at\n        FROM publications\n        WHERE participant_id = :participant_id\n        ORDER BY id DESC\n        LIMIT 100\n    ");

    $pubStmt->execute([
        ':participant_id' => $participantId
    ]);

    $publications = $pubStmt->fetchAll();

    $dealStmt = $pdo->prepare("\n        SELECT\n            id,\n            client_name,\n            client_phone,\n            plot_number,\n            deal_date,\n            status,\n            comment,\n            admin_comment,\n            created_at\n        FROM deals\n        WHERE participant_id = :participant_id\n        ORDER BY id DESC\n        LIMIT 100\n    ");

    $dealStmt->execute([
        ':participant_id' => $participantId
    ]);

    $deals = $dealStmt->fetchAll();

    $ticketStmt = $pdo->prepare("\n        SELECT\n            id,\n            ticket_number,\n            reason,\n            source_id,\n            admin_comment,\n            created_at\n        FROM tickets\n        WHERE participant_id = :participant_id\n        ORDER BY id DESC\n        LIMIT 200\n    ");

    $ticketStmt->execute([
        ':participant_id' => $participantId
    ]);

    $tickets = $ticketStmt->fetchAll();

    $confirmedPublications = get_count(
        $pdo,
        "\n        SELECT COUNT(DISTINCT publish_date)\n        FROM publications\n        WHERE participant_id = :participant_id\n          AND status = 'confirmed'\n        ",
        [':participant_id' => $participantId]
    );

    $confirmedDeals = get_count(
        $pdo,
        "\n        SELECT COUNT(*)\n        FROM deals\n        WHERE participant_id = :participant_id\n          AND status = 'confirmed'\n        ",
        [':participant_id' => $participantId]
    );

    $ticketsCount = get_count(
        $pdo,
        "\n        SELECT COUNT(*)\n        FROM tickets\n        WHERE participant_id = :participant_id\n        ",
        [':participant_id' => $participantId]
    );

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
            'fuel20_status' => $confirmedPublications >= 20 ? 'available' : 'pending',
            'fuel30_status' => $confirmedPublications >= 30 ? 'available' : 'pending',
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
    json_response([
        'success' => false,
        'message' => 'Ошибка загрузки кабинета',
        'error' => $e->getMessage()
    ], 500);
}
