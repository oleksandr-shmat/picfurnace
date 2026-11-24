<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\Period;
use App\Admin\ProfitReport;
use App\Admin\ReportRow;
use App\Http\Requests\AdminReportExportRequest;
use App\Money\Usd;
use Carbon\CarbonImmutable;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportExportController extends Controller
{
    /**
     * The days or the users of the report as a CSV file, with the filters and the sort of the page
     * but all rows. Amounts are exact decimals, in dollars.
     */
    public function __invoke(AdminReportExportRequest $request, ProfitReport $report): StreamedResponse
    {
        $period = $request->period(CarbonImmutable::now('UTC'));
        $user = $request->userId();
        $type = $request->type();

        $header = $type === 'days'
            ? ['day', 'generations', 'charged_usd', 'provider_cost_usd', 'profit_usd']
            : ['user_id', 'name', 'email', 'generations', 'charged_usd', 'provider_cost_usd', 'profit_usd', 'last_finished_utc', 'credit_usd'];

        $rows = $type === 'days'
            ? $report->allDays($period, $user, $request->sort('days'), $request->direction('days'))
            : $report->allUsers($period, $user, $request->search(), $request->sort('users'), $request->direction('users'));

        return response()->streamDownload(function () use ($header, $rows, $type) {
            $out = new SplFileObject('php://output', 'w');
            $out->fputcsv($header);

            foreach ($rows as $row) {
                $out->fputcsv($type === 'days' ? $this->dayLine($row) : $this->userLine($row));
            }
        }, $this->filename($type, $period), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string|int>
     */
    private function dayLine(ReportRow $row): array
    {
        return [
            $row->key,
            $row->generations,
            Usd::format($row->charged),
            Usd::format($row->providerCost),
            Usd::format($row->profit()),
        ];
    }

    /**
     * @return list<string|int>
     */
    private function userLine(ReportRow $row): array
    {
        return [
            $row->key,
            $this->safe($row->name ?? ''),
            $this->safe($row->email ?? ''),
            $row->generations,
            Usd::format($row->charged),
            Usd::format($row->providerCost),
            Usd::format($row->profit()),
            $row->lastActivity?->toDateTimeString() ?? '',
            Usd::format($row->balance ?? Usd::zero()),
        ];
    }

    /**
     * A spreadsheet runs a cell that starts with = + - or @ as a formula, and a name is typed by
     * whoever signed up: an apostrophe in front keeps it text.
     */
    private function safe(string $text): string
    {
        return $text !== '' && str_contains('=+-@', $text[0]) ? "'{$text}" : $text;
    }

    private function filename(string $type, Period $period): string
    {
        $span = $period->isAll()
            ? 'all-time'
            : $period->from->toDateString().'_'.$period->lastDay()->toDateString();

        return "picfurnace-{$type}-{$span}.csv";
    }
}
