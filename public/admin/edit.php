<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/upload.php';
require_once __DIR__ . '/../../app/tracks.php';
require_once __DIR__ . '/../../app/audio.php';

require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM records WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    exit('Record not found');
}

$error = '';
$editorTracks = tracks_for_record($id, (string)($record['tracklist_text'] ?? ''));

if (isset($_POST['delete_record']) && $_POST['delete_record'] === '1') {
    try {
        db()->beginTransaction();

        $stmtPhotos = db()->prepare('SELECT file_path FROM record_photos WHERE record_id = ?');
        $stmtPhotos->execute([$id]);
        $photos = $stmtPhotos->fetchAll();

        foreach ($photos as $photo) {
            $fullPath = __DIR__ . '/..' . $photo['file_path'];
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }

        $musicDir = (string)($record['music_dir'] ?? '');
        $musicFiles = array_values(array_filter(array_column(tracks_for_record($id), 'audio_path')));

        $deleteRecord = db()->prepare('DELETE FROM records WHERE id = ?');
        $deleteRecord->execute([$id]);

        $recordDir = __DIR__ . '/../uploads/records/' . $id;
        if (is_dir($recordDir)) {
            $files = scandir($recordDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $recordDir . '/' . $file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($recordDir);
        }

        db()->commit();

        remove_audio_files($musicFiles);
        $musicPath = music_dir_fs_path($musicDir);
        if ($musicPath !== null && is_dir($musicPath)) remove_dir_tree($musicPath);

        header('Location: ' . app_url('/'));
        exit;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $error = $e->getMessage();
    }
}

if (isset($_POST['delete_photo_id'])) {
    try {
        $photoId = (int) $_POST['delete_photo_id'];
        $stmtPhoto = db()->prepare('SELECT * FROM record_photos WHERE id = ? AND record_id = ? LIMIT 1');
        $stmtPhoto->execute([$photoId, $id]);
        $photo = $stmtPhoto->fetch();

        if ($photo) {
            $fullPath = __DIR__ . '/..' . $photo['file_path'];
            if (is_file($fullPath)) {
                unlink($fullPath);
            }

            $del = db()->prepare('DELETE FROM record_photos WHERE id = ?');
            $del->execute([$photoId]);
        }

        header('Location: ' . app_url('/admin/edit.php?id=' . $id));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_photo_id']) && !isset($_POST['delete_record'])) {
    $editorTracks = is_array($_POST['tracks'] ?? null) ? $_POST['tracks'] : parse_tracklist((string)($_POST['tracklist_text'] ?? ''));
    $form = [
        'artist' => trim($_POST['artist'] ?? ''),
        'album_title' => trim($_POST['album_title'] ?? ''),
        'tracklist_text' => trim($_POST['tracklist_text'] ?? ''),
        'release_year' => trim($_POST['release_year'] ?? ''),
        'album_year' => trim($_POST['album_year'] ?? ''),
        'catalog_number' => trim($_POST['catalog_number'] ?? ''),
        'barcode' => trim($_POST['barcode'] ?? ''),
        'pressing_plant' => trim($_POST['pressing_plant'] ?? ''),
        'label_name' => trim($_POST['label_name'] ?? ''),
        'genre_name' => trim($_POST['genre_name'] ?? ''),
        'style_name' => trim($_POST['style_name'] ?? ''),
        'acquired_at' => trim($_POST['acquired_at'] ?? ''),
        'purchase_price' => trim($_POST['purchase_price'] ?? ''),
        'purchase_price_usd' => trim($_POST['purchase_price_usd'] ?? ''),
        'sale_price' => trim($_POST['sale_price'] ?? ''),
        'storage_status' => trim($_POST['storage_status'] ?? 'in_stock'),
        'notes' => trim($_POST['notes'] ?? ''),
        'discogs_release_id' => trim($_POST['discogs_release_id'] ?? ''),
        'media_condition' => trim($_POST['media_condition'] ?? ''),
        'sleeve_condition' => trim($_POST['sleeve_condition'] ?? ''),
        'discogs_image_url' => trim($_POST['discogs_image_url'] ?? ''),
    ];

    try {
        if ($form['artist'] === '' || $form['album_title'] === '') {
            throw new InvalidArgumentException('Поля Исполнитель и Название альбома обязательны.');
        }
        $editorTracks = posted_tracks($_POST);
        $form['tracklist_text'] = tracklist_to_text($editorTracks);
        db()->beginTransaction();

        $stmtUpdate = db()->prepare('
            UPDATE records
            SET artist = ?,
                album_title = ?,
                tracklist_text = ?,
                release_year = ?,
                album_year = ?,
                catalog_number = ?,
                barcode = ?,
                pressing_plant = ?,
                label_name = ?,
                genre_name = ?,
                style_name = ?,
                acquired_at = ?,
                purchase_price = ?,
                purchase_price_usd = ?,
                sale_price = ?,
                storage_status = ?,
                notes = ?,
                discogs_release_id = ?,
                media_condition = ?,
                sleeve_condition = ?,
                discogs_image_url = ?
            WHERE id = ?
        ');

        $stmtUpdate->execute([
            $form['artist'],
            $form['album_title'],
            $form['tracklist_text'] !== '' ? $form['tracklist_text'] : null,
            $form['release_year'] !== '' ? (int) $form['release_year'] : null,
            $form['album_year'] !== '' ? (int) $form['album_year'] : null,
            $form['catalog_number'] !== '' ? $form['catalog_number'] : null,
            $form['barcode'] !== '' ? $form['barcode'] : null,
            $form['pressing_plant'] !== '' ? $form['pressing_plant'] : null,
            $form['label_name'] !== '' ? $form['label_name'] : null,
            $form['genre_name'] !== '' ? $form['genre_name'] : null,
            $form['style_name'] !== '' ? $form['style_name'] : null,
            $form['acquired_at'] !== '' ? str_replace('T', ' ', $form['acquired_at']) . ':00' : null,
            normalize_decimal($form['purchase_price']),
            normalize_decimal($form['purchase_price_usd']),
            normalize_decimal($form['sale_price']),
            $form['storage_status'],
            $form['notes'] !== '' ? $form['notes'] : null,
            $form['discogs_release_id'] !== '' ? (int) $form['discogs_release_id'] : null,
            $form['media_condition'] !== '' ? $form['media_condition'] : null,
            $form['sleeve_condition'] !== '' ? $form['sleeve_condition'] : null,
            $form['discogs_image_url'] !== '' ? $form['discogs_image_url'] : null,
            $id,
        ]);

        $removedAudio = save_record_tracks($id, $editorTracks);

        save_uploaded_photos($id, $_FILES['photos'] ?? []);

        db()->commit();
        remove_audio_files($removedAudio);
        forget_missing_music_dir($id);

        header('Location: ' . app_url('/admin/edit.php?id=' . $id));
        exit;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $error = $e->getMessage();
        $record = array_merge($record, $form);
    }
}

$stmtPhotos = db()->prepare('SELECT * FROM record_photos WHERE record_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC');
$stmtPhotos->execute([$id]);
$photos = $stmtPhotos->fetchAll();
page_head('Редактировать пластинку');
?>
<main class="container admin-container" id="content">
    <div class="topbar">
        <h1>Редактировать пластинку</h1>
        <div class="actions">
            <a class="button button-secondary" href="/record.php?id=<?= $id ?>">Карточка</a>
            <a class="button button-secondary" href="/">Каталог</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="discogs_image_url" value="<?= e((string)($record['discogs_image_url'] ?? '')) ?>">

        <div><label>Исполнитель</label><input type="text" name="artist" value="<?= e($record['artist']) ?>" required></div>
        <div><label>Название альбома</label><input type="text" name="album_title" value="<?= e($record['album_title']) ?>" required></div>

        <div><label>Год релиза экземпляра</label><input type="number" name="release_year" value="<?= e((string)$record['release_year']) ?>"></div>
        <div><label>Год выпуска альбома</label><input type="number" name="album_year" value="<?= e((string)$record['album_year']) ?>"></div>

        <div><label>Каталожный номер</label><input type="text" name="catalog_number" value="<?= e($record['catalog_number']) ?>"></div>
        <div><label>Баркод</label><input type="text" name="barcode" value="<?= e($record['barcode']) ?>"></div>

        <div><label>Завод</label><input type="text" name="pressing_plant" value="<?= e($record['pressing_plant']) ?>"></div>
        <div><label>Лейбл</label><input type="text" name="label_name" value="<?= e($record['label_name']) ?>"></div>

        <div><label>Жанр</label><input type="text" name="genre_name" value="<?= e((string)$record['genre_name']) ?>"></div>
        <div><label>Стиль</label><input type="text" name="style_name" value="<?= e((string)$record['style_name']) ?>"></div>

        <div><label>Дата/время поступления</label><input type="datetime-local" name="acquired_at" value="<?= e($record['acquired_at'] ? str_replace(' ', 'T', substr($record['acquired_at'], 0, 16)) : '') ?>"></div>
        <div><label>Discogs ID</label><input type="text" name="discogs_release_id" value="<?= e((string)$record['discogs_release_id']) ?>"></div>

        <div><label>Стоимость при покупке</label><input type="text" name="purchase_price" value="<?= e((string)$record['purchase_price']) ?>"></div>
        <div><label>Стоимость при покупке $</label><input type="text" name="purchase_price_usd" value="<?= e((string)$record['purchase_price_usd']) ?>"></div>

        <div><label>Стоимость при продаже</label><input type="text" name="sale_price" value="<?= e((string)$record['sale_price']) ?>"></div>
        <div>
            <label>Статус</label>
            <select name="storage_status">
                <option value="in_stock" <?= $record['storage_status'] === 'in_stock' ? 'selected' : '' ?>>В наличии</option>
                <option value="expected" <?= $record['storage_status'] === 'expected' ? 'selected' : '' ?>>Ожидаю</option>
                <option value="lent_out" <?= $record['storage_status'] === 'lent_out' ? 'selected' : '' ?>>Дал послушать</option>
            </select>
        </div>

        <div><label>Состояние носителя</label><input type="text" name="media_condition" value="<?= e($record['media_condition']) ?>"></div>
        <div><label>Состояние обложки</label><input type="text" name="sleeve_condition" value="<?= e($record['sleeve_condition']) ?>"></div>

        <?php $audioRecordId = $id; require __DIR__ . '/../../app/track_editor.php'; ?>
        <div class="full"><label>Комментарий / заметки</label><textarea name="notes"><?= e($record['notes']) ?></textarea></div>
        <div class="full"><label>Добавить фото</label><input type="file" name="photos[]" multiple accept="image/*"></div>

        <div class="full actions">
            <button type="submit">Сохранить изменения</button>
        </div>
    </form>

    <form method="post" onsubmit="return confirm('Удалить пластинку полностью вместе с фото?');" style="margin:20px 0;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="delete_record" value="1">
        <button type="submit" class="button button-danger">Удалить пластинку</button>
    </form>

    <h2>Текущие фото</h2>
    <div class="photo-grid">
        <?php foreach ($photos as $photo): ?>
            <div class="photo-card">
                <img src="<?= e($photo['file_path']) ?>" alt="">
                <?php if ((int)$photo['is_primary'] === 1): ?>
                    <div class="meta">Главное фото</div>
                <?php endif; ?>

                <form method="post" onsubmit="return confirm('Удалить фото?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="delete_photo_id" value="<?= (int)$photo['id'] ?>">
                    <button type="submit" class="button button-secondary">Удалить фото</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</main>
<?php page_foot(); ?>
