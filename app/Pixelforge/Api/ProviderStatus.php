<?php

declare(strict_types=1);

namespace App\Pixelforge\Api;

use App\Enums\GenerationStatus;

/**
 * The statuses Pixelforge reports for a generation. Our own `pending` is not among them.
 */
enum ProviderStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function toGenerationStatus(): GenerationStatus
    {
        return match ($this) {
            self::Queued => GenerationStatus::Queued,
            self::Processing => GenerationStatus::Processing,
            self::Succeeded => GenerationStatus::Succeeded,
            self::Failed => GenerationStatus::Failed,
        };
    }
}
