<?php

declare(strict_types=1);

use App\Http\Controllers\GenerationImageController;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

mutates(GenerationImageController::class);

beforeEach(function () {
    Storage::fake('images');
    config(['images.disk' => 'images']);

    $this->owner = User::factory()->create();
    $this->generation = Generation::factory()->succeeded()->create(['user_id' => $this->owner->id, 'image_path' => 'generations/a.png']);
    Storage::disk('images')->put('generations/a.png', "\x89PNG\r\n\x1a\nbytes");
});

it('shows the author the copy of the image', function () {
    $response = $this->actingAs($this->owner)->get(route('generations.image', $this->generation->uuid));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe("\x89PNG\r\n\x1a\nbytes");
});

it('shows an admin the image of any user', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('generations.image', $this->generation->uuid))
        ->assertOk();
});

it('does not show the image to anybody else', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generations.image', $this->generation->uuid))
        ->assertForbidden();
});

it('sends a guest to the login page', function () {
    $this->get(route('generations.image', $this->generation->uuid))->assertRedirect(route('login'));
});

it('sends the user to the Pixelforge link until our copy exists', function () {
    $this->generation->update([
        'image_path' => null,
        'image_url' => 'https://images.pixelforge.test/gen.png?signature=abc',
        'image_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($this->owner)->get(route('generations.image', $this->generation->uuid))
        ->assertRedirect('https://images.pixelforge.test/gen.png?signature=abc');
});

it('has nothing to show once the copy is deleted and the Pixelforge link has expired', function () {
    $this->generation->update([
        'image_path' => null,
        'image_purged_at' => now(),
        'image_url' => 'https://images.pixelforge.test/gen.png?signature=abc',
        'image_expires_at' => now()->subHour(),
    ]);

    $this->actingAs($this->owner)->get(route('generations.image', $this->generation->uuid))->assertNotFound();
});

it('has nothing to show when the Pixelforge link has no expiry date and there is no copy', function () {
    $this->generation->update(['image_path' => null, 'image_url' => 'https://images.pixelforge.test/a.png', 'image_expires_at' => null]);

    $this->actingAs($this->owner)->get(route('generations.image', $this->generation->uuid))->assertNotFound();
});

it('has nothing to show for a generation without an image', function () {
    $failed = Generation::factory()->failed()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->get(route('generations.image', $failed->uuid))->assertNotFound();
});
