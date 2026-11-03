<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    foreach (['DB_HOST','DB_NAME'] as $name) {
        if (preg_match('/[;\r\n]/', (string)constant($name))) throw new RuntimeException('Invalid ' . $name);
    }
    $port = defined('DB_PORT') ? (int)DB_PORT : 5432;
    $ssl = defined('DB_SSLMODE') ? DB_SSLMODE : 'prefer';
    if ($port < 1 || $port > 65535) throw new RuntimeException('Invalid DB_PORT');
    if (!in_array($ssl, ['disable','allow','prefer','require','verify-ca','verify-full'], true)) throw new RuntimeException('Invalid DB_SSLMODE');
    $connection = new PDO('pgsql:host='.DB_HOST.';port='.$port.';dbname='.DB_NAME.';sslmode='.$ssl, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET client_encoding TO 'UTF8'");
    $connection->exec("SET search_path TO public");
    $connection->exec("SET datestyle TO 'ISO, YMD'");
    if (defined('DB_TIMEZONE')) {
        $s = $connection->prepare("SELECT set_config('TimeZone', ?, false)");
        $s->execute([DB_TIMEZONE]);
    }
    return $pdo = $connection;
}
