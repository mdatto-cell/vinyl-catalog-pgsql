<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/discogs.php';
require_once __DIR__ . '/../../app/upload.php';
require_once __DIR__ . '/../../app/tracks.php';

require_login();

$form = [
    'artist' => '',
    'album_title' => '',
    'tracklist_text' => '',
    'release_year' => '',
    'album_year' => '',
    'catalog_number' => '',
    'barcode' => '',
    'pressing_plant' => '',
    'label_name' => '',
    'genre_name' => '',
    'style_name' => '',
    'acquired_at' => date('Y-m-d\TH:i'),
    'purchase_price' => '',
    'purchase_price_usd' => '',
    'sale_price' => '',
    'storage_status' => 'in_stock',
    'notes' => '',
    'discogs_release_id' => '',
    'media_condition' => '',
    'sleeve_condition' => '',
    'discogs_image_url' => '',
    'tracks' => [],
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['discogs_release_id'])) {
    try {
        $release = discogs_get_release((int) $_GET['discogs_release_id']);
        $form = array_merge($form, discogs_release_to_form_data($release));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editorTracks = $form['tracks'] ?: parse_tracklist((string)$form['tracklist_text']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if ($form['artist'] === '' || $form['album_title'] === '') {
        $error = 'Поля Исполнитель и Название альбома обязательны.';
    } else {
        try {
            $editorTracks = posted_tracks($_POST);
            $form['tracklist_text'] = tracklist_to_text($editorTracks);
            db()->beginTransaction();

            $stmt = db()->prepare('
                INSERT INTO records (
                    artist,
                    album_title,
                    tracklist_text,
                    release_year,
                    album_year,
                    catalog_number,
                    barcode,
                    pressing_plant,
                    label_name,
                    genre_name,
                    style_name,
                    acquired_at,
                    purchase_price,
                    purchase_price_usd,
                    sale_price,
                    storage_status,
                    notes,
                    discogs_release_id,
                    media_condition,
                    sleeve_condition,
                    discogs_image_url
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
            ');

            $stmt->execute([
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
            ]);

            $recordId = (int) $stmt->fetchColumn();

            save_record_tracks($recordId, $editorTracks);

            import_discogs_primary_image($recordId, $form['discogs_image_url'] ?? '');
            save_uploaded_photos($recordId, $_FILES['photos'] ?? []);

            db()->commit();

            header('Location: ' . app_url('/record.php?id=' . $recordId));
            exit;
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}
page_head('Добавить пластинку');
?>
<main class="container admin-container" id="content">
    <div class="topbar">
        <h1>Добавить пластинку</h1>
        <div class="actions">
            <a class="button button-secondary" href="/">Каталог</a>
            <a class="button button-secondary" href="/admin/discogs_search.php">Поиск Discogs</a>
        </div>
    </div>

    <?php if (!empty($form['discogs_image_url'])): ?>
        <div style="margin-bottom:16px;">
            <div class="meta">Предпросмотр обложки Discogs</div>
            <img
                src="<?= e($form['discogs_image_url']) ?>"
                alt="Discogs cover"
                style="max-width:260px;border:1px solid #ddd;border-radius:8px;"
            >
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= csrf_field() ?>
        <div><label>Исполнитель</label><input type="text" name="artist" value="<?= e($form['artist']) ?>" required></div>
        <div><label>Название альбома</label><input type="text" name="album_title" value="<?= e($form['album_title']) ?>" required></div>

        <div><label>Год релиза экземпляра</label><input type="number" name="release_year" value="<?= e((string) $form['release_year']) ?>"></div>
        <div><label>Год выпуска альбома</label><input type="number" name="album_year" value="<?= e((string) $form['album_year']) ?>"></div>

        <div><label>Каталожный номер</label><input type="text" name="catalog_number" value="<?= e($form['catalog_number']) ?>"></div>
        <div><label>Баркод</label><input type="text" name="barcode" value="<?= e($form['barcode']) ?>"></div>

        <div><label>Завод</label><input type="text" name="pressing_plant" value="<?= e($form['pressing_plant']) ?>"></div>
        <div><label>Лейбл</label><input type="text" name="label_name" value="<?= e($form['label_name']) ?>"></div>

        <div><label>Жанр</label><input type="text" name="genre_name" value="<?= e($form['genre_name']) ?>"></div>
        <div><label>Стиль</label><input type="text" name="style_name" value="<?= e($form['style_name']) ?>"></div>

        <div><label>Дата/время поступления</label><input type="datetime-local" name="acquired_at" value="<?= e($form['acquired_at']) ?>"></div>
        <div>
            <label>Discogs ID</label>
            <input type="text" name="discogs_release_id" value="<?= e((string) $form['discogs_release_id']) ?>">
            <input type="hidden" name="discogs_image_url" value="<?= e((string) ($form['discogs_image_url'] ?? '')) ?>">
        </div>

        <div><label>Стоимость при покупке</label><input type="text" name="purchase_price" value="<?= e($form['purchase_price']) ?>"></div>
        <div><label>Стоимость при покупке $</label><input type="text" name="purchase_price_usd" value="<?= e($form['purchase_price_usd']) ?>"></div>

        <div><label>Стоимость при продаже</label><input type="text" name="sale_price" value="<?= e($form['sale_price']) ?>"></div>
        <div>
            <label>Статус</label>
            <select name="storage_status">
                <option value="in_stock" <?= $form['storage_status'] === 'in_stock' ? 'selected' : '' ?>>В наличии</option>
                <option value="expected" <?= $form['storage_status'] === 'expected' ? 'selected' : '' ?>>Ожидаю</option>
                <option value="lent_out" <?= $form['storage_status'] === 'lent_out' ? 'selected' : '' ?>>Дал послушать</option>
            </select>
        </div>

        <div><label>Состояние носителя</label><input type="text" name="media_condition" value="<?= e($form['media_condition']) ?>" placeholder="NM, VG+, VG..."></div>
        <div><label>Состояние обложки</label><input type="text" name="sleeve_condition" value="<?= e($form['sleeve_condition']) ?>"></div>

        <?php require __DIR__ . '/../../app/track_editor.php'; ?>
        <div class="full"><label>Комментарий / заметки</label><textarea name="notes"><?= e($form['notes']) ?></textarea></div>
        <div class="full"><label>Фото (до 10)</label><input type="file" name="photos[]" multiple accept="image/*"></div>

        <div class="full actions">
            <button type="submit">Сохранить</button>
        </div>
    </form>
</main>
<?php page_foot(); ?>
