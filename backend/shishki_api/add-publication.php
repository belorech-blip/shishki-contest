<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

function shk_normalize_publication_url(string $url): string
{
    $url = trim($url);
    $parts = parse_url($url);

    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return mb_strtolower(rtrim($url, "/ \t\n\r\0\x0B"));
    }

    $scheme = mb_strtolower((string)$parts['scheme']);
    $host = mb_strtolower((string)$parts['host']);
    $path = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
    $query = isset($parts['query']) ? (string)$parts['query'] : '';

    if ($query !== '') {
        parse_str($query, $queryParams);
        if (is_array($queryParams)) {
            ksort($queryParams);
            $query = http_build_query($queryParams);
        }
    }

    return $scheme . '://' . $host . $path . ($query !== '' ? '?' . $query : '');
}

$data = get_json_input();
$publishDate = app_clean($data['publish_date'] ?? '');
$platform = app_clean($data['platform'] ?? '');
$url = app_clean($data['url'] ?? '');
$comment = app_clean($data['comment'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishDate)) json_response(['success' => false, 'message' => 'Введите дату публикации'], 422);
if (!in_array($platform, ['vk', 'max', 'telegram', 'instagram'], true)) json_response(['success' => false, 'message' => 'Выберите соцсеть'], 422);
if (!preg_match('#^https?://#i', $url)) json_response(['success' => false, 'message' => 'Ссылка должна начинаться с http или https'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $participant = app_current_participant($pdo);
    $participantId = (int)$participant['id'];

    $normalizedUrl = shk_normalize_publication_url($url);

    $sameDay = $pdo->prepare("SELECT id, status FROM publications WHERE participant_id = :participant_id AND platform = :platform AND publish_date = :publish_date AND status IN ('pending','confirmed') LIMIT 1");
    $sameDay->execute([
        ':participant_id' => $participantId,
        ':platform' => $platform,
        ':publish_date' => $publishDate,
    ]);
    $sameDayRow = $sameDay->fetch();

    if ($sameDayRow) {
        $message = ($sameDayRow['status'] ?? '') === 'confirmed'
            ? 'Публикация за эту дату и площадку уже подтверждена. Повторно отправлять не нужно.'
            : 'Публикация за эту дату и площадку уже находится на проверке.';
        json_response(['success' => false, 'message' => $message], 409);
    }

    $existing = $pdo->prepare("SELECT id, url, status FROM publications WHERE participant_id = :participant_id ORDER BY id DESC LIMIT 1000");
    $existing->execute([':participant_id' => $participantId]);
    foreach ($existing->fetchAll() as $row) {
        if (shk_normalize_publication_url((string)($row['url'] ?? '')) === $normalizedUrl) {
            $status = (string)($row['status'] ?? '');
            $message = 'Эта ссылка уже отправлялась ранее. Повторно отправлять одну и ту же публикацию нельзя.';
            if ($status === 'pending') $message = 'Эта ссылка уже находится на проверке.';
            if ($status === 'confirmed') $message = 'Эта ссылка уже подтверждена.';
            if ($status === 'rejected') $message = 'Эта ссылка уже была отклонена. Отправьте другую ссылку или новую публикацию.';
            json_response(['success' => false, 'message' => $message], 409);
        }
    }

    $stmt = $pdo->prepare("INSERT INTO publications (participant_id, publish_date, platform, url, comment, status) VALUES (:participant_id, :publish_date, :platform, :url, :comment, 'pending')");
    $stmt->execute([':participant_id' => $participantId, ':publish_date' => $publishDate, ':platform' => $platform, ':url' => $url, ':comment' => $comment ?: null]);

    json_response(['success' => true, 'message' => 'Публикация отправлена на проверку', 'publication_id' => (int)$pdo->lastInsertId()]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка отправки публикации', 'error' => $e->getMessage()], 500);
}
