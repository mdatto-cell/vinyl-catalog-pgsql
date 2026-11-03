# vinyl-catalog-pgsql

Самостоятельный каталог винила на PHP и PostgreSQL 15/16/17/18.
Установка с нуля: без MySQL, миграторов и скачивания исходного проекта при запуске.
Основа: mdatto-cell/vinyl-catalog, commit fea3d04de41587e0b646752ea4b5299dae6070f5.
UI, CSS/JS, формы, маршруты, Discogs, фотографии и аудио сохранены.

## Требования
PHP 8.0+ (64 bit): pdo_pgsql, mbstring, curl, fileinfo.
PostgreSQL 15-18 с UTF-8 и locale, корректно обрабатывающей кириллицу.
Apache 2.4 или Nginx + PHP-FPM. Корень сайта — public/.
Папки app/, sql/, tests/, tools/ и docs/ не должны отдаваться веб-сервером.

## Установка
```bash
git clone https://github.com/mdatto-cell/vinyl-catalog-pgsql.git /var/www/vinyl-catalog-pgsql
cd /var/www/vinyl-catalog-pgsql
cp app/config.example.php app/config.php
# Заполните DB_*, APP_URL и токен Discogs в app/config.php.
# Замените CHANGE_ME в sql/01_create_database.sql на пароль роли.
sudo -u postgres psql -v ON_ERROR_STOP=1 -f sql/01_create_database.sql
psql -h 127.0.0.1 -U vinyl_user -d vinyl_catalog -v ON_ERROR_STOP=1 -f sql/02_schema.sql
php -r 'echo password_hash("ваш_пароль", PASSWORD_DEFAULT), PHP_EOL;'
php tools/create_admin.php admin
# Вставьте полученный хеш в stdin.
chown -R www-data:www-data public/uploads
chmod 640 app/config.php
chown root:www-data app/config.php
```
Схему импортируйте от vinyl_user: эта роль должна владеть таблицами.
01_create_database.sql запускается один раз, вне транзакции.
Администратор создаётся отдельно, фиксированного пароля в проекте нет.
Не публикуйте config.php и не храните рабочие пароли в git.

DB_PORT по умолчанию 5432; DB_SSLMODE — prefer. Для удалённой БД настройте
verify-full и доверенный CA libpq. DB_TIMEZONE необязателен; без него используется
часовой пояс PostgreSQL. Даты хранятся без часового пояса.

## Nginx
```nginx
server {
    server_name catalog.example.com;
    root /var/www/vinyl-catalog-pgsql/public;
    index index.php;
    client_max_body_size 2g;
    location ^~ /uploads/ {
        location ~ \.php$ { return 403; }
        try_files $uri =404;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 900s;
    }
}
```
Настройте HTTPS и сокет вашей версии PHP. Для Apache используйте public/ как
DocumentRoot и AllowOverride All: исходные .htaccess сохранены.
Для больших аудиофайлов: upload_max_filesize=2G, post_max_size=2G,
max_execution_time=900. Подробности аудио/CDN — docs/.

## Проверки (только отдельная тестовая БД)
```bash
php tests/tracks_test.php
php tests/tracks_test.php --db
php tests/audio_test.php
php tests/pgsql_test.php --test-db
```
Тесты строк откатываются, но identity sequences расходуются.
GitHub Actions проверяет PostgreSQL 15/16/17/18 и PHP lint; фактический результат
смотрите в Actions, наличие workflow само по себе не означает успешный запуск.

## Особенности PostgreSQL
Используются native PDO prepared statements, INSERT RETURNING id, ILIKE,
массив для фильтра жанров, CHECK вместо ENUM/unsigned и триггер updated_at.
Логины сравниваются без учёта регистра через lower(), уникальный индекс это учитывает.
MySQL utf8mb4_unicode_ci и PostgreSQL locale не полностью эквивалентны:
диакритика, пробелы и порядок смешанной кириллицы/латиницы могут сравниваться иначе.
Файлы PORT-MANIFEST.json и Actions показывают происхождение и проверки.
