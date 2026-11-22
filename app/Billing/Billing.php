<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Generation;
use Money\Money;

/**
 * Where money moves. The generation flow only talks to this contract, so balances can be added
 * later by swapping the implementation, without touching the flow.
 *
 * The three calls match the life of a generation: it is reserved when submitted, then either
 * settled (it succeeded and the user pays) or released (it failed or was cancelled, nothing to pay).
 */
interface Billing
{
    /**
     * Called before a generation is submitted.
     *
     * @throws InsufficientBalance when the user cannot afford it.
     */
    public function reserve(Generation $generation): void;

    /**
     * The generation succeeded: the user pays this price.
     */
    public function settle(Generation $generation, Money $price): void;

    /**
     * The generation ended without a charge.
     */
    public function release(Generation $generation): void;
}
