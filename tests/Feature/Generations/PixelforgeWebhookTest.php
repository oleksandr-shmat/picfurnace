<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Enums\GenerationStatus;
use App\Generations\WebhookEventHandler;
use App\Generations\WebhookOutcome;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\ProviderEvent;
use Illuminate\Support\Facades\Log;

mutates(WebhookEventHandler::class, ProviderEvent::class);

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    config(['pixelforge.webhook_secret' => WEBHOOK_SECRET]);

    $this->billing = Mockery::mock(Billing::class)->shouldIgnoreMissing();
    app()->instance(Billing::class, $this->billing);

    // A generation we sent: its uuid travels in the metadata of every event about it.
    $this->generation = Generation::factory()->create(['claimed_at' => now()]);
});

/**
 * A recorded event whose generation carries our uuid.
 *
 * @param  array<string, mixed>  $eventOverrides
 * @param  array<string, mixed>  $generationOverrides
 */
function webhookEvent(string $fixture, string $ourUuid, array $eventOverrides = [], array $generationOverrides = []): array
{
    $event = pixelforgeFixture($fixture);
    $event['data']['object']['metadata'] = ['generation_uuid' => $ourUuid];
    $event['data']['object'] = array_replace($event['data']['object'], $generationOverrides);

    return array_replace($event, $eventOverrides);
}

/** Posts a body to the webhook endpoint, signed the way Pixelforge does it. */
function deliver(mixed $test, array|string $body, ?int $signedAt = null, string $secret = WEBHOOK_SECRET)
{
    $rawBody = is_array($body) ? json_encode($body) : $body;
    $timestamp = $signedAt ?? time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

    return $test->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $rawBody);
}

it('charges a generation when the success event arrives', function () {
    $this->billing->shouldReceive('settle')->once();

    deliver($this, webhookEvent('event_succeeded', $this->generation->uuid))->assertNoContent();

    $this->generation->refresh();
    $event = WebhookEvent::sole();
    expect($this->generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->generation->charged_nano)->toBe(17_287_500)
        ->and($event->generation_id)->toBe($this->generation->id)
        ->and($event->outcome)->toBe(WebhookOutcome::Applied)
        ->and($event->deliveries)->toBe(1)
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->type)->toBe('generation.succeeded')
        ->and($event->payload['id'])->toBe(pixelforgeFixture('event_succeeded')['id'])
        ->and($event->created_at)->not->toBeNull()
        ->and($event->updated_at)->not->toBeNull();
});

it('fails a generation for free when the failure event arrives', function () {
    $this->billing->shouldReceive('release')->once();

    deliver($this, webhookEvent('event_failed', $this->generation->uuid))->assertNoContent();

    $this->generation->refresh();
    expect($this->generation->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->charged_nano)->toBe(0)
        ->and($this->generation->failure_code)->toBe('generation_failed');
});

it('charges once however often the same event is delivered', function () {
    $this->billing->shouldReceive('settle')->once();
    $event = webhookEvent('event_succeeded', $this->generation->uuid);

    foreach (range(1, 3) as $ignored) {
        deliver($this, $event)->assertNoContent();
    }

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::sole()->deliveries)->toBe(3)
        ->and($this->generation->refresh()->charged_nano)->toBe(17_287_500);
});

it('ends in the same state whatever order the events arrive in', function (array $order) {
    $this->billing->shouldReceive('settle')->once();
    $events = [
        'processing' => webhookEvent('event_processing', $this->generation->uuid, [], ['id' => 'gen_same']),
        'succeeded' => webhookEvent('event_succeeded', $this->generation->uuid, [], ['id' => 'gen_same']),
    ];

    foreach ($order as $name) {
        deliver($this, $events[$name])->assertNoContent();
    }

    $this->generation->refresh();
    expect($this->generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->generation->charged_nano)->toBe(17_287_500);
})->with([
    'in order' => [['processing', 'succeeded']],
    'the result first, then a late processing' => [['succeeded', 'processing']],
    'a repeated processing after the result' => [['processing', 'succeeded', 'processing']],
]);

it('marks a late event as ignored and leaves the generation alone', function () {
    deliver($this, webhookEvent('event_succeeded', $this->generation->uuid, [], ['id' => 'gen_same']));

    deliver($this, webhookEvent('event_processing', $this->generation->uuid, [], ['id' => 'gen_same']))->assertNoContent();

    expect(WebhookEvent::where('type', 'generation.processing')->sole()->outcome)->toBe(WebhookOutcome::IgnoredStale);
});

it('takes a result that arrives before our own request has returned', function () {
    expect($this->generation->provider_id)->toBeNull();

    deliver($this, webhookEvent('event_succeeded', $this->generation->uuid))->assertNoContent();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('finds the generation by its Pixelforge id when the metadata has no uuid', function () {
    $this->generation->update(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_01M80VTQ32794C2GD648HPJCTW']);
    $event = pixelforgeFixture('event_succeeded');
    $event['data']['object']['metadata'] = [];

    deliver($this, $event)->assertNoContent();

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('records an event about a generation it does not know and changes nothing', function (array $metadata) {
    Log::spy();
    $event = pixelforgeFixture('event_succeeded');
    $event['data']['object']['metadata'] = $metadata;

    deliver($this, $event)->assertNoContent();

    expect(WebhookEvent::sole()->outcome)->toBe(WebhookOutcome::IgnoredUnknownGeneration)
        ->and(WebhookEvent::sole()->generation_id)->toBeNull()
        ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Pending);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === [
        'event' => pixelforgeFixture('event_succeeded')['id'],
        'provider_id' => pixelforgeFixture('event_succeeded')['data']['object']['id'],
    ]);
})->with([
    'no metadata' => [[]],
    'an uuid we never issued' => [['generation_uuid' => '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10']],
    'something that is not an uuid' => [['generation_uuid' => 'order-1042']],
]);

it('accepts event types it does not act on', function (string $type) {
    deliver($this, ['id' => 'evt_ping', 'object' => 'event', 'type' => $type, 'data' => ['whatever' => true]])->assertNoContent();

    expect(WebhookEvent::sole()->outcome)->toBe(WebhookOutcome::IgnoredType)
        ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Pending);
})->with(['ping', 'generation.something_new']);

it('refuses a request that is not signed correctly and changes nothing', function (callable $send) {
    $send($this, webhookEvent('event_succeeded', $this->generation->uuid))->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0)
        ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'no signature' => [fn ($test, $body) => $test->call('POST', route('webhooks.pixelforge'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body))],
    'wrong secret' => [fn ($test, $body) => deliver($test, $body, secret: 'not_our_secret')],
    'old request' => [fn ($test, $body) => deliver($test, $body, signedAt: time() - 301)],
    'body changed after signing' => [function ($test, $body) {
        $raw = json_encode($body);
        $signature = hash_hmac('sha256', time().'.'.$raw, WEBHOOK_SECRET);

        return $test->call('POST', route('webhooks.pixelforge'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1='.$signature,
        ], $raw.' ');
    }],
]);

it('refuses a signed body it cannot read', function (array|string $body) {
    deliver($this, $body)->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
})->with([
    'not JSON' => ['not json'],
    'JSON that is not an object' => ['"text"'],
    'no event id' => [fn () => ['type' => 'generation.processing']],
    'no type' => [['id' => 'evt_1']],
    'generation event without a generation' => [['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => []]],
    'succeeded without usage' => [fn () => ['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => ['object' => ['usage' => null] + pixelforgeFixture('event_succeeded')['data']['object']]]],
]);

it('does not remember an event it failed to apply, so that the retry is processed', function () {
    // linked to another Pixelforge generation: applying the event is refused
    $this->generation->update(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_other']);

    deliver($this, webhookEvent('event_succeeded', $this->generation->uuid))->assertStatus(500);

    expect(WebhookEvent::count())->toBe(0)
        ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('is outside the web group, which would demand a CSRF token and start a session', function () {
    $middleware = Route::getRoutes()->getByName('webhooks.pixelforge')->gatherMiddleware();

    expect($middleware)->not->toContain('web');
});
