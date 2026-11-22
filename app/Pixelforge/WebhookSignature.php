<?php

declare(strict_types=1);

namespace App\Pixelforge;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Checks the `Pixelforge-Signature` header of a webhook: `t=<unix timestamp>,v1=<signature>`, where the
 * signature is HMAC-SHA256 of "<timestamp>.<raw body>" with the webhook secret of the API key.
 *
 * The timestamp must be recent, so a captured request cannot be replayed later.
 */
final class WebhookSignature
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret)
    {
        // An empty key would let anybody sign a request, so refuse to run without a secret.
        if ($secret === '') {
            throw new InvalidArgumentException('The Pixelforge webhook secret is not configured.');
        }
    }

    /**
     * @param  string  $rawBody  The body exactly as received, before any JSON decoding.
     */
    public function isValid(string $rawBody, string $header, CarbonInterface $now): bool
    {
        $parts = $this->parse($header);

        if ($parts === null) {
            return false;
        }

        [$timestamp, $signature] = $parts;

        if (abs($now->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array{0: string, 1: string}|null Timestamp and signature as sent, or null if the header is malformed.
     */
    private function parse(string $header): ?array
    {
        // "t=1762265009,v1=5257..." read as a query string: parts we do not know are ignored.
        parse_str(str_replace(',', '&', $header), $values);

        $timestamp = $values['t'] ?? null;
        $signature = $values['v1'] ?? null;

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($signature)) {
            return null;
        }

        return [$timestamp, $signature];
    }
}
