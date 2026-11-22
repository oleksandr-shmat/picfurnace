<?php

declare(strict_types=1);

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * The response of GET /v1/pricing: current rates plus the token rules needed to estimate a cost.
 *
 * Everything is read from the API instead of being hardcoded, so an estimate follows the provider.
 */
final readonly class Pricing
{
    private const float FLOAT_ROUNDING_ERROR = 1e-6;

    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{0: int, 1: int}>  $reasoningRanges  quality => [min, max]
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textVariancePercent,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningRanges,
    ) {}

    /**
     * Normalisation boundary: turns the untyped decoded JSON of GET /v1/pricing into typed values
     * and throws on anything unexpected, so the rest of the app never touches the raw payload.
     *
     * @param  array<string, mixed>  $payload  Decoded GET /v1/pricing response.
     */
    public static function fromArray(array $payload): self
    {
        $tokens = self::section($payload, 'tokens');

        return new self(
            Rates::fromArray(self::section($payload, 'rates')),
            Payload::nonNegativeInt($tokens['characters_per_text_token'] ?? null, 'characters_per_text_token'),
            self::percent($tokens['text_token_variance'] ?? null),
            Payload::nonNegativeInt($tokens['per_reference_image'] ?? null, 'per_reference_image'),
            self::outputTokensByQualityAndSize(self::section($tokens, 'output')),
            self::reasoningRangesByQuality(self::section($tokens, 'reasoning')),
        );
    }

    /**
     * @return list<string>
     */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /**
     * @return list<string>
     */
    public function sizes(): array
    {
        // Every quality is offered in the same sizes.
        $firstQuality = $this->qualities()[0];

        return array_keys($this->outputTokens[$firstQuality]);
    }

    public function outputTokens(string $quality, string $size): int
    {
        return $this->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("No output token count for quality [{$quality}] and size [{$size}].");
    }

    /**
     * @return array{0: int, 1: int} Minimum and maximum reasoning tokens.
     */
    public function reasoningRange(string $quality): array
    {
        return $this->reasoningRanges[$quality]
            ?? throw new InvalidArgumentException("No reasoning range for quality [{$quality}].");
    }

    /**
     * @param  array<string, mixed>  $output  `tokens.output`: quality => size => tokens
     * @return array<string, array<string, int>>
     */
    private static function outputTokensByQualityAndSize(array $output): array
    {
        $result = [];

        foreach ($output as $quality => $sizes) {
            foreach (Payload::nonEmptyArray($sizes, "output.{$quality}") as $size => $count) {
                $result[$quality][$size] = Payload::nonNegativeInt($count, "output.{$quality}.{$size}");
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $reasoning  `tokens.reasoning`: quality => {min, max}
     * @return array<string, array{0: int, 1: int}>
     */
    private static function reasoningRangesByQuality(array $reasoning): array
    {
        $result = [];

        foreach ($reasoning as $quality => $range) {
            $range = Payload::nonEmptyArray($range, "reasoning.{$quality}");

            $result[$quality] = [
                Payload::nonNegativeInt($range['min'] ?? null, "reasoning.{$quality}.min"),
                Payload::nonNegativeInt($range['max'] ?? null, "reasoning.{$quality}.max"),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function section(array $payload, string $key): array
    {
        return Payload::nonEmptyArray($payload[$key] ?? null, $key);
    }

    /**
     * The variance arrives as a ratio such as 0.15 and is used as a whole percent (15).
     * It is a token-count tolerance, not money, so a float is acceptable here; but 0.29 * 100 is
     * 28.999999999999996, so the product is rounded and then checked to be within a rounding error
     * of a whole number. A ratio that is not a whole percent (0.155) is refused.
     */
    private static function percent(mixed $ratio): int
    {
        if (! is_int($ratio) && ! is_float($ratio)) {
            throw new InvalidArgumentException('Pricing payload has an invalid [text_token_variance].');
        }

        $exactPercent = $ratio * 100;
        $percent = (int) round($exactPercent);
        $isWholePercent = abs($exactPercent - $percent) <= self::FLOAT_ROUNDING_ERROR;

        if ($percent < 0 || ! $isWholePercent) {
            throw new InvalidArgumentException('Pricing payload has a [text_token_variance] that is not a whole percent.');
        }

        return $percent;
    }
}
