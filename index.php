<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$storage = new JsonStorage();
$state = $storage->loadState();
$cssVersion = is_file(__DIR__ . '/assets/app.css') ? (string) filemtime(__DIR__ . '/assets/app.css') : '1';
$jsVersion = is_file(__DIR__ . '/assets/app.js') ? (string) filemtime(__DIR__ . '/assets/app.js') : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KF2_WSMC</title>
    <link rel="stylesheet" href="./assets/app.css?v=<?php echo htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div class="eyebrow">Steam Workshop Collector</div>
            <h1>KF2_WSMC</h1>
            <p class="subtitle">
                Web version for XAMPP. It scans the Killing Floor 2 Workshop, stores results in JSON,
                and shows both confident maps and review candidates in the browser.
            </p>
        </section>

        <section class="panel">
            <div class="controls">
                <button class="button button-primary" data-action="refresh">Refresh Workshop Data</button>
                <button class="button button-secondary" data-action="reset">Reset Parsed Data</button>
                <input type="number" min="0" step="1" value="40" data-role="delay" aria-label="Delay in milliseconds">
                <input type="number" min="1" step="1" placeholder="Optional page limit" data-role="max-pages" aria-label="Optional page limit">
            </div>
            <p class="hint">Delay is useful to reduce request pressure. Leave page limit empty for a full scan.</p>
            <p class="status" data-role="status">
                <?php echo $state['last_run_at'] ? 'Loaded existing JSON data.' : 'No data yet. Start the first scan.'; ?>
            </p>
            <div class="progress-wrap" data-role="progress-wrap" hidden>
                <div class="progress-meta">
                    <span data-role="progress-phase">Phase: idle</span>
                    <span data-role="progress-percent">0%</span>
                </div>
                <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Workshop scan progress">
                    <div class="progress-fill" data-role="progress-fill"></div>
                </div>
            </div>
        </section>

        <section class="stats">
            <div class="stat">
                <div class="stat-label">Workshop Items</div>
                <div class="stat-value" data-role="count-total"><?php echo (int) ($state['workshop_total_items'] ?? 0); ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Confident Maps</div>
                <div class="stat-value" data-role="count-maps"><?php echo (int) ($state['maps_count'] ?? 0); ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Review Items</div>
                <div class="stat-value" data-role="count-review"><?php echo (int) ($state['review_count'] ?? 0); ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Archived Maps</div>
                <div class="stat-value" data-role="count-archived"><?php echo (int) ($state['archived_count'] ?? 0); ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Bugged Maps</div>
                <div class="stat-value" data-role="count-bugged"><?php echo (int) ($state['bugged_count'] ?? 0); ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Browse Pages</div>
                <div class="stat-value" data-role="count-pages"><?php echo (int) ($state['browse_pages_processed'] ?? 0); ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Last Run</div>
                <div class="stat-value" data-role="last-run"><?php echo htmlspecialchars((string) ($state['last_run_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </section>

        <section class="panel">
            <div class="filters">
                <div class="tabs">
                    <button class="tab active" data-type="maps">Confident Maps</button>
                    <button class="tab" data-type="review">Review List</button>
                    <button class="tab" data-type="archived">Archived</button>
                    <button class="tab" data-type="bugged">Bugged</button>
                </div>
                <div class="search-row">
                    <input type="search" placeholder="Search by map name or workshop id" data-role="search" aria-label="Search maps">
                </div>
            </div>

            <div class="list-wrap">
                <div class="list-head">
                    <div>Name</div>
                    <div>Workshop ID</div>
                    <div>Steam</div>
                    <div>Actions</div>
                </div>
                <div data-role="list">
                    <div class="empty">Loading data...</div>
                </div>
            </div>
        </section>
    </main>

    <script src="./assets/app.js?v=<?php echo htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
