<?php
require_once __DIR__ . '/bootstrap.php';

app_require_method('POST');

$data = get_json_input();
$login = app_clean($data['login'] ?? '');
$password = (string)($data['password'] ?? '');

if ($login === '') json_response(['success' => false, 'message' => 'Введите логин администратора'], 422);
if (mb_strlen($password) < 8) json_response(['success' => false, 'message' => 'Пароль администратора должен быть не короче 8 символов'], 422);

try {
    $pdo = db();
    app_ensure_core_schema($pdo);

    $adminsCount = app_count($pdo, "SELECT COUNT(*) FROM admins");
    $hasLastLogin = app_column_exists($pdo, 'admins', 'last_login_at');

    if ($adminsCount === 0) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $token = app_token();

        if ($hasLastLogin) {
            $stmt = $pdo->prepare("INSERT INTO admins (login, password_hash, token, name, role, status, last_login_at) VALUES (:login, :password_hash, :token, :name, 'admin', 'active', NOW())");
        } else {
            $stmt = $pdo->prepare("INSERT INTO admins (login, password_hash, token, name, role, status) VALUES (:login, :password_hash, :token, :name, 'admin', 'active')");
        }

        $stmt->execute([
            ':login' => $login,
            ':password_hash' => $hash,
            ':token' => $token,
            ':name' => 'Администратор'
        ]);

        json_response([
            'success' => true,
            'message' => 'Первый администратор создан. Вход выполнен.',
            'token' => $token,
            'admin' => [
                'id' => (int)$pdo->lastInsertId(),
                'login' => $login,
                'name' => 'Администратор',
                'role' => 'admin'
            ]
        ]);
    }

    $stmt = $pdo->prepare("SELECT id, login, password_hash, name, role, status FROM admins WHERE login = :login LIMIT 1");
    $stmt->execute([':login' => $login]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) json_response(['success' => false, 'message' => 'Неверный логин или пароль'], 401);
    if (($admin['status'] ?? '') !== 'active') json_response(['success' => false, 'message' => 'Администратор заблокирован'], 403);

    $token = app_token();

    if ($hasLastLogin) {
        $update = $pdo->prepare("UPDATE admins SET token = :token, last_login_at = NOW() WHERE id = :id LIMIT 1");
    } else {
        $update = $pdo->prepare("UPDATE admins SET token = :token WHERE id = :id LIMIT 1");
    }

    $update->execute([
        ':token' => $token,
        ':id' => (int)$admin['id']
    ]);

    json_response([
        'success' => true,
        'message' => 'Вход выполнен',
        'token' => $token,
        'admin' => [
            'id' => (int)$admin['id'],
            'login' => $admin['login'],
            'name' => $admin['name'] ?: $admin['login'],
            'role' => $admin['role']
        ]
    ]);

} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Ошибка входа в админ-панель', 'error' => $e->getMessage()], 500);
}
