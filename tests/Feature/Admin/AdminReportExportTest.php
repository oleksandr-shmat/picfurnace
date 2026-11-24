<?php

declare(strict_types=1);

use App\Http\Controllers\AdminReportExportController;
use App\Http\Requests\AdminReportExportRequest;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

mutates(AdminReportExportController::class, AdminReportExportRequest::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-24 12:00:00');
    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function exportOf(array $query): TestResponse
{
    return test()->actingAs(test()->admin)->get(route('admin.report.export', $query));
}

/** @return list<list<string>> the rows of the downloaded CSV, header first */
function csvRows(TestResponse $response): array
{
    return array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));
}

function doneAt(string $completedAt, int $providerCost, int $charged, ?User $user = null): Generation
{
    return Generation::factory()->succeeded($providerCost, $charged)->create([
        'user_id' => ($user ?? User::factory()->create())->id,
        'completed_at' => $completedAt,
    ]);
}

it('downloads the days as a CSV with exact amounts', function () {
    doneAt('2026-11-20 10:00:00', 1_000, 1_500);
    doneAt('2026-11-20 11:00:00', 2_000, 2_500);
    doneAt('2026-11-21 10:00:00', 1_000, 1_500);

    $response = exportOf(['type' => 'days', 'period' => '7d']);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('picfurnace-days-2026-11-18_2026-11-24.csv');
    expect(csvRows($response))->toBe([
        ['day', 'generations', 'charged_usd', 'provider_cost_usd', 'profit_usd'],
        ['2026-11-21', '1', '0.000001500', '0.000001000', '0.000000500'],
        ['2026-11-20', '2', '0.000004000', '0.000003000', '0.000001000'],
    ]);
});

it('downloads the users as a CSV', function () {
    $ann = User::factory()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
    doneAt('2026-11-20 10:00:00', 1_000, 1_500, $ann);
    doneAt('2026-11-21 09:30:00', 1_000, 1_500, $ann);
    LedgerEntry::factory()->create(['user_id' => $ann->id, 'amount_nano' => 900]);

    $rows = csvRows(exportOf(['type' => 'users']));

    expect($rows)->toBe([
        ['user_id', 'name', 'email', 'generations', 'charged_usd', 'provider_cost_usd', 'profit_usd', 'last_finished_utc', 'credit_usd'],
        [(string) $ann->id, 'Ann Lee', 'ann@example.com', '2', '0.000003000', '0.000002000', '0.000001000', '2026-11-21 09:30:00', '0.000000900'],
    ]);
});

it('has every row, not only a page', function () {
    foreach (range(1, 20) as $day) {
        doneAt('2026-11-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00:00', 1_000, 1_500);
    }

    // a header and 20 days
    expect(csvRows(exportOf(['type' => 'days', 'period' => 'month'])))->toHaveCount(21);
});

it('follows the filters of the report', function () {
    $ann = User::factory()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
    doneAt('2026-11-20 10:00:00', 1_000, 1_500, $ann);
    doneAt('2026-11-20 10:00:00', 1_000, 1_500, User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']));
    doneAt('2026-10-01 10:00:00', 1_000, 1_500, $ann);

    expect(csvRows(exportOf(['type' => 'users', 'q' => 'lee'])))->toHaveCount(2)
        ->and(csvRows(exportOf(['type' => 'days', 'user' => $ann->id, 'period' => '7d'])))->toHaveCount(2)
        ->and(csvRows(exportOf(['type' => 'days', 'user' => $ann->id, 'period' => 'all'])))->toHaveCount(3);
});

it('follows the sort of the report', function () {
    doneAt('2026-11-10 10:00:00', 1_000, 1_500);
    doneAt('2026-11-11 10:00:00', 1_000, 1_500);

    $rows = csvRows(exportOf(['type' => 'days', 'days_sort' => 'day', 'days_dir' => 'asc']));

    expect([$rows[1][0], $rows[2][0]])->toBe(['2026-11-10', '2026-11-11']);
});

it('names the file after what it holds, also for all time and for a range of dates', function () {
    expect(exportOf(['type' => 'users', 'period' => 'all'])->assertDownload('picfurnace-users-all-time.csv'))
        ->and(exportOf(['type' => 'days', 'period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-05'])->assertDownload('picfurnace-days-2026-11-01_2026-11-05.csv'));
});

it('keeps a spreadsheet from running a name as a formula', function (string $name, string $expected) {
    doneAt('2026-11-20 10:00:00', 1_000, 1_500, User::factory()->create(['name' => $name, 'email' => 'x@example.com']));

    expect(csvRows(exportOf(['type' => 'users']))[1][1])->toBe($expected);
})->with([
    'equals' => ['=HYPERLINK("http://evil")', "'=HYPERLINK(\"http://evil\")"],
    'plus' => ['+1+1', "'+1+1"],
    'minus' => ['-2', "'-2"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'a plain name' => ['Ann', 'Ann'],
    'a dash inside' => ['Ann-Lee', 'Ann-Lee'],
]);

it('copes with a user whose name is empty', function () {
    doneAt('2026-11-20 10:00:00', 1_000, 1_500, User::factory()->create(['name' => '', 'email' => 'x@example.com']));

    expect(csvRows(exportOf(['type' => 'users']))[1][1])->toBe('');
});

it('quotes commas and quotes in a name', function () {
    doneAt('2026-11-20 10:00:00', 1_000, 1_500, User::factory()->create(['name' => 'Lee, "Ann"', 'email' => 'x@example.com']));

    expect(csvRows(exportOf(['type' => 'users']))[1][1])->toBe('Lee, "Ann"');
});

it('needs to know what to download', function (array $query) {
    exportOf($query)->assertSessionHasErrors('type');
})->with([
    'nothing' => [[]],
    'something else' => [['type' => 'passwords']],
]);

it('refuses what the report refuses', function () {
    exportOf(['type' => 'days', 'period' => 'fortnight'])->assertSessionHasErrors('period');
});

it('is not for ordinary users', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.report.export', ['type' => 'days']))->assertForbidden();
});

it('is not for guests', function () {
    $this->get(route('admin.report.export', ['type' => 'days']))->assertRedirect(route('login'));
});
