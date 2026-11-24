<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Admin\Period;
use App\Admin\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the admin report is asked to show: a period (a preset or two dates), maybe one user, a search
 * in the users table, and how each table is sorted and paged. Everything is in the query string, so
 * a view of the report can be shared as a link.
 */
class AdminReportRequest extends FormRequest
{
    public const string DEFAULT_PERIOD = '30d';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $direction = ['nullable', Rule::in(['asc', 'desc'])];

        return [
            'period' => ['nullable', Rule::in([...Period::presets(), 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'q' => ['nullable', 'string', 'max:100'],
            'days_page' => ['nullable', 'integer', 'min:1'],
            'users_page' => ['nullable', 'integer', 'min:1'],
            'days_sort' => ['nullable', Rule::in(array_keys(ProfitReport::daySorts()))],
            'days_dir' => $direction,
            'users_sort' => ['nullable', Rule::in(array_keys(ProfitReport::userSorts()))],
            'users_dir' => $direction,
        ];
    }

    public function periodName(): string
    {
        return $this->validated('period') ?? self::DEFAULT_PERIOD;
    }

    public function period(CarbonImmutable $now): Period
    {
        if ($this->periodName() === 'custom') {
            return Period::between(
                CarbonImmutable::parse($this->validated('from'), 'UTC'),
                CarbonImmutable::parse($this->validated('to'), 'UTC'),
            );
        }

        return Period::preset($this->periodName(), $now);
    }

    public function userId(): ?int
    {
        $user = $this->validated('user');

        return $user === null ? null : (int) $user;
    }

    public function search(): ?string
    {
        return $this->validated('q');
    }

    /**
     * @param  'days'|'users'  $table
     */
    public function sort(string $table): string
    {
        return $this->validated("{$table}_sort") ?? ($table === 'days' ? 'day' : 'charged');
    }

    /**
     * @param  'days'|'users'  $table
     */
    public function direction(string $table): string
    {
        return $this->validated("{$table}_dir") ?? 'desc';
    }

    /**
     * @param  'days'|'users'  $table
     */
    public function page(string $table): int
    {
        return (int) ($this->validated("{$table}_page") ?? 1);
    }
}
