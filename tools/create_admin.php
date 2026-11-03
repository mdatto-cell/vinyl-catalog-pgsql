<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/db.php';
$username = $argv[1] ?? 'admin';
if ($username === '' || mb_strlen($username) > 64) throw new RuntimeException('Invalid username');
fwrite(STDERR, "Paste a PHP password_hash() value and press Enter: ");
$hash = trim((string)fgets(STDIN));
if ((password_get_info($hash)['algoName'] ?? 'unknown') === 'unknown') throw new RuntimeException('Expected password_hash(), not plaintext');
$s = db()->prepare('INSERT INTO users(username,password_hash) VALUES (?,?) RETURNING id');
$s->execute([$username,$hash]);
echo 'Created user ID ' . $s->fetchColumn() . PHP_EOL;
