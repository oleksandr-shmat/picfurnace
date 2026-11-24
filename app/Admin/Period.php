<?php

declare(strict_types=1);

namespace App\Admin;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A stretch of whole UTC days for the report: from the start of the first day up to (not
 * including) the start of the day after the last one. "All time" has no limits.
 *
 * Days are UTC because Pixelforge reports its own usage per UTC day, and the numbers of a day
 * should be comparable with it.
 */
final readonly class Period
{
    /**
     * The names `preset()` understands.
     *
     * @return list<string>
     */
    public static function presets(): array
    {
        return ['today', '7d', '30d', 'month', 'last_month', 'all'];
    }

    private function __construct(
        /** Start of the first day, included. Null for all time. */
        public ?CarbonImmutable $from,
        /** Start of the day after the last one, excluded. Null for all time. */
        public ?CarbonImmutable $to,
    ) {}

    public static function all(): self
    {
        return new self(null, null);
    }

    /**
     * Both dates are included.
     */
    public static function between(CarbonImmutable $first, CarbonImmutable $last): self
    {
        $from = self::startOfDay($first);
        $until = self::startOfDay($last);

        if ($until < $from) {
            throw new InvalidArgumentException('The last day of a period cannot be before the first.');
        }

        return new self($from, $until->addDay());
    }

    public static function preset(string $name, CarbonImmutable $now): self
    {
        $today = self::startOfDay($now);

        return match ($name) {
            'today' => new self($today, $today->addDay()),
            '7d' => new self($today->subDays(6), $today->addDay()),
            '30d' => new self($today->subDays(29), $today->addDay()),
            'month' => new self($today->startOfMonth(), $today->startOfMonth()->addMonth()),
            'last_month' => new self($today->startOfMonth()->subMonth(), $today->startOfMonth()),
            'all' => self::all(),
            default => throw new InvalidArgumentException("Unknown period [{$name}]."),
        };
    }

    public function isAll(): bool
    {
        return $this->from === null;
    }

    /**
     * The number of days, or null for all time.
     */
    public function days(): ?int
    {
        return $this->from === null ? null : (int) $this->from->diffInDays($this->to);
    }

    /**
     * The last day that is part of the period, or null for all time.
     */
    public function lastDay(): ?CarbonImmutable
    {
        return $this->to?->subDay();
    }

    /**
     * The period of the same length right before this one, to compare with. None for all time.
     */
    public function previous(): ?self
    {
        if ($this->from === null) {
            return null;
        }

        return new self($this->from->subDays($this->days()), $this->from);
    }

    private static function startOfDay(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->utc()->startOfDay();
    }
}
