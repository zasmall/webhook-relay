<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IngestEvent;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Publishes events through the real ingest path (and so fan-out and
 * delivery), so retries, backoff and the breaker happen live.
 */
final class DemoTrafficCommand extends Command
{
    protected $signature = 'relay:demo:traffic
        {--rate=1 : Events per second}
        {--count=0 : Stop after this many events (0 runs until interrupted)}';

    protected $description = 'Publish a steady stream of demo events';

    private const TYPES = ['invoice.created', 'invoice.paid', 'invoice.voided', 'order.created', 'order.shipped', 'customer.created', 'customer.updated'];

    public function handle(IngestEvent $ingest): int
    {
        $sources = Source::all();

        if ($sources->isEmpty()) {
            $this->components->error('No sources yet. Run php artisan relay:demo first.');

            return self::FAILURE;
        }

        $count = (int) $this->option('count');
        $pause = (int) round(1_000_000 / max(0.1, (float) $this->option('rate')));

        for ($sent = 1; $count === 0 || $sent <= $count; $sent++) {
            $type = self::TYPES[array_rand(self::TYPES)];
            $event = $ingest->handle(
                $sources->random(),
                $type,
                (object) ['ref' => Str::lower(Str::random(8)), 'amount_cents' => random_int(500, 90_000)],
                (string) Str::uuid(),
            )->event;

            $this->line(sprintf('%s  %-18s %s', now()->format('H:i:s'), $type, $event->id));

            if ($count === 0 || $sent < $count) {
                usleep($pause);
            }
        }

        return self::SUCCESS;
    }
}
