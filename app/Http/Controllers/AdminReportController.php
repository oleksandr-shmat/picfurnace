<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Admin\ProfitReport;
use App\Admin\ReportRow;
use App\Money\Usd;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    /**
     * What the service earned: per UTC day and per user, with a check that the ledger agrees.
     */
    public function __invoke(ProfitReport $report): Response
    {
        return Inertia::render('Admin/Report', [
            'days' => array_map($this->line(...), $report->perDay()),
            'users' => array_map($this->line(...), $report->perUser()),
            'total' => $this->line($report->total()),
            'ledgerAgrees' => $report->ledgerAgrees(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(ReportRow $row): array
    {
        return [
            'key' => $row->key,
            'label' => $row->label,
            'generations' => $row->generations,
            'charged' => Usd::format($row->charged),
            'providerCost' => Usd::format($row->providerCost),
            'profit' => Usd::format($row->profit()),
        ];
    }
}
