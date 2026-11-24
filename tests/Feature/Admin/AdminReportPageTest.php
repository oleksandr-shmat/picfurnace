<?php

declare(strict_types=1);

use App\Http\Controllers\AdminReportController;
use App\Http\Requests\AdminReportRequest;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

mutates(AdminReportController::class, AdminReportRequest::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-24 12:00:00');
    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function reportAt(array $query = []): TestResponse
{
    return test()->actingAs(test()->admin)->get(route('admin.report', $query));
}

function finishedAt(string $completedAt, int $providerCost, int $charged, ?User $user = null, array $override = []): Generation
{
    return Generation::factory()->succeeded($providerCost, $charged)->create($override + [
        'user_id' => ($user ?? User::factory()->create())->id,
        'completed_at' => $completedAt,
    ]);
}

it('shows the last 30 days by default', function () {
    reportAt()->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Report')
        ->where('filters.period', '30d')
        ->where('range.from', '2026-10-26')
        ->where('range.to', '2026-11-24')
        ->where('range.days', 30));
});

it('works out every preset', function (string $preset, ?string $from, ?string $to, ?int $days) {
    reportAt(['period' => $preset])->assertInertia(fn (Assert $page) => $page
        ->where('filters.period', $preset)
        ->where('range.from', $from)
        ->where('range.to', $to)
        ->where('range.days', $days));
})->with([
    'today' => ['today', '2026-11-24', '2026-11-24', 1],
    'a week' => ['7d', '2026-11-18', '2026-11-24', 7],
    'this month' => ['month', '2026-11-01', '2026-11-30', 30],
    'last month' => ['last_month', '2026-10-01', '2026-10-31', 31],
    'all time' => ['all', null, null, null],
]);

it('takes a range of dates', function () {
    reportAt(['period' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-12'])->assertInertia(fn (Assert $page) => $page
        ->where('filters.period', 'custom')
        ->where('filters.from', '2026-11-10')
        ->where('filters.to', '2026-11-12')
        ->where('range.days', 3));
});

it('refuses a bad filter', function (array $query, string $field) {
    reportAt($query)->assertSessionHasErrors($field);
})->with([
    'unknown preset' => [['period' => 'fortnight'], 'period'],
    'custom without dates' => [['period' => 'custom'], 'from'],
    'custom without an end' => [['period' => 'custom', 'from' => '2026-11-10'], 'to'],
    'not a date' => [['period' => 'custom', 'from' => 'yesterday', 'to' => '2026-11-12'], 'from'],
    'end before start' => [['period' => 'custom', 'from' => '2026-11-12', 'to' => '2026-11-10'], 'to'],
    'unknown user' => [['user' => 999_999], 'user'],
    'user is not a number' => [['user' => 'ann'], 'user'],
    'page zero' => [['days_page' => 0], 'days_page'],
    'unknown sort' => [['days_sort' => 'password'], 'days_sort'],
    'unknown direction' => [['users_dir' => 'sideways'], 'users_dir'],
    'search too long' => [['q' => str_repeat('a', 101)], 'q'],
    'search is a list' => [['q' => ['a']], 'q'],
    'days page is not a number' => [['days_page' => 'abc'], 'days_page'],
    'users page is not a number' => [['users_page' => 'abc'], 'users_page'],
    'users page zero' => [['users_page' => 0], 'users_page'],
    'a date in another format' => [['period' => 'custom', 'from' => '10.11.2026', 'to' => '2026-11-12'], 'from'],
    'a date that does not exist' => [['period' => 'custom', 'from' => '2026-13-45', 'to' => '2026-11-12'], 'from'],
    'an end in another format' => [['period' => 'custom', 'from' => '2026-11-10', 'to' => '12.11.2026'], 'to'],
    'unknown users sort' => [['users_sort' => 'password'], 'users_sort'],
    'unknown days direction' => [['days_dir' => 'up'], 'days_dir'],
    'the SQL behind a days sort is not a sort' => [['days_sort' => '(sum(charged_nano) - sum(provider_cost_nano))'], 'days_sort'],
    'the SQL behind a users sort is not a sort' => [['users_sort' => 'lower(users.name)'], 'users_sort'],
]);

it('accepts every sort the tables offer', function (string $table, string $sort) {
    reportAt(["{$table}_sort" => $sort, "{$table}_dir" => 'asc'])->assertOk()->assertSessionHasNoErrors();
})->with([
    ...array_map(fn ($sort) => ['days', $sort], ['day', 'generations', 'charged', 'provider_cost', 'profit']),
    ...array_map(fn ($sort) => ['users', $sort], ['user', 'generations', 'charged', 'provider_cost', 'profit', 'last_activity', 'balance']),
]);

it('treats a cleared field of the form as no filter at all', function () {
    reportAt([
        'period' => '', 'from' => '', 'to' => '', 'user' => '', 'q' => '',
        'days_page' => '', 'users_page' => '',
        'days_sort' => '', 'days_dir' => '', 'users_sort' => '', 'users_dir' => '',
    ])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.period', '30d')
        ->where('filters.user', null)
        ->where('filters.search', null)
        ->where('filters.daysSort', 'day')
        ->where('filters.daysDir', 'desc')
        ->where('filters.usersSort', 'charged')
        ->where('filters.usersDir', 'desc')
        ->where('days.meta.page', 1)
        ->where('users.meta.page', 1));
});

it('hands the sort of each table to the page', function () {
    reportAt(['days_sort' => 'profit', 'days_dir' => 'asc', 'users_sort' => 'balance', 'users_dir' => 'asc'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.daysSort', 'profit')
            ->where('filters.daysDir', 'asc')
            ->where('filters.usersSort', 'balance')
            ->where('filters.usersDir', 'asc'));
});

it('pages the users table on its own', function () {
    foreach (range(1, 17) as $i) {
        finishedAt('2026-11-20 10:00:00', 1_000, $i * 1_000, User::factory()->create(['name' => 'User '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]));
    }

    reportAt(['users_page' => 2])->assertInertia(fn (Assert $page) => $page
        ->where('users.meta.page', 2)
        ->where('users.meta.lastPage', 2)
        ->where('users.meta.total', 17)
        ->has('users.rows', 2)
        ->where('days.meta.page', 1));
});

it('shows the figures of the period with the change against the one before', function () {
    // previous 7 days: 2026-11-11 .. 2026-11-17, current 7 days: 2026-11-18 .. 2026-11-24
    finishedAt('2026-11-12 10:00:00', 800, 1_000);
    finishedAt('2026-11-20 10:00:00', 1_000, 1_500);
    finishedAt('2026-11-21 10:00:00', 1_000, 1_500);
    Generation::factory()->failed()->create(['completed_at' => '2026-11-22 10:00:00']);

    reportAt(['period' => '7d'])->assertInertia(fn (Assert $page) => $page
        ->where('metrics.revenue.value', '0.000003000')
        ->where('metrics.revenue.change', '+200.0')
        ->where('metrics.cost.value', '0.000002000')
        ->where('metrics.cost.change', '+150.0')
        ->where('metrics.profit.value', '0.000001000')
        ->where('metrics.profit.change', '+400.0')
        ->where('metrics.profit.margin', '33.3')
        ->where('metrics.generations.value', 2)
        ->where('metrics.generations.change', '+100.0')
        ->where('metrics.generations.failed', 1)
        ->where('metrics.generations.successRate', '66.7')
        ->where('metrics.averagePrice.value', '0.000001500')
        ->where('metrics.averagePrice.change', '+50.0'));
});

it('gives the daily values for the small trend lines, one per day', function () {
    finishedAt('2026-11-23 10:00:00', 1_000, 1_500);

    reportAt(['period' => '7d'])->assertInertia(fn (Assert $page) => $page
        ->has('metrics.revenue.series', 7)
        ->where('metrics.revenue.series.5', '0.000001500')
        ->where('metrics.revenue.series.6', '0.000000000')
        ->where('metrics.generations.series.5', 1)
        ->where('metrics.profit.series.5', '0.000000500')
        ->where('metrics.cost.series.5', '0.000001000'));
});

it('has no change figures for all time, and nothing to compare with when the period before is empty', function () {
    finishedAt('2026-11-23 10:00:00', 1_000, 1_500);

    reportAt(['period' => 'all'])->assertInertia(fn (Assert $page) => $page
        ->where('metrics.revenue.change', null)
        ->where('metrics.averagePrice.change', null)
        ->where('metrics.revenue.value', '0.000001500'));

    reportAt(['period' => '7d'])->assertInertia(fn (Assert $page) => $page
        ->where('metrics.revenue.change', null));
});

it('has empty figures and no average price for a period without anything', function () {
    reportAt(['period' => '7d'])->assertInertia(fn (Assert $page) => $page
        ->where('metrics.revenue.value', '0.000000000')
        ->where('metrics.profit.margin', null)
        ->where('metrics.generations.value', 0)
        ->where('metrics.generations.successRate', null)
        ->where('metrics.averagePrice.value', null)
        ->has('days.rows', 0)
        ->has('users.rows', 0));
});

it('shows the credit sold in the period and the credit users still hold', function () {
    $ann = User::factory()->create();
    LedgerEntry::factory()->create(['user_id' => $ann->id, 'type' => 'purchase', 'amount_nano' => 5_000, 'created_at' => '2026-11-20 10:00:00']);
    LedgerEntry::factory()->create(['user_id' => $ann->id, 'type' => 'purchase', 'amount_nano' => 3_000, 'created_at' => '2026-11-12 10:00:00']);

    reportAt(['period' => '7d'])->assertInertia(fn (Assert $page) => $page
        ->where('metrics.credit.sold', '0.000005000')
        ->where('metrics.credit.change', '+66.7')
        ->where('metrics.credit.held', '0.000008000'));
});

it('limits everything to the user who is chosen, and offers everybody to choose from', function () {
    $ann = User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.com']);
    finishedAt('2026-11-20 10:00:00', 1_000, 1_500, $ann);
    finishedAt('2026-11-20 10:00:00', 4_000, 5_000);
    LedgerEntry::factory()->create(['user_id' => $ann->id, 'amount_nano' => 700]);

    reportAt(['user' => $ann->id])->assertInertia(fn (Assert $page) => $page
        ->where('filters.user', $ann->id)
        ->where('metrics.revenue.value', '0.000001500')
        ->where('metrics.credit.held', '0.000000700')
        ->has('users.rows', 1)
        ->where('users.rows.0.label', 'Ann (ann@example.com)')
        ->has('userOptions', 3));
});

it('lists the days and the users with their figures, a total of the period, and pages', function () {
    $ann = User::factory()->create(['name' => 'Ann', 'email' => 'ann@example.com']);
    finishedAt('2026-11-20 10:00:00', 1_000, 1_500, $ann);
    finishedAt('2026-11-21 10:00:00', 1_000, 2_500, $ann);
    LedgerEntry::factory()->create(['user_id' => $ann->id, 'amount_nano' => 900]);

    reportAt()->assertInertia(fn (Assert $page) => $page
        ->where('days.rows.0.key', '2026-11-21')
        ->where('days.rows.0.generations', 1)
        ->where('days.rows.0.charged', '0.000002500')
        ->where('days.rows.0.providerCost', '0.000001000')
        ->where('days.rows.0.profit', '0.000001500')
        ->where('days.meta.total', 2)
        ->where('days.meta.page', 1)
        ->where('days.meta.lastPage', 1)
        ->where('days.meta.perPage', 15)
        ->where('users.rows.0.label', 'Ann (ann@example.com)')
        ->where('users.rows.0.generations', 2)
        ->where('users.rows.0.lastActivity', '2026-11-21T10:00:00+00:00')
        ->where('users.rows.0.balance', '0.000000900')
        ->where('totals.generations', 2)
        ->where('totals.charged', '0.000004000')
        ->where('totals.providerCost', '0.000002000')
        ->where('totals.profit', '0.000002000'));
});

it('sorts and pages the tables from the query', function () {
    foreach (range(10, 14) as $day) {
        finishedAt("2026-11-{$day} 10:00:00", 1_000, 1_500);
    }

    reportAt(['period' => 'month', 'days_sort' => 'day', 'days_dir' => 'asc', 'days_page' => 2])->assertInertia(fn (Assert $page) => $page
        ->where('filters.daysSort', 'day')
        ->where('filters.daysDir', 'asc')
        ->where('days.meta.page', 2)
        ->has('days.rows', 0));

    reportAt(['period' => 'month', 'days_sort' => 'day', 'days_dir' => 'asc'])->assertInertia(fn (Assert $page) => $page
        ->where('days.rows.0.key', '2026-11-10')
        ->where('days.rows.4.key', '2026-11-14'));
});

it('searches the users table', function () {
    finishedAt('2026-11-20 10:00:00', 1_000, 1_500, User::factory()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']));
    finishedAt('2026-11-20 10:00:00', 1_000, 1_500, User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']));

    reportAt(['q' => 'lee'])->assertInertia(fn (Assert $page) => $page
        ->where('filters.search', 'lee')
        ->has('users.rows', 1)
        ->where('users.meta.total', 1)
        ->has('days.rows', 1));
});

it('breaks the sales down by quality and by size', function () {
    finishedAt('2026-11-20 10:00:00', 1_000, 1_500, null, ['quality' => 'high', 'size' => '1536x1024']);
    finishedAt('2026-11-20 10:00:00', 1_000, 1_500, null, ['quality' => 'low', 'size' => '1536x1024']);

    reportAt()->assertInertia(fn (Assert $page) => $page
        ->has('breakdown.quality', 2)
        ->where('breakdown.quality.0.key', 'high')
        ->where('breakdown.quality.0.charged', '0.000001500')
        ->has('breakdown.size', 1)
        ->where('breakdown.size.0.key', '1536x1024')
        ->where('breakdown.size.0.generations', 2));
});

it('warns with both sums when the ledger and the generations disagree', function () {
    finishedAt('2026-11-20 10:00:00', 1_000, 1_250);

    reportAt()->assertInertia(fn (Assert $page) => $page
        ->where('ledger.agrees', false)
        ->where('ledger.onGenerations', '0.000001250')
        ->where('ledger.inLedger', '0.000000000'));
});

it('says the ledger agrees', function () {
    $generation = finishedAt('2026-11-20 10:00:00', 1_000, 1_250);
    LedgerEntry::factory()->charge($generation, 1_250)->create();

    reportAt()->assertInertia(fn (Assert $page) => $page->where('ledger.agrees', true));
});

it('is not for ordinary users', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

it('is not for guests', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
});
