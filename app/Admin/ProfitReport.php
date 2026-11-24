<?php

declare(strict_types=1);

namespace App\Admin;

use App\Billing\LedgerEntryType;
use App\Enums\GenerationStatus;
use App\Generations\GenerationLifecycle;
use App\Money\Usd;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Money\Money;
use stdClass;

/**
 * What the service earned. For succeeded generations: what users were charged and what Pixelforge
 * billed us, in a period of UTC days, optionally for one user. A generation counts on the day it
 * completed, like Pixelforge's own usage. A failed generation is free for both sides, so it only
 * shows as a count.
 *
 * The provider cost of a day should match `GET /v1/usage` of Pixelforge; that is a manual check.
 */
final class ProfitReport
{
    private const string SUMS = 'count(*) as generations, sum(charged_nano) as charged, sum(provider_cost_nano) as provider_cost';

    /**
     * What the table of days can be sorted by, and the SQL behind each name. The user picks a name,
     * never SQL.
     *
     * @return array<string, literal-string>
     */
    public static function daySorts(): array
    {
        return [
            'day' => 'day',
            'generations' => 'generations',
            'charged' => 'charged',
            'provider_cost' => 'provider_cost',
            'profit' => '(sum(charged_nano) - sum(provider_cost_nano))',
        ];
    }

    /**
     * @return array<string, literal-string>
     */
    public static function userSorts(): array
    {
        return [
            'user' => 'lower(users.name)',
            'generations' => 'generations',
            'charged' => 'charged',
            'provider_cost' => 'provider_cost',
            'profit' => '(sum(charged_nano) - sum(provider_cost_nano))',
            'last_activity' => 'last_activity',
            'balance' => 'balance_nano',
        ];
    }

    public function summary(Period $period, ?int $userId = null): Summary
    {
        $line = $this->succeeded($period, $userId)
            ->selectRaw(self::SUMS)
            ->first();

        $failed = $this->finished($period, $userId)
            ->where('status', GenerationStatus::Failed->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('failure_code')
                ->orWhere('failure_code', '!=', GenerationLifecycle::CANCELLED_BY_USER))
            ->count();

        return new Summary(
            (int) $line->generations,
            $failed,
            Usd::nano((string) ($line->charged ?? 0)),
            Usd::nano((string) ($line->provider_cost ?? 0)),
        );
    }

    /**
     * One point per day, also the quiet ones, up to `$today`, for a small trend line. At most the
     * latest 90 days.
     *
     * @return array<int, ReportRow>
     */
    public function series(Period $period, ?int $userId, CarbonImmutable $today): array
    {
        $today = $today->utc()->startOfDay();
        $last = $period->lastDay() === null ? $today : min($period->lastDay(), $today);
        $first = $period->from ?? $this->firstDay($userId);

        if ($first === null) {
            return [];
        }

        // At most the latest 90 days: a longer trend line would be too crowded to read.
        $first = max($first, $last->subDays(89));

        $byDay = $this->succeeded($period, $userId)
            ->selectRaw("to_char(completed_at, 'YYYY-MM-DD') as day, ".self::SUMS)
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $points = [];

        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            $key = $day->toDateString();
            $line = $byDay->get($key) ?? (object) ['generations' => 0, 'charged' => 0, 'provider_cost' => 0];
            $points[] = $this->row($line, $key, null);
        }

        return $points;
    }

    /**
     * @return LengthAwarePaginator<int, ReportRow>
     */
    public function days(
        Period $period,
        ?int $userId = null,
        string $sort = 'day',
        string $direction = 'desc',
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
        return $this->daysQuery($period, $userId, $sort, $direction)
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (stdClass $line) => $this->row($line, $line->day, null));
    }

    /**
     * Every day of the period, not paged: for an export.
     *
     * @return array<int, ReportRow>
     */
    public function allDays(Period $period, ?int $userId = null, string $sort = 'day', string $direction = 'desc'): array
    {
        return $this->daysQuery($period, $userId, $sort, $direction)
            ->get()
            ->map(fn (stdClass $line) => $this->row($line, $line->day, null))
            ->all();
    }

    private function daysQuery(Period $period, ?int $userId, string $sort, string $direction): Builder
    {
        $query = $this->succeeded($period, $userId)
            ->selectRaw("to_char(completed_at, 'YYYY-MM-DD') as day, ".self::SUMS)
            ->groupBy('day');

        $this->sort($query, self::daySorts(), 'day', $sort, $direction, then: 'day desc');

        return $query;
    }

    /**
     * Users with something finished in the period. Users who have left are included: their
     * history stays.
     *
     * @return LengthAwarePaginator<int, ReportRow>
     */
    public function users(
        Period $period,
        ?int $userId = null,
        ?string $search = null,
        string $sort = 'charged',
        string $direction = 'desc',
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
        return $this->usersQuery($period, $userId, $search, $sort, $direction)
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (stdClass $line) => $this->userRow($line));
    }

    /**
     * Every user of the period, not paged: for an export.
     *
     * @return array<int, ReportRow>
     */
    public function allUsers(Period $period, ?int $userId = null, ?string $search = null, string $sort = 'charged', string $direction = 'desc'): array
    {
        return $this->usersQuery($period, $userId, $search, $sort, $direction)
            ->get()
            ->map(fn (stdClass $line) => $this->userRow($line))
            ->all();
    }

    private function usersQuery(Period $period, ?int $userId, ?string $search, string $sort, string $direction): Builder
    {
        $query = $this->succeeded($period, $userId)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw(
                'users.id as user_id, users.name, users.email, '.self::SUMS.', '
                .'max(generations.completed_at) as last_activity, '
                .'(select coalesce(sum(amount_nano), 0) from credit_ledger where credit_ledger.user_id = users.id) as balance_nano',
            )
            ->groupBy('users.id', 'users.name', 'users.email');

        if ($search !== null) {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $inner) => $inner
                ->where('users.name', 'ilike', $pattern)
                ->orWhere('users.email', 'ilike', $pattern));
        }

        $this->sort($query, self::userSorts(), 'charged', $sort, $direction, then: 'users.id');

        return $query;
    }

    private function userRow(stdClass $line): ReportRow
    {
        return $this->row($line, (string) $line->user_id, "{$line->name} ({$line->email})");
    }

    /**
     * What is bought: succeeded generations per value of a column, the biggest revenue first.
     *
     * @param  string  $column  'quality' or 'size'
     * @return array<int, ReportRow>
     */
    public function breakdown(Period $period, ?int $userId, string $column): array
    {
        if (! in_array($column, ['quality', 'size'], true)) {
            throw new InvalidArgumentException("Cannot break the report down by [{$column}].");
        }

        return $this->succeeded($period, $userId)
            ->selectRaw("{$column} as value, ".self::SUMS)
            ->groupBy($column)
            ->orderByRaw('sum(charged_nano) desc')
            ->orderBy($column)
            ->get()
            ->map(fn (stdClass $line) => $this->row($line, (string) $line->value, null))
            ->all();
    }

    /**
     * Credit users bought in the period (a grant is a gift, not a sale).
     */
    public function creditSold(Period $period, ?int $userId = null): Money
    {
        $query = DB::table('credit_ledger')->where('type', LedgerEntryType::Purchase->value);

        if ($period->from !== null) {
            $query->where('created_at', '>=', $period->from)->where('created_at', '<', $period->to);
        }

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return Usd::nano((string) $query->sum('amount_nano'));
    }

    /**
     * Credit users still hold now. A user below zero (a generation cost more than was held) holds
     * none.
     */
    public function creditHeld(?int $userId = null): Money
    {
        $balances = DB::table('credit_ledger')
            ->selectRaw('user_id, sum(amount_nano) as balance')
            ->groupBy('user_id');

        if ($userId !== null) {
            $balances->where('user_id', $userId);
        }

        $held = DB::query()
            ->fromSub($balances, 'balances')
            ->sum(DB::raw('greatest(balance, 0)'));

        return Usd::nano((string) $held);
    }

    /**
     * Checks that no money was moved around the ledger: all time, whatever the filters are.
     */
    public function ledgerCheck(): LedgerCheck
    {
        $onGenerations = DB::table('generations')
            ->where('status', GenerationStatus::Succeeded->value)
            ->sum('charged_nano');
        $inLedger = DB::table('credit_ledger')
            ->where('type', LedgerEntryType::Charge->value)
            ->sum('amount_nano');

        return new LedgerCheck(
            Usd::nano((string) $onGenerations),
            Usd::nano((string) $inLedger)->negative(),
        );
    }

    /**
     * Everybody, to choose one from. Users who have left are included.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function userOptions(): array
    {
        return DB::table('users')
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->map(fn (stdClass $user) => ['id' => (int) $user->id, 'label' => "{$user->name} ({$user->email})"])
            ->all();
    }

    /**
     * Generations that ended in the period (completed, succeeded or not).
     */
    private function finished(Period $period, ?int $userId): Builder
    {
        $query = DB::table('generations');

        if ($period->from !== null) {
            $query->where('generations.completed_at', '>=', $period->from)
                ->where('generations.completed_at', '<', $period->to);
        }

        if ($userId !== null) {
            $query->where('generations.user_id', $userId);
        }

        return $query;
    }

    private function succeeded(Period $period, ?int $userId): Builder
    {
        return $this->finished($period, $userId)->where('generations.status', GenerationStatus::Succeeded->value);
    }

    private function firstDay(?int $userId): ?CarbonImmutable
    {
        $first = $this->succeeded(Period::all(), $userId)->min('completed_at');

        return $first === null ? null : CarbonImmutable::parse($first, 'UTC')->startOfDay();
    }

    /**
     * Sorts by a column from a fixed list: the user chooses a name, never SQL.
     *
     * @param  array<string, literal-string>  $columns
     * @param  literal-string  $then
     */
    private function sort(Builder $query, array $columns, string $default, string $sort, string $direction, string $then): void
    {
        $column = $columns[$sort] ?? $columns[$default];
        $order = $direction === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw("{$column} {$order}")->orderByRaw($then);
    }

    private function row(stdClass $line, string $key, ?string $label): ReportRow
    {
        return new ReportRow(
            $key,
            $label,
            (int) $line->generations,
            Usd::nano((string) ($line->charged ?? 0)),
            Usd::nano((string) ($line->provider_cost ?? 0)),
            isset($line->last_activity) ? CarbonImmutable::parse($line->last_activity, 'UTC') : null,
            isset($line->balance_nano) ? Usd::nano((string) $line->balance_nano) : null,
            $line->name ?? null,
            $line->email ?? null,
        );
    }
}
