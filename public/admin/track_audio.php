<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/audio.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function audio_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    $number = (int)$value;
    return match (strtolower(substr($value, -1))) { 'g' => $number * 1024 ** 3, 'm' => $number * 1024 ** 2, 'k' => $number * 1024, default => $number };
}

if (!is_logged_in()) audio_json(401, ['error' => 'Сессия истекла. Войди заново.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') audio_json(405, ['error' => 'Только POST.']);

// Файл больше post_max_size: PHP отбрасывает всё тело, включая CSRF-токен.
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = ini_bytes((string)ini_get('post_max_size'));
if (!$_POST && !$_FILES && $length > 0 && $postMax > 0 && $length > $postMax) {
    audio_json(413, ['error' => 'Файл больше post_max_size (' . ini_get('post_max_size') . ') в настройках PHP.']);
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) audio_json(403, ['error' => 'Сессия формы устарела. Обнови страницу.']);

$recordId = (int)($_POST['record_id'] ?? 0);
$trackId = (int)($_POST['track_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'upload':
            @set_time_limit(0);
            audio_json(200, ['ok' => true, 'track' => save_track_audio($recordId, $trackId, $_FILES['audio'] ?? [])]);
        case 'delete':
            delete_track_audio($recordId, $trackId);
            audio_json(200, ['ok' => true, 'folder' => record_music_dir($recordId)]);
        case 'delete_all':
            delete_record_music($recordId);
            audio_json(200, ['ok' => true, 'folder' => '']);
        default:
            audio_json(400, ['error' => 'Неизвестное действие.']);
    }
} catch (Throwable $e) {
    error_log('track_audio: ' . $e->getMessage());
    audio_json(422, ['error' => $e instanceof PDOException ? 'Ошибка БД. Применена ли миграция sql/04_track_audio.sql?' : $e->getMessage()]);
}
