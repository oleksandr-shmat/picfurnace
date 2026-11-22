<?php

declare(strict_types=1);

namespace App\Generations;

/**
 * What happened to a generation when a report from Pixelforge was applied to it.
 * The values are what is stored in `webhook_events.outcome`.
 */
enum SnapshotOutcome: string
{
    case Applied = 'applied';

    /** The report is older than what we already know (duplicate or out of order), so nothing changed. */
    case IgnoredStale = 'ignored_stale';
}
