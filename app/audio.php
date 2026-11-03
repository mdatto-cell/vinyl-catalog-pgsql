<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tracks.php';

/*
 * Оцифровки треков.
 * Наличие файла определяется только по record_tracks.audio_path — диск не сканируется.
 * Можно переопределить в config.php: MUSIC_DIR, MAX_AUDIO_UPLOAD_SIZE.
 */

const AUDIO_URL_PREFIX = '/uploads/music';
const AUDIO_FORMATS = ['flac' => 'FLAC', 'wav' => 'WAV', 'mp3' => 'MP3'];

function music_base_dir(): string
{
    return rtrim(defined('MUSIC_DIR') ? (string)constant('MUSIC_DIR') : __DIR__ . '/../public/uploads/music', '/');
}

function max_audio_upload_size(): int
{
    return defined('MAX_AUDIO_UPLOAD_SIZE') ? (int)constant('MAX_AUDIO_UPLOAD_SIZE') : 2 * 1024 * 1024 * 1024;
}

/** Часть имени файла/папки: пробелы → _, без символов, недопустимых в путях и URL-ловушек. */
function audio_slug(string $value, int $maxLength = 100): string
{
    $value = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|#%&{}$!\'`=+@^~;]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', '_', trim($value)) ?? '';
    $value = preg_replace('/_+/u', '_', $value) ?? '';
    $value = trim($value, '._- ');
    if (mb_strlen($value) > $maxLength) $value = rtrim(mb_substr($value, 0, $maxLength), '._- ');
    return $value !== '' ? $value : 'Без_названия';
}

/** Имя_Исполнителя_[год]-_Название_Альбома */
function music_dir_name(array $record): string
{
    $year = (int)($record['album_year'] ?: $record['release_year'] ?: 0);
    return audio_slug((string)$record['artist'], 80) . ($year > 0 ? '_-_[' . $year . ']' : '') . '_-_' . audio_slug((string)$record['album_title'], 100);
}

/** XX_Название_Трека.ext */
function track_audio_file_name(int $number, string $title, string $format): string
{
    return sprintf('%02d', max(1, $number)) . '_' . audio_slug($title, 120) . '.' . $format;
}

/** Определение формата по сигнатуре файла, а не по присланному типу. */
function detect_audio_format(string $path): ?string
{
    $head = (string)@file_get_contents($path, false, null, 0, 12);
    if (strlen($head) < 4) return null;
    if (str_starts_with($head, 'fLaC')) return 'flac';
    if ((str_starts_with($head, 'RIFF') || str_starts_with($head, 'RF64')) && substr($head, 8, 4) === 'WAVE') return 'wav';
    if (str_starts_with($head, 'ID3')) return 'mp3';
    if (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) return 'mp3';
    return null;
}

function audio_public_url(?string $path): string
{
    if (!$path) return '';
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Внешний адрес для раздачи оцифровок, например https://cd1.beebzzz.ru.
 * Задаётся в config.php: const AUDIO_BASE_URL = 'https://cd1.beebzzz.ru';
 * Пусто или не задано — файлы отдаёт сам каталог.
 */
function audio_base_url(): string
{
    return defined('AUDIO_BASE_URL') ? rtrim((string)constant('AUDIO_BASE_URL'), '/') : '';
}

/** Версия файла для ссылок: после замены файла кеш CDN не отдаст старую копию. */
function audio_version(array $track): string
{
    $stamp = (string)($track['audio_uploaded_at'] ?? '') . '|' . (string)($track['audio_size'] ?? '');
    return substr(md5($stamp), 0, 8);
}

/** Ссылка для плеера: CDN, если задан AUDIO_BASE_URL, иначе локальный /uploads/music/... */
function audio_stream_url(array $track): string
{
    $url = audio_public_url((string)($track['audio_path'] ?? ''));
    if ($url === '') return '';
    return audio_base_url() . $url . '?v=' . audio_version($track);
}

/** Ссылка «скачать»: при CDN — файл на CDN с ?dl=1 (CDN добавит Content-Disposition: attachment). */
function audio_download_url(array $track): string
{
    if (audio_base_url() !== '' && !empty($track['audio_path'])) return audio_stream_url($track) . '&dl=1';
    return '/download.php?track=' . (int)($track['id'] ?? 0);
}

/** Путь в БД → путь на диске. Только внутри MUSIC_DIR. */
function audio_fs_path(string $path): ?string
{
    if (!str_starts_with($path, AUDIO_URL_PREFIX . '/') || str_contains($path, '..') || str_contains($path, "\0")) return null;
    return music_base_dir() . substr($path, strlen(AUDIO_URL_PREFIX));
}

function music_dir_fs_path(?string $dir): ?string
{
    $dir = (string)$dir;
    if ($dir === '' || str_contains($dir, '/') || str_contains($dir, '\\') || $dir === '.' || $dir === '..') return null;
    return music_base_dir() . '/' . $dir;
}

function format_bytes(?int $bytes): string
{
    $bytes = (int)$bytes;
    if ($bytes >= 1024 ** 3) return number_format($bytes / 1024 ** 3, 1, ',', '') . ' ГБ';
    if ($bytes >= 1024 ** 2) return number_format($bytes / 1024 ** 2, $bytes >= 100 * 1024 ** 2 ? 0 : 1, ',', '') . ' МБ';
    return max(1, (int)round($bytes / 1024)) . ' КБ';
}

function audio_info(array $track): ?array
{
    if (empty($track['audio_path'])) return null;
    $format = (string)($track['audio_format'] ?? '');
    return [
        'url' => audio_stream_url($track),
        'download' => audio_download_url($track),
        'file_name' => basename((string)$track['audio_path']),
        'format' => AUDIO_FORMATS[$format] ?? strtoupper($format),
        'size' => format_bytes(isset($track['audio_size']) ? (int)$track['audio_size'] : 0),
    ];
}

/** Папка альбома: создаётся при загрузке первого трека, имя хранится в records.music_dir. */
function ensure_record_music_dir(int $recordId): string
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, artist, album_title, album_year, release_year, music_dir FROM records WHERE id = ? FOR UPDATE');
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();
        if (!$record) throw new RuntimeException('Пластинка не найдена.');
        $dir = (string)($record['music_dir'] ?? '');
        if ($dir === '') {
            $base = music_dir_name($record);
            $taken = $pdo->prepare('SELECT COUNT(*) FROM records WHERE music_dir = ? AND id <> ?');
            for ($n = 1; $n < 100; $n++) {
                $candidate = $n === 1 ? $base : $base . '_' . $n;
                $taken->execute([$candidate, $recordId]);
                if (!(int)$taken->fetchColumn() && !file_exists(music_base_dir() . '/' . $candidate)) { $dir = $candidate; break; }
            }
            if ($dir === '') throw new RuntimeException('Не удалось подобрать имя папки.');
            $pdo->prepare('UPDATE records SET music_dir = ? WHERE id = ?')->execute([$dir, $recordId]);
        }
        $fs = music_dir_fs_path($dir);
        if ($fs === null) throw new RuntimeException('Некорректное имя папки.');
        if (!is_dir($fs) && !@mkdir($fs, 0775, true) && !is_dir($fs)) {
            throw new RuntimeException('Не удалось создать папку ' . AUDIO_URL_PREFIX . '/' . $dir . '. Проверь права на uploads/music.');
        }
        if ($own) $pdo->commit();
        return $dir;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Файл больше upload_max_filesize (' . ini_get('upload_max_filesize') . ') в настройках PHP.',
        UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер.',
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Повтори попытку.',
        UPLOAD_ERR_NO_FILE => 'Выбери файл.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'PHP не может записать временный файл на сервере.',
        default => 'Ошибка загрузки файла (код ' . $code . ').',
    };
}

function find_track(int $recordId, int $trackId): array
{
    $stmt = db()->prepare('SELECT * FROM record_tracks WHERE id = ? AND record_id = ? LIMIT 1');
    $stmt->execute([$trackId, $recordId]);
    $track = $stmt->fetch();
    if (!$track) throw new RuntimeException('Трек не найден. Сохрани трек-лист и повтори.');
    return $track;
}

/** Номер XX = порядковый номер трека в сохранённом трек-листе. */
function track_number(array $track): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM record_tracks WHERE record_id = ? AND (sort_order < ? OR (sort_order = ? AND id <= ?))');
    $stmt->execute([$track['record_id'], $track['sort_order'], $track['sort_order'], $track['id']]);
    return (int)$stmt->fetchColumn();
}

function save_track_audio(int $recordId, int $trackId, array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException(upload_error_message($error));
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Файл не получен.');
    $size = (int)($file['size'] ?? filesize($tmp));
    if ($size > max_audio_upload_size()) throw new RuntimeException('Файл больше ' . format_bytes(max_audio_upload_size()) . '.');

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'wave') $ext = 'wav';
    $format = detect_audio_format($tmp);
    if ($format === null || !isset(AUDIO_FORMATS[$ext])) throw new RuntimeException('Поддерживаются только FLAC, WAV и MP3.');
    if ($format !== $ext) throw new RuntimeException('Содержимое файла не совпадает с расширением .' . $ext . ' (похоже на ' . AUDIO_FORMATS[$format] . ').');

    $track = find_track($recordId, $trackId);
    $dir = ensure_record_music_dir($recordId);
    // Старые строки вида «A1 - Название - 4:32» ещё не разобраны в БД.
    $title = (string)(normalize_legacy_track($track)['track_title'] ?? $track['track_title']);
    $name = track_audio_file_name(track_number($track), $title, $format);

    // Не перезаписывать файл другого трека с тем же именем.
    $busy = db()->prepare('SELECT COUNT(*) FROM record_tracks WHERE record_id = ? AND id <> ? AND audio_path = ?');
    $stem = pathinfo($name, PATHINFO_FILENAME);
    for ($n = 2; ; $n++) {
        $busy->execute([$recordId, $trackId, AUDIO_URL_PREFIX . '/' . $dir . '/' . $name]);
        if (!(int)$busy->fetchColumn()) break;
        $name = $stem . '_' . $n . '.' . $format;
    }

    $path = AUDIO_URL_PREFIX . '/' . $dir . '/' . $name;
    $target = audio_fs_path($path);
    $partial = $target . '.uploading-' . bin2hex(random_bytes(4));
    if (!move_uploaded_file($tmp, $partial)) throw new RuntimeException('Не удалось сохранить файл в ' . AUDIO_URL_PREFIX . '/' . $dir . '.');
    @chmod($partial, 0664);
    if (!rename($partial, $target)) { @unlink($partial); throw new RuntimeException('Не удалось сохранить файл.'); }

    try {
        db()->prepare('UPDATE record_tracks SET audio_path = ?, audio_format = ?, audio_size = ?, audio_uploaded_at = NOW() WHERE id = ? AND record_id = ?')
            ->execute([$path, $format, $size, $trackId, $recordId]);
    } catch (Throwable $e) {
        @unlink($target);
        throw $e;
    }
    $old = (string)($track['audio_path'] ?? '');
    if ($old !== '' && $old !== $path) remove_audio_files([$old]);

    return ['id' => $trackId, 'audio' => audio_info(['id' => $trackId, 'audio_path' => $path, 'audio_format' => $format, 'audio_size' => $size, 'audio_uploaded_at' => date('Y-m-d H:i:s')]), 'folder' => $dir];
}

/** Удаление файлов по ссылкам из БД. Пустая папка альбома тоже удаляется. */
function remove_audio_files(array $paths): void
{
    foreach (array_unique(array_filter($paths)) as $path) {
        $fs = audio_fs_path((string)$path);
        if ($fs === null) continue;
        if (is_file($fs)) @unlink($fs);
        @rmdir(dirname($fs)); // только если папка пуста
    }
}

function forget_missing_music_dir(int $recordId): void
{
    try {
        $stmt = db()->prepare('SELECT music_dir FROM records WHERE id = ?');
        $stmt->execute([$recordId]);
        $dir = music_dir_fs_path((string)$stmt->fetchColumn());
        if ($dir !== null && !is_dir($dir)) db()->prepare('UPDATE records SET music_dir = NULL WHERE id = ?')->execute([$recordId]);
    } catch (PDOException) {
        // Migration 04 not applied yet.
    }
}

function delete_track_audio(int $recordId, int $trackId): void
{
    $track = find_track($recordId, $trackId);
    db()->prepare('UPDATE record_tracks SET audio_path = NULL, audio_format = NULL, audio_size = NULL, audio_uploaded_at = NULL WHERE id = ? AND record_id = ?')
        ->execute([$trackId, $recordId]);
    remove_audio_files([(string)($track['audio_path'] ?? '')]);
    forget_missing_music_dir($recordId);
}

function remove_dir_tree(string $dir): void
{
    $base = realpath(music_base_dir());
    $real = realpath($dir);
    if ($base === false || $real === false || !str_starts_with($real, $base . '/')) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($real);
}

/** Вся папка альбома на диске + ссылки в БД. */
function delete_record_music(int $recordId, bool $updateDb = true): void
{
    $stmt = db()->prepare('SELECT music_dir FROM records WHERE id = ?');
    $stmt->execute([$recordId]);
    $dirName = (string)$stmt->fetchColumn();
    $paths = db()->prepare('SELECT audio_path FROM record_tracks WHERE record_id = ? AND audio_path IS NOT NULL');
    $paths->execute([$recordId]);
    $files = $paths->fetchAll(PDO::FETCH_COLUMN);
    if ($updateDb) {
        db()->prepare('UPDATE record_tracks SET audio_path = NULL, audio_format = NULL, audio_size = NULL, audio_uploaded_at = NULL WHERE record_id = ?')->execute([$recordId]);
        db()->prepare('UPDATE records SET music_dir = NULL WHERE id = ?')->execute([$recordId]);
    }
    remove_audio_files($files);
    $fs = music_dir_fs_path($dirName);
    if ($fs !== null && is_dir($fs)) remove_dir_tree($fs);
}

function record_music_dir(int $recordId): string
{
    $stmt = db()->prepare('SELECT music_dir FROM records WHERE id = ?');
    $stmt->execute([$recordId]);
    return (string)$stmt->fetchColumn();
}

function audio_by_track(int $recordId): array
{
    $stmt = db()->prepare('SELECT id, audio_path, audio_format, audio_size, audio_uploaded_at FROM record_tracks WHERE record_id = ? AND audio_path IS NOT NULL');
    $stmt->execute([$recordId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(int)$row['id']] = audio_info($row);
    return $out;
}
