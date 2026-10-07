<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class IssueUserTokenCommand extends Command
{
    protected $signature = 'relay:user:token
        {email : The operator\'s email address}
        {--name=cli : A label for the token}';

    protected $description = 'Issue an API token an operator can use to manage endpoints';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No user has that email address.');

            return self::FAILURE;
        }

        $token = $user->createToken($this->option('name'))->plainTextToken;

        $this->components->info("Token issued to {$user->email}.");
        $this->components->warn('Copy this token now. It will not be shown again.');
        $this->line($token);

        return self::SUCCESS;
    }
}
