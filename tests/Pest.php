<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests spawn separate processes that must see committed rows, so
// they truncate tables instead of wrapping each test in a transaction.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('concurrency')
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Verifies an X-Relay-Signature header the way a receiver would: any v1 that
 * matches HMAC-SHA256("{t}.{body}") with the secret passes.
 */
function signatureVerifies(string $header, string $body, string $secret): bool
{
    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');

        if ($key === 't') {
            $timestamp = $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}
