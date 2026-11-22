<?php

declare(strict_types=1);

namespace App\Pixelforge\Api;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Checks for values read from a decoded Pixelforge JSON payload.
 *
 * Decoded JSON is untyped, so each value is checked here and returned with a real type. A failed
 * check throws InvalidArgumentException naming the field.
 */
final class Payload
{
    public static function nonNegativeInt(mixed $value, string $name): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Invalid [{$name}]: expected a non-negative integer.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function nonEmptyArray(mixed $value, string $name): array
    {
        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException("Invalid [{$name}]: expected a non-empty object.");
        }

        return $value;
    }

    public static function nonEmptyString(mixed $value, string $name): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Invalid [{$name}]: expected a non-empty string.");
        }

        return $value;
    }

    public static function nullableString(mixed $value, string $name): ?string
    {
        return $value === null ? null : self::nonEmptyString($value, $name);
    }

    /**
     * @param  string  $name  Field name used in the error message.
     * @param  mixed  $value  ISO 8601 text such as "2026-11-04T14:03:11Z", or null.
     */
    public static function nullableTimestamp(mixed $value, string $name): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        // Carbon throws InvalidArgumentException itself for text that is not a date.
        return CarbonImmutable::parse(self::nonEmptyString($value, $name));
    }
}
