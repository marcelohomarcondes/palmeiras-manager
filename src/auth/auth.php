<?php
declare(strict_types=1);

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }

    session_name('palmeiras_manager_session');
    session_start();
}

function auth_mb_strtolower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function auth_mb_strlen(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function auth_normalize_username(string $username): string
{
    return trim(auth_mb_strtolower($username));
}

function auth_login(PDO $pdo, string $username, string $password): bool
{
    auth_start_session();

    $username = auth_normalize_username($username);
    $password = trim($password);

    if ($username === '' || $password === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id, username, password_hash, is_active
        FROM users
        WHERE lower(username) = :username
        LIMIT 1
    ");
    $stmt->execute([
        ':username' => $username,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false;
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        return false;
    }

    $hash = (string)($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['logged_in_at'] = date('Y-m-d H:i:s');

    return true;
}

function auth_logout(): void
{
    auth_start_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

function auth_user_id(): ?int
{
    auth_start_session();

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $userId = (int)$_SESSION['user_id'];
    return $userId > 0 ? $userId : null;
}

function auth_username(): ?string
{
    auth_start_session();

    if (!isset($_SESSION['username'])) {
        return null;
    }

    $username = trim((string)$_SESSION['username']);
    return $username !== '' ? $username : null;
}

function auth_check(): bool
{
    return auth_user_id() !== null;
}

function auth_require_login(): void
{
    if (!auth_check()) {
        header('Location: /login.php');
        exit;
    }
}

function auth_create_password_hash(string $plainPassword): string
{
    $plainPassword = trim($plainPassword);

    if ($plainPassword === '') {
        throw new InvalidArgumentException('A senha não pode ser vazia.');
    }

    return password_hash($plainPassword, PASSWORD_DEFAULT);
}