<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        $back = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? (string)($_SERVER['REQUEST_URI'] ?? '') : '';
        header('Location: ' . login_url($back));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();
}

/**
 * Адрес, куда вернуться после входа. Только локальный путь этого сайта:
 * отбрасываем внешние адреса (https://..., //evil.com, /\evil.com) и сами login/logout.
 */
function safe_return_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//') || strpbrk($url, "\\\r\n") !== false) {
        return '/';
    }
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (in_array($path, ['/login.php', '/logout.php'], true)) {
        return '/';
    }
    return $url;
}

/** Ссылка на вход с возвратом на указанную (по умолчанию текущую) страницу. */
function login_url(?string $returnTo = null): string
{
    $back = safe_return_url($returnTo ?? (string)($_SERVER['REQUEST_URI'] ?? '/'));
    return '/login.php' . ($back === '/' ? '' : '?return=' . rawurlencode($back));
}

function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, username, password_hash FROM users WHERE lower(username) = lower(?) LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];

    return true;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}
