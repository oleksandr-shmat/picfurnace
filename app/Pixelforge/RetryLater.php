<?php

declare(strict_types=1);

namespace App\Pixelforge;

/**
 * The same request may succeed later: rate limit, concurrency limit, daily limit, overload, a
 * network failure or a 5xx.
 *
 * `nothingWasCreated` is true when Pixelforge said it rejected the request (429, 503). After a lost
 * connection or another 5xx it is false: the generation may exist there, which is why retrying
 * must use the same Idempotency-Key.
 */
final class RetryLater extends PixelforgeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly string $errorCode,
        string $message,
        public readonly bool $nothingWasCreated,
    ) {
        parent::__construct($message);
    }
}
