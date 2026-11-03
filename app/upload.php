<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config.php';

function save_uploaded_photos(int $recordId, array $files): void
{
    if (empty($files['name']) || !is_array($files['name'])) {
        return;
    }

    $stmtCount = db()->prepare('SELECT COUNT(*) FROM record_photos WHERE record_id = ?');
    $stmtCount->execute([$recordId]);
    $existingCount = (int) $stmtCount->fetchColumn();

    $incomingNames = array_filter($files['name'], static fn($v) => trim((string) $v) !== '');
    $totalIncoming = count($incomingNames);

    if (($existingCount + $totalIncoming) > MAX_PHOTOS_PER_RECORD) {
        throw new RuntimeException('Нельзя загрузить больше ' . MAX_PHOTOS_PER_RECORD . ' фото для одной пластинки.');
    }

    $dir = ensure_upload_dir($recordId);

    $stmtPrimary = db()->prepare('SELECT COUNT(*) FROM record_photos WHERE record_id = ? AND is_primary = 1');
    $stmtPrimary->execute([$recordId]);
    $hasPrimary = (int) $stmtPrimary->fetchColumn() > 0;

    $stmtInsert = db()->prepare(
        'INSERT INTO record_photos (record_id, file_name, file_path, is_primary, sort_order) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($files['name'] as $i => $originalName) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        if (($files['size'][$i] ?? 0) > MAX_UPLOAD_SIZE) {
            throw new RuntimeException('Файл ' . $originalName . ' превышает лимит размера.');
        }

        $tmpPath = $files['tmp_name'][$i] ?? '';
        $mime = mime_content_type($tmpPath);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Недопустимый тип файла: ' . $originalName);
        }

        $ext = $allowed[$mime];
        $safeName = bin2hex(random_bytes(12)) . '.' . $ext;
        $targetPath = $dir . '/' . $safeName;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new RuntimeException('Не удалось сохранить файл: ' . $originalName);
        }

        $relativePath = '/uploads/records/' . $recordId . '/' . $safeName;
        $isPrimary = $hasPrimary ? 0 : 1;

        $stmtInsert->execute([$recordId, $safeName, $relativePath, $isPrimary, $i + 1]);
        $hasPrimary = true;
    }
}

function import_discogs_primary_image(int $recordId, ?string $imageUrl): void
{
    $imageUrl = trim((string) $imageUrl);

    if ($imageUrl === '') {
        return;
    }

    $stmtCount = db()->prepare('SELECT COUNT(*) FROM record_photos WHERE record_id = ?');
    $stmtCount->execute([$recordId]);

    if ((int) $stmtCount->fetchColumn() >= MAX_PHOTOS_PER_RECORD) {
        return;
    }

    $ch = curl_init($imageUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . DISCOGS_USER_AGENT,
        ],
    ]);

    $binary = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    curl_close($ch);

    if ($binary === false || $httpCode >= 400 || $binary === '') {
        return;
    }

    $mime = strtolower(trim(explode(';', $contentType)[0]));

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return;
    }

    $dir = ensure_upload_dir($recordId);
    $fileName = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $targetPath = $dir . '/' . $fileName;

    file_put_contents($targetPath, $binary);

    $stmtPrimary = db()->prepare('SELECT COUNT(*) FROM record_photos WHERE record_id = ? AND is_primary = 1');
    $stmtPrimary->execute([$recordId]);
    $isPrimary = ((int) $stmtPrimary->fetchColumn() === 0) ? 1 : 0;

    $stmtInsert = db()->prepare(
        'INSERT INTO record_photos (record_id, file_name, file_path, is_primary, sort_order) VALUES (?, ?, ?, ?, ?)'
    );

    $relativePath = '/uploads/records/' . $recordId . '/' . $fileName;
    $stmtInsert->execute([$recordId, $fileName, $relativePath, $isPrimary, 0]);
}

