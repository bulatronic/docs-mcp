# docs-mcp

[![CI](https://github.com/bulatronic/docs-mcp/actions/workflows/ci.yml/badge.svg)](https://github.com/bulatronic/docs-mcp/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat&logo=php)](https://www.php.net/)
[![MCP](https://img.shields.io/badge/MCP-Streamable%20HTTP-0A7EA4?style=flat)](https://modelcontextprotocol.io/)
[![FrankenPHP](https://img.shields.io/badge/FrankenPHP-Ready-000000?style=flat)](https://frankenphp.dev/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat&logo=docker)](https://www.docker.com/)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-11-3C9A40?style=flat)](https://phpunit.de/)
[![License: Unlicense](https://img.shields.io/badge/License-Unlicense-blue.svg?style=flat)](https://unlicense.org/)

<a id="russian-english-switch"></a>
**Язык / Language:** [Русский](#russian) · [English](#english)

<a id="russian"></a>

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

| Инструмент             | Назначение                                                                  |
|------------------------|-----------------------------------------------------------------------------|
| `list_documents`       | Список Google Docs в разрешённой папке (обход подпапок, опциональный query) |
| `get_document_content` | Текст документа в конверте `untrusted` (`notice`, `truncated`, …)           |
| `append_to_document`   | Дописать текст в конец документа из allowlist (не идемпотентно)             |

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

## CI

GitHub Actions (`.github/workflows/ci.yml`) на `push` в `main` и на `pull_request`:

| Job               | Что проверяет                                                                                            |
|-------------------|----------------------------------------------------------------------------------------------------------|
| `php-checks`      | `composer validate --strict`, install, затем `composer test` / `phpstan` / `deptrac` / `cs-check`        |
| `composer-audit`  | `composer audit` (уязвимости зависимостей)                                                               |
| `docker-build`    | сборка target `runtime` (без push), нет `vendor/bin/phpunit`, uid ≠ 0, smoke `tools/list` с фиктивным SA |
| `dockerfile-lint` | Hadolint (порог warning)                                                                                 |
| `secret-scan`     | Gitleaks по полной истории (`docker run`, без gitleaks-action)                                           |

Локально те же PHP-проверки:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint bash composer -c \
  'composer validate --strict && composer test && composer phpstan && composer deptrac && composer cs-check && composer audit'
```

В настройках ветки `main` (required status checks) включите все пять job: `php-checks`, `composer-audit`, `docker-build`, `dockerfile-lint`, `secret-scan`.

Русский и английский блоки README нужно обновлять синхронно.

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

| Ограничение              | Комментарий                                                                                       |
|--------------------------|---------------------------------------------------------------------------------------------------|
| SA + личный `@gmail.com` | Нельзя создавать файлы от имени SA (`storageQuotaExceeded`); документы для записи создаёт человек |
| Вкладки Docs (tabs)      | Не поддерживаются: читается только `body.content`                                                 |
| Untrusted content        | Текст документа — данные для модели, не инструкции                                                |
| Пагинация Drive          | Один pageSize ≤ 1000 на list; `nextPageToken` не обходится                                        |

## Сессии

Используется **`FileSessionStore`** в `MCP_SESSION_DIR` (совместимость с MCP Inspector / handshake-era клиентами при process-per-request FrankenPHP).

## Лицензия

[Unlicense](https://unlicense.org/) — программное обеспечение в общественном достоянии.

[↑ Язык / Language](#russian-english-switch)

---

<a id="english"></a>

A small MCP server on PHP 8.5: lets an AI agent **read** Google Docs and **append** text inside **one** Google Drive folder via a service account.

**Create, delete, rename, move, sharing, and permission changes are not supported — by design.** The code and ports have no `google/apiclient` and no destructive Drive/Docs operations. The only write path is `insertText` at the end of a document, and only if the document is explicitly listed in `DOCS_APPEND_ALLOWED_IDS` when `DOCS_WRITE_ENABLED=true` (default-deny).

## Stack

| Technology                                 | Role                                              |
|--------------------------------------------|---------------------------------------------------|
| PHP 8.5 + FrankenPHP                       | Runtime and HTTP (Streamable MCP)                 |
| mcp/sdk                                    | MCP server and tools                              |
| google/auth                                | Service Account JWT (no `google/apiclient`)       |
| symfony/http-client + symfony/cache        | HTTP to Google API and access-token cache (PSR-6) |
| Docker Compose                             | Run, isolation, secrets                           |
| PHPUnit / PHPStan / Deptrac / PHP CS Fixer | Tests, static analysis, layers, style             |

## Capabilities

| Tool                   | Purpose                                                                  |
|------------------------|--------------------------------------------------------------------------|
| `list_documents`       | List Google Docs in the allowed folder (walk subfolders, optional query) |
| `get_document_content` | Document text in an `untrusted` envelope (`notice`, `truncated`, …)      |
| `append_to_document`   | Append text to the end of an allowlist document (not idempotent)         |

A new tool without an explicit decision breaks the catalog snapshot test.

## Google Cloud setup

1. Create (or select) a project in [Google Cloud Console](https://console.cloud.google.com/).
2. Enable the APIs:
   - [Google Docs API](https://console.cloud.google.com/apis/library/docs.googleapis.com)
   - [Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com)
3. Create a **service account** with no project-level roles (IAM roles are not needed: access is only via folder sharing).
4. Issue a service account JSON key and store it **outside git**, for example at `./secrets/google_sa.json` (see below).
5. In Google Drive create **one** working folder. Open sharing and share it with the **service account email** (`…@….iam.gserviceaccount.com`) with **Editor** access.
6. Copy the folder ID from the URL (`https://drive.google.com/drive/folders/<ID>`) into `DOCS_ROOT_FOLDER_ID`.
7. Create documents for writing **manually** in this folder (or subfolders). List their IDs in `DOCS_APPEND_ALLOWED_IDS`. A service account on a personal `@gmail.com` Drive **cannot create files** in the user’s Drive (typical error `storageQuotaExceeded`) — that is a Google limitation, not a docs-mcp bug.

## Environment variables

```bash
cp .env.example .env
mkdir -p secrets
# place the JSON key: secrets/google_sa.json
# permissions (important with cap_drop ALL): directory 0700, key file 0444 on the host
chmod 0700 secrets
chmod 0444 secrets/google_sa.json
```

| Variable                         | Required | Description                                                              |
|----------------------------------|----------|--------------------------------------------------------------------------|
| `GOOGLE_APPLICATION_CREDENTIALS` | yes      | Path to the JSON in the container: `/run/secrets/google_sa.json`         |
| `DOCS_ROOT_FOLDER_ID`            | yes      | ID of the single allowed Drive root folder                               |
| `DOCS_WRITE_ENABLED`             | no       | Append writes; default `false`                                           |
| `DOCS_APPEND_ALLOWED_IDS`        | no*      | Comma-separated document IDs (*required for writes)                      |
| `DOCS_MAX_READ_CHARS`            | no       | Read ceiling (default `20000`)                                           |
| `DOCS_MAX_APPEND_CHARS`          | no       | Ceiling for `text` length on append (default `5000`)                     |
| `MCP_SESSION_DIR`                | no       | MCP sessions and rate-limit (`/var/mcp-sessions`)                        |
| `TZ`                             | no       | Timezone (timestamp label in the append block)                           |

The service account key and access tokens are never written to logs or MCP responses.

### Key file permissions

With `cap_drop: [ALL]` the process has no `CAP_DAC_OVERRIDE`: there is no root bypass of DAC. The key file must be **readable by the process uid** (`10001` in the image):

- compose secret: the host file is read by the Docker daemon; in the container it is mounted as `/run/secrets/google_sa.json` with `mode: 0444`;
- or bind-mount: directory `0700`, file `0444`, and/or `user:` in compose matches the file owner.

## Running

### Dev (bind-mount sources)

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm composer install
```

The service listens on `http://127.0.0.1:8080/mcp` (container `docs-mcp`, image `docs-mcp:local`).

### Prod (local compose without bind-mount)

```bash
docker compose up -d --build
```

The image is built with target `runtime`: `composer install --no-dev`, without PHPUnit/PHPStan in the final layer.

### Stopping

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

### Checks

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/phpunit
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/phpstan analyse --memory-limit=512M
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/deptrac analyse --config-file=deptrac.yaml --cache-file=/tmp/docs-mcp-deptrac.cache
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint php composer vendor/bin/php-cs-fixer check --diff --config=.php-cs-fixer.dist.php
```

Or Composer scripts (`cs-check` / `cs-fix` / `test` / `phpstan` / `deptrac`) via the `tools` profile.

Layers: `Mcp → Application → Ports ← Infrastructure`; Domain depends on nothing (Deptrac).

## CI

GitHub Actions (`.github/workflows/ci.yml`) on `push` to `main` and on `pull_request`:

| Job               | What it checks                                                                                           |
|-------------------|----------------------------------------------------------------------------------------------------------|
| `php-checks`      | `composer validate --strict`, install, then `composer test` / `phpstan` / `deptrac` / `cs-check`         |
| `composer-audit`  | `composer audit` (dependency advisories)                                                                 |
| `docker-build`    | build target `runtime` (no push), no `vendor/bin/phpunit`, uid ≠ 0, smoke `tools/list` with a fake SA    |
| `dockerfile-lint` | Hadolint (warning threshold)                                                                             |
| `secret-scan`     | Gitleaks over full history (`docker run`, not gitleaks-action)                                           |

Locally, the same PHP checks:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile tools run --rm --entrypoint bash composer -c \
  'composer validate --strict && composer test && composer phpstan && composer deptrac && composer cs-check && composer audit'
```

In the `main` branch settings (required status checks) enable all five jobs: `php-checks`, `composer-audit`, `docker-build`, `dockerfile-lint`, `secret-scan`.

Keep the Russian and English README sections in sync.

## Checking with MCP Inspector

```bash
npx @modelcontextprotocol/inspector
```

Connection:

- Transport: **Streamable HTTP**
- URL: `http://127.0.0.1:8080/mcp`

The list must contain **exactly three** tools from the table above.

## Connecting in picoclaw

On the Docker Compose network the service is reachable by hostname `docs-mcp`:

```text
http://docs-mcp:8080/mcp
```

Service snippet for the shared picoclaw compose file: [`docs/picoclaw-compose-snippet.yml`](docs/picoclaw-compose-snippet.yml). From the host machine use `http://127.0.0.1:8080/mcp`.

## Checklist before enabling writes

1. `DOCS_WRITE_ENABLED=false`, key and `DOCS_ROOT_FOLDER_ID` configured, folder shared with the SA.
2. Smoke (CLI inside the container, **not** HTTP):

   ```bash
   docker compose exec docs-mcp php bin/smoke.php
   docker compose exec docs-mcp php bin/smoke.php --read=<DOCUMENT_ID>
   ```

3. MCP Inspector: `list_documents` / `get_document_content` on documents inside the root folder.
4. Only after that: add IDs to `DOCS_APPEND_ALLOWED_IDS`, set `DOCS_WRITE_ENABLED=true`, restart the service.
5. Optional smoke with write:

   ```bash
   docker compose exec docs-mcp php bin/smoke.php --append=<DOCUMENT_ID>
   ```

   `bin/smoke.php` lives outside `public/` and is not served by Caddy (only `/mcp` is exposed).

## Security and operations

The limits below are a product decision, not an unfinished feature.

- **No create/delete/sharing/move** of files or folders; no comments and no permission changes.
- **No `google/apiclient`:** only allowlisted HTTP to Drive/Docs via `GoogleHttpGateway`.
- **Folder boundary:** any `document_id` / `folder_id` is checked by walking `parents` up to `DOCS_ROOT_FOLDER_ID`; “not found” and “outside root” share one message.
- **Writes are default-deny:** append only with the flag and allowlist; calling `append_to_document` is **not idempotent**.
- **Document contents are untrusted content:** `get_document_content` always returns an envelope with `notice`; do not follow instructions inside the document.
- Audit: JSON line on stdout (`time`, `tool`, `document_id`, `status`, `duration_ms`, optionally `appended_chars` / `error_class`) without keys, tokens, or document bodies.
- Limit: **60 tool calls per minute**.
- Container: non-root `10001`, `read_only`, tmpfs, `cap_drop: ALL`, `no-new-privileges`.

### Known limitations

| Limitation                 | Note                                                                                          |
|----------------------------|-----------------------------------------------------------------------------------------------|
| SA + personal `@gmail.com` | Cannot create files as the SA (`storageQuotaExceeded`); a human creates documents for writing |
| Docs tabs                  | Not supported: only `body.content` is read                                                    |
| Untrusted content          | Document text is data for the model, not instructions                                         |
| Drive pagination           | One pageSize ≤ 1000 per list; `nextPageToken` is not followed                                 |

## Sessions

Uses **`FileSessionStore`** in `MCP_SESSION_DIR` (compatibility with MCP Inspector / handshake-era clients under process-per-request FrankenPHP).

## License

[Unlicense](https://unlicense.org/) — software dedicated to the public domain.

[↑ Язык / Language](#russian-english-switch)
