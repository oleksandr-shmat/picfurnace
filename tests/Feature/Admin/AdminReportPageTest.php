<?php

declare(strict_types=1);

use App\Http\Controllers\AdminReportController;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

mutates(AdminReportController::class);

it('shows an admin the report', function () {
    $user = User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.com']);
    $generation = Generation::factory()->succeeded(1_000_000_000, 1_250_000_000)->create([
        'user_id' => $user->id,
        'completed_at' => '2026-11-24 10:00:00',
    ]);
    LedgerEntry::factory()->charge($generation, 1_250_000_000)->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->has('days', 1)
            ->where('days.0.key', '2026-11-24')
            ->where('days.0.generations', 1)
            ->where('days.0.charged', '1.250000000')
            ->where('days.0.providerCost', '1.000000000')
            ->where('days.0.profit', '0.250000000')
            ->has('users', 1)
            ->where('users.0.label', 'Ann (ann@example.com)')
            ->where('users.0.charged', '1.250000000')
            ->where('total.generations', 1)
            ->where('total.charged', '1.250000000')
            ->where('total.providerCost', '1.000000000')
            ->where('total.profit', '0.250000000')
            ->where('ledgerAgrees', true));
});

it('warns when the ledger and the generations disagree', function () {
    Generation::factory()->succeeded(1_000, 1_250)->create(['completed_at' => '2026-11-24 10:00:00']);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page->where('ledgerAgrees', false));
});

it('is not for ordinary users', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

it('is not for guests', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});
