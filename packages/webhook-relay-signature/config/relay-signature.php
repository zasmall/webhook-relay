<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Signing secrets
    |--------------------------------------------------------------------------
    |
    | The endpoint secret(s) the relay gave you (whsec_...). A comma-separated
    | list lets you accept an old and a new secret while you rotate.
    |
    */

    'secrets' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RELAY_WEBHOOK_SECRET', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Timestamp tolerance
    |--------------------------------------------------------------------------
    |
    | Seconds the signature timestamp may differ from this server's clock.
    | Requests outside the window are rejected, which blocks replayed
    | captures. Keep your clock in sync (NTP).
    |
    */

    'tolerance' => (int) env('RELAY_WEBHOOK_TOLERANCE', 300),

];
