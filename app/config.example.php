<?php

declare(strict_types=1);

// Скопируйте в app/config.php и заполните. app/config.php в git не попадает.

const DB_HOST = '127.0.0.1';
const DB_PORT = 5432;
const DB_SSLMODE = 'prefer';
// const DB_TIMEZONE = 'Europe/Moscow'; // optional; defaults to PostgreSQL server timezone
const DB_NAME = 'vinyl_catalog';
const DB_USER = 'vinyl_user';
const DB_PASS = 'CHANGE_ME';

const APP_NAME = 'Каталог пластинок';
const APP_URL = 'https://catalog.example.com/';

// Персональный токен: https://www.discogs.com/settings/developers
const DISCOGS_TOKEN = '';
const DISCOGS_USER_AGENT = 'VinylCatalog/1.0 +https://catalog.example.com';

const UPLOAD_DIR = __DIR__ . '/../public/uploads/records';
const MAX_PHOTOS_PER_RECORD = 10;
const MAX_UPLOAD_SIZE = 10 * 1024 * 1024;

// ---- Оцифровки (необязательно, см. docs/audio.md и docs/cdn.md) ----
// const MUSIC_DIR = __DIR__ . '/../public/uploads/music';      // папка с аудио
// const MAX_AUDIO_UPLOAD_SIZE = 2 * 1024 * 1024 * 1024;        // 2 ГБ на файл
// const AUDIO_X_ACCEL = true;                                  // /download.php отдаёт через nginx X-Accel-Redirect
// const AUDIO_BASE_URL = 'https://cdn.example.com';            // плеер и «скачать» идут на отдельный хост
