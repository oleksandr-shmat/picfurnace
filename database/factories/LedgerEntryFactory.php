<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Billing\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default entry: a $5 grant.
 *
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => LedgerEntryType::Grant,
            'amount_nano' => 5_000_000_000,
        ];
    }

    public function charge(Generation $generation, int $amountNano = 1_250): static
    {
        return $this->state([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => LedgerEntryType::Charge,
            'amount_nano' => -$amountNano,
        ]);
    }
}
