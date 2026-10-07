<?php

declare(strict_types=1);

use App\Support\RetryAfter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-10-21 07:28:00', 'UTC');
});

it('reads delay-seconds', function () {
    expect(RetryAfter::seconds('120', $this->now, 3600))->toBe(120)
        ->and(RetryAfter::seconds(' 0 ', $this->now, 3600))->toBe(0);
});

it('reads an HTTP date', function () {
    expect(RetryAfter::seconds('Wed, 21 Oct 2026 07:30:00 GMT', $this->now, 3600))->toBe(120);
});

it('treats a past date as no wait', function () {
    expect(RetryAfter::seconds('Wed, 21 Oct 2026 07:00:00 GMT', $this->now, 3600))->toBe(0);
});

it('caps the wait', function () {
    expect(RetryAfter::seconds('86400', $this->now, 3600))->toBe(3600)
        ->and(RetryAfter::seconds('Thu, 22 Oct 2026 07:28:00 GMT', $this->now, 3600))->toBe(3600);
});

it('ignores missing or unreadable values', function (?string $header) {
    expect(RetryAfter::seconds($header, $this->now, 3600))->toBeNull();
})->with([null, '', 'soon', '-5', '1.5', 'Wed, 99 Oct 2026']);
