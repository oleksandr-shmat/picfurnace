<?php

declare(strict_types=1);

namespace App\Admin;

use Money\Money;

/**
 * The two places that know what users were charged: the generations and the credit ledger. They
 * must hold the same total, otherwise money was moved around the ledger.
 */
final readonly class LedgerCheck
{
    public function __construct(
        /** The sum of the charges written on succeeded generations. */
        public Money $onGenerations,
        /** The sum of the `charge` lines of the ledger, as a positive amount. */
        public Money $inLedger,
    ) {}

    public function agrees(): bool
    {
        return $this->onGenerations->equals($this->inLedger);
    }
}
