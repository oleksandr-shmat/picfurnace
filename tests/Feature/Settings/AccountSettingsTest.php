<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

mutates(
    ProfileController::class,
    SecurityController::class,
    PasswordUpdateRequest::class,
    ProfileDeleteRequest::class,
    ProfileUpdateRequest::class,
);

it('shows the profile and password pages', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertOk();
})->with(['profile.edit', 'security.edit', 'appearance.edit']);

it('is for signed-in users only', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['profile.edit', 'security.edit', 'appearance.edit']);

it('gives the password page the rules for a new password', function () {
    $this->actingAs(User::factory()->create())->get(route('security.edit'))
        ->assertInertia(fn ($page) => $page->component('settings/Security')->has('passwordRules'));
});

it('updates the name and the e-mail', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->name)->toBe('New Name')->and($user->email)->toBe('new@example.com');
});

it('confirms a saved profile and a changed password with a message', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Profile updated.']);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Password updated.']);
});

it('stores the e-mail in lowercase so that the user can still sign in', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'Test@Example.com'])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->email)->toBe('test@example.com');

    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => 'Test@Example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('keeps e-mail addresses unique regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'Taken@Example.com'])
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));
});

it('changes the password only when the current one is given', function (string $current, bool $changed) {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => $current,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBe($changed);
})->with([
    'the right one' => ['password', true],
    'a wrong one' => ['wrong-password', false],
]);

it('lets the user leave: the account is deleted but the history stays', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertSoftDeleted($user);
});

it('ends the session of a user who deletes the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['left-over' => 'data', '_token' => 'old-token'])
        ->delete(route('profile.destroy'), ['password' => 'password']);

    expect(session('left-over'))->toBeNull()
        ->and(session()->token())->not->toBe('old-token');
});

it('asks for the password before deleting the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    $this->assertNotSoftDeleted($user);
});
