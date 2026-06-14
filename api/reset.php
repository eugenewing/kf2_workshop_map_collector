<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kf2_wsmc_send_json(['error' => 'Method not allowed.'], 405);
}

$storage = new JsonStorage();

$state = [
    'phase' => null,
    'last_run_at' => null,
    'workshop_total_items' => 0,
    'detailed_items_analyzed' => 0,
    'maps_count' => 0,
    'review_count' => 0,
    'archived_count' => 0,
    'bugged_count' => 0,
    'ignored_count' => 0,
    'featured_count' => 0,
    'browse_pages_processed' => 0,
    'browse_pages_limit' => null,
    'requested_max_browse_pages' => null,
    'status' => 'idle',
    'error' => null,
];

$storage->saveAll([], [], $state, [], [], [], []);

kf2_wsmc_send_json([
    'ok' => true,
    'state' => $state,
]);
