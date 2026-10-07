<?php

declare(strict_types=1);

use App\Models\Endpoint;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires login', function () {
    $this->get(route('endpoints.index'))->assertRedirect(route('login'));
});

it('lists endpoints without secrets', function () {
    Endpoint::factory()->count(2)->create();

    $this->actingAs($this->user)
        ->get(route('endpoints.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('endpoints/Index')
            ->has('endpoints', 2)
            ->missing('endpoints.0.secret'));
});

it('renders the create page', function () {
    $this->actingAs($this->user)
        ->get(route('endpoints.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('endpoints/Create'));
});

it('creates an endpoint from the form', function () {
    $this->actingAs($this->user)
        ->post(route('endpoints.store'), [
            'url' => 'https://hooks.example.com/relay',
            'description' => '',
            'event_types' => ['invoice.*'],
        ])
        ->assertRedirect(route('endpoints.show', Endpoint::sole()));

    expect(Endpoint::sole())
        ->description->toBeNull()
        ->event_types->toBe(['invoice.*']);
});

it('shows validation errors from the form', function () {
    $this->actingAs($this->user)
        ->from(route('endpoints.create'))
        ->post(route('endpoints.store'), ['url' => 'https://127.0.0.1', 'event_types' => ['Bad']])
        ->assertRedirect(route('endpoints.create'))
        ->assertSessionHasErrors(['url', 'event_types.0']);
});

it('hides the secret on the show page', function () {
    $endpoint = Endpoint::factory()->create();

    $this->actingAs($this->user)
        ->get(route('endpoints.show', $endpoint))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('endpoints/Show')
            ->where('endpoint.id', $endpoint->id)
            ->where('secret', null)
            ->missing('endpoint.secret'));
});

it('requires password confirmation to reveal the secret', function () {
    $endpoint = Endpoint::factory()->create();

    $this->actingAs($this->user)
        ->get(route('endpoints.secret', $endpoint))
        ->assertRedirect(route('password.confirm'));
});

it('reveals the secret after password confirmation', function () {
    $endpoint = Endpoint::factory()->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('endpoints.secret', $endpoint))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('endpoints/Show')
            ->where('secret', $endpoint->secret));
});

it('updates, toggles, rotates and deletes', function () {
    $endpoint = Endpoint::factory()->create();
    $oldSecret = $endpoint->secret;

    $this->actingAs($this->user);

    $this->patch(route('endpoints.update', $endpoint), [
        'url' => 'https://new.example.com/hook',
        'description' => 'Renamed',
        'event_types' => ['customer.*'],
    ])->assertRedirect(route('endpoints.show', $endpoint));

    expect($endpoint->fresh())
        ->url->toBe('https://new.example.com/hook')
        ->event_types->toBe(['customer.*']);

    $this->patch(route('endpoints.update', $endpoint), ['is_active' => false]);
    expect($endpoint->fresh()->is_active)->toBeFalse();

    $this->post(route('endpoints.rotate-secret', $endpoint))
        ->assertRedirect(route('endpoints.show', $endpoint));
    expect($endpoint->fresh()->previous_secret)->toBe($oldSecret);

    $this->delete(route('endpoints.destroy', $endpoint))
        ->assertRedirect(route('endpoints.index'));
    $this->assertSoftDeleted($endpoint);
});
