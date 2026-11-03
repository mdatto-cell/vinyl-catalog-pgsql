<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/tracks.php';
require_once __DIR__ . '/../app/audio.php';
$id=(int)($_GET['id']??0);$isAdmin=is_logged_in();
$stmt=db()->prepare('SELECT * FROM records WHERE id=? LIMIT 1');$stmt->execute([$id]);$record=$stmt->fetch();
if(!$record){http_response_code(404);page_head('Пластинка не найдена');echo '<main class="container" id="content"><h1>Пластинка не найдена</h1><p><a href="/">Вернуться в каталог</a></p></main>';page_foot();exit;}
$tracks=tracks_for_record($id,(string)($record['tracklist_text']??''));
$stmt=db()->prepare('SELECT * FROM record_photos WHERE record_id=? ORDER BY is_primary DESC,sort_order,id');$stmt->execute([$id]);$photos=$stmt->fetchAll();
$duration=tracklist_duration($tracks);
$hasAudio=(bool)array_filter($tracks,static fn(array $t):bool=>!empty($t['audio_path']));
$icon=static fn(string $d):string=>'<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="'.$d.'"/></svg>';
page_head($record['artist'].' — '.$record['album_title']);
?>
<main class="container record-container" id="content">
    <div class="record-breadcrumb"><a href="<?= e(app_url('/')) ?>">← Моя коллекция</a><?php if($isAdmin): ?><a class="button button-secondary" href="<?= e(app_url('/admin/edit.php?id='.$id)) ?>">Редактировать</a><?php endif; ?></div>
    <div class="record-detail">
        <div class="record-gallery">
            <?php if($photos): ?><button class="primary-photo" type="button" data-photo-open="<?= e($photos[0]['file_path']) ?>"><img src="<?= e($photos[0]['file_path']) ?>" alt="<?= e($record['artist'].' — '.$record['album_title']) ?>" width="800" height="800"></button>
            <?php if(count($photos)>1): ?><div class="photo-thumbnails"><?php foreach($photos as $i=>$photo): ?><button type="button" data-photo-select="<?= e($photo['file_path']) ?>" aria-label="Фото <?= $i+1 ?>" aria-pressed="<?= $i===0?'true':'false' ?>"><img src="<?= e($photo['file_path']) ?>" alt="" width="100" height="100" loading="lazy"></button><?php endforeach; ?></div><?php endif; ?>
            <?php else: ?><div class="cover-placeholder large">Нет обложки</div><?php endif; ?>
        </div>
        <section class="record-information" aria-labelledby="record-title">
            <p class="record-artist"><?= e($record['artist']) ?></p><h1 id="record-title"><?= e($record['album_title']) ?></h1>
            <p class="record-summary"><?= e((string)$record['album_year']) ?><?php if($record['genre_name']): ?> · <?= e($record['genre_name']) ?><?php endif; ?></p>
            <dl class="record-metadata">
                <?php foreach(['release_year'=>'Год издания','album_year'=>'Год альбома','label_name'=>'Лейбл','catalog_number'=>'Каталожный номер','barcode'=>'Баркод','pressing_plant'=>'Завод','style_name'=>'Стиль','media_condition'=>'Состояние носителя','sleeve_condition'=>'Состояние обложки','discogs_release_id'=>'Discogs ID'] as $field=>$label): if(($record[$field]??'')==='')continue; if($record[$field]===null)continue; ?><dt><?= $label ?></dt><dd><?= e((string)$record[$field]) ?></dd><?php endforeach; ?>
            </dl>
            <?php if($isAdmin): ?><details class="private-details"><summary>Учёт экземпляра</summary><dl class="record-metadata"><?php foreach(['acquired_at'=>'Поступление','purchase_price'=>'Покупка, ₽','purchase_price_usd'=>'Покупка, USD','sale_price'=>'Продажа, ₽'] as $field=>$label): ?><dt><?= $label ?></dt><dd><?= e((string)($record[$field]??'')) ?></dd><?php endforeach; ?><dt>Статус</dt><dd><?= e(status_label($record['storage_status'])) ?></dd></dl></details><?php endif; ?>
            <?php if(!empty($record['notes'])): ?><div class="record-notes"><h2>Заметки</h2><p><?= nl2br(e($record['notes'])) ?></p></div><?php endif; ?>
        </section>
    </div>
    <section class="tracklist-section" aria-labelledby="tracklist-heading"><div class="section-heading"><h2 id="tracklist-heading">Трек-лист</h2><span class="meta"><?= count($tracks) ?> треков<?php if($duration!==''): ?> · <?= e($duration) ?><?php endif; ?></span></div>
        <?php if($tracks): ?><table class="table tracklist<?= $hasAudio?' has-audio':'' ?>"<?= $hasAudio?' data-player':'' ?>><thead><tr><th scope="col">#</th><th scope="col">Позиция</th><th scope="col">Название</th><?php if($hasAudio): ?><th scope="col"><span class="visually-hidden">Оцифровка</span></th><?php endif; ?><th scope="col">Время</th></tr></thead><tbody><?php foreach($tracks as $i=>$track): $audio=audio_info($track); ?><tr<?php if($audio): ?> data-track-src="<?= e($audio['url']) ?>"<?php endif; ?>><td><?= $i+1 ?></td><td><?= e((string)($track['position']??'')) ?></td><td><span class="track-name"><?= e((string)$track['track_title']) ?></span><?php if($audio): ?><div class="mini-player" data-mini-player hidden><input type="range" min="0" max="1000" value="0" step="1" data-mini-seek aria-label="Перемотка: <?= e((string)$track['track_title']) ?>"><span class="mini-time" data-mini-time>0:00</span></div><?php endif; ?></td><?php if($hasAudio): ?><td class="track-listen"><?php if($audio): ?><button type="button" class="icon-button track-play" data-play aria-label="Слушать: <?= e((string)$track['track_title']) ?>" title="Слушать"><?= $icon('M8 5v14l11-7z') ?></button><a class="icon-button track-download" href="<?= e($audio['download']) ?>" aria-label="Скачать <?= e($audio['format']) ?>, <?= e($audio['size']) ?>: <?= e((string)$track['track_title']) ?>" title="Скачать <?= e($audio['format']) ?> · <?= e($audio['size']) ?>"><?= $icon('M11 4h2v9.2l3.3-3.3 1.4 1.4L12 17l-5.7-5.7 1.4-1.4 3.3 3.3zM5 19h14v2H5z') ?></a><?php endif; ?></td><?php endif; ?><td><?= e((string)($track['duration']??'')) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="meta">Трек-лист пока не добавлен.</p><?php endif; ?>
    </section>
</main>
<dialog class="photo-dialog" id="photo-dialog"><button type="button" data-photo-close aria-label="Закрыть фото">×</button><img alt="<?= e($record['artist'].' — '.$record['album_title']) ?>" width="1200" height="1200"></dialog>
<?php page_foot(); ?>
