<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\QueueDueDeliveries;
use App\Actions\ResetStaleDeliveries;
use Illuminate\Console\Command;

final class SweepDeliveriesCommand extends Command
{
    protected $signature = 'relay:sweep';

    protected $description = 'Reset stuck deliveries and queue pending deliveries that are due';

    public function handle(ResetStaleDeliveries $resetStale, QueueDueDeliveries $queueDue): int
    {
        $reset = $resetStale->handle();
        $due = $queueDue->handle();

        // Deliveries whose job is already waiting are skipped by the unique
        // lock, so "due" can be more than the jobs actually added.
        $this->components->info("Reset {$reset} stuck, found {$due} due.");

        return self::SUCCESS;
    }
}
