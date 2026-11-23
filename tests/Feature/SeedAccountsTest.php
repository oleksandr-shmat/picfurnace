<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('creates the demo user and the admin', function () {
    $this->seed(DatabaseSeeder::class);

    $demo = User::where('email', 'demo@example.com')->sole();
    $admin = User::where('email', 'admin@example.com')->sole();

    expect($demo->role)->toBe(Role::User)
        ->and($admin->role)->toBe(Role::Admin)
        ->and($admin->isAdmin())->toBeTrue()
        ->and($demo->isAdmin())->toBeFalse();
});

it('lets both sign in with the configured password', function () {
    $this->seed(DatabaseSeeder::class);

    $this->post(route('login.store'), ['email' => 'demo@example.com', 'password' => 'password']);

    $this->assertAuthenticated();
});

it('can be run again without making duplicates', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(2);
});

it('brings back an account that was deleted', function () {
    $this->seed(DatabaseSeeder::class);
    User::where('email', 'demo@example.com')->sole()->delete();

    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'demo@example.com')->sole()->trashed())->toBeFalse();
});

it('takes the e-mail addresses and passwords from the configuration', function () {
    config([
        'accounts.demo.email' => 'viewer@example.com',
        'accounts.demo.password' => 'from-the-configuration',
    ]);

    $this->seed(DatabaseSeeder::class);

    $this->post(route('login.store'), ['email' => 'viewer@example.com', 'password' => 'from-the-configuration']);
    $this->assertAuthenticated();
});

it('treats an empty password from the environment as not set, never as an empty password', function () {
    // `DEMO_USER_PASSWORD=` in a .env file reaches the configuration as an empty string
    config(['accounts.demo.password' => '', 'accounts.admin.password' => '']);

    $this->seed(DatabaseSeeder::class);

    $this->post(route('login.store'), ['email' => 'demo@example.com', 'password' => '']);
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => 'demo@example.com', 'password' => 'password']);
    $this->assertAuthenticated();
});

it('refuses to create accounts with the default password in production', function (?string $password) {
    config(['accounts.demo.password' => $password]);
    app()->detectEnvironment(fn () => 'production');

    // Called directly: the db:seed command itself asks for confirmation in production.
    (new DatabaseSeeder)->run();
})->throws(RuntimeException::class, 'Set the password of the demo account')->with([
    'not set' => [null],
    'set to nothing' => [''],
]);
