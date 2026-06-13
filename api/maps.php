<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$storage = new JsonStorage();
$type = $_GET['type'] ?? 'maps';
$query = trim((string) ($_GET['q'] ?? ''));

switch ($type) {
    case 'review':
        $items = $storage->loadReview();
        break;
    case 'archived':
        $items = $storage->loadArchived();
        break;
    case 'bugged':
        $items = $storage->loadBugged();
        break;
    case 'maps':
    default:
        $items = $storage->loadMaps();
        $type = 'maps';
        break;
}

if ($query !== '') {
    $needle = mb_strtolower($query);
    $items = array_values(array_filter(
        $items,
        static fn (array $item): bool =>
            mb_stripos($item['name'], $needle) !== false || mb_stripos($item['id'], $needle) !== false
    ));
}

kf2_wsmc_send_json([
    'items' => $items,
    'count' => count($items),
    'type' => $type,
]);
