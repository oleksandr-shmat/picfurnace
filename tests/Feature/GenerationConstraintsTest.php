<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('refuses a charge on a generation that is not finished', function () {
    Generation::factory()->create(['charged_nano' => 1_000]);
})->throws(QueryException::class, 'generations_charge_check');

it('refuses a charge on a failed generation', function () {
    Generation::factory()->failed()->create(['charged_nano' => 1]);
})->throws(QueryException::class, 'generations_charge_check');

it('refuses a succeeded generation without a cost', function () {
    Generation::factory()->succeeded()->create(['provider_cost_nano' => null]);
})->throws(QueryException::class, 'generations_charge_check');

it('refuses negative money and an inverted estimate range', function (array $override) {
    Generation::factory()->create($override);
})->throws(QueryException::class, 'generations_money_check')->with([
    'negative estimate' => [['estimated_price_min_nano' => -1]],
    'min above expected' => [['estimated_price_min_nano' => 2_001]],
    'expected above max' => [['estimated_price_expected_nano' => 3_001]],
]);

it('refuses an unknown status even when written around the model', function () {
    $generation = Generation::factory()->create();

    DB::update('update generations set status = ? where id = ?', ['cancelled', $generation->id]);
})->throws(QueryException::class, 'generations_status_check');

it('refuses an unknown role even when written around the model', function () {
    $user = User::factory()->create();

    DB::update('update users set role = ? where id = ?', ['owner', $user->id]);
})->throws(QueryException::class, 'users_role_check');

it('keeps provider ids unique', function () {
    Generation::factory()->create(['provider_id' => 'gen_1']);

    Generation::factory()->create(['provider_id' => 'gen_1']);
})->throws(QueryException::class, 'generations_provider_id_unique');

it('keeps webhook event ids unique', function () {
    WebhookEvent::create(['event_id' => 'evt_1', 'type' => 'generation.processing', 'payload' => []]);

    WebhookEvent::create(['event_id' => 'evt_1', 'type' => 'generation.failed', 'payload' => []]);
})->throws(QueryException::class, 'webhook_events_event_id_unique');

it('stores a user as a regular user unless made admin', function () {
    expect(User::factory()->create()->isAdmin())->toBeFalse()
        ->and(User::factory()->admin()->create()->isAdmin())->toBeTrue();
});

it('keeps the history of a deleted user and still knows the owner', function () {
    $generation = Generation::factory()->succeeded()->create();

    $generation->user->delete();

    expect(User::find($generation->user_id))->toBeNull()
        ->and($generation->fresh()->user->trashed())->toBeTrue()
        ->and(Generation::count())->toBe(1);
});

it('refuses to hard delete a user who has generations', function () {
    $generation = Generation::factory()->create();

    $generation->user->forceDelete();
})->throws(QueryException::class, 'generations_user_id_foreign');

it('refuses to hard delete a generation that has webhook events', function () {
    $generation = Generation::factory()->create();
    WebhookEvent::create(['event_id' => 'evt_1', 'generation_id' => $generation->id, 'type' => 'generation.processing', 'payload' => []]);

    $generation->delete();
})->throws(QueryException::class, 'webhook_events_generation_id_foreign');
