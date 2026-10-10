<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Assistant de démarrage : connecté et vérifié, sans entreprise requise.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding', [OnboardingController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('onboarding.store');
});

// Espace entreprise : contexte tenant résolu côté serveur, MFA des administrateurs,
// puis état de l'abonnement (lecture seule / suspension).
Route::middleware(['auth', 'verified', 'tenant', 'mfa.privileged', 'subscription'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('context/tenant', [ContextController::class, 'switchTenant'])->name('context.tenant');
    Route::post('context/warehouse', [ContextController::class, 'switchWarehouse'])->name('context.warehouse');

    Route::get('settings/subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
    Route::get('settings/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::post('settings/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
    Route::get('settings/audit', [AuditController::class, 'index'])->name('audit.index');
});

// Console plateforme (super-administrateur) : aucun contexte d'entreprise.
Route::bind('platformSubscription', fn (string $value) => TenantController::resolveSubscription($value));

Route::middleware(['auth', 'verified', 'mfa.privileged'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('subscriptions/{platformSubscription}', [TenantController::class, 'updateSubscription'])
        ->middleware('throttle:30,1')
        ->name('subscriptions.update');
    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
});

require __DIR__.'/settings.php';
