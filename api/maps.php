<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$storage = new JsonStorage();
$type = $_GET['type'] ?? 'maps';
$query = trim((string) ($_GET['q'] ?? ''));

$items = $type === 'review' ? $storage->loadReview() : $storage->loadMaps();

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

