<?php
/**
 * Queue a job on a Localia server running on a DIFFERENT machine, over HTTPS with an API key.
 */
require __DIR__ . '/../src/HttpError.php';
require __DIR__ . '/../src/Queue.php';
require __DIR__ . '/../src/Client.php';

$localia = new \Localia\Client('https://example.com/localia/api.php', getenv('LOCALIA_API_KEY') ?: 'CHANGE_ME_API_KEY');

$id = $localia->enqueue(['prompt' => 'Summarize the benefits of cron jobs in one sentence.']);
echo "queued {$id}\n";

$job = $localia->waitFor($id, 900, 10);
echo $job['status'] === 'done' ? $job['response'] . "\n" : "status: {$job['status']} {$job['error']}\n";
