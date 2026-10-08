<?php

declare(strict_types=1);

use App\Actions\CreateDeliveries;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Event;
use App\Models\User;
use App\Queries\DeliveryLogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Uid\Ulid;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function deliveryOfType(string $type, array $attributes = []): Delivery
{
    return Delivery::factory()->create([
        'event_id' => Event::factory()->create(['type' => $type]),
        ...$attributes,
    ]);
}

/**
 * @return list<string>
 */
function logIds(array $query = []): array
{
    $ids = [];

    test()->get(route('deliveries.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids) {
            $page->component('deliveries/Index');
            $ids = array_column($page->toArray()['props']['deliveries']['data'], 'id');
        });

    return $ids;
}

it('requires login', function () {
    auth()->logout();

    $this->get(route('deliveries.index'))->assertRedirect(route('login'));
});

it('lists deliveries newest first with their event and endpoint', function () {
    $older = deliveryOfType('invoice.paid');
    $this->travel(1)->second();
    $newer = deliveryOfType('customer.updated');

    $this->get(route('deliveries.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('deliveries.data', 2)
            ->where('deliveries.data.0.id', $newer->id)
            ->where('deliveries.data.0.event_type', 'customer.updated')
            ->where('deliveries.data.0.endpoint.url', $newer->endpoint->url)
            ->where('deliveries.data.1.id', $older->id)
            ->where('statuses', ['pending', 'delivering', 'succeeded', 'dead'])
            ->has('endpoints', 2));
});

it('filters by status and endpoint', function () {
    $endpoint = Endpoint::factory()->create();
    $match = deliveryOfType('invoice.paid', ['endpoint_id' => $endpoint->id, 'status' => DeliveryStatus::Dead]);
    deliveryOfType('invoice.paid', ['endpoint_id' => $endpoint->id]);
    deliveryOfType('invoice.paid', ['status' => DeliveryStatus::Dead]);

    expect(logIds(['status' => 'dead', 'endpoint' => $endpoint->id]))->toBe([$match->id]);
});

it('filters by event type pattern', function () {
    $paid = deliveryOfType('invoice.paid');
    $line = deliveryOfType('invoice.line_item.added');
    $customer = deliveryOfType('customer.updated');
    $underscore = deliveryOfType('invoice_v2.paid');
    $lookalike = deliveryOfType('invoiceXv2.paid');

    expect(logIds(['event_type' => 'invoice.paid']))->toBe([$paid->id])
        ->and(logIds(['event_type' => 'invoice.*']))->toEqualCanonicalizing([$paid->id, $line->id])
        ->and(logIds(['event_type' => 'invoice_v2.*']))->toBe([$underscore->id])
        ->and(logIds(['event_type' => '*']))->toHaveCount(5)
        ->and($lookalike)->not->toBeNull()
        ->and($customer)->not->toBeNull();
});

it('filters by date range, inclusive of whole days', function () {
    $this->travelTo('2026-10-01 23:59:00');
    $first = deliveryOfType('a');
    $this->travelTo('2026-10-03 00:00:30');
    $third = deliveryOfType('a');
    $this->travelTo('2026-10-05 12:00:00');
    deliveryOfType('a');

    expect(logIds(['from' => '2026-10-01', 'to' => '2026-10-03']))->toEqualCanonicalizing([$first->id, $third->id])
        ->and(logIds(['from' => '2026-10-02']))->toHaveCount(2)
        ->and(logIds(['to' => '2026-10-01']))->toBe([$first->id]);
});

it('rejects invalid filters', function (array $query, string $field) {
    $this->get(route('deliveries.index', $query))->assertSessionHasErrors($field);
})->with([
    'status' => [['status' => 'lost'], 'status'],
    'endpoint' => [['endpoint' => 'nope'], 'endpoint'],
    'event type' => [['event_type' => 'Bad Type'], 'event_type'],
    'date' => [['from' => '10/01/2026'], 'from'],
    'range' => [['from' => '2026-10-05', 'to' => '2026-10-01'], 'to'],
]);

it('paginates with a cursor and keeps the filters', function () {
    $endpoint = Endpoint::factory()->create();
    Delivery::factory()->count(51)->create(['endpoint_id' => $endpoint->id]);

    $next = null;

    $this->get(route('deliveries.index', ['endpoint' => $endpoint->id]))
        ->assertInertia(function (Assert $page) use (&$next) {
            $page->has('deliveries.data', 50)->where('deliveries.prev_page_url', null);
            $next = $page->toArray()['props']['deliveries']['next_page_url'];
        });

    expect($next)->toContain('cursor=')->toContain('endpoint='.$endpoint->id);

    $this->get($next)->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 1));
});

it('renders many rows without lazy loading', function () {
    // Model::shouldBeStrict() throws on lazy loading outside production.
    Delivery::factory()->count(5)->create();

    $this->get(route('deliveries.index'))->assertOk();
});

it('gives deliveries ids whose time matches created_at', function () {
    $this->travelTo('2026-03-14 15:09:26');
    Endpoint::factory()->create();
    $event = Event::factory()->create();
    app(CreateDeliveries::class)->handle($event);
    $factoryMade = Delivery::factory()->create();

    foreach ([Delivery::where('event_id', $event->id)->sole(), $factoryMade] as $delivery) {
        $idTime = Ulid::fromString(strtoupper($delivery->id))->getDateTime();

        expect($idTime->format('Y-m-d H:i:s'))->toBe($delivery->created_at->format('Y-m-d H:i:s'));
    }
});

it('brackets every ULID of a second between its bounds', function () {
    $at = CarbonImmutable::parse('2026-10-08 12:34:56');
    $first = strtolower((string) Str::ulid($at->startOfSecond()));
    $last = strtolower((string) Str::ulid($at->endOfSecond()));

    expect(DeliveryLogQuery::ulidBound($at, '0'))->toBeLessThanOrEqual($first)
        ->and(DeliveryLogQuery::ulidBound($at, 'z'))->toBeGreaterThanOrEqual($last);
});
