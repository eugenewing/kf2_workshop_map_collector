# PROJECT_CONTEXT

## 1. Назначение проекта

`KF2_WSMC` — серверное PHP-приложение (без БД), которое:

- сканирует Steam Workshop для Killing Floor 2 (`appid=232090`),
- отбирает вероятные игровые карты,
- делит результаты на:
  - **confident maps** (уверенно определённые карты),
  - **review list** (подозрительные/пограничные элементы),
- сохраняет результаты и состояние сканирования в JSON,
- отдаёт данные на фронтенд через HTTP API.

Целевой рантайм: XAMPP (Apache + PHP) на отдельном ПК.

---

## 2. Технологический стек

- PHP (серверная логика + API)
- JSON-файлы как хранилище состояния и результатов
- HTML/CSS/JavaScript (встроенный веб-интерфейс)
- Без Composer, без build-step, без СУБД

---

## 3. Структура проекта

- `index.php` — основная страница UI
- `api/`
  - `refresh.php` — запуск полного/ограниченного сканирования
  - `maps.php` — выдача списков `maps`/`review` + поиск
  - `state.php` — выдача текущего/последнего состояния
  - `reset.php` — сброс JSON-данных к начальному состоянию
- `src/`
  - `bootstrap.php` — подключение классов и helper ответа JSON
  - `Config.php` — константы и path helpers
  - `HttpClient.php` — HTTP-клиент (cURL, fallback на stream)
  - `SteamWorkshopCollector.php` — сканирование, детализация, эвристики классификации
  - `JsonStorage.php` — чтение/запись `data/*.json`
- `assets/`
  - `app.js` — фронтенд-логика (refresh/reset/polling/search/progress)
  - `app.css` — стили
- `data/`
  - `maps.json`, `review.json`, `state.json` — генерируемые файлы (игнорируются в Git)

---

## 4. Функциональные сценарии

### 4.1 Обновление данных Workshop

1. UI отправляет `POST` на `api/refresh.php` с `delay_ms` и опционально `max_browse_pages`.
2. Сервер выставляет начальный `state.status=running`.
3. Коллектор:
   - проходит browse-страницы Steam Workshop,
   - собирает `publishedfileid` и названия,
   - запрашивает детали пачками через Steam API `GetPublishedFileDetails`.
4. Каждый детальный item проходит эвристическую классификацию:
   - в `maps` (уверенная карта),
   - в `review` (сомнительный кандидат),
   - либо отбрасывается.
5. Результаты сортируются, сохраняются в JSON, статус переводится в `done`.

### 4.2 Просмотр данных

- UI запрашивает `api/maps.php?type=maps|review&q=...`.
- Фильтрация по `name` и `id` делается на сервере.

### 4.3 Мониторинг статуса

- UI опрашивает `api/state.php` (polling ~1.5s), пока `status=running`.
- На основе состояния отрисовываются:
  - статусная строка,
  - счётчики,
  - прогресс-бар по фазам (`init`, `browse`, `details`, `classify`, `done`/`error`).

### 4.4 Сброс данных

- `POST api/reset.php` очищает `maps/review` и возвращает state к `idle`.

---

## 5. Внешние зависимости и интеграции

### Steam endpoints

- Browse HTML: `https://steamcommunity.com/workshop/browse/`
- Details API: `https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/`

### Важные константы

- `KF2_WSMC_APP_ID = 232090`
- `KF2_WSMC_PAGE_SIZE = 30`
- `KF2_WSMC_API_BATCH_SIZE = 100`
- `KF2_WSMC_DEFAULT_DELAY_MS = 40`

---

## 6. Модель данных (JSON)

### `maps.json`

Массив:

```json
[
  {"id": "1234567890", "name": "KF-ExampleMap"}
]
```

### `review.json`

Массив аналогичной структуры.

### `state.json`

Ключевые поля:

- `phase`: `init|browse|details|classify|done|error|null`
- `status`: `idle|running|ok|error`
- `workshop_total_items`
- `detailed_items_analyzed`
- `maps_count`
- `review_count`
- `browse_pages_processed`
- `browse_pages_limit`
- `requested_max_browse_pages`
- `last_run_at` (ISO-8601 UTC)
- `error` (строка или `null`)

---

## 7. Эвристика классификации карт

Классификация в `SteamWorkshopCollector::analyzeCandidate()` основана на взвешенных сигналах:

- **Позитивные**: `kf-` префикс, `mapname=KF-...`, `.kfm`, map-related слова/теги, gameplay термины.
- **Негативные**: audio/cosmetic/mod-only/gamemode сигналы, mutator-команды, utility-only контекст.
- Итог: score + набор сигналов → `is_likely_map` или `is_suspicious`.

Это rule-based подход без ML, поэтому возможны false positives/false negatives.

---

## 8. Архитектурные решения

1. **Файловое хранилище JSON вместо БД**
   - проще деплой в XAMPP,
   - удобно для single-node/локального запуска,
   - минимальный порог поддержки.

2. **Синхронный запуск refresh в HTTP-запросе**
   - реализация проста,
   - `set_time_limit(0)` позволяет длинный скан,
   - прогресс сохраняется в `state.json`, фронт читает polling-ом.

3. **Разделение на API + статичный UI**
   - тонкий фронтенд,
   - серверный источник истины для состояния и данных.

---

## 9. Ограничения и риски

1. **Зависимость от HTML-разметки Steam browse**
   - парсинг regex-ом чувствителен к изменениям в верстке.

2. **Производительность и время выполнения**
   - полный скан может идти минуты,
   - при большом объёме данных — нагрузка на сеть/CPU.

3. **Конкурентные refresh-запуски**
   - нет явного lock-механизма на запуск,
   - потенциально возможна гонка записи JSON при параллельных запросах.

4. **Надёжность хранения**
   - JSON-файлы — единая точка отказа, без транзакций БД.

5. **Качество эвристики**
   - всегда потребуется ручная проверка части результатов (`review`).

---

## 10. Требования к окружению (XAMPP target)

- Apache + PHP в XAMPP
- Доступ к интернету к доменам Steam
- Права записи в директорию `data/`
- Желательно включённый `curl` в PHP (иначе fallback на stream)

Деплой:

1. Копировать папку проекта в `htdocs`.
2. Запустить Apache.
3. Открыть `/kf2_workshop_map_collector/` (или соответствующий алиас/имя папки).

---

## 11. Контракты API (текущее поведение)

### `GET api/state.php`

Ответ:

```json
{"state": {"status": "idle"}}
```

### `GET api/maps.php?type=maps|review&q=...`

Ответ:

```json
{"items": [{"id": "...", "name": "..."}], "count": 1, "type": "maps"}
```

### `POST api/refresh.php`

Параметры:

- `delay_ms` (int >= 0)
- `max_browse_pages` (int >= 1, optional; можно также через header `X-KF2-WSMC-Max-Pages`)

Ответ при успехе:

```json
{"ok": true, "state": {...}}
```

Ответ при ошибке:

```json
{"ok": false, "error": "...", "state": {...}}
```

### `POST api/reset.php`

Ответ:

```json
{"ok": true, "state": {...}}
```

---

## 12. Направления развития (backlog)

1. Добавить блокировку от параллельных `refresh`.
2. Вести журнал запусков/ошибок отдельно от `state.json`.
3. Вынести эвристики в конфиг/правила для более удобной калибровки.
4. Добавить экспорт (CSV/JSON download) из UI.
5. Добавить простой smoke-check endpoint (health).
6. Добавить тесты для парсинга и классификации (на фиксированных fixtures).

---

## 13. Статус контекста

Документ отражает текущее состояние кода на момент создания. Используется как базовая точка для дальнейшей разработки проекта.

