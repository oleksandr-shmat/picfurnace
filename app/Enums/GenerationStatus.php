<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a generation in our system.
 *
 * `pending` is ours only: the generation waits in our own queue and has not been accepted by
 * Pixelforge yet (it can still be cancelled for free). The rest mirrors the provider.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Webhooks can arrive duplicated and out of order (a late `processing` after `succeeded`), and
     * one can even arrive before our POST has returned. A status may therefore only move forward,
     * skipping steps is fine, and a terminal status never changes.
     */
    public function canMoveTo(self $next): bool
    {
        return $this->rank() < $next->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
