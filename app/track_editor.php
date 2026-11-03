<?php
/** $editorTracks is supplied by the add/edit page; $audioRecordId only by edit.php. */
$editorTracks = $editorTracks ?: [['position'=>'','track_title'=>'','duration'=>'']];
$audioRecordId = (int)($audioRecordId ?? 0);
$audioByTrack = [];
$musicFolder = '';
if ($audioRecordId > 0) {
    require_once __DIR__ . '/audio.php';
    try { $audioByTrack = audio_by_track($audioRecordId); $musicFolder = record_music_dir($audioRecordId); }
    catch (PDOException) { $audioRecordId = -1; } // миграция 04 не применена
}
?>
<section class="full track-editor" aria-labelledby="track-editor-title"<?php if ($audioRecordId > 0): ?> data-audio-endpoint="/admin/track_audio.php" data-record-id="<?= $audioRecordId ?>" data-csrf="<?= e(csrf_token()) ?>"<?php endif; ?>>
    <div class="section-heading"><h2 id="track-editor-title">Трек-лист</h2><button type="button" class="button button-secondary" data-add-track>Добавить трек</button></div>
    <p class="meta track-help">Позиция на пластинке, название и время сохраняются отдельно. Порядок меняется стрелками.<?php if ($audioRecordId > 0): ?> Оцифровки FLAC, WAV или MP3 загружаются сразу, без сохранения формы.<?php endif; ?></p>
    <?php if ($audioRecordId === -1): ?><p class="error">Загрузка оцифровок недоступна: примени миграцию sql/04_track_audio.sql.</p><?php endif; ?>
    <?php if ($audioRecordId > 0): ?>
    <div class="music-folder" data-music-folder<?= $musicFolder === '' ? ' hidden' : '' ?>>
        <span class="meta">Папка оцифровок: <code data-music-folder-name><?= e($musicFolder) ?></code></span>
        <button type="button" class="button button-danger" data-audio-delete-all>Удалить папку целиком</button>
    </div>
    <?php endif; ?>
    <input type="hidden" name="tracks_present" value="1">
    <div class="track-rows<?= $audioRecordId > 0 ? ' has-audio' : '' ?>" data-track-rows>
        <?php foreach ($editorTracks as $i => $track): $trackId = (int)($track['id'] ?? 0); $audio = $audioByTrack[$trackId] ?? null; ?>
            <div class="track-row" data-track-row>
                <input type="hidden" name="tracks[<?= $i ?>][id]" value="<?= $trackId ?>" data-field="id">
                <span class="track-index"><?= $i+1 ?></span>
                <label>Позиция<input type="text" name="tracks[<?= $i ?>][position]" value="<?= e((string)($track['position'] ?? '')) ?>" placeholder="A1" maxlength="32" data-field="position"></label>
                <label class="track-title-input">Название<input type="text" name="tracks[<?= $i ?>][track_title]" value="<?= e((string)($track['track_title'] ?? '')) ?>" placeholder="Название трека" maxlength="255" data-field="track_title"></label>
                <label>Время<input type="text" name="tracks[<?= $i ?>][duration]" value="<?= e((string)($track['duration'] ?? '')) ?>" placeholder="4:32" maxlength="32" inputmode="text" data-field="duration"></label>
                <?php if ($audioRecordId > 0): ?>
                <div class="track-audio" data-track-audio data-track-id="<?= $trackId ?>"<?php if ($audio): ?> data-audio-url="<?= e($audio['url']) ?>" data-audio-download="<?= e($audio['download']) ?>" data-audio-format="<?= e($audio['format']) ?>" data-audio-size="<?= e($audio['size']) ?>" data-audio-name="<?= e($audio['file_name']) ?>"<?php endif; ?>></div>
                <?php endif; ?>
                <div class="track-row-actions"><button type="button" class="button button-secondary" data-track-up aria-label="Поднять трек">↑</button><button type="button" class="button button-secondary" data-track-down aria-label="Опустить трек">↓</button><button type="button" class="button button-secondary" data-track-remove aria-label="Удалить строку трека">×</button></div>
            </div>
        <?php endforeach; ?>
    </div>
    <details class="bulk-tracks"><summary>Вставить трек-лист текстом</summary><label for="tracklist-bulk">Одна строка на трек: A1 - Название - 4:32. Также поддерживаются столбцы, разделённые табуляцией.</label><textarea id="tracklist-bulk" data-track-bulk placeholder="A1 - Первый трек - 4:32&#10;A2 - Второй трек - 3:15"></textarea><label class="inline-label"><input type="checkbox" data-track-replace> Заменить текущий список</label><button type="button" class="button button-secondary" data-import-tracks>Разобрать и добавить</button><p class="meta" data-import-status aria-live="polite"></p></details>
</section>
