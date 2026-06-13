<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$storage = new JsonStorage();

kf2_wsmc_send_json([
    'state' => $storage->loadState(),
]);

