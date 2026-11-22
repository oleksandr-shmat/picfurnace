<?php

declare(strict_types=1);

namespace App\Generations;

/**
 * What we did with a webhook event. The values are what is stored in `webhook_events.outcome`.
 */
enum WebhookOutcome: string
{
    case Applied = 'applied';

    /** Older than what we already know (late or out of order), so nothing changed. */
    case IgnoredStale = 'ignored_stale';

    /** A generation we have no record of. */
    case IgnoredUnknownGeneration = 'ignored_unknown_generation';

    /** An event type that does not concern generations, such as `ping`. */
    case IgnoredType = 'ignored_type';
}
