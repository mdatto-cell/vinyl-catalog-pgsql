<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$view = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
$letter = trim((string)($_GET['letter'] ?? ''));
$genre = trim((string)($_GET['genre'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['artist','year','added'],true) ? $_GET['sort'] : 'artist';
$isAdmin = is_logged_in();
$sql = "SELECT r.*, (SELECT rp.file_path FROM record_photos rp WHERE rp.record_id = r.id
        ORDER BY rp.is_primary DESC, rp.sort_order, rp.id LIMIT 1) AS primary_photo,
        CAST(EXISTS(SELECT 1 FROM record_tracks rt WHERE rt.record_id = r.id AND rt.audio_path IS NOT NULL) AS integer) AS has_audio
        FROM records r WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= ' AND (r.artist ILIKE ? OR r.album_title ILIKE ? OR r.catalog_number ILIKE ? OR r.barcode ILIKE ? OR r.label_name ILIKE ?)';
    array_push($params, ...array_fill(0,5,'%'.$q.'%'));
}
if ($isAdmin && in_array($status,['in_stock','expected','lent_out'],true)) {
    $sql .= ' AND r.storage_status = ?'; $params[] = $status;
}
if ($letter !== '') {
    $sql .= ' AND UPPER(LEFT(r.artist,1)) = ?'; $params[] = mb_strtoupper($letter);
}
if ($genre !== '') {
    $sql .= " AND lower(?) = ANY(string_to_array(lower(REPLACE(COALESCE(r.genre_name,''), ', ', ',')), ','))"; $params[] = $genre;
}
$sql .= match($sort) {
    'year' => ' ORDER BY r.album_year IS NULL, r.album_year, r.artist, r.album_title',
    'added' => ' ORDER BY r.id DESC',
    default => ' ORDER BY r.artist, r.album_year IS NULL, r.album_year, r.album_title'
};
try {
    $stmt=db()->prepare($sql);$stmt->execute($params);$records=$stmt->fetchAll();
} catch (PDOException $ex) {
    // Миграция 04_track_audio.sql ещё не применена — каталог работает без значка «Слушать».
    $sql=preg_replace('/,\s*CAST\(EXISTS\(SELECT 1 FROM record_tracks.*?\) AS integer\) AS has_audio/s','',$sql);
    $stmt=db()->prepare($sql);$stmt->execute($params);$records=$stmt->fetchAll();
}
function format_rub(float $value): string {
    return number_format($value, fmod($value, 1.0) == 0.0 ? 0 : 2, ',', "\u{00A0}") . "\u{00A0}₽";
}
$priceSum=0.0;$pricedCount=0;
if ($isAdmin) {
    foreach ($records as $row) {
        if ($row['purchase_price'] !== null && $row['purchase_price'] !== '') { $priceSum += (float)$row['purchase_price']; $pricedCount++; }
    }
}
$listenBadge='<span class="listen-badge" title="Есть оцифровка — можно слушать"><svg viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M3 1.8v8.4L10 6z"/></svg><span class="listen-badge-text">Слушать</span><span class="visually-hidden">: есть оцифровка</span></span>';
$total=(int)db()->query('SELECT COUNT(*) FROM records')->fetchColumn();
$letters=db()->query('SELECT DISTINCT UPPER(LEFT(artist,1)) AS letter FROM records ORDER BY letter')->fetchAll();
$genres=[];
foreach(db()->query("SELECT DISTINCT genre_name FROM records WHERE genre_name IS NOT NULL") as $row) {
    foreach(explode(',',$row['genre_name']) as $g) { $g=trim($g);if($g!=='')$genres[$g]=true; }
}
$genres=array_keys($genres);sort($genres);
function catalog_url(array $changes=[]): string {
    $allowed=array_intersect_key($_GET,array_flip(['q','status','view','letter','genre','sort']));
    return '/?' . http_build_query(array_filter(array_merge($allowed,$changes),static fn($v)=>$v!==''));
}
page_head('Моя коллекция');
?>
<main class="container catalog-container" id="content">
    <div class="catalog-heading"><h1>Моя коллекция</h1></div>
    <form method="get" class="catalog-search" autocomplete="off">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <?php if($letter!==''): ?><input type="hidden" name="letter" value="<?= e($letter) ?>"><?php endif; ?>
        <div class="search-field"><label for="catalog-query" class="sr-only">Поиск по коллекции</label><span aria-hidden="true">⌕</span><input id="catalog-query" type="search" name="q" value="<?= e($q) ?>" placeholder="Исполнитель, альбом, номер, баркод…" autocomplete="off"><button type="submit">Искать</button></div>
        <div class="filter-fields">
            <label class="sr-only" for="genre">Жанр</label><select id="genre" name="genre" data-auto-submit><option value="">Все жанры</option><?php foreach($genres as $g): ?><option value="<?= e($g) ?>" <?= $genre===$g?'selected':'' ?>><?= e($g) ?></option><?php endforeach; ?></select>
            <?php if($isAdmin): ?><label class="sr-only" for="storage-status">Наличие</label><select id="storage-status" name="status" data-auto-submit><option value="">Все статусы</option><?php foreach(['in_stock','expected','lent_out'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select><?php endif; ?>
        </div>
    </form>
    <div class="catalog-toolbar">
        <p class="meta catalog-count"><?= count($records) ?> из <?= $total ?> пластинок<?php if($isAdmin && $pricedCount>0): ?> <span class="catalog-sum" title="Сумма покупки по <?= $pricedCount ?> пластинкам с указанной ценой">(<?= e(format_rub($priceSum)) ?>)</span><?php endif; ?></p>
        <div class="catalog-tools">
            <?php if($q!==''||$genre!==''||$letter!==''||$status!==''): ?><a class="reset-link" href="/?view=<?= $view ?>">Сбросить</a><?php endif; ?>
            <form method="get" class="sort-form">
                <?php foreach(['q'=>$q,'status'=>$status,'view'=>$view,'letter'=>$letter,'genre'=>$genre] as $key=>$value): if($value==='')continue; ?><input type="hidden" name="<?= $key ?>" value="<?= e($value) ?>"><?php endforeach; ?>
                <label class="sr-only" for="sort">Сортировка</label><select id="sort" name="sort" data-auto-submit><option value="artist" <?= $sort==='artist'?'selected':'' ?>>По исполнителю</option><option value="year" <?= $sort==='year'?'selected':'' ?>>По году альбома</option><option value="added" <?= $sort==='added'?'selected':'' ?>>Сначала новые</option></select><noscript><button>Применить</button></noscript>
            </form>
            <div class="view-switch"><a href="<?= e(catalog_url(['view'=>'grid'])) ?>" aria-label="Плитка" <?= $view==='grid'?'aria-current="true"':'' ?>>▦</a><a href="<?= e(catalog_url(['view'=>'list'])) ?>" aria-label="Список" <?= $view==='list'?'aria-current="true"':'' ?>>☰</a></div>
        </div>
    </div>
    <nav class="alphabet" aria-label="По первой букве исполнителя"><a href="<?= e(catalog_url(['letter'=>''])) ?>" <?= $letter===''?'aria-current="true"':'' ?>>Все</a><?php foreach($letters as $item): ?><a href="<?= e(catalog_url(['letter'=>$item['letter']])) ?>" <?= mb_strtoupper($letter)===$item['letter']?'aria-current="true"':'' ?>><?= e($item['letter']) ?></a><?php endforeach; ?></nav>
    <?php if(!$records): ?>
        <section class="empty-state"><h2>Ничего не нашлось</h2><p>Попробуй другое название или убери фильтры.</p><a class="button button-secondary" href="<?= e(app_url('/')) ?>">Вся коллекция</a></section>
    <?php elseif($view==='list'): ?>
        <div class="catalog-list">
        <?php foreach($records as $record): ?>
            <article class="catalog-list-row <?= $record['storage_status']==='expected'?'row-expected':'' ?>">
                <a class="list-cover" href="<?= e(app_url('/record.php?id='.(int)$record['id'])) ?>"><?php if($record['primary_photo']): ?><img src="<?= e($record['primary_photo']) ?>" alt="<?= e($record['artist'].' — '.$record['album_title']) ?>" width="80" height="80" loading="lazy"><?php else: ?><span class="cover-placeholder">Нет фото</span><?php endif; ?><?= !empty($record['has_audio'])?$listenBadge:'' ?></a>
                <div class="list-title"><h2><a href="<?= e(app_url('/record.php?id='.(int)$record['id'])) ?>"><?= e($record['artist']) ?></a></h2><p><?= e($record['album_title']) ?></p></div>
                <div class="list-year"><?= e((string)$record['album_year']) ?></div>
                <div class="list-release"><span><?= e((string)$record['genre_name']) ?></span><span><?= e(trim(($record['label_name']??'').' '.($record['release_year']??''))) ?></span><span><?= e((string)$record['style_name']) ?></span></div>
                <?php if($isAdmin): ?><div class="list-private"><span><?= e(status_label($record['storage_status'])) ?></span><?php if($record['purchase_price']!==null): ?><span><?= e(format_rub((float)$record['purchase_price'])) ?></span><?php endif; ?></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card-grid">
        <?php foreach($records as $i=>$record): ?>
            <article class="card <?= $record['storage_status']==='expected'?'card-expected':'' ?>">
                <a class="card-cover" href="<?= e(app_url('/record.php?id='.(int)$record['id'])) ?>"><?php if($record['primary_photo']): ?><img src="<?= e($record['primary_photo']) ?>" alt="<?= e($record['artist'].' — '.$record['album_title']) ?>" width="600" height="600" decoding="async" <?= $i>7?'loading="lazy"':'' ?>><?php else: ?><span class="cover-placeholder">Нет обложки</span><?php endif; ?><?= !empty($record['has_audio'])?$listenBadge:'' ?><span class="cover-open" aria-hidden="true">Подробнее ↗</span></a>
                <div class="card-body"><h2><a href="<?= e(app_url('/record.php?id='.(int)$record['id'])) ?>"><?= e($record['artist']) ?></a></h2><p class="album-title"><?= e($record['album_title']) ?></p><div class="card-meta"><span><?= e((string)$record['album_year']) ?></span><span><?= e((string)$record['genre_name']) ?></span></div><p class="card-label"><?= e(trim(($record['label_name']??'').' '.($record['release_year']??''))) ?></p><?php if($isAdmin): ?><p class="record-status"><span><?= e(status_label($record['storage_status'])) ?></span><?php if($record['purchase_price']!==null && $record['purchase_price']!==''): ?><span class="card-price"><?= e(format_rub((float)$record['purchase_price'])) ?></span><?php endif; ?></p><?php endif; ?></div>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php page_foot(); ?>
