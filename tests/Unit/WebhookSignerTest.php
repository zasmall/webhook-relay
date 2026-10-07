<?php

declare(strict_types=1);

use App\Support\WebhookSigner;

// Expected values computed independently with:
// printf '%s' '1700000000.{"id":"evt_1"}' | openssl dgst -sha256 -hmac <secret>
const SIGNED_BODY = '{"id":"evt_1"}';
const CURRENT_SIGNATURE = 'c89214b5b5da833daed6f0b8c5bb6bd58cea9022bd80ccc78230f3942d632925';
const PREVIOUS_SIGNATURE = 'ef1b1b7e4b312671a75ef2a03e10529f7834a6528a49db77ad6568c8c8017843';

it('signs "{t}.{body}" with HMAC-SHA256', function () {
    expect(WebhookSigner::header(SIGNED_BODY, ['whsec_test'], 1700000000))
        ->toBe('t=1700000000,v1='.CURRENT_SIGNATURE);
});

it('adds one v1 per secret, current first', function () {
    expect(WebhookSigner::header(SIGNED_BODY, ['whsec_test', 'whsec_old'], 1700000000))
        ->toBe('t=1700000000,v1='.CURRENT_SIGNATURE.',v1='.PREVIOUS_SIGNATURE);
});

it('changes when the body or timestamp changes', function () {
    $signature = WebhookSigner::signature(SIGNED_BODY, 'whsec_test', 1700000000);

    expect(WebhookSigner::signature(SIGNED_BODY.' ', 'whsec_test', 1700000000))->not->toBe($signature)
        ->and(WebhookSigner::signature(SIGNED_BODY, 'whsec_test', 1700000001))->not->toBe($signature);
});
