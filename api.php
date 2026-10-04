<?php

/*
 * Практична робота №7
 * Варіант 15 — Система бронювання квитків
 *
 * API:
 *
 * GET /api.php?resource=events
 *     Отримати список усіх подій.
 *
 * GET /api.php?resource=events&id=1
 *     Отримати одну подію.
 *
 * POST /api.php?resource=events
 *     Створити нову подію.
 *
 * POST /api.php?resource=events&action=buy&id=1
 *     Купити один квиток.
 */

header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? null;
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;


/*
 * Допоміжна функція для JSON-відповіді.
 */
function response(int $statusCode, array $data): void
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    exit;
}


/*
 * Отримання POST-даних.
 *
 * Підтримується як звичайний form-data,
 * так і JSON.
 */
function getInputData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($contentType, 'application/json')) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!is_array($data)) {
            response(400, [
                'success' => false,
                'error' => 'Некоректний JSON'
            ]);
        }

        return $data;
    }

    return $_POST;
}


/*
 * GET — список усіх подій.
 * Оптимізовано: N+1 усунуто через GROUP BY.
 */
function getEvents(PDO $pdo): void
{
    $start = microtime(true);
    $sqlQueries = 0;

    // 1-й запит — отримуємо всі події.
    $stmt = $pdo->query(
        'SELECT id, title, event_date, venue, seats_left
         FROM events
         ORDER BY event_date'
    );
    $sqlQueries++;

    $events = $stmt->fetchAll();

    // 2-й запит — одразу рахуємо кількість подій для кожного venue.
    $countStmt = $pdo->query(
        'SELECT venue, COUNT(*) AS event_count
         FROM events
         GROUP BY venue'
    );
    $sqlQueries++;

    $venueCounts = [];

    foreach ($countStmt->fetchAll() as $row) {
        $venueCounts[$row['venue']] = (int) $row['event_count'];
    }

    foreach ($events as &$event) {
        $event['venue_event_count'] = $venueCounts[$event['venue']] ?? 0;
    }

    unset($event);

    $elapsed = (microtime(true) - $start) * 1000;
    $peakMemory = memory_get_peak_usage(true) / 1024 / 1024;

    error_log(sprintf(
        'PROFILE AFTER N+1 FIX: time=%.3f ms, memory=%.2f MB, SQL queries=%d',
        $elapsed,
        $peakMemory,
        $sqlQueries
    ));

    response(200, [
        'success' => true,
        'sql_query_count' => $sqlQueries,
        'data' => $events
    ]);
}
/*
 * Шлях до файлового кешу soldOutList().
 */
function soldOutCacheFile(): string
{
    return sys_get_temp_dir() . '/php_practicum_07_sold_out.json';
}


/*
 * Очищення кешу.
 */
function clearSoldOutCache(): void
{
    $file = soldOutCacheFile();

    if (is_file($file)) {
        unlink($file);
    }
}


/*
 * Список подій без вільних місць.
 * Кеш з TTL = 60 секунд.
 */
function getSoldOutEvents(PDO $pdo): void
{
    $file = soldOutCacheFile();
    $ttl = 60;

    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $events = json_decode(file_get_contents($file), true);

        response(200, [
            'success' => true,
            'cache' => 'hit',
            'sql_query_count' => 0,
            'data' => $events
        ]);
    }

    $stmt = $pdo->query(
        'SELECT id, title, event_date, venue, seats_left
         FROM events
         WHERE seats_left = 0
         ORDER BY event_date'
    );

    $events = $stmt->fetchAll();

    file_put_contents(
        $file,
        json_encode($events, JSON_UNESCAPED_UNICODE)
    );

    response(200, [
        'success' => true,
        'cache' => 'miss',
        'sql_query_count' => 1,
        'data' => $events
    ]);
}


/*
 * GET — одна подія.
 */
function getEvent(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        response(400, [
            'success' => false,
            'error' => 'Некоректний id'
        ]);
    }

    $stmt = $pdo->prepare(
        'SELECT id, title, event_date, venue, seats_left
         FROM events
         WHERE id = ?'
    );

    $stmt->execute([$id]);

    $event = $stmt->fetch();

    if (!$event) {
        response(404, [
            'success' => false,
            'error' => 'Подію не знайдено'
        ]);
    }

    response(200, [
        'success' => true,
        'data' => $event
    ]);
}


/*
 * POST — створення нової події.
 */
function createEvent(PDO $pdo): void
{
    $data = getInputData();

    $title = trim($data['title'] ?? '');
    $eventDate = trim($data['event_date'] ?? '');
    $venue = trim($data['venue'] ?? '');
    $seatsLeft = $data['seats_left'] ?? null;

    if ($title === '') {
        response(400, [
            'success' => false,
            'error' => 'Поле title є обов’язковим'
        ]);
    }

    if ($eventDate === '') {
        response(400, [
            'success' => false,
            'error' => 'Поле event_date є обов’язковим'
        ]);
    }

    if ($venue === '') {
        response(400, [
            'success' => false,
            'error' => 'Поле venue є обов’язковим'
        ]);
    }

    if (
        $seatsLeft === null ||
        filter_var($seatsLeft, FILTER_VALIDATE_INT) === false ||
        (int) $seatsLeft < 0
    ) {
        response(400, [
            'success' => false,
            'error' => 'Поле seats_left повинно бути невід’ємним цілим числом'
        ]);
    }

    $date = DateTime::createFromFormat('Y-m-d H:i:s', $eventDate);

    if (!$date || $date->format('Y-m-d H:i:s') !== $eventDate) {
        response(400, [
            'success' => false,
            'error' => 'event_date має формат YYYY-MM-DD HH:MM:SS'
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO events (title, event_date, venue, seats_left)
         VALUES (?, ?, ?, ?)'
    );

    $stmt->execute([
        $title,
        $eventDate,
        $venue,
        (int) $seatsLeft
    ]);
    clearSoldOutCache();

    $id = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'SELECT id, title, event_date, venue, seats_left
         FROM events
         WHERE id = ?'
    );

    $stmt->execute([$id]);

    $event = $stmt->fetch();

    response(201, [
        'success' => true,
        'data' => $event
    ]);
}


/*
 * POST action=buy — купівля одного квитка.
 */
function buyTicket(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        response(400, [
            'success' => false,
            'error' => 'Некоректний id'
        ]);
    }

    /*
     * Одразу намагаємося зменшити seats_left.
     * Умова seats_left > 0 не дозволяє отримати
     * від'ємну кількість місць.
     */
    $stmt = $pdo->prepare(
        'UPDATE events
         SET seats_left = seats_left - 1
         WHERE id = ?
           AND seats_left > 0'
    );

    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {

        /*
         * Перевіряємо, чому UPDATE нічого не змінив:
         * події немає чи просто закінчилися квитки.
         */
        $stmt = $pdo->prepare(
            'SELECT id, seats_left
             FROM events
             WHERE id = ?'
        );

        $stmt->execute([$id]);

        $event = $stmt->fetch();

        if (!$event) {
            response(404, [
                'success' => false,
                'error' => 'Подію не знайдено'
            ]);
        }

        response(400, [
            'success' => false,
            'error' => 'Квитків на цю подію більше немає'
        ]);
    }

    // Дані змінилися — старий кеш soldOutList більше не актуальний.
    clearSoldOutCache();

    $stmt = $pdo->prepare(
        'SELECT id, title, event_date, venue, seats_left
         FROM events
         WHERE id = ?'
    );

    $stmt->execute([$id]);

    $event = $stmt->fetch();

    response(200, [
        'success' => true,
        'message' => 'Квиток успішно придбано',
        'data' => $event
    ]);
}


/*
 * Маршрутизація API.
 */

if ($resource !== 'events') {
    response(404, [
        'success' => false,
        'error' => 'Невідомий ресурс'
    ]);
}


if ($method === 'GET') {

    if ($action === 'soldout') {
        getSoldOutEvents($pdo);
    }

    if ($action !== null) {
        response(400, [
            'success' => false,
            'error' => 'Невідома дія'
        ]);
    }

    if ($id === null) {
        getEvents($pdo);
    }

    getEvent($pdo, $id);
}

if ($method === 'POST') {

    if ($action === null) {
        createEvent($pdo);
    }

    if ($action === 'buy') {

        if ($id === null) {
            response(400, [
                'success' => false,
                'error' => 'Для купівлі квитка необхідно передати id події'
            ]);
        }

        buyTicket($pdo, $id);
    }

    response(400, [
        'success' => false,
        'error' => 'Невідома дія'
    ]);
}


/*
 * PUT, PATCH, DELETE та інші методи
 * для цього API не підтримуються.
 */
header('Allow: GET, POST');

response(405, [
    'success' => false,
    'error' => 'HTTP-метод не підтримується'
]);