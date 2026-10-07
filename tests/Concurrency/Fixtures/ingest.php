<?php

declare(strict_types=1);

/*
 * Boots the app in a fresh process and ingests one event, so the concurrency
 * test can race several real database connections against each other.
 *
 * Usage: php ingest.php <source-id> <idempotency-key> <start-at-unix-float>
 * Prints {"id": "...", "created": true|false}.
 */

use App\Actions\IngestEvent;
use App\Models\Source;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $sourceId, $key, $startAt] = $argv;

$source = Source::findOrFail($sourceId);

// Wait for a shared start time so every process inserts at the same moment.
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$result = $app->make(IngestEvent::class)->handle($source, 'test.race', (object) ['n' => 1], $key);

echo json_encode(['id' => $result->event->id, 'created' => $result->created]);
