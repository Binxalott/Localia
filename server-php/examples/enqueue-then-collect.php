<?php
/**
 * Two-step cron pattern that never blocks:
 *
 *   php enqueue-then-collect.php enqueue   (e.g. every night at 1am)
 *   php enqueue-then-collect.php collect   (e.g. every 10 minutes)
 *
 * Job ids are tracked in "meta" so "collect" can find the ones it cares about.
 */
require __DIR__ . '/../bootstrap.php';

$queue = localia_queue();
$mode = $argv[1] ?? 'collect';

if ($mode === 'enqueue') {
    // Your own data source here, e.g. products missing a description.
    $products = [['id' => 1, 'name' => 'Blue mug'], ['id' => 2, 'name' => 'Desk lamp']];
    foreach ($products as $p) {
        $id = $queue->enqueue([
            'prompt' => "Write a 2 sentence product description for: {$p['name']}",
            'meta'   => ['task' => 'product_description', 'product_id' => $p['id']],
        ]);
        echo "queued {$id} for product {$p['id']}\n";
    }
    exit;
}

// collect: everything finished in the last hour
foreach ($queue->list(['status' => 'done', 'since' => time() - 3600, 'limit' => 200]) as $job) {
    if (($job['meta']['task'] ?? null) !== 'product_description') {
        continue;
    }
    echo "product {$job['meta']['product_id']}: {$job['response']}\n";
    // UPDATE products SET description = ? WHERE id = ? AND description IS NULL ...
}
