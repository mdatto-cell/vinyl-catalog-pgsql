<?php
declare(strict_types=1);

// Скачивание оцифровки трека: /download.php?track=ID
// Путь берётся из record_tracks.audio_path, диск не сканируется.
require_once __DIR__ . '/../app/audio.php';

$trackId = (int)($_GET['track'] ?? 0);
$stmt = db()->prepare('SELECT id, audio_path, audio_format, audio_size, audio_uploaded_at FROM record_tracks WHERE id = ? AND audio_path IS NOT NULL LIMIT 1');
$stmt->execute([$trackId]);
$track = $stmt->fetch();
$file = $track ? audio_fs_path((string)$track['audio_path']) : null;

if ($file === null || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Файл не найден.');
}

// Если задан AUDIO_BASE_URL — старые ссылки /download.php?track=ID уводим на CDN.
if (audio_base_url() !== '') {
    header('Cache-Control: no-store');
    header('Location: ' . audio_download_url($track), true, 302);
    exit;
}

$name = basename($file);
// Запасное ASCII-имя для старых клиентов; современные браузеры берут filename* (UTF-8).
$latin = function_exists('transliterator_transliterate') ? (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $name) : (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
$ascii = trim((string)preg_replace('/[^A-Za-z0-9._\-\[\]]+/', '_', $latin), '_');
if ($ascii === '' || $ascii === '.' . $track['audio_format']) $ascii = 'track-' . $trackId . '.' . $track['audio_format'];
$types = ['flac' => 'audio/flac', 'wav' => 'audio/wav', 'mp3' => 'audio/mpeg'];

header('Content-Type: ' . ($types[$track['audio_format']] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');

// Для Nginx: define('AUDIO_X_ACCEL', true) в config.php — файл отдаст сам Nginx (быстрее, с докачкой).
if (defined('AUDIO_X_ACCEL') && constant('AUDIO_X_ACCEL')) {
    header('X-Accel-Redirect: ' . audio_public_url((string)$track['audio_path']));
    exit;
}

header('Content-Length: ' . filesize($file));
@set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();
readfile($file);
