<?php
/**
 * Example cron job on the SAME server as server-php: queue a prompt and wait for the answer.
 *
 * crontab:  0 6 * * *  php /var/www/localia/server-php/examples/cron-enqueue.php >> /var/log/localia-cron.log 2>&1
 */
require __DIR__ . '/../bootstrap.php';

$queue = localia_queue();

$id = $queue->enqueue([
    'system'  => 'You write short, upbeat blog intros.',
    'prompt'  => 'Write a 3 sentence intro for a post titled "Why I self-host my AI".',
    'options' => ['temperature' => 0.7, 'max_tokens' => 300],
    'meta'    => ['post_id' => 123],   // anything you want back later; never sent to the agent
    // 'callback_url' => 'https://example.com/localia-callback.php',  // optional webhook
    // 'priority'     => 10,                                         // higher runs first
    // 'not_before'   => '2026-12-01 06:00',                         // hold until then
]);
echo "queued {$id}\n";

// Option A: wait right here (fine for CLI/cron, NOT inside a web request).
$job = $queue->waitFor($id, 1800, 10);

if ($job['status'] === 'done') {
    echo "response:\n{$job['response']}\n";
    // ...save $job['response'] to your database, publish it, etc.
} else {
    fwrite(STDERR, "job not finished: {$job['status']} {$job['error']}\n");
    exit(1);
}
