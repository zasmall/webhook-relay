<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoVolume;
use Illuminate\Console\Command;

final class DemoCommand extends Command
{
    protected $signature = 'relay:demo
        {--volume=0 : Also add this many old deliveries, for checking query plans}
        {--force : Skip the confirmation}';

    protected $description = 'Wipe the database and load a week of demo relay history';

    public function handle(DemoVolume $volume): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('The demo is never loaded in production.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This wipes the database. Continue?')) {
            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $extra = (int) $this->option('volume');

        if ($extra > 0) {
            $this->components->task("Adding {$extra} old deliveries", fn () => $volume->add($extra));
        }

        $this->newLine();
        $this->components->info('Demo loaded.');
        $this->components->bulletList([
            'Log in as '.DemoSeeder::EMAIL.' / '.DemoSeeder::PASSWORD,
            'Endpoints deliver to the mock receiver on port 9000 (composer dev starts it)',
            'Run php artisan relay:demo:traffic to publish live events',
        ]);

        return self::SUCCESS;
    }
}
