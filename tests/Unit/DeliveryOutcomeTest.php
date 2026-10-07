<?php

declare(strict_types=1);

use App\Enums\DeliveryOutcome;

it('classifies status codes', function (?int $status, DeliveryOutcome $expected) {
    expect(DeliveryOutcome::fromStatus($status))->toBe($expected);
})->with([
    'no response' => [null, DeliveryOutcome::Failed],
    '200' => [200, DeliveryOutcome::Succeeded],
    '204' => [204, DeliveryOutcome::Succeeded],
    '299' => [299, DeliveryOutcome::Succeeded],
    '302' => [302, DeliveryOutcome::Failed],
    '400' => [400, DeliveryOutcome::Failed],
    '404' => [404, DeliveryOutcome::Failed],
    '410' => [410, DeliveryOutcome::Gone],
    '429' => [429, DeliveryOutcome::RateLimited],
    '500' => [500, DeliveryOutcome::Failed],
    '503' => [503, DeliveryOutcome::Failed],
]);
