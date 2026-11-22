<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Generation;
use Money\Money;

/**
 * Stage 1 of the product: users have no balance, so every generation is allowed and nothing moves.
 * The price of a generation is recorded on the generation row itself (`charged_nano`).
 *
 * Stage 2 replaces this with a ledger-backed implementation: reserve holds the maximum estimate
 * and throws InsufficientBalance, settle turns the hold into a charge, release returns the hold.
 */
final class NoBalanceBilling implements Billing
{
    public function reserve(Generation $generation): void {}

    public function settle(Generation $generation, Money $price): void {}

    public function release(Generation $generation): void {}
}
