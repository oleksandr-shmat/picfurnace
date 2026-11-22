<?php

declare(strict_types=1);

namespace App\Pixelforge;

/**
 * Pixelforge refused this exact request (blocked prompt, invalid parameter). Retrying it
 * cannot help, so the generation fails and the message can be shown to the user.
 */
final class RequestRejected extends PixelforgeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
