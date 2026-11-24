<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;
use Money\Money;

/**
 * Billing on the append-only credit ledger. The balance is the sum of a user's entries, and money
 * that is held for a running generation is already inside that sum (a hold is a negative entry).
 *
 * - addCredit: a purchase entry, money in.
 * - reserve: hold the maximum price of the estimate, refuse when the balance does not cover it.
 * - settle: give the hold back and write the real charge. A price above the hold is charged in
 *   full and flagged `overrun`: the balance may go negative, and a negative balance cannot
 *   reserve anything until the user adds credit.
 * - release: give the hold back, the user pays nothing.
 *
 * Every step writes at most one entry per generation and type (the database enforces it), so a
 * repeated call, e.g. from a replayed webhook, does not move money twice. A generation that was
 * already running before the ledger existed has no hold: it is only charged, nothing is given back.
 */
final class LedgerBilling implements Billing
{
    public function balance(User $user): Money
    {
        $sum = LedgerEntry::query()->where('user_id', $user->id)->sum('amount_nano');

        return Usd::nano((string) $sum);
    }

    public function addCredit(User $user, Money $amount): void
    {
        LedgerEntry::create([
            'user_id' => $user->id,
            'type' => LedgerEntryType::Purchase,
            'amount_nano' => Usd::toInt($amount),
        ]);
    }

    /**
     * The caller holds the user row lock (see GenerationSubmitter), so two requests cannot both
     * pass this check against the same money.
     */
    public function reserve(Generation $generation): void
    {
        $hold = $generation->estimated_price_max_nano;

        if ($this->balance($generation->user)->lessThan(Usd::nano($hold))) {
            throw new InsufficientBalance('Not enough credit for this generation. Add credit and try again.');
        }

        $this->write($generation, LedgerEntryType::Hold, -$hold);
    }

    public function settle(Generation $generation, Money $price): void
    {
        $nano = Usd::toInt($price);
        $held = $this->giveHoldBack($generation);

        $this->write($generation, LedgerEntryType::Charge, -$nano, $held !== null && $nano > $held ? 'overrun' : null);
    }

    public function release(Generation $generation): void
    {
        $this->giveHoldBack($generation);
    }

    /**
     * Returns the held amount, or null when the generation has no hold.
     */
    private function giveHoldBack(Generation $generation): ?int
    {
        $hold = LedgerEntry::query()
            ->where('generation_id', $generation->id)
            ->where('type', LedgerEntryType::Hold->value)
            ->first();

        if ($hold === null) {
            return null;
        }

        $this->write($generation, LedgerEntryType::HoldRelease, -$hold->amount_nano);

        return -$hold->amount_nano;
    }

    private function write(Generation $generation, LedgerEntryType $type, int $amountNano, ?string $note = null): void
    {
        LedgerEntry::query()->insertOrIgnore([
            'user_id' => $generation->user_id,
            'type' => $type->value,
            'amount_nano' => $amountNano,
            'generation_id' => $generation->id,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
