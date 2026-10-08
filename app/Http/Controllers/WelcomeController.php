<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Database\Seeders\DemoSeeder;
use Inertia\Inertia;
use Inertia\Response;

final class WelcomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'demoLogin' => config()->boolean('relay.demo')
                ? ['email' => DemoSeeder::EMAIL, 'password' => DemoSeeder::PASSWORD]
                : null,
        ]);
    }
}
