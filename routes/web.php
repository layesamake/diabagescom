<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Catalog\PartyController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImportController;
use App\Http\Controllers\Catalog\ReferenceDataController;
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

    // Catalogue
    Route::get('catalog/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('catalog/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('catalog/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('catalog/lookup', [ProductController::class, 'lookup'])->middleware('throttle:120,1')->name('products.lookup');
    Route::get('catalog/import', [ProductImportController::class, 'create'])->name('products.import.create');
    Route::post('catalog/import', [ProductImportController::class, 'store'])->middleware('throttle:10,1')->name('products.import.store');
    Route::get('catalog/import/{import}', [ProductImportController::class, 'show'])->whereUuid('import')->name('products.import.show');
    Route::post('catalog/import/{import}/apply', [ProductImportController::class, 'apply'])->whereUuid('import')->name('products.import.apply');
    Route::get('catalog/products/{product}', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('catalog/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('catalog/products/{product}/archive', [ProductController::class, 'archive'])->name('products.archive');
    Route::post('catalog/products/{product}/barcodes', [ProductController::class, 'addBarcode'])->name('products.barcodes.store');
    Route::delete('catalog/products/{product}/barcodes/{barcode}', [ProductController::class, 'removeBarcode'])->name('products.barcodes.destroy');
    Route::post('catalog/products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('throttle:20,1')->name('products.image.store');
    Route::get('catalog/products/{product}/image', [ProductController::class, 'image'])->middleware('signed')->name('products.image');
    Route::delete('catalog/products/{product}/image', [ProductController::class, 'removeImage'])->name('products.image.destroy');
    Route::post('catalog/categories/{category}/image', [ReferenceDataController::class, 'uploadCategoryImage'])->whereUuid('category')->middleware('throttle:20,1')->name('catalog.categories.image.store');
    Route::delete('catalog/categories/{category}/image', [ReferenceDataController::class, 'removeCategoryImage'])->whereUuid('category')->name('catalog.categories.image.destroy');
    Route::get('catalog/categories/{category}/image', [ReferenceDataController::class, 'categoryImage'])->whereUuid('category')->middleware('signed')->name('catalog.categories.image');

    Route::get('catalog/settings', [ReferenceDataController::class, 'index'])->name('catalog.settings');
    Route::post('catalog/categories/{category?}', [ReferenceDataController::class, 'saveCategory'])->whereUuid('category')->name('catalog.categories.save');
    Route::post('catalog/units/{unit?}', [ReferenceDataController::class, 'saveUnit'])->whereUuid('unit')->name('catalog.units.save');
    Route::post('catalog/tax-rates/{taxRate?}', [ReferenceDataController::class, 'saveTaxRate'])->whereUuid('taxRate')->name('catalog.tax_rates.save');

    // Tiers
    foreach (['customers' => 'customer', 'suppliers' => 'supplier'] as $path => $party) {
        Route::get($path, [PartyController::class, 'index'])->defaults('party', $party)->name("{$path}.index");
        Route::post($path, [PartyController::class, 'store'])->defaults('party', $party)->name("{$path}.store");
        Route::put("{$path}/{party}", [PartyController::class, 'update'])->defaults('party', $party)->whereUuid('party')->name("{$path}.update");
        Route::post("{$path}/{party}/archive", [PartyController::class, 'archive'])->defaults('party', $party)->whereUuid('party')->name("{$path}.archive");
    }
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
