<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AdminReportExportController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

// There is no public page: guests are sent on from the dashboard to the sign-in page.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');

    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::delete('generations/{generation:uuid}', [GenerationController::class, 'destroy'])->name('generations.destroy');
    Route::get('generations/{generation:uuid}/image', GenerationImageController::class)->name('generations.image');
    Route::middleware('can:view-admin-report')->group(function () {
        Route::get('admin/report', AdminReportController::class)->name('admin.report');
        Route::get('admin/report/export', AdminReportExportController::class)->name('admin.report.export');
    });

    Route::post('credits', CreditPurchaseController::class)->name('credits.store');
    Route::post('generations/estimate', EstimateController::class)->name('generations.estimate');
});

require __DIR__.'/settings.php';
