<?php

namespace Database\Seeders;

use App\Billing\LedgerEntryType;
use App\Enums\Role;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Money\Usd;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the two accounts the application has (see config/accounts.php). Nobody can register.
 * The demo account gets its starting credit once: every deploy seeds again, so a user who already
 * received it is never given more.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $demo = $this->account('Demo User', 'demo', Role::User);
        $this->grantStartingCredit($demo);
        $this->account('Admin', 'admin', Role::Admin);
    }

    private function account(string $name, string $key, Role $role): User
    {
        $user = User::withTrashed()->firstOrNew(['email' => config("accounts.{$key}.email")]);
        $user->fill(['name' => $name, 'password' => $this->password($key)]);
        $user->role = $role;
        $user->deleted_at = null;
        $user->save();

        return $user;
    }

    private function grantStartingCredit(User $user): void
    {
        $alreadyGranted = LedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('type', LedgerEntryType::Grant->value)
            ->exists();

        if ($alreadyGranted) {
            return;
        }

        LedgerEntry::create([
            'user_id' => $user->id,
            'type' => LedgerEntryType::Grant,
            'amount_nano' => Usd::toInt(Usd::parse((string) config('accounts.demo.credit'))),
            'note' => 'starting credit',
        ]);
    }

    private function password(string $key): string
    {
        $password = config("accounts.{$key}.password");

        // An empty value counts as "not set": `.env.example` lists the variable with nothing after it.
        if (filled($password)) {
            return $password;
        }

        if (app()->isProduction()) {
            throw new RuntimeException('Set the password of the '.$key.' account (config/accounts.php) before seeding in production.');
        }

        return 'password';
    }
}
