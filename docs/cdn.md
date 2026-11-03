# Раздача оцифровок через cdn.example.com

Страницы и админка остаются на site.example.com. Плеер и кнопка «скачать» берут файлы с отдельного хоста, который кеширует `/uploads/music/` у основного сервера.

## 1. config.php на site.example.com

```php
// Внешний адрес для оцифровок, без слеша в конце. Убрать или '' — отдаёт сам каталог.
const AUDIO_BASE_URL = 'https://cdn.example.com';
```

Что меняется:

- Плеер: `https://cdn.example.com/uploads/music/<папка>/<файл>?v=<версия>`.
- Скачать: та же ссылка с `&dl=1`. Заголовок `Content-Disposition: attachment` ставит cdn.
- Старые ссылки `/download.php?track=ID` отвечают 302 на cdn. Ссылки, которые уже разосланы, продолжают работать.
- `?v=` — это хеш от даты загрузки и размера. После замены файла ссылка меняется, и cdn не отдаст старую копию из кеша.
- Загрузка и удаление файлов, а также папки на диске остаются на основном сервере. В БД, как и раньше, хранится путь `/uploads/music/...`.

## 2. Nginx на cdn.example.com

Нужен модуль `ngx_http_slice_module`, он есть в пакетах nginx для Ubuntu и Debian (проверка: `nginx -V 2>&1 | grep -o with-http_slice_module`). Файлы кешируются кусками по 8 МБ. Перемотка и докачка работают сразу, без ожидания, пока cdn скачает весь FLAC целиком.

```nginx
# http { ... }
proxy_cache_path /var/cache/nginx/music levels=1:2 keys_zone=music:50m
                 max_size=200g inactive=60d use_temp_path=off;

# Имя файла из URL (уже в процентной кодировке, подходит для filename*)
map $request_uri $music_fname {
    default              "";
    "~/([^/?]+)(\?|$)"   $1;
}
map $arg_dl $music_disposition {
    default "";
    1       "attachment; filename*=UTF-8''$music_fname";
}

# Отдельный upstream для музыки. max_fails=0 — сервер не помечается «мёртвым»
# из-за чужих запросов (сканеры по IP и т.п.). Иначе модуль slice получит
# «no live upstreams» и оборвёт скачивание на границе куска.
upstream music_backend {
    server <IP основного сервера>:443 max_fails=0;
    keepalive 8;
}

server {
    listen 443 ssl;
    http2 on;                      # для nginx < 1.25.1 (например, 1.18): listen 443 ssl http2;
    server_name cdn.examlpe.com;

    ssl_certificate     /etc/letsencrypt/live/cdn.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cdn.example.com/privkey.pem;

    location /uploads/music/ {
        limit_except GET HEAD { deny all; }

        slice 8m;
        proxy_set_header Range $slice_range;
        proxy_cache       music;
        proxy_cache_key   $uri$arg_v$slice_range;    # dl= не влияет на ключ: плеер и скачивание делят кеш
        proxy_cache_valid 200 206 60d;
        proxy_cache_valid 404 1m;
        proxy_cache_lock  on;
        proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
        proxy_ignore_headers Set-Cookie Cache-Control Expires;
        proxy_hide_header Set-Cookie;

        proxy_http_version 1.1;
        proxy_set_header Host site.example.com;
        proxy_set_header Connection "";
        proxy_ssl_server_name on;
        proxy_ssl_name site.example.com;
        proxy_pass https://music_backend;          # отдельный upstream, см. ниже
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;

        add_header Content-Disposition $music_disposition always;   # пустое значение не отправляется
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        add_header Access-Control-Allow-Origin "https://site.example.com" always;
        add_header X-Content-Type-Options nosniff always;
        add_header X-Cache $upstream_cache_status always;
    }

    location / { return 404; }
}

server {
    listen 80;
    server_name cdn.example.com;
    return 301 https://$host$request_uri;
}
```

```bash
install -d -o www-data -g www-data /var/cache/nginx/music
nginx -t && systemctl reload nginx
```

Типы `audio/flac`, `audio/wav`, `audio/mpeg` отдаёт основной сервер: на Apache через `uploads/music/.htaccess`, cdn передаёт их дальше. Если `Content-Type` придёт `application/octet-stream`, добавьте на cdn `types { audio/flac flac; audio/wav wav; audio/mpeg mp3; }` в location и `proxy_hide_header Content-Type;`.

## 3. DNS и сертификат

- Запись A или CNAME для `cdn.example.com`.
- `certbot --nginx -d cdn.example.com` (или `certonly --webroot`).

## 4. Необязательно: пускать к /uploads/music/ только cdn

Если основной сервер должен отдавать музыку только cdn (Apache 2.4, в `public/uploads/music/.htaccess`):

```apache
Require ip <IP cdn>
```

Админке это не мешает: загрузка идёт через `/admin/track_audio.php`, а не через эту папку.

## Проверка

```bash
U='https://cdn.example.com/uploads/music/<папка>/<файл>?v=test'
curl -sI "$U" | grep -iE 'HTTP/|content-type|content-length|accept-ranges|x-cache'      # MISS, затем HIT
curl -s -o /dev/null -D - -r 50000000-50000099 "$U" | grep -iE 'HTTP/|content-range'   # 206
curl -sI "$U&dl=1" | grep -i content-disposition                                       # attachment; filename*=...
```

Имена папок и файлов с кириллицей и пробелами в URL идут в процентной кодировке. Их нужно копировать из исходного кода страницы или из ссылки «скачать».

## Что нужно учитывать

- Если удалить файл в админке, его копия на cdn остаётся в кеше до истечения `inactive` (60 дней), а старая ссылка продолжит работать. Чтобы убрать сразу, очистите кеш: `rm -rf /var/cache/nginx/music/*` (cdn заново скачает файлы при следующих запросах). Можно и точечно, через `proxy_cache_purge` из модуля ngx_cache_purge.
- Если убрать `AUDIO_BASE_URL` из конфига, всё снова будет отдаваться с site.example.com, менять код не нужно.

## Сервер по умолчанию

Запросы по IP и с чужим Host не должны попадать в рабочие upstream. Для nginx < 1.19.4 (без `ssl_reject_handshake`) нужен самоподписанный сертификат:

```bash
openssl req -x509 -nodes -newkey rsa:2048 -days 3650 -subj '/CN=default' \
  -keyout /etc/nginx/default.key -out /etc/nginx/default.crt
```

```nginx
server {
    listen 80 default_server;
    listen 443 ssl default_server;
    server_name _;
    ssl_certificate     /etc/nginx/default.crt;
    ssl_certificate_key /etc/nginx/default.key;
    return 444;
}
```

## Не кешируйте PHP-страницы

Страницы каталога зависят от cookie: от темы (`theme`) и от входа администратора (`PHPSESSID`). Поэтому приложение отдаёт их с `Cache-Control: private, no-store` и `Vary: Cookie`. На CDN и в `proxy_cache` кешируйте только `/assets/`, `/uploads/records/` и `/uploads/music/`. Если HTML кешируется без учёта cookie, гости могут увидеть страницу, открытую под админом, вместе с ценами.
