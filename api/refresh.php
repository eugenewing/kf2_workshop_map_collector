<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kf2_wsmc_send_json(['error' => 'Method not allowed.'], 405);
}

/**
 * Reads a scalar request value from POST, GET, then custom header.
 */
function kf2_wsmc_request_value(string $key): ?string
{
    if (array_key_exists($key, $_POST) && is_scalar($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }

    if (array_key_exists($key, $_GET) && is_scalar($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }

    if ($key === 'max_browse_pages') {
        $headerValue = $_SERVER['HTTP_X_KF2_WSMC_MAX_PAGES'] ?? null;
        if (is_scalar($headerValue)) {
            return trim((string) $headerValue);
        }
    }

    return null;
}

$delayRaw = kf2_wsmc_request_value('delay_ms');
$delayMs = $delayRaw === null || $delayRaw === ''
    ? KF2_WSMC_DEFAULT_DELAY_MS
    : max(0, (int) $delayRaw);

$maxPagesRaw = kf2_wsmc_request_value('max_browse_pages');
$maxBrowsePages = $maxPagesRaw === null || $maxPagesRaw === ''
    ? null
    : max(1, (int) $maxPagesRaw);

$storage = new JsonStorage();

try {
    $initialState = array_merge($storage->loadState(), [
        'phase' => 'init',
        'last_run_at' => null,
        'workshop_total_items' => 0,
        'detailed_items_analyzed' => 0,
        'maps_count' => 0,
        'review_count' => 0,
        'browse_pages_processed' => 0,
        'browse_pages_limit' => null,
        'requested_max_browse_pages' => $maxBrowsePages,
        'status' => 'running',
        'error' => null,
    ]);

    $storage->saveAll(
        $storage->loadMaps(),
        $storage->loadReview(),
        $initialState
    );

    $collector = new SteamWorkshopCollector();
    $progressState = $initialState;

    $result = $collector->collectAll($maxBrowsePages, $delayMs, static function (array $progress) use ($storage, &$progressState): void {
        $progressState = array_merge($progressState, $progress, [
            'status' => 'running',
            'error' => null,
        ]);

        $storage->saveAll(
            $storage->loadMaps(),
            $storage->loadReview(),
            $progressState
        );
    });

    $finalState = array_merge($result['state'], [
        'requested_max_browse_pages' => $maxBrowsePages,
    ]);

    $storage->saveAll($result['maps'], $result['review'], $finalState);

    kf2_wsmc_send_json([
        'ok' => true,
        'state' => $finalState,
    ]);
} catch (Throwable $throwable) {
    $state = array_merge($storage->loadState(), [
        'phase' => 'error',
        'status' => 'error',
        'error' => $throwable->getMessage(),
        'last_run_at' => gmdate('c'),
    ]);

    $storage->saveAll($storage->loadMaps(), $storage->loadReview(), $state);

    kf2_wsmc_send_json([
        'ok' => false,
        'error' => $throwable->getMessage(),
        'state' => $state,
    ], 500);
}
