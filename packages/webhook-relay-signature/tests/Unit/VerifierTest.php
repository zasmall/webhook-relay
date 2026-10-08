<?php

declare(strict_types=1);

use Zasmall\RelaySignature\Exceptions\MalformedSignature;
use Zasmall\RelaySignature\Exceptions\MissingSignature;
use Zasmall\RelaySignature\Exceptions\SignatureMismatch;
use Zasmall\RelaySignature\Exceptions\TimestampOutsideTolerance;
use Zasmall\RelaySignature\Signer;
use Zasmall\RelaySignature\Verifier;

const NOW = 1700000000;

function signed(string $body = '{"id":"evt_1"}', array $secrets = ['whsec_current'], int $at = NOW): string
{
    return Signer::header($body, $secrets, $at);
}

it('accepts a valid signature', function () {
    Verifier::verify(signed(), '{"id":"evt_1"}', ['whsec_current'], NOW);
})->throwsNoExceptions();

it('accepts when any v1 matches any secret', function (array $relaySecrets, array $receiverSecrets) {
    Verifier::verify(signed(secrets: $relaySecrets), '{"id":"evt_1"}', $receiverSecrets, NOW);
})->with([
    'relay rotating' => [['whsec_new', 'whsec_old'], ['whsec_old']],
    'receiver rotating' => [['whsec_current'], ['whsec_next', 'whsec_current']],
])->throwsNoExceptions();

it('rejects a tampered body or wrong secret', function (string $body, array $secrets) {
    Verifier::verify(signed(), $body, $secrets, NOW);
})->with([
    'body changed' => ['{"id":"evt_2"}', ['whsec_current']],
    'body whitespace' => ['{"id":"evt_1"} ', ['whsec_current']],
    'wrong secret' => ['{"id":"evt_1"}', ['whsec_other']],
])->throws(SignatureMismatch::class);

it('rejects timestamps outside the tolerance, either way', function (int $signedAt) {
    Verifier::verify(signed(at: $signedAt), '{"id":"evt_1"}', ['whsec_current'], NOW, tolerance: 300);
})->with([
    'too old' => NOW - 301,
    'too far ahead' => NOW + 301,
])->throws(TimestampOutsideTolerance::class);

it('accepts timestamps at the edge of the tolerance', function (int $signedAt) {
    Verifier::verify(signed(at: $signedAt), '{"id":"evt_1"}', ['whsec_current'], NOW, tolerance: 300);
})->with([NOW - 300, NOW + 300])->throwsNoExceptions();

it('rejects a missing header', function (?string $header) {
    Verifier::verify($header, 'body', ['whsec_current'], NOW);
})->with([null, '', '   '])->throws(MissingSignature::class);

it('rejects malformed headers', function (string $header) {
    Verifier::verify($header, 'body', ['whsec_current'], NOW);
})->with([
    'no timestamp' => 'v1='.str_repeat('a', 64),
    'no v1' => 't=1700000000',
    'only unknown schemes' => 't=1700000000,v0='.str_repeat('a', 64),
    'non-numeric timestamp' => 't=abc,v1='.str_repeat('a', 64),
    'negative timestamp' => 't=-5,v1='.str_repeat('a', 64),
    'two timestamps' => 't=1,t=2,v1='.str_repeat('a', 64),
    'short v1' => 't=1700000000,v1=abc',
    'uppercase hex' => 't=1700000000,v1='.str_repeat('A', 64),
    'garbage' => 'hello',
    'too long' => 't=1700000000,v1='.str_repeat('a', 64).str_repeat(',x=y', 2000),
])->throws(MalformedSignature::class);

it('ignores unknown schemes next to a valid v1', function () {
    $header = signed().',v2='.str_repeat('f', 128);

    Verifier::verify($header, '{"id":"evt_1"}', ['whsec_current'], NOW);
})->throwsNoExceptions();

it('requires a secret', function () {
    Verifier::verify(signed(), '{"id":"evt_1"}', ['', ''], NOW);
})->throws(InvalidArgumentException::class);
