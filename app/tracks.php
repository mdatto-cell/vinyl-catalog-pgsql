<?php
declare(strict_types=1);

/**
 * Parse the old textarea format without splitting hyphens inside a title.
 * Canonical interchange format: position TAB title TAB duration.
 */
function parse_track_line(string $line): array
{
    $line = trim($line, "\r\n ");
    $track = ['position' => '', 'track_title' => '', 'duration' => ''];
    if (str_contains($line, "\t")) {
        $parts = explode("\t", $line);
        if (count($parts) >= 3) {
            return ['position' => trim(array_shift($parts)), 'duration' => trim(array_pop($parts)),
                'track_title' => trim(implode(' ', $parts))];
        }
    }
    $title = trim($line);
    if (preg_match('/(?:\s+[-–—|]\s+|\s+)\(?(\d{1,3}:\d{2}(?::\d{2})?)\)?$/u', $title, $m)) {
        $track['duration'] = $m[1];
        $title = trim(substr($title, 0, -strlen($m[0])));
    }
    $position = '(?:[A-Za-zА-Яа-я]{1,3}\d+(?:[.-]\d+)*|\d{1,3}(?:[.-]\d+)*|[A-Za-zА-Яа-я])';
    if (preg_match('/^(' . $position . ')\s+(?:[-–—|]\s+)(.+)$/u', $title, $m)
        || preg_match('/^(' . $position . ')[.)]\s+(.+)$/u', $title, $m)
        || preg_match('/^([A-Za-zА-Яа-я]{1,3}\d+(?:[.-]\d+)*)\s+(.+)$/u', $title, $m)) {
        $track['position'] = $m[1];
        $title = $m[2];
    }
    $track['track_title'] = trim($title);
    return $track;
}

function parse_tracklist(string $text): array
{
    $tracks = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') continue;
        $track = parse_track_line($line);
        if ($track['track_title'] !== '') {
            $track['sort_order'] = count($tracks) + 1;
            $tracks[] = $track;
        }
    }
    return $tracks;
}

function normalize_legacy_track(array $row): array
{
    // New rows store empty strings, not NULL, to distinguish an intentionally
    // unnumbered / untimed track whose real title may itself look like "A1 - …".
    if (($row['position'] ?? null) === null && ($row['duration'] ?? null) === null) {
        $row = array_merge($row, parse_track_line((string)$row['track_title']));
    }
    return $row;
}

function tracks_for_record(int $recordId, string $fallback = ''): array
{
    $stmt = db()->prepare('SELECT * FROM record_tracks WHERE record_id = ? ORDER BY sort_order, id');
    $stmt->execute([$recordId]);
    $rows = $stmt->fetchAll();
    return $rows ? array_map('normalize_legacy_track', $rows) : parse_tracklist($fallback);
}

function tracklist_to_text(array $tracks): string
{
    return implode("\n", array_map(static fn(array $t): string =>
        trim((string)($t['position'] ?? '')) . "\t" .
        trim((string)($t['track_title'] ?? '')) . "\t" .
        trim((string)($t['duration'] ?? '')), $tracks));
}

function validate_tracks(array $rows): array
{
    $tracks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Некорректная строка трека.');
        $position = trim((string)($row['position'] ?? ''));
        $title = trim((string)($row['track_title'] ?? ''));
        $duration = trim((string)($row['duration'] ?? ''));
        if ($position === '' && $title === '' && $duration === '') continue;
        $number = count($tracks) + 1;
        if ($title === '') throw new InvalidArgumentException("Укажи название трека в строке {$number}.");
        if (mb_strlen($title) > 255 || mb_strlen($position) > 32 || mb_strlen($duration) > 32) {
            throw new InvalidArgumentException("Слишком длинное значение в строке трека {$number}.");
        }
        if ($duration !== '') {
            if (!preg_match('/^\d{1,3}:[0-5]\d(?::[0-5]\d)?$/D', $duration)) {
                throw new InvalidArgumentException("Длительность трека {$number}: используй м:сс или ч:мм:сс, например 4:32.");
            }
            $parts = explode(':', $duration);
            if (count($parts) === 3 && (int)$parts[1] > 59) {
                throw new InvalidArgumentException("Некорректное число минут у трека {$number}.");
            }
        }
        $tracks[] = ['id' => (int)($row['id'] ?? 0), 'position' => $position,
            'track_title' => $title, 'duration' => $duration, 'sort_order' => $number];
    }
    return $tracks;
}

function posted_tracks(array $post): array
{
    if (isset($post['tracks_present'])) {
        if (!is_array($post['tracks'] ?? [])) throw new InvalidArgumentException('Некорректный трек-лист.');
        return validate_tracks($post['tracks'] ?? []);
    }
    return validate_tracks(parse_tracklist((string)($post['tracklist_text'] ?? '')));
}

/**
 * Called inside the record transaction. Keep existing track IDs on editing.
 * Returns audio links of removed tracks: delete those files after COMMIT.
 */
function save_record_tracks(int $recordId, array $tracks): array
{
    $stmt = db()->prepare('SELECT id FROM record_tracks WHERE record_id = ?');
    $stmt->execute([$recordId]);
    $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $kept = [];
    $insert = db()->prepare('INSERT INTO record_tracks (record_id, position, track_title, duration, sort_order) VALUES (?, ?, ?, ?, ?) RETURNING id');
    $update = db()->prepare('UPDATE record_tracks SET position=?, track_title=?, duration=?, sort_order=? WHERE id=? AND record_id=?');
    foreach ($tracks as $i => $track) {
        $id = (int)($track['id'] ?? 0);
        $values = [$track['position'], $track['track_title'], $track['duration'], $i + 1];
        if ($id > 0 && in_array($id, $existing, true) && !in_array($id, $kept, true)) {
            $update->execute([...$values, $id, $recordId]);
        } else {
            $insert->execute([$recordId, ...$values]);
            $id = (int)$insert->fetchColumn();
        }
        $kept[] = $id;
    }
    $removed = array_values(array_diff($existing, $kept));
    $removedAudio = [];
    if ($removed) {
        $marks = implode(',', array_fill(0, count($removed), '?'));
        try {
            $audio = db()->prepare('SELECT audio_path FROM record_tracks WHERE record_id=? AND audio_path IS NOT NULL AND id IN (' . $marks . ')');
            $audio->execute([$recordId, ...$removed]);
            $removedAudio = $audio->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            // Migration 04 not applied yet: no audio columns, nothing to clean.
        }
        $delete = db()->prepare('DELETE FROM record_tracks WHERE record_id=? AND id IN (' . implode(',', array_fill(0,count($removed),'?')) . ')');
        $delete->execute([$recordId, ...$removed]);
    }
    return $removedAudio;
}

function tracklist_duration(array $tracks): string
{
    $seconds = 0; $known = 0;
    foreach ($tracks as $t) {
        $value = (string)($t['duration'] ?? '');
        if (!preg_match('/^\d{1,3}:[0-5]\d(?::[0-5]\d)?$/D', $value)) continue;
        $parts = array_map('intval', explode(':', $value));
        $seconds += count($parts) === 3 ? $parts[0]*3600+$parts[1]*60+$parts[2] : $parts[0]*60+$parts[1];
        $known++;
    }
    if (!$known) return '';
    $time = $seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds,3600),intdiv($seconds%3600,60),$seconds%60)
        : sprintf('%d:%02d', intdiv($seconds,60),$seconds%60);
    return $time . ($known < count($tracks) ? ' · указана не для всех треков' : '');
}
