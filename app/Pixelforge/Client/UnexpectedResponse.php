<?php

declare(strict_types=1);

namespace App\Pixelforge\Client;

use RuntimeException;

/**
 * A response we do not know how to act on: a bad API key, an unknown id, an Idempotency-Key
 * conflict, a body that is not the JSON we expect. This is a bug or a misconfiguration, not a
 * condition to retry blindly.
 */
final class UnexpectedResponse extends RuntimeException {}
