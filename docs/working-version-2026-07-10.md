# Рабочая версия от 2026-07-10

Что исправлено:

- Добавлен `backend/shishki_api/bootstrap.php` с общими helper-функциями, CORS/OPTIONS и Bearer token parsing.
- `register.php`, `login.php`, `me.php`, `subscription-check.php` переведены на единую стабильную схему.
- Реализованы endpoint'ы:
  - `add-publication.php`
  - `add-deal.php`
  - `admin-login.php`
  - `admin-dashboard.php`
  - `admin-action.php`
- Добавлена миграция `sql/migrations/001-working-socials-admin.sql`.
- Логика билетов:
  - 5 подтверждённых соцсетей = 1 билет.
  - 30 дней публикаций = 1 билет.
  - 1 подтверждённая сделка = 3 билета.
- Призы:
  - 20 дней публикаций = топливная карта 3 000 ₽.
  - OZON 20 000 ₽ = розыгрыш.
  - OZON 30 000 ₽ = розыгрыш.
  - Участок КП «ШИШКИ» = розыгрыш.

## Порядок выкладки на хостинг

1. Загрузить все PHP-файлы из `backend/shishki_api/` на хостинг в:

```text
www/wow.shishki72.ru/shishki_api/
```

2. Реальный `config.php` на хостинге не удалять и не заменять на `config.example.php`.

3. Проверить:

```text
https://wow.shishki72.ru/shishki_api/ping.php
https://wow.shishki72.ru/shishki_api/db-test.php
```

4. При необходимости выполнить SQL:

```text
sql/migrations/001-working-socials-admin.sql
```

Большая часть схемы также автоматически проверяется backend'ом через `app_ensure_core_schema()`.

## Tilda

Для Tilda использовать файлы из папки `tilda/`.
