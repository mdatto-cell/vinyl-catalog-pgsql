(() => {
  'use strict';
  const root = document.documentElement;
  // Тема хранится в cookie "theme" (её же читает сервер), в адрес страницы не попадает.
  const applyTheme = (theme, remember = false) => {
    root.dataset.theme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      button.setAttribute('aria-pressed', String(theme === 'light'));
      button.textContent = theme === 'light' ? 'Тёмная тема' : 'Светлая тема';
    });
    if (remember) {
      document.cookie = `theme=${theme}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
    }
  };
  applyTheme(root.dataset.theme === 'light' ? 'light' : 'dark');
  try {
    const url = new URL(location.href);
    if (url.searchParams.has('theme')) { url.searchParams.delete('theme'); history.replaceState(null, '', url); }
  } catch (_) {}
  document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true));
  document.querySelectorAll('[data-auto-submit]').forEach(field => field.addEventListener('change', () => field.form.requestSubmit()));
  // Associate the original metadata labels without changing their field names.
  document.querySelectorAll('label').forEach((label,index) => {
    if (label.htmlFor || label.querySelector('input,select,textarea')) return;
    const field = label.nextElementSibling;
    if (field?.matches('input,select,textarea')) { if (!field.id) field.id = `field-${index}`; label.htmlFor = field.id; }
  });
  const rows = document.querySelector('[data-track-rows]');
  if (rows) {
    const template = rows.querySelector('[data-track-row]').cloneNode(true);
    const reindex = () => [...rows.children].forEach((row,i,array) => {
      row.querySelector('.track-index').textContent = i + 1;
      row.querySelectorAll('[data-field]').forEach(input => { input.name = `tracks[${i}][${input.dataset.field}]`; });
      row.querySelector('[data-track-up]').disabled = i === 0;
      row.querySelector('[data-track-down]').disabled = i === array.length - 1;
    });
    const append = (track = {}) => {
      const row = template.cloneNode(true);
      row.querySelectorAll('[data-field]').forEach(input => { input.value = track[input.dataset.field] || ''; });
      const audioCell = row.querySelector('[data-track-audio]');
      if (audioCell) { ['audioUrl','audioDownload','audioFormat','audioSize','audioName'].forEach(key => delete audioCell.dataset[key]); audioCell.dataset.trackId = '0'; window.renderTrackAudio?.(audioCell); }
      rows.append(row); reindex(); return row;
    };
    document.querySelector('[data-add-track]').addEventListener('click', () => append().querySelector('[data-field="track_title"]').focus());
    rows.addEventListener('click', event => {
      const button = event.target.closest('button'); if (!button) return;
      const row = button.closest('[data-track-row]');
      if (button.hasAttribute('data-track-remove')) {
        const audioName = row.querySelector('[data-track-audio]')?.dataset.audioName;
        if (audioName && !confirm(`У трека есть оцифровка ${audioName}. После сохранения формы файл будет удалён с диска. Убрать трек?`)) return;
        row.remove(); if (!rows.children.length) append();
      }
      if (button.hasAttribute('data-track-up') && row.previousElementSibling) rows.insertBefore(row,row.previousElementSibling);
      if (button.hasAttribute('data-track-down') && row.nextElementSibling) rows.insertBefore(row.nextElementSibling,row);
      reindex();
    });
    const parseLine = line => {
      line = line.replace(/^[ \r\n]+|[ \r\n]+$/g,'');
      const columns = line.split('\t');
      if (columns.length >= 3) return {position:columns[0].trim(),track_title:columns.slice(1,-1).join('\t').trim(),duration:columns.at(-1).trim()};
      let duration = '', position = '', match;
      match = line.match(/(?:\s+[-–—|]\s+|\s+)\(?(\d{1,3}:\d{2}(?::\d{2})?)\)?$/u);
      if (match) { duration = match[1]; line = line.slice(0,match.index).trim(); }
      match = line.match(/^((?:[A-Za-zА-Яа-я]{1,3}\d+(?:[.-]\d+)*|\d{1,3}(?:[.-]\d+)*|[A-Za-zА-Яа-я]))\s+[-–—|]\s+(.+)$/u)
        || line.match(/^((?:[A-Za-zА-Яа-я]{1,3}\d+(?:[.-]\d+)*|\d{1,3}(?:[.-]\d+)*|[A-Za-zА-Яа-я]))[.)]\s+(.+)$/u)
        || line.match(/^([A-Za-zА-Яа-я]{1,3}\d+(?:[.-]\d+)*)\s+(.+)$/u);
      if (match) { position = match[1]; line = match[2]; }
      return {position,track_title:line.trim(),duration};
    };
    document.querySelector('[data-import-tracks]').addEventListener('click', () => {
      const tracks = document.querySelector('[data-track-bulk]').value.split(/\r\n|\r|\n/).filter(line => line.trim()).map(parseLine);
      const status = document.querySelector('[data-import-status]');
      if (!tracks.length) { status.textContent = 'Сначала вставь трек-лист.'; return; }
      if (document.querySelector('[data-track-replace]').checked) {
        if (rows.querySelector('[data-track-audio][data-audio-url]') && !confirm('У части треков есть оцифровки. При замене списка и сохранении формы эти файлы будут удалены с диска. Продолжить?')) return;
        rows.replaceChildren();
      }
      else [...rows.children].filter(row => [...row.querySelectorAll('[data-field]:not([data-field="id"])')].every(input => !input.value.trim())).forEach(row => row.remove());
      tracks.forEach(append);
      status.textContent = `Добавлено строк: ${tracks.length}. Проверь позиции и время перед сохранением.`;
    });
    reindex();
  }
  const primary = document.querySelector('[data-photo-open]');
  document.querySelectorAll('[data-photo-select]').forEach(button => button.addEventListener('click', () => {
    primary.querySelector('img').src = button.dataset.photoSelect;
    primary.dataset.photoOpen = button.dataset.photoSelect;
    document.querySelectorAll('[data-photo-select]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
  }));
  const dialog = document.querySelector('#photo-dialog');
  primary?.addEventListener('click', () => { dialog.querySelector('img').src = primary.dataset.photoOpen; dialog.showModal(); });
  document.querySelector('[data-photo-close]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  // ---- Оцифровки: админка ----
  const svg = d => `<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="${d}"/></svg>`;
  const ICON_UPLOAD = 'M11 20h2v-9.2l3.3 3.3 1.4-1.4L12 7l-5.7 5.7 1.4 1.4 3.3-3.3zM5 3h14v2H5z';
  const ICON_DOWNLOAD = 'M11 4h2v9.2l3.3-3.3 1.4 1.4L12 17l-5.7-5.7 1.4-1.4 3.3 3.3zM5 19h14v2H5z';
  const ICON_DELETE = 'M9 3h6l1 2h4v2H4V5h4zm-3 6h12l-1 12H7z';
  const ICON_PLAY = 'M8 5v14l11-7z';
  const ICON_PAUSE = 'M7 5h4v14H7zm6 0h4v14h-4z';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const audioEditor = document.querySelector('[data-audio-endpoint]');
  let activeUploads = 0;
  const renderTrackAudio = (cell, message = '') => {
    const data = cell.dataset;
    if (!data.trackId || data.trackId === '0') { cell.innerHTML = '<span class="audio-pending">Загрузка после сохранения</span>'; return; }
    const title = cell.closest('[data-track-row]')?.querySelector('[data-field="track_title"]')?.value || 'трек';
    const upload = `<label class="icon-button audio-upload" title="${data.audioUrl ? 'Заменить оцифровку' : 'Загрузить оцифровку'}">${svg(ICON_UPLOAD)}<span class="visually-hidden">${data.audioUrl ? 'Заменить' : 'Загрузить'} оцифровку: ${escapeHtml(title)}</span><input type="file" accept=".flac,.wav,.mp3,audio/flac,audio/wav,audio/x-wav,audio/mpeg" data-audio-file></label>`;
    cell.innerHTML = data.audioUrl
      ? `<a class="audio-file" href="${escapeHtml(data.audioDownload || `/download.php?track=${encodeURIComponent(data.trackId)}`)}" title="${escapeHtml(data.audioName)}">${escapeHtml(data.audioFormat)} · ${escapeHtml(data.audioSize)}</a>${upload}<button type="button" class="icon-button audio-delete" data-audio-delete title="Удалить файл" aria-label="Удалить оцифровку: ${escapeHtml(title)}">${svg(ICON_DELETE)}</button>`
      : `${upload}<span class="audio-empty">нет файла</span>`;
    if (message) cell.insertAdjacentHTML('beforeend', `<span class="audio-error" role="alert">${escapeHtml(message)}</span>`);
  };
  window.renderTrackAudio = renderTrackAudio;
  if (audioEditor) {
    const endpoint = audioEditor.dataset.audioEndpoint;
    const folderBox = audioEditor.querySelector('[data-music-folder]');
    const setFolder = name => { if (!folderBox) return; folderBox.hidden = !name; folderBox.querySelector('[data-music-folder-name]').textContent = name || ''; };
    const formData = fields => {
      const body = new FormData();
      Object.entries({record_id: audioEditor.dataset.recordId, csrf_token: audioEditor.dataset.csrf, ...fields}).forEach(([k, v]) => body.append(k, v));
      return body;
    };
    const send = (fields, onProgress) => new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint);
      xhr.responseType = 'json';
      if (onProgress) xhr.upload.addEventListener('progress', e => e.lengthComputable && onProgress(e.loaded / e.total));
      xhr.addEventListener('load', () => {
        const json = xhr.response || {};
        if (xhr.status === 413) reject(new Error(json.error || 'Файл слишком большой для настроек сервера (client_max_body_size в Nginx или post_max_size в PHP).'));
        else if (xhr.status >= 200 && xhr.status < 300 && json.ok) resolve(json);
        else reject(new Error(json.error || `Сервер ответил ${xhr.status}.`));
      });
      xhr.addEventListener('error', () => reject(new Error('Соединение прервано.')));
      xhr.send(formData(fields));
    });
    audioEditor.querySelectorAll('[data-track-audio]').forEach(cell => renderTrackAudio(cell));
    audioEditor.addEventListener('change', async event => {
      const input = event.target.closest('[data-audio-file]'); if (!input?.files?.length) return;
      const cell = input.closest('[data-track-audio]'); const file = input.files[0];
      if (!/\.(flac|wav|mp3)$/i.test(file.name)) { renderTrackAudio(cell, 'Поддерживаются только FLAC, WAV и MP3.'); return; }
      if (cell.dataset.audioUrl && !confirm(`Заменить ${cell.dataset.audioName} новым файлом?`)) { renderTrackAudio(cell); return; }
      cell.innerHTML = '<span class="audio-progress"><progress max="100" value="0"></progress><span>0%</span></span>';
      const bar = cell.querySelector('progress'), label = cell.querySelector('.audio-progress span');
      activeUploads++;
      try {
        const json = await send({action: 'upload', track_id: cell.dataset.trackId, audio: file}, ratio => {
          const percent = Math.round(ratio * 100); bar.value = percent; label.textContent = percent < 100 ? `${percent}%` : 'обработка…';
        });
        const audio = json.track.audio;
        Object.assign(cell.dataset, {audioUrl: audio.url, audioDownload: audio.download || '', audioFormat: audio.format, audioSize: audio.size, audioName: audio.file_name});
        setFolder(json.track.folder);
        renderTrackAudio(cell);
      } catch (error) { renderTrackAudio(cell, error.message); }
      finally { activeUploads--; }
    });
    audioEditor.addEventListener('click', async event => {
      const remove = event.target.closest('[data-audio-delete]');
      if (remove) {
        const cell = remove.closest('[data-track-audio]');
        if (!confirm(`Удалить файл ${cell.dataset.audioName} с диска?`)) return;
        try {
          const json = await send({action: 'delete', track_id: cell.dataset.trackId});
          ['audioUrl','audioDownload','audioFormat','audioSize','audioName'].forEach(key => delete cell.dataset[key]);
          setFolder(json.folder); renderTrackAudio(cell);
        } catch (error) { renderTrackAudio(cell, error.message); }
        return;
      }
      if (event.target.closest('[data-audio-delete-all]')) {
        const name = folderBox?.querySelector('[data-music-folder-name]').textContent;
        if (!confirm(`Удалить папку ${name} со всеми оцифровками этого альбома? Действие необратимо.`)) return;
        try {
          await send({action: 'delete_all'});
          audioEditor.querySelectorAll('[data-track-audio]').forEach(cell => { ['audioUrl','audioDownload','audioFormat','audioSize','audioName'].forEach(key => delete cell.dataset[key]); renderTrackAudio(cell); });
          setFolder('');
        } catch (error) { alert(error.message); }
      }
    });
    window.addEventListener('beforeunload', event => { if (activeUploads > 0) { event.preventDefault(); event.returnValue = ''; } });
    audioEditor.closest('form')?.addEventListener('submit', event => {
      if (activeUploads > 0 && !confirm('Оцифровка ещё загружается. Сохранение формы прервёт загрузку. Продолжить?')) event.preventDefault();
    });
  }

  // ---- Оцифровки: мини-проигрыватель в карточке ----
  const playerTable = document.querySelector('[data-player]');
  if (playerTable) {
    const audio = new Audio(); audio.preload = 'none';
    let current = null, seeking = false;
    const time = seconds => {
      if (!Number.isFinite(seconds)) return '0:00';
      seconds = Math.floor(seconds); const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = String(seconds % 60).padStart(2, '0');
      return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
    };
    const setButton = (row, playing) => {
      const button = row.querySelector('[data-play]'), name = row.querySelector('.track-name').textContent;
      row.classList.toggle('is-playing', playing);
      button.innerHTML = svg(playing ? ICON_PAUSE : ICON_PLAY);
      button.setAttribute('aria-label', `${playing ? 'Пауза' : 'Слушать'}: ${name}`);
      button.title = playing ? 'Пауза' : 'Слушать';
    };
    const update = () => {
      if (!current) return;
      const seek = current.querySelector('[data-mini-seek]'), ratio = audio.duration ? audio.currentTime / audio.duration : 0;
      if (!seeking) seek.value = Math.round(ratio * 1000);
      seek.style.setProperty('--progress', `${ratio * 100}%`);
      current.querySelector('[data-mini-time]').textContent = `${time(audio.currentTime)} / ${time(audio.duration)}`;
    };
    const start = row => {
      if (current && current !== row) { setButton(current, false); current.classList.remove('is-active'); current.querySelector('[data-mini-player]').hidden = true; }
      current = row; row.classList.add('is-active');
      row.querySelector('[data-mini-player]').hidden = false;
      audio.src = row.dataset.trackSrc;
      audio.play().catch(() => { row.querySelector('[data-mini-time]').textContent = 'Браузер не смог воспроизвести файл'; });
    };
    playerTable.addEventListener('click', event => {
      const button = event.target.closest('[data-play]'); if (!button) return;
      const row = button.closest('tr');
      if (row === current) audio.paused ? audio.play() : audio.pause(); else start(row);
    });
    playerTable.addEventListener('input', event => {
      const seek = event.target.closest('[data-mini-seek]'); if (!seek || seek.closest('tr') !== current) return;
      seeking = true; if (audio.duration) audio.currentTime = seek.value / 1000 * audio.duration; seeking = false; update();
    });
    audio.addEventListener('play', () => current && setButton(current, true));
    audio.addEventListener('pause', () => current && setButton(current, false));
    ['timeupdate', 'loadedmetadata', 'durationchange'].forEach(type => audio.addEventListener(type, update));
    audio.addEventListener('error', () => { if (current) current.querySelector('[data-mini-time]').textContent = 'Не удалось загрузить файл'; });
    audio.addEventListener('ended', () => {
      let next = current?.nextElementSibling;
      while (next && !next.dataset.trackSrc) next = next.nextElementSibling;
      if (next) start(next); else if (current) setButton(current, false);
    });
  }
})();
