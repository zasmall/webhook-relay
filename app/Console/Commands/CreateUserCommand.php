<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * There is no public sign-up: operators are created here.
 */
final class CreateUserCommand extends Command
{
    protected $signature = 'relay:user:create
        {name : The operator\'s name}
        {email : Their email address, used to log in}
        {--password= : Their password (prompted for if omitted)}';

    protected $description = 'Create an operator account for the dashboard';

    public function handle(CreateNewUser $createNewUser): int
    {
        $password = $this->option('password') ?? $this->secret('Password');
        $confirmation = $this->option('password') ?? $this->secret('Confirm password');

        try {
            $user = $createNewUser->create([
                'name' => $this->argument('name'),
                'email' => $this->argument('email'),
                'password' => $password,
                'password_confirmation' => $confirmation,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // Created by an operator, so there's no email to verify.
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("Operator {$user->email} created.");

        return self::SUCCESS;
    }
}
