<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Money\Usd;
use InvalidArgumentException;
use Money\Money;

/**
 * Price of one token of each kind, in nanodollars.
 *
 * Pixelforge publishes rates as dollars per one million tokens ("12.50"). One million tokens
 * is exactly 1e6, and a nanodollar is 1e-9 dollars, so a rate with at most three decimals is a
 * whole number of nanodollars per token. Anything finer is refused rather than rounded.
 */
final readonly class Rates
{
    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(
        public Money $inputText,
        public Money $inputImage,
        public Money $output,
        public Money $reasoning,
    ) {}

    /**
     * Normalisation boundary: turns the decimal strings of the API into Money per token and
     * throws on anything unexpected.
     *
     * @param  array<string, mixed>  $rates  The `rates` object of a generation or of GET /v1/pricing.
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            self::perToken($rates, 'input_text_tokens'),
            self::perToken($rates, 'input_image_tokens'),
            self::perToken($rates, 'output_tokens'),
            self::perToken($rates, 'reasoning_tokens'),
        );
    }

    /**
     * The rates in the format of the API (dollars per million tokens), as stored with a generation.
     * `fromArray` reads this back.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => self::perMillion($this->inputText),
            'input_image_tokens' => self::perMillion($this->inputImage),
            'output_tokens' => self::perMillion($this->output),
            'reasoning_tokens' => self::perMillion($this->reasoning),
        ];
    }

    private static function perMillion(Money $perToken): string
    {
        return Usd::format($perToken->multiply(self::TOKENS_PER_RATE_UNIT));
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private static function perToken(array $rates, string $key): Money
    {
        if (! isset($rates[$key]) || ! is_string($rates[$key])) {
            throw new InvalidArgumentException("Rates are missing a decimal string [{$key}].");
        }

        // "12.50" dollars per million tokens is 12_500_000_000 nanodollars per million tokens.
        $nanoPerMillionTokens = Usd::parse($rates[$key]);

        $isWholeNanoPerToken = $nanoPerMillionTokens->mod(self::TOKENS_PER_RATE_UNIT)->isZero();

        if ($nanoPerMillionTokens->isNegative() || ! $isWholeNanoPerToken) {
            throw new InvalidArgumentException("Rate [{$key}] = {$rates[$key]} is not a whole number of nanodollars per token.");
        }

        // ... which is 12_500 nanodollars per token.
        return $nanoPerMillionTokens->divide(self::TOKENS_PER_RATE_UNIT);
    }
}
