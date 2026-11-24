<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::User,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A user with credit in the ledger ($5 by default), who can submit generations.
     */
    public function withCredit(int $nano = 5_000_000_000): static
    {
        return $this->afterCreating(fn (User $user) => LedgerEntry::factory()->create([
            'user_id' => $user->id,
            'amount_nano' => $nano,
        ]));
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Admin]);
    }
}
