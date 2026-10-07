<?php

declare(strict_types=1);

use App\Support\EventTypePattern;

it('lists every pattern that matches a type', function () {
    expect(EventTypePattern::candidatesFor('invoice'))->toBe(['*', 'invoice'])
        ->and(EventTypePattern::candidatesFor('invoice.paid'))->toBe(['*', 'invoice.*', 'invoice.paid'])
        ->and(EventTypePattern::candidatesFor('a.b.c'))->toBe(['*', 'a.*', 'a.b.*', 'a.b.c']);
});

it('matches types against patterns', function (string $pattern, string $type, bool $expected) {
    expect(EventTypePattern::matches($pattern, $type))->toBe($expected);
})->with([
    'exact' => ['invoice.paid', 'invoice.paid', true],
    'exact mismatch' => ['invoice.paid', 'invoice.voided', false],
    'wildcard' => ['*', 'anything.at.all', true],
    'prefix' => ['invoice.*', 'invoice.paid', true],
    'prefix at depth' => ['invoice.*', 'invoice.line_item.added', true],
    'nested prefix' => ['invoice.line_item.*', 'invoice.line_item.added', true],
    'prefix excludes the bare name' => ['invoice.*', 'invoice', false],
    'prefix is segment-aware' => ['invoice.*', 'invoices.paid', false],
    'nested prefix mismatch' => ['invoice.line_item.*', 'invoice.paid', false],
]);

it('validates patterns', function (string $pattern, bool $expected) {
    expect(EventTypePattern::isValid($pattern))->toBe($expected);
})->with([
    ['*', true],
    ['invoice', true],
    ['invoice.paid', true],
    ['invoice.*', true],
    ['invoice.line_item.*', true],
    ['', false],
    ['*.paid', false],
    ['invoice.*.paid', false],
    ['invoice*', false],
    ['invoice.**', false],
    ['Invoice.Paid', false],
    ['invoice paid', false],
    ['.*', false],
]);
