<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

mutates(FortifyServiceProvider::class);

it('shows the sign-in page', function () {
    $this->get(route('login'))->assertOk();
});

it('signs a user in and sends them to the studio', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('does not sign in with a wrong password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $this->assertGuest();
});

it('does not sign in a user who has deleted their account', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

it('signs a user out', function () {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
});

it('limits sign-in attempts', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('allows five attempts a minute and not a sixth', function () {
    $user = User::factory()->create();
    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 4);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('has no public page: the address sends visitors to the studio, which asks guests to sign in', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('has no way to create an account or to sign in other than with a password', function (string $path) {
    $this->get($path)->assertNotFound();
})->with([
    'registration' => '/register',
    'password reset request' => '/forgot-password',
    'password reset' => '/reset-password/token',
    'e-mail verification' => '/email/verify',
    'two-factor challenge' => '/two-factor-challenge',
    'passkeys' => '/.well-known/passkey-endpoints',
]);

it('has no password confirmation page', function () {
    $this->actingAs(User::factory()->create())->get('/user/confirm-password')->assertNotFound();
});

it('does not register the routes of the removed login features', function (string $name) {
    expect(Route::has($name))->toBeFalse();
})->with(['register', 'password.request', 'password.reset', 'verification.notice', 'two-factor.login']);
