<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Assistant de démarrage : connecté et vérifié, sans entreprise requise.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding', [OnboardingController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('onboarding.store');
});

// Espace entreprise : contexte tenant résolu côté serveur et MFA des administrateurs.
Route::middleware(['auth', 'verified', 'tenant', 'mfa.privileged'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('context/tenant', [ContextController::class, 'switchTenant'])->name('context.tenant');
    Route::post('context/warehouse', [ContextController::class, 'switchWarehouse'])->name('context.warehouse');

    Route::get('settings/audit', [AuditController::class, 'index'])->name('audit.index');
});

require __DIR__.'/settings.php';
