<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

const THEME_COOKIE = 'theme';

/** Тема хранится в cookie, а не в адресе страницы. */
function current_theme(): string
{
    return ($_COOKIE[THEME_COOKIE] ?? 'light') === 'light' ? 'light' : 'dark';
}

function remember_theme(string $theme): void
{
    $_COOKIE[THEME_COOKIE] = $theme;
    if (headers_sent()) return;
    setcookie(THEME_COOKIE, $theme, [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,   // переключатель темы в site.js пишет ту же cookie
        'samesite' => 'Lax',
    ]);
}

/**
 * Старые ссылки и закладки вида ?theme=light: только запоминаем тему в cookie.
 * Без переадресации — CDN, который не учитывает query string в ключе кеша,
 * мог закешировать 302 и зациклить страницу (ERR_TOO_MANY_REDIRECTS).
 * Параметр из адресной строки убирает site.js через history.replaceState.
 */
function migrate_theme_param(): void
{
    $requested = $_GET['theme'] ?? $_POST['theme'] ?? null;
    if ($requested === 'light' || $requested === 'dark') remember_theme($requested);
}
migrate_theme_param();

// Страницы зависят от cookie (тема, вход админа) — общим кешам их хранить нельзя.
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('Cache-Control: private, no-store, max-age=0');
    header('Vary: Cookie');
}

/** Раньше добавляла ?theme=..., теперь адрес не меняется. Оставлена для совместимости. */
function app_url(string $path): string
{
    return $path;
}

function app_asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = __DIR__ . '/../public/' . $path;
    $version = is_file($file) ? substr((string)hash_file('sha256', $file), 0, 12) : '20261030-fix1';
    return '/' . $path . '?v=' . $version;
}

function page_head(string $title): void
{
    ?>
<!doctype html>
<html lang="ru" data-theme="<?= current_theme() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(app_asset_url('assets/css/gallery.css')) ?>">
    <script src="<?= e(app_asset_url('assets/js/site.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#content">К содержимому</a>
<header class="site-header">
    <a class="wordmark" href="<?= e(app_url('/')) ?>">beebzzz <span>records</span></a>
    <span class="header-note">Личная коллекция винила</span>
    <nav class="header-actions" aria-label="Навигация">
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <a href="<?= e(app_url('/admin/add.php')) ?>" class="nav-add">Добавить пластинку</a>
            <a href="<?= e(app_url('/admin/discogs_search.php')) ?>">Discogs</a>
            <a href="<?= e(app_url('/logout.php')) ?>">Выход</a>
        <?php else: ?>
            <a href="<?= e(function_exists('login_url') ? login_url() : '/login.php') ?>">Вход</a>
        <?php endif; ?>
        <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="<?= current_theme() === 'light' ? 'true' : 'false' ?>" aria-label="Переключить светлую и тёмную тему"><?= current_theme() === 'light' ? 'Тёмная тема' : 'Светлая тема' ?></button>
    </nav>
</header>
<?php
}

function page_foot(): void
{
    ?>
<footer class="site-footer"><span>beebzzz / records</span><span>Личная виниловая коллекция</span></footer>
</body>
</html>
<?php
}
