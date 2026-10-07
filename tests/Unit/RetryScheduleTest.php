<?php

declare(strict_types=1);

use App\Support\RetrySchedule;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('doubles the ceiling each attempt up to the cap', function () {
    $schedule = new RetrySchedule(baseDelay: 180, maxDelay: 10_800);

    expect(array_map($schedule->ceiling(...), range(1, 8)))
        ->toBe([180, 360, 720, 1440, 2880, 5760, 10_800, 10_800]);
});

it('never overflows on huge attempt counts', function () {
    expect((new RetrySchedule(180, 10_800))->ceiling(PHP_INT_MAX))->toBe(10_800);
});

it('picks a delay between zero and the ceiling (full jitter)', function () {
    $schedule = new RetrySchedule(180, 10_800);

    foreach (range(1, 8) as $attempt) {
        foreach (range(1, 50) as $_) {
            expect($schedule->delayFor($attempt))->toBeBetween(0, $schedule->ceiling($attempt));
        }
    }
});

it('is deterministic with a seeded randomizer', function () {
    $delays = fn () => array_map(
        (new RetrySchedule(180, 10_800, new Randomizer(new Mt19937(42))))->delayFor(...),
        range(1, 8),
    );

    expect($delays())->toBe($delays());
});

it('spans at most about six hours over the default eight attempts', function () {
    // Seven retries follow the first attempt.
    $schedule = new RetrySchedule(180, 10_800);
    $worstCase = array_sum(array_map($schedule->ceiling(...), range(1, 7)));

    expect($worstCase)->toBe(22_140); // 6h 9m
});
