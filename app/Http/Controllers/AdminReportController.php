<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\Period;
use App\Admin\ProfitReport;
use App\Admin\ReportRow;
use App\Admin\Summary;
use App\Http\Requests\AdminReportRequest;
use App\Money\Usd;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Money\Money;

class AdminReportController extends Controller
{
    /**
     * What the service earned in a period, optionally for one user: figures with the change against
     * the period before, what is bought, and the days and the users in tables that can be sorted and
     * paged. Money is sent as exact decimal strings and rounded only where it is shown.
     */
    public function __invoke(AdminReportRequest $request, ProfitReport $report): Response
    {
        $now = CarbonImmutable::now('UTC');
        $period = $request->period($now);
        $user = $request->userId();

        $current = $report->summary($period, $user);

        return Inertia::render('Admin/Report', [
            'filters' => [
                'period' => $request->periodName(),
                'from' => $request->validated('from'),
                'to' => $request->validated('to'),
                'user' => $user,
                'search' => $request->search(),
                'daysSort' => $request->sort('days'),
                'daysDir' => $request->direction('days'),
                'usersSort' => $request->sort('users'),
                'usersDir' => $request->direction('users'),
            ],
            'range' => [
                'from' => $period->from?->toDateString(),
                'to' => $period->lastDay()?->toDateString(),
                'days' => $period->days(),
            ],
            'userOptions' => $report->userOptions(),
            'metrics' => $this->metrics($report, $period, $user, $current, $now),
            'totals' => $this->totals($current),
            'breakdown' => [
                'quality' => $this->lines($report->breakdown($period, $user, 'quality')),
                'size' => $this->lines($report->breakdown($period, $user, 'size')),
            ],
            'days' => $this->table($report->days(
                $period,
                $user,
                $request->sort('days'),
                $request->direction('days'),
                $this->perPage(),
                $request->page('days'),
            )),
            'users' => $this->table($report->users(
                $period,
                $user,
                $request->search(),
                $request->sort('users'),
                $request->direction('users'),
                $this->perPage(),
                $request->page('users'),
            )),
            'ledger' => $this->ledger($report),
        ]);
    }

    /**
     * The figures at the top, each with its change against the period of the same length before
     * (none for all time) and its daily values for a small trend line.
     *
     * @return array<string, array<string, mixed>>
     */
    private function metrics(ProfitReport $report, Period $period, ?int $user, Summary $current, CarbonImmutable $now): array
    {
        $previousPeriod = $period->previous();
        $previous = $previousPeriod === null ? null : $report->summary($previousPeriod, $user);
        $series = $report->series($period, $user, $now);

        $money = fn (callable $of) => fn (Summary $summary) => $this->amount($of($summary));
        $change = fn (callable $of) => $previous === null
            ? null
            : Summary::change($of($current)->getAmount(), $of($previous)->getAmount());

        $charged = fn (Summary $summary) => $summary->charged;
        $cost = fn (Summary $summary) => $summary->providerCost;
        $profit = fn (Summary $summary) => $summary->profit();

        $average = $current->averagePrice();
        $previousAverage = $previous?->averagePrice();

        $sold = $report->creditSold($period, $user);
        $previousSold = $previousPeriod === null ? null : $report->creditSold($previousPeriod, $user);

        return [
            'revenue' => [
                'value' => $money($charged)($current),
                'change' => $change($charged),
                'series' => array_map(fn (ReportRow $point) => $this->amount($point->charged), $series),
            ],
            'cost' => [
                'value' => $money($cost)($current),
                'change' => $change($cost),
                'series' => array_map(fn (ReportRow $point) => $this->amount($point->providerCost), $series),
            ],
            'profit' => [
                'value' => $money($profit)($current),
                'change' => $change($profit),
                'margin' => $current->marginPercent(),
                'series' => array_map(fn (ReportRow $point) => $this->amount($point->profit()), $series),
            ],
            'generations' => [
                'value' => $current->generations,
                'change' => $previous === null ? null : Summary::change($current->generations, $previous->generations),
                'failed' => $current->failed,
                'successRate' => $current->successRate(),
                'series' => array_map(fn (ReportRow $point) => $point->generations, $series),
            ],
            'averagePrice' => [
                'value' => $average === null ? null : $this->amount($average),
                'change' => $average === null || $previousAverage === null
                    ? null
                    : Summary::change($average->getAmount(), $previousAverage->getAmount()),
            ],
            'credit' => [
                'sold' => $this->amount($sold),
                'change' => $previousSold === null ? null : Summary::change($sold->getAmount(), $previousSold->getAmount()),
                'held' => $this->amount($report->creditHeld($user)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(Summary $summary): array
    {
        return [
            'generations' => $summary->generations,
            'charged' => $this->amount($summary->charged),
            'providerCost' => $this->amount($summary->providerCost),
            'profit' => $this->amount($summary->profit()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ledger(ProfitReport $report): array
    {
        $check = $report->ledgerCheck();

        return [
            'agrees' => $check->agrees(),
            'onGenerations' => $this->amount($check->onGenerations),
            'inLedger' => $this->amount($check->inLedger),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, ReportRow>  $paginator
     * @return array<string, mixed>
     */
    private function table(LengthAwarePaginator $paginator): array
    {
        return [
            'rows' => $this->lines($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
            ],
        ];
    }

    /**
     * @param  array<int, ReportRow>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(array $rows): array
    {
        return array_map(fn (ReportRow $row) => [
            'key' => $row->key,
            'label' => $row->label,
            'generations' => $row->generations,
            'charged' => $this->amount($row->charged),
            'providerCost' => $this->amount($row->providerCost),
            'profit' => $this->amount($row->profit()),
            'lastActivity' => $row->lastActivity?->toIso8601String(),
            'balance' => $row->balance === null ? null : $this->amount($row->balance),
        ], $rows);
    }

    /** Rows in each table of the page. */
    private function perPage(): int
    {
        return 15;
    }

    private function amount(Money $money): string
    {
        return Usd::format($money);
    }
}
