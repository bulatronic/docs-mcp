# docs-mcp

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat&logo=php)](https://www.php.net/)
[![MCP](https://img.shields.io/badge/MCP-Streamable%20HTTP-0A7EA4?style=flat)](https://modelcontextprotocol.io/)
[![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Ready-000000?style=flat)](https://frankenphp.dev/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat&logo=docker)](https://www.docker.com/)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-11-3C9A40?style=flat)](https://phpunit.de/)
[![License: Unlicense](https://img.shields.io/badge/License-Unlicense-blue.svg?style=flat)](https://unlicense.org/)

Небольшой MCP-сервер на PHP 8.5: даёт ИИ-агенту **читать** Google Docs и **дописывать** текст внутри **одной** папки Google Drive через сервисный аккаунт.

**Создание, удаление, переименование, перемещение, шеринг и смена прав не поддерживаются — умышленно.** В коде и портах нет `google/apiclient` и разрушительных Drive/Docs-операций. Единственная запись — `insertText` в конец документа, и только если документ явно перечислен в `DOCS_APPEND_ALLOWED_IDS` при `DOCS_WRITE_ENABLED=true` (default-deny).

## Стек

| Технология                                 | Роль                                              |
|--------------------------------------------|---------------------------------------------------|
| PHP 8.5 + FrankenPHP                       | Runtime и HTTP (Streamable MCP)                   |
| mcp/sdk                                    | MCP-сервер и инструменты                          |
| google/auth                                | Service Account JWT (без `google/apiclient`)      |
| symfony/http-client + symfony/cache        | HTTP к Google API и кэш access-токена (PSR-6)     |
| Docker Compose                             | Запуск, изоляция, secrets                         |
| PHPUnit / PHPStan / Deptrac / PHP CS Fixer | Тесты, статический анализ, слои, стиль            |

## Что умеет

| Инструмент             | Назначение                                                                 |
|------------------------|----------------------------------------------------------------------------|
| `list_documents`       | Список Google Docs в разрешённой папке (обход подпапок, опциональный query) |
| `get_document_content` | Текст документа в конверте `untrusted` (`notice`, `truncated`, …)          |
| `append_to_document`   | Дописать текст в конец документа из allowlist (не идемпотентно)            |

Новый инструмент без явного решения ломает snapshot-тест каталога.

## Настройка Google Cloud

1. Создайте (или выберите) проект в [Google Cloud Console](https://console.cloud.google.com/).
2. Включите API:
   - [Google Docs API](https://console.cloud.google.com/apis/library/docs.googleapis.com)
   - [Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com)
3. Создайте **сервисный аккаунт** без ролей на уровне проекта (IAM roles не нужны: доступ только через шаринг папки).
4. Выпустите JSON-ключ сервисного аккаунта и сохраните его **вне git**, например в `./secrets/google_sa.json` (см. ниже).
5. В Google Drive создайте **одну** рабочую папку. Откройте «Доступ» и расшарьте её на **email сервисного аккаунта** (`…@….iam.gserviceaccount.com`) с правом **«Редактор»**.
6. Скопируйте ID папки из URL (`https://drive.google.com/drive/folders/<ID>`) в `DOCS_ROOT_FOLDER_ID`.
7. Документы для записи создайте **вручную** в этой папке (или подпапках). Их ID перечислите в `DOCS_APPEND_ALLOWED_IDS`. Сервисный аккаунт на личном `@gmail.com` **не может создавать файлы** в Drive пользователя (типичная ошибка `storageQuotaExceeded`) — это ограничение Google, не бага docs-mcp.

## Переменные окружения

```bash
cp .env.example .env
mkdir -p secrets
# положите JSON-ключ: secrets/google_sa.json
# права (важно при cap_drop ALL): каталог 0700, файл ключа 0444 на хосте
chmod 0700 secrets
chmod 0444 secrets/google_sa.json
```

| Переменная                       | Обязательно | Описание                                                                 |
|----------------------------------|-------------|--------------------------------------------------------------------------|
| `GOOGLE_APPLICATION_CREDENTIALS` | да          | Путь к JSON в контейнере: `/run/secrets/google_sa.json`                  |
| `DOCS_ROOT_FOLDER_ID`            | да          | ID единственной разрешённой папки Drive                                  |
| `DOCS_WRITE_ENABLED`             | нет         | Запись append; по умолчанию `false`                                      |
| `DOCS_APPEND_ALLOWED_IDS`        | нет*        | Список ID документов через запятую (*обязателен для записи)              |
| `DOCS_MAX_READ_CHARS`            | нет         | Потолок чтения (по умолчанию `20000`)                                    |
| `DOCS_MAX_APPEND_CHARS`          | нет         | Потолок длины `text` при append (по умолчанию `5000`)                    |
| `MCP_SESSION_DIR`                | нет         | Сессии MCP и rate-limit (`/var/mcp-sessions`)                            |
| `TZ`                             | нет         | Часовой пояс (метка времени в блоке append)                              |

Ключ сервисного аккаунта и access-токены в логи и ответы MCP не пишутся.

### Права на файл ключа

При `cap_drop: [ALL]` у процесса нет `CAP_DAC_OVERRIDE`: root-прав на обход DAC нет. Файл ключа должен быть **читаем uid процесса** (`10001` в образе):

- compose secret: файл на хосте читается демоном Docker; в контейнере монтируется как `/run/secrets/google_sa.json` с `mode: 0444`;
- либо bind-mount: каталог `0700`, файл `0444`, и/или `user:` в compose совпадает с владельцем файла.

## Запуск

### Dev (bind-mount исходников)

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm composer install
```

Сервис слушает `http://127.0.0.1:8080/mcp` (контейнер `docs-mcp`, образ `docs-mcp:local`).

### Prod (локальный compose без bind-mount)

```bash
docker compose up -d --build
```

Образ собирается target `runtime`: `composer install --no-dev`, без PHPUnit/PHPStan в финальном слое.

### Остановка

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

### Проверки

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/phpunit
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/phpstan analyse --memory-limit=512M
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/deptrac analyse --config-file=deptrac.yaml --cache-file=/tmp/docs-mcp-deptrac.cache
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/php-cs-fixer check --diff --config=.php-cs-fixer.dist.php
```

Или Composer-скрипты (`cs-check` / `cs-fix` / `test` / `phpstan` / `deptrac`) через profile `tools`.

Слои: `Mcp → Application → Ports ← Infrastructure`, Domain ни от кого не зависит (Deptrac).

## Проверка через MCP Inspector

```bash
npx @modelcontextprotocol/inspector
```

Подключение:

- Transport: **Streamable HTTP**
- URL: `http://127.0.0.1:8080/mcp`

В списке должны быть **ровно три** инструмента из таблицы выше.

## Подключение в picoclaw

В сети Docker Compose сервис доступен по имени хоста `docs-mcp`:

```text
http://docs-mcp:8080/mcp
```

Фрагмент сервиса для общего compose picoclaw: [`docs/picoclaw-compose-snippet.yml`](docs/picoclaw-compose-snippet.yml). С хоста машины используйте `http://127.0.0.1:8080/mcp`.

## Чеклист перед включением записи

1. `DOCS_WRITE_ENABLED=false`, ключ и `DOCS_ROOT_FOLDER_ID` настроены, папка расшарена на SA.
2. Smoke (CLI внутри контейнера, **не** HTTP):

   ```bash
   docker compose exec docs-mcp php bin/smoke.php
   docker compose exec docs-mcp php bin/smoke.php --read=<DOCUMENT_ID>
   ```

3. MCP Inspector: `list_documents` / `get_document_content` на документах внутри root.
4. Только после этого: внесите ID в `DOCS_APPEND_ALLOWED_IDS`, выставьте `DOCS_WRITE_ENABLED=true`, перезапустите сервис.
5. Повторный smoke с записью (опционально):

   ```bash
   docker compose exec docs-mcp php bin/smoke.php --append=<DOCUMENT_ID>
   ```

   `bin/smoke.php` лежит вне `public/` и не отдаётся Caddy (доступен только `/mcp`).

## Безопасность и эксплуатация

Ограничения ниже — продуктовое решение, а не недоделка.

- **Нет создания/удаления/шеринга/перемещения** файлов и папок; нет комментариев и смены прав.
- **Нет `google/apiclient`:** только allowlisted HTTP к Drive/Docs через `GoogleHttpGateway`.
- **Граница папки:** любой `document_id` / `folder_id` проверяется обходом `parents` до `DOCS_ROOT_FOLDER_ID`; «не найден» и «вне root» — один текст.
- **Запись default-deny:** append только при флаге и allowlist; вызов `append_to_document` **не идемпотентен**.
- **Содержимое документов — недоверенные данные:** в ответе `get_document_content` всегда конверт с `notice`; инструкции внутри документа не выполнять.
- Аудит: JSON line в stdout (`time`, `tool`, `document_id`, `status`, `duration_ms`, опционально `appended_chars` / `error_class`) без ключей, токенов и тел документов.
- Лимит: **60 вызовов инструментов в минуту**.
- Контейнер: non-root `10001`, `read_only`, tmpfs, `cap_drop: ALL`, `no-new-privileges`.

### Известные ограничения

| Ограничение | Комментарий |
|-------------|-------------|
| SA + личный `@gmail.com` | Нельзя создавать файлы от имени SA (`storageQuotaExceeded`); документы для записи создаёт человек |
| Вкладки Docs (tabs) | Не поддерживаются: читается только `body.content` |
| Untrusted content | Текст документа — данные для модели, не инструкции |
| Пагинация Drive | Один pageSize ≤ 1000 на list; `nextPageToken` не обходится |

## Сессии

Используется **`FileSessionStore`** в `MCP_SESSION_DIR` (совместимость с MCP Inspector / handshake-era клиентами при process-per-request FrankenPHP).

## Лицензия

[Unlicense](https://unlicense.org/) — программное обеспечение в общественном достоянии.
