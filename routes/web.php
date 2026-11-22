<?php

use App\Http\Controllers\EstimateController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations/{generation:uuid}/image', GenerationImageController::class)->name('generations.image');
    Route::post('generations/estimate', EstimateController::class)->name('generations.estimate');
});

require __DIR__.'/settings.php';
