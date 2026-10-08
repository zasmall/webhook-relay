<?php

declare(strict_types=1);

use App\Jobs\FanOutEvent;
use App\Models\Event;
use App\Models\Source;
use Illuminate\Support\Facades\Queue;

it('refuses to load the demo in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('relay:demo', ['--force' => true])
        ->expectsOutputToContain('never loaded in production')
        ->assertFailed();
});

it('asks before wiping the database', function () {
    $this->artisan('relay:demo')
        ->expectsConfirmation('This wipes the database. Continue?', 'no')
        ->assertFailed();
});

it('publishes demo traffic through the real ingest path', function () {
    Queue::fake();
    Source::factory()->create();

    $this->artisan('relay:demo:traffic', ['--count' => 3, '--rate' => 100])->assertSuccessful();

    expect(Event::count())->toBe(3);
    Queue::assertPushed(FanOutEvent::class, 3);
});

it('needs sources before publishing traffic', function () {
    $this->artisan('relay:demo:traffic', ['--count' => 1])
        ->expectsOutputToContain('No sources yet')
        ->assertFailed();
});
