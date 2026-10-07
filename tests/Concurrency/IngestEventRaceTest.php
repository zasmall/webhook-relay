<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Source;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

it('creates exactly one event when the same key is ingested concurrently', function () {
    $source = Source::factory()->create();
    $processes = 8;
    $startAt = microtime(true) + 2;

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        // Parallel test runs use a per-process database, so pass the real one.
        'DB_DATABASE' => config('database.connections.mysql.database'),
        // Ingest dispatches fan-out; keep it off the real Redis queue.
        'QUEUE_CONNECTION' => 'sync',
    ];

    $results = Process::pool(function (Pool $pool) use ($processes, $source, $startAt, $env) {
        foreach (range(1, $processes) as $i) {
            $pool->env($env)->command([
                PHP_BINARY,
                base_path('tests/Concurrency/Fixtures/ingest.php'),
                $source->id,
                'race-key',
                (string) $startAt,
            ]);
        }
    })->start()->wait();

    $outcomes = collect($results)->map(function ($result) {
        expect($result->successful())->toBeTrue($result->errorOutput());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    });

    $event = Event::sole();

    expect($outcomes)->toHaveCount($processes)
        ->and($outcomes->pluck('id')->unique()->all())->toBe([$event->id])
        ->and($outcomes->where('created', true))->toHaveCount(1);
});
