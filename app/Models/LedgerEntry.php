<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\LedgerEntryType;
use Carbon\CarbonImmutable;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the append-only credit ledger. A user's balance is the sum of their amounts.
 * Entries are never changed or deleted (the database refuses it).
 *
 * @property int $id
 * @property int $user_id
 * @property LedgerEntryType $type
 * @property int $amount_nano
 * @property int|null $generation_id
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    // Created only by our own services, never from request input.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
        ];
    }
}
