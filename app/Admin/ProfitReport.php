<?php

declare(strict_types=1);

namespace App\Admin;

use App\Billing\LedgerEntryType;
use App\Enums\GenerationStatus;
use App\Money\Usd;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the service earned: for succeeded generations, what users were charged and what Pixelforge
 * billed us, added up per UTC day (by the day the generation completed, like Pixelforge's own
 * usage) and per user. A failed generation is free for both sides and is left out.
 *
 * The provider cost per day should match `GET /v1/usage` of Pixelforge; that is a manual check.
 */
final class ProfitReport
{
    private const string SUMS = 'count(*) as generations, sum(charged_nano) as charged, sum(provider_cost_nano) as provider_cost';

    /**
     * @return array<int, ReportRow> newest day first
     */
    public function perDay(int $limit = 90): array
    {
        return $this->succeeded()
            ->selectRaw("to_char(completed_at, 'YYYY-MM-DD') as day, ".self::SUMS)
            ->groupBy('day')
            ->orderByDesc('day')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $line) => $this->row($line, $line->day, null))
            ->all();
    }

    /**
     * Users who have left are included: their history stays.
     *
     * @return array<int, ReportRow> biggest spender first
     */
    public function perUser(): array
    {
        return $this->succeeded()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw('users.id as user_id, users.name, users.email, '.self::SUMS)
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByRaw('sum(charged_nano) desc')
            ->orderBy('users.id')
            ->get()
            ->map(fn (stdClass $line) => $this->row($line, (string) $line->user_id, "{$line->name} ({$line->email})"))
            ->all();
    }

    public function total(): ReportRow
    {
        $line = $this->succeeded()->selectRaw(self::SUMS)->first();

        return $this->row($line, 'total', null);
    }

    /**
     * True when the ledger holds exactly the charges written on the generations: a check that no
     * money was moved around the ledger.
     */
    public function ledgerAgrees(): bool
    {
        $onGenerations = $this->succeeded()->sum('charged_nano');
        $inLedger = DB::table('credit_ledger')->where('type', LedgerEntryType::Charge->value)->sum('amount_nano');

        return Usd::nano((string) $onGenerations)->equals(Usd::nano((string) $inLedger)->negative());
    }

    private function succeeded(): Builder
    {
        return DB::table('generations')->where('generations.status', GenerationStatus::Succeeded->value);
    }

    private function row(stdClass $line, string $key, ?string $label): ReportRow
    {
        return new ReportRow(
            $key,
            $label,
            (int) $line->generations,
            Usd::nano((string) ($line->charged ?? 0)),
            Usd::nano((string) ($line->provider_cost ?? 0)),
        );
    }
}
