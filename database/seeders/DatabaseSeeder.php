<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the two accounts the application has (see config/accounts.php). Nobody can register.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->account('Demo User', 'demo', Role::User);
        $this->account('Admin', 'admin', Role::Admin);
    }

    private function account(string $name, string $key, Role $role): void
    {
        $user = User::withTrashed()->firstOrNew(['email' => config("accounts.{$key}.email")]);
        $user->fill(['name' => $name, 'password' => $this->password($key)]);
        $user->role = $role;
        $user->deleted_at = null;
        $user->save();
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
