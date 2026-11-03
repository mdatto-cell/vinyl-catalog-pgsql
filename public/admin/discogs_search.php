<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/discogs.php';

require_login();

$results = [];
$error = '';
$artist = trim($_POST['artist'] ?? '');
$album = trim($_POST['album'] ?? '');
$catalog = trim($_POST['catalog_number'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $results = discogs_search_release($artist, $album, $catalog);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
page_head('Поиск в Discogs');
?>
<main class="container admin-container" id="content">
    <div class="topbar">
        <h1>Поиск в Discogs</h1>
        <div class="actions">
            <a class="button button-secondary" href="/">Каталог</a>
            <a class="button button-secondary" href="/admin/add.php">Ручное добавление</a>
        </div>
    </div>

    <div class="flash">
        Поиск ограничен только релизами формата Vinyl.
    </div>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="form-grid" style="margin-bottom:20px;">
        <?= csrf_field() ?>
        <div>
            <label>Исполнитель</label>
            <input type="text" name="artist" value="<?= e($artist) ?>">
        </div>
        <div>
            <label>Альбом</label>
            <input type="text" name="album" value="<?= e($album) ?>">
        </div>
        <div>
            <label>Каталожный номер</label>
            <input type="text" name="catalog_number" value="<?= e($catalog) ?>">
        </div>
        <div class="full actions">
            <button type="submit">Искать</button>
        </div>
    </form>

    <?php foreach ($results as $row): ?>
        <div class="card" style="margin-bottom:12px;">
            <div class="card-body">
                <?php if (!empty($row['cover_image'])): ?>
                    <div style="margin-bottom:10px;">
                        <img
                            src="<?= e($row['cover_image']) ?>"
                            alt="cover"
                            style="width:140px;height:140px;object-fit:cover;border:1px solid #ddd;border-radius:8px;"
                        >
                    </div>
                <?php endif; ?>

                <strong><?= e($row['title'] ?? '') ?></strong><br>
                <div class="meta">Год: <?= e((string) ($row['year'] ?? '')) ?></div>
                <div class="meta">Cat#: <?= e($row['catno'] ?? '') ?></div>
                <div class="meta">Format: <?= e(implode(', ', $row['format'] ?? [])) ?></div>
                <div class="meta">Label: <?= e(isset($row['label'][0]) ? $row['label'][0] : '') ?></div>

                <div class="actions" style="margin-top:10px;">
                    <a class="button" href="/admin/add.php?discogs_release_id=<?= (int) ($row['id'] ?? 0) ?>">
                        Выбрать и добавить
                    </a>

                    <?php if (!empty($row['uri'])): ?>
                        <a
                            class="button button-secondary"
                            href="https://www.discogs.com<?= e($row['uri']) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Открыть в Discogs
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</main>
<?php page_foot(); ?>
