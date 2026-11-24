<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * What a credit ledger entry means. The sign of the amount follows from the type (the database
 * checks it): money in is positive, money out or held is negative.
 */
enum LedgerEntryType: string
{
    /** Free credit given to a user (the demo balance). */
    case Grant = 'grant';
    /** Credit bought by the user (fake payment in this demo). */
    case Purchase = 'purchase';
    /** Maximum price of a generation, held when it is submitted. */
    case Hold = 'hold';
    /** The hold given back when the generation ends. */
    case HoldRelease = 'hold_release';
    /** What the user finally pays for a generation (0 or less, free when it failed). */
    case Charge = 'charge';

    /** Hold, its release and the charge all belong to one generation. */
    public function belongsToGeneration(): bool
    {
        return match ($this) {
            self::Grant, self::Purchase => false,
            self::Hold, self::HoldRelease, self::Charge => true,
        };
    }
}
