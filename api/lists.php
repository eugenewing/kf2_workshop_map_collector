<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kf2_wsmc_send_json(['error' => 'Method not allowed.'], 405);
}

$storage = new JsonStorage();

/**
 * @return array<int, string>
 */
function kf2_wsmc_allowed_list_types(): array
{
    return ['maps', 'review', 'archived', 'bugged', 'ignored', 'featured'];
}

function kf2_wsmc_find_item_index(array $items, string $id): int
{

    foreach ($items as $index => $item) {
        if ((string) ($item['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

$action = trim((string) ($_POST['action'] ?? ''));
$id = trim((string) ($_POST['id'] ?? ''));
$fromType = trim((string) ($_POST['from'] ?? ''));
$toType = trim((string) ($_POST['to'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($id === '') {
    kf2_wsmc_send_json(['error' => 'Missing required field: id.'], 400);
}

$allowedTypes = kf2_wsmc_allowed_list_types();
$lists = [
    'maps' => $storage->loadMaps(),
    'review' => $storage->loadReview(),
    'archived' => $storage->loadArchived(),
    'bugged' => $storage->loadBugged(),
    'ignored' => $storage->loadIgnored(),
    'featured' => $storage->loadFeatured(),
];

if ($action === 'delete') {
    if (!in_array($fromType, $allowedTypes, true)) {

        kf2_wsmc_send_json(['error' => 'Invalid source list.'], 400);
    }

    $index = kf2_wsmc_find_item_index($lists[$fromType], $id);
    if ($index < 0) {
        kf2_wsmc_send_json(['error' => 'Map id not found in source list.'], 404);
    }

    array_splice($lists[$fromType], $index, 1);
} elseif ($action === 'move') {
    if (!in_array($fromType, $allowedTypes, true) || !in_array($toType, $allowedTypes, true)) {
        kf2_wsmc_send_json(['error' => 'Invalid list type in move request.'], 400);
    }

    if ($fromType === $toType) {
        kf2_wsmc_send_json(['error' => 'Source and target lists must differ.'], 400);
    }

    $sourceIndex = kf2_wsmc_find_item_index($lists[$fromType], $id);
    if ($sourceIndex < 0) {
        kf2_wsmc_send_json(['error' => 'Map id not found in source list.'], 404);
    }

    $item = $lists[$fromType][$sourceIndex];
    array_splice($lists[$fromType], $sourceIndex, 1);

    if ($toType === 'bugged') {
        if ($reason === '') {
            kf2_wsmc_send_json(['error' => 'Field "reason" is required when moving to bugged list.'], 400);
        }
        $item['reason'] = $reason;
    } elseif (array_key_exists('reason', $item)) {
        unset($item['reason']);
    }

    if (kf2_wsmc_find_item_index($lists[$toType], $id) < 0) {
        $lists[$toType][] = $item;
    }
} else {
    kf2_wsmc_send_json(['error' => 'Unsupported action. Use action=move or action=delete.'], 400);
}

$state = array_merge($storage->loadState(), [
    'maps_count' => count($lists['maps']),
    'review_count' => count($lists['review']),
    'archived_count' => count($lists['archived']),
    'bugged_count' => count($lists['bugged']),
    'ignored_count' => count($lists['ignored']),
    'featured_count' => count($lists['featured']),
]);

$storage->saveAll($lists['maps'], $lists['review'], $state, $lists['archived'], $lists['bugged'], $lists['ignored'], $lists['featured']);

kf2_wsmc_send_json([
    'ok' => true,
    'state' => $state,
    'counts' => [
        'maps' => count($lists['maps']),
        'review' => count($lists['review']),
        'archived' => count($lists['archived']),
        'bugged' => count($lists['bugged']),
        'ignored' => count($lists['ignored']),
        'featured' => count($lists['featured']),
    ],
]);




