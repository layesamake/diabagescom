<?php

use App\Http\Controllers\Accounting\AccountingController;
use App\Http\Controllers\Accounting\ChartController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\ReconciliationController;
use App\Http\Controllers\Accounting\ReportController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\SandboxCheckoutController;
use App\Http\Controllers\Billing\WebhookController;
use App\Http\Controllers\Cash\CashController;
use App\Http\Controllers\Catalog\LabelController;
use App\Http\Controllers\Catalog\PartyController;
use App\Http\Controllers\Catalog\PartyImportController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImportController;
use App\Http\Controllers\Catalog\ReferenceDataController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\Inventory\CountController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\TransferController;
use App\Http\Controllers\Marketing\WelcomeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Platform\BillingConsoleController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\SupportController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\TenantProfileController;
use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Http\Controllers\Purchases\PurchaseRequestController;
use App\Http\Controllers\Purchases\ReceiptController;
use App\Http\Controllers\Purchases\SupplierInvoiceController;
use App\Http\Controllers\RestoreController;
use App\Http\Controllers\Sales\DocumentShareController;
use App\Http\Controllers\Sales\PosController;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\BackupController;
use App\Http\Controllers\Settings\CompanyController;
use App\Http\Controllers\Settings\FeatureController;
use App\Http\Controllers\Settings\GoogleDriveController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Simple\SimpleCustomerController;
use App\Http\Controllers\Simple\SimpleGuideController;
use App\Http\Controllers\Simple\SimpleModeController;
use App\Http\Controllers\Simple\SimpleProductController;
use App\Http\Controllers\Simple\SimplePurchaseController;
use App\Http\Controllers\Simple\SimpleResultsController;
use App\Http\Controllers\Simple\SimpleSalesController;
use App\Http\Controllers\Simple\SimpleSalesLedgerController;
use App\Http\Controllers\Simple\SimpleStockController;
use App\Http\Controllers\Simple\SimpleVoiceController;
use App\Http\Controllers\Storefront\PublicStorefrontController;
use App\Http\Controllers\Storefront\StorefrontController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');
Route::get('sitemap.xml', [WelcomeController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [WelcomeController::class, 'robots'])->name('robots');

// Boutique publique : aucune donnée métier privée n'est exposée sans que le
// slug et l'activation de la boutique soient vérifiés par le contrôleur.
Route::prefix('boutique/{slug}')->group(function (): void {
    Route::get('/', [PublicStorefrontController::class, 'show'])->name('storefront.show');
    Route::post('orders', [PublicStorefrontController::class, 'order'])->middleware('throttle:20,1')->name('storefront.orders.store');
    Route::get('orders/{order}/confirmation', [PublicStorefrontController::class, 'confirmation'])->name('storefront.order.confirmation');
    Route::get('products/{product}/image', [PublicStorefrontController::class, 'image'])->whereUuid('product')->middleware('throttle:120,1')->name('storefront.products.image');
    Route::get('logo', [PublicStorefrontController::class, 'brandImage'])->defaults('slot', 'logo')->middleware('throttle:120,1')->name('storefront.logo');
    Route::get('cover', [PublicStorefrontController::class, 'brandImage'])->defaults('slot', 'cover')->middleware('throttle:120,1')->name('storefront.cover');
});

// Pages légales publiques (versions provisoires, à faire valider par un juriste).
Route::inertia('confidentialite', 'legal/Privacy')->name('legal.privacy');
Route::inertia('conditions', 'legal/Terms')->name('legal.terms');

// Assistant de démarrage : connecté et vérifié, sans entreprise requise.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding', [OnboardingController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('onboarding.store');
    // Restaurer une sauvegarde dans une nouvelle entreprise.
    Route::get('onboarding/restore', [RestoreController::class, 'create'])->name('restore.create');
    Route::post('onboarding/restore', [RestoreController::class, 'store'])
        ->middleware('throttle:5,10')
        ->name('restore.store');
});

// Espace entreprise : contexte tenant résolu côté serveur, MFA des administrateurs,
// puis état de l'abonnement (lecture seule / suspension).
Route::middleware(['auth', 'verified', 'tenant', 'mfa.privileged', 'subscription'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('reports', [SalesReportController::class, 'index'])->name('reports.index');

    // Centre d'aide : tâches courantes, questions fréquentes, glossaire.
    Route::inertia('aide', 'help/Index')->name('help.index');

    // Recherche globale (Ctrl+K) : articles, clients, fournisseurs, documents.
    Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('search.index');

    // Guide de démarrage : propre à chaque utilisateur, sans permission particulière.
    Route::get('guide', [GuideController::class, 'index'])->name('guide.index');
    Route::put('guide', [GuideController::class, 'update'])->name('guide.update');
    Route::post('guide/steps/{step}', [GuideController::class, 'step'])->where('step', '[a-z_]+')->name('guide.steps.update');

    Route::post('context/tenant', [ContextController::class, 'switchTenant'])->name('context.tenant');
    Route::post('context/warehouse', [ContextController::class, 'switchWarehouse'])->name('context.warehouse');

    // Mode simple (lot 1 : bascule et page de comparaison). Les routes
    // existent toujours — pour que le build génère leurs helpers — mais
    // répondent 404 tant que DIABA_SIMPLE_MODE n'est pas activé.
    Route::post('mode', [SimpleModeController::class, 'switch'])->name('simple.mode');
    Route::get('simple', [SimpleModeController::class, 'home'])->name('simple.home');
    Route::get('simple/guide', [SimpleGuideController::class, 'index'])->name('simple.guide');
    Route::get('simple/composants', [SimpleModeController::class, 'demo'])->name('simple.demo');
    Route::get('simple/maquettes/{fichier}', [SimpleModeController::class, 'mockup'])
        ->where('fichier', '[0-9a-z-]+')
        ->name('simple.mockup');

    // Mode simple — lot 2 : vente et caisse simplifiées.
    Route::get('simple/vendre', [SimpleSalesController::class, 'sell'])->name('simple.sell');
    Route::post('simple/vendre', [SimpleSalesController::class, 'storeSale'])->name('simple.sell.store');
    Route::get('simple/caisse', [SimpleSalesController::class, 'cash'])->name('simple.cash');
    Route::post('simple/caisse/{register}/ouvrir', [SimpleSalesController::class, 'openRegister'])->name('simple.cash.open');
    Route::post('simple/caisse/{session}/fermer', [SimpleSalesController::class, 'closeSession'])->name('simple.cash.close');
    Route::post('simple/caisse/{session}/mouvement', [SimpleSalesController::class, 'movement'])->name('simple.cash.movement');

    // Mode simple — lot 3 : Mes ventes (chiffres du jour, liste, annulation).
    Route::get('simple/ventes', [SimpleSalesLedgerController::class, 'index'])->name('simple.sales');
    Route::post('simple/ventes/{document}/annuler', [SimpleSalesLedgerController::class, 'cancel'])->whereUuid('document')->name('simple.sales.cancel');

    // Mode simple — lot 5 : liste des dettes clients.
    Route::get('simple/clients', [SimpleCustomerController::class, 'index'])->name('simple.customers');
    Route::get('simple/clients/{customer}', [SimpleCustomerController::class, 'show'])->whereUuid('customer')->name('simple.customers.show');
    Route::post('simple/clients/{customer}/factures/{document}/paiement', [SimpleCustomerController::class, 'pay'])
        ->whereUuid('customer')->whereUuid('document')->middleware('throttle:60,1')->name('simple.customers.pay');

    // Mode simple — lot 5 : stock visuel du dépôt autorisé.
    Route::get('simple/stock', [SimpleStockController::class, 'index'])->name('simple.stock');
    Route::post('simple/stock/ajuster', [SimpleStockController::class, 'adjust'])->name('simple.stock.adjust');

    // Mode simple — lot 5 : ajout tactile d'un article.
    Route::get('simple/articles/nouveau', [SimpleProductController::class, 'create'])->name('simple.products.create');
    Route::post('simple/articles', [SimpleProductController::class, 'store'])->name('simple.products.store');

    // Mode simple — lot 6 : commandes fournisseurs à réceptionner.
    Route::get('simple/achats', [SimplePurchaseController::class, 'index'])->name('simple.purchases');
    Route::get('simple/achats/{order}', [SimplePurchaseController::class, 'show'])->whereUuid('order')->name('simple.purchases.show');
    Route::post('simple/achats/{order}/recevoir', [SimplePurchaseController::class, 'receive'])->whereUuid('order')->name('simple.purchases.receive');
    Route::get('simple/resultats', [SimpleResultsController::class, 'index'])->name('simple.results');

    // Mode simple — lot 4 : Messages audio, réservé à l'administrateur.
    Route::get('simple/voix', [SimpleVoiceController::class, 'index'])->name('simple.voice.index');
    Route::put('simple/voix/langue', [SimpleVoiceController::class, 'updateLanguage'])->name('simple.voice.language');
    Route::post('simple/voix/{code}/{segment}', [SimpleVoiceController::class, 'storeSegment'])
        ->where('code', '[A-Z]{3}-[0-9]{2}')->where('segment', '[0-9]+')->middleware('throttle:200,1')->name('simple.voice.segments.store');
    Route::delete('simple/voix/{code}/{segment}', [SimpleVoiceController::class, 'destroySegment'])
        ->where('code', '[A-Z]{3}-[0-9]{2}')->where('segment', '[0-9]+')->name('simple.voice.segments.destroy');
    Route::get('simple/voix/clips/{clip}', [SimpleVoiceController::class, 'clip'])->whereUuid('clip')->name('simple.voice.clips.show');

    Route::get('settings/subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
    // Facturation de l'abonnement (accessible même suspendu, pour régulariser).
    Route::post('billing/invoices', [BillingController::class, 'store'])->middleware('throttle:10,1')->name('billing.invoices.store');
    Route::post('billing/invoices/{invoice}/void', [BillingController::class, 'void'])->whereUuid('invoice')->name('billing.invoices.void');
    Route::post('billing/invoices/{invoice}/pay', [BillingController::class, 'pay'])->whereUuid('invoice')->middleware('throttle:10,1')->name('billing.invoices.pay');
    Route::get('billing/invoices/{invoice}/receipt', [BillingController::class, 'receipt'])->whereUuid('invoice')->name('billing.invoices.receipt');
    Route::get('billing/credit-notes/{creditNote}', [BillingController::class, 'creditNote'])->whereUuid('creditNote')->name('billing.credit-notes.show');
    Route::post('billing/payments/{payment}/declare', [BillingController::class, 'declare'])->whereUuid('payment')->middleware('throttle:10,1')->name('billing.payments.declare');
    Route::get('settings/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::post('settings/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
    Route::get('settings/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('settings/company', [CompanyController::class, 'edit'])->name('company.edit');
    Route::put('settings/company', [CompanyController::class, 'update'])->name('company.update');
    Route::post('settings/company/images/{slot}', [CompanyController::class, 'uploadImage'])->whereIn('slot', ['logo', 'stamp', 'cover'])->middleware('throttle:20,1')->name('company.images.store');
    Route::delete('settings/company/images/{slot}', [CompanyController::class, 'removeImage'])->whereIn('slot', ['logo', 'stamp', 'cover'])->name('company.images.destroy');
    Route::get('settings/company/images/{slot}', [CompanyController::class, 'image'])->whereIn('slot', ['logo', 'stamp', 'cover'])->middleware('signed')->name('company.images.show');
    Route::get('settings/features', [FeatureController::class, 'edit'])->name('features.edit');
    Route::put('settings/features/{feature}', [FeatureController::class, 'update'])->name('features.update');
    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('notifications.edit');
    Route::put('settings/notifications', [NotificationController::class, 'update'])->name('notifications.update');
    Route::middleware('feature:online_store')->prefix('storefront')->name('storefront.')->group(function (): void {
        Route::get('/', [StorefrontController::class, 'index'])->name('index');
        Route::put('/', [StorefrontController::class, 'update'])->name('update');
        Route::get('products', [StorefrontController::class, 'products'])->name('products');
        Route::put('products/{product}', [StorefrontController::class, 'updateProduct'])->whereUuid('product')->name('products.update');
        Route::get('orders', [StorefrontController::class, 'orders'])->name('orders');
        Route::put('orders/{order}', [StorefrontController::class, 'updateOrder'])->whereUuid('order')->name('orders.update');
    });
    Route::get('settings/members', [MemberController::class, 'index'])->name('members.index');
    Route::post('settings/members', [MemberController::class, 'store'])->middleware('throttle:20,1')->name('members.store');
    Route::put('settings/members/{membership}', [MemberController::class, 'update'])->whereUuid('membership')->name('members.update');
    Route::post('settings/members/{membership}/suspend', [MemberController::class, 'suspend'])->whereUuid('membership')->name('members.suspend');
    Route::post('settings/members/{membership}/reactivate', [MemberController::class, 'reactivate'])->whereUuid('membership')->name('members.reactivate');
    Route::post('settings/members/{membership}/resend', [MemberController::class, 'resend'])->whereUuid('membership')->middleware('throttle:5,1')->name('members.resend');
    Route::get('settings/backup', [BackupController::class, 'index'])->name('backup.index');
    Route::post('settings/backup', [BackupController::class, 'store'])->middleware('throttle:5,1')->name('backup.store');
    Route::get('settings/backup/{export}/download', [BackupController::class, 'download'])->whereUuid('export')->middleware('throttle:10,1')->name('backup.download');
    Route::get('settings/backup/google/connect', [GoogleDriveController::class, 'connect'])->middleware('throttle:10,1')->name('backup.google.connect');
    Route::get('settings/backup/google/callback', [GoogleDriveController::class, 'callback'])->middleware('throttle:10,1')->name('backup.google.callback');
    Route::put('settings/backup/google', [GoogleDriveController::class, 'update'])->name('backup.google.update');
    Route::post('settings/backup/google/send', [GoogleDriveController::class, 'send'])->middleware('throttle:5,1')->name('backup.google.send');
    Route::delete('settings/backup/google', [GoogleDriveController::class, 'destroy'])->name('backup.google.destroy');

    // Catalogue
    Route::get('catalog/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('catalog/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('catalog/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('catalog/lookup', [ProductController::class, 'lookup'])->middleware('throttle:120,1')->name('products.lookup');
    Route::get('catalog/import', [ProductImportController::class, 'create'])->name('products.import.create');
    Route::post('catalog/import', [ProductImportController::class, 'store'])->middleware('throttle:10,1')->name('products.import.store');
    Route::get('catalog/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
    Route::get('catalog/import/{import}', [ProductImportController::class, 'show'])->whereUuid('import')->name('products.import.show');
    Route::post('catalog/import/{import}/apply', [ProductImportController::class, 'apply'])->whereUuid('import')->name('products.import.apply');
    Route::get('catalog/products/{product}', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('catalog/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('catalog/products/{product}/archive', [ProductController::class, 'archive'])->name('products.archive');
    Route::post('catalog/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
    Route::get('catalog/labels', [LabelController::class, 'show'])->middleware('throttle:30,1')->name('products.labels');
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

    Route::get('catalog/search', [ProductController::class, 'search'])->middleware('throttle:240,1')->name('products.search');

    // Stock
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/movements', [StockController::class, 'movements'])->name('stock.movements');
    Route::post('stock/adjustments', [StockController::class, 'adjust'])->name('stock.adjust');
    Route::post('stock/products/{product}/reorder-point', [StockController::class, 'reorderPoint'])->name('stock.reorder_point');

    Route::get('stock/transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::get('stock/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
    Route::post('stock/transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::get('stock/transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
    Route::post('stock/transfers/{transfer}/ship', [TransferController::class, 'ship'])->name('transfers.ship');
    Route::post('stock/transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
    Route::post('stock/transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

    Route::get('stock/counts', [CountController::class, 'index'])->name('counts.index');
    Route::post('stock/counts', [CountController::class, 'store'])->name('counts.store');
    Route::get('stock/counts/{count}', [CountController::class, 'show'])->name('counts.show');
    Route::post('stock/counts/{count}/lines', [CountController::class, 'setLine'])->name('counts.lines.store');
    Route::delete('stock/counts/{count}/lines/{line}', [CountController::class, 'removeLine'])->name('counts.lines.destroy');
    Route::post('stock/counts/{count}/validate', [CountController::class, 'validateCount'])->name('counts.validate');
    Route::post('stock/counts/{count}/cancel', [CountController::class, 'cancel'])->name('counts.cancel');

    // Ventes
    Route::get('sales/pos', [PosController::class, 'create'])->name('pos.create');
    Route::post('sales/pos', [PosController::class, 'store'])->middleware('throttle:120,1')->name('pos.store');
    Route::get('sales/customers', [SalesDocumentController::class, 'customers'])->middleware('throttle:240,1')->name('sales.customers');
    Route::get('sales', [SalesDocumentController::class, 'index'])->name('sales.index');
    Route::get('sales/new/{type}', [SalesDocumentController::class, 'create'])->whereIn('type', ['quote', 'order', 'invoice'])->name('sales.create');
    Route::post('sales', [SalesDocumentController::class, 'store'])->name('sales.store');
    Route::get('sales/{document}', [SalesDocumentController::class, 'show'])->whereUuid('document')->name('sales.show');
    Route::get('sales/{document}/edit', [SalesDocumentController::class, 'edit'])->whereUuid('document')->name('sales.edit');
    Route::put('sales/{document}', [SalesDocumentController::class, 'update'])->whereUuid('document')->name('sales.update');
    Route::post('sales/{document}/validate', [SalesDocumentController::class, 'validateDocument'])->whereUuid('document')->name('sales.validate');
    Route::post('sales/{document}/convert', [SalesDocumentController::class, 'convert'])->whereUuid('document')->name('sales.convert');
    Route::post('sales/{document}/cancel', [SalesDocumentController::class, 'cancel'])->whereUuid('document')->name('sales.cancel');
    Route::post('sales/{document}/payments', [SalesDocumentController::class, 'pay'])->whereUuid('document')->name('sales.payments.store');
    Route::post('sales/{document}/refunds', [SalesDocumentController::class, 'refund'])->whereUuid('document')->name('sales.refunds.store');
    Route::post('sales/{document}/credit-notes', [SalesDocumentController::class, 'creditNote'])->whereUuid('document')->name('sales.credit_notes.store');
    Route::get('sales/{document}/pdf', [SalesDocumentController::class, 'pdf'])->whereUuid('document')->middleware('throttle:60,1')->name('sales.pdf');
    Route::get('sales/{document}/ticket', [SalesDocumentController::class, 'ticket'])->whereUuid('document')->middleware('throttle:60,1')->name('sales.ticket');
    Route::post('sales/{document}/share', [DocumentShareController::class, 'link'])->whereUuid('document')->middleware('throttle:30,1')->name('sales.share');
    Route::post('sales/{document}/email', [DocumentShareController::class, 'email'])->whereUuid('document')->middleware('throttle:10,1')->name('sales.email');

    // Hors connexion (PWA) : appareils et synchronisation
    Route::post('sync/devices', [SyncController::class, 'register'])->middleware('throttle:30,1')->name('sync.devices.register');
    Route::get('sync/catalog', [SyncController::class, 'catalog'])->middleware('throttle:60,1')->name('sync.catalog');
    Route::post('sync/commands', [SyncController::class, 'commands'])->middleware('throttle:60,1')->name('sync.commands');
    Route::inertia('sync', 'sync/Queue')->name('sync.index');
    Route::get('settings/devices', [SyncController::class, 'devices'])->name('sync.devices.index');
    Route::post('settings/devices/{device}/revoke', [SyncController::class, 'revoke'])->whereUuid('device')->name('sync.devices.revoke');

    // Caisse
    Route::get('cash', [CashController::class, 'index'])->name('cash.index');
    Route::post('cash/registers', [CashController::class, 'storeRegister'])->name('cash.registers.store');
    Route::post('cash/registers/{register}/open', [CashController::class, 'open'])->whereUuid('register')->name('cash.open');
    Route::get('cash/sessions/{session}', [CashController::class, 'show'])->whereUuid('session')->name('cash.sessions.show');
    Route::post('cash/sessions/{session}/movements', [CashController::class, 'movement'])->whereUuid('session')->name('cash.movements.store');
    Route::post('cash/sessions/{session}/close', [CashController::class, 'close'])->whereUuid('session')->name('cash.close');

    // Achats
    Route::prefix('purchases')->name('purchases.')->group(function () {
        Route::get('orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
        Route::get('orders/create', [PurchaseOrderController::class, 'create'])->name('orders.create');
        Route::post('orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{order}', [PurchaseOrderController::class, 'show'])->whereUuid('order')->name('orders.show');
        Route::get('orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->whereUuid('order')->name('orders.edit');
        Route::put('orders/{order}', [PurchaseOrderController::class, 'update'])->whereUuid('order')->name('orders.update');
        Route::post('orders/{order}/confirm', [PurchaseOrderController::class, 'confirm'])->whereUuid('order')->name('orders.confirm');
        Route::post('orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->whereUuid('order')->name('orders.cancel');
        Route::post('orders/{order}/receive', [PurchaseOrderController::class, 'receive'])->whereUuid('order')->name('orders.receive');

        Route::get('requests', [PurchaseRequestController::class, 'index'])->name('requests.index');
        Route::get('requests/create', [PurchaseRequestController::class, 'create'])->name('requests.create');
        Route::post('requests', [PurchaseRequestController::class, 'store'])->name('requests.store');
        Route::get('requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->whereUuid('purchaseRequest')->name('requests.show');
        Route::get('requests/{purchaseRequest}/edit', [PurchaseRequestController::class, 'edit'])->whereUuid('purchaseRequest')->name('requests.edit');
        Route::put('requests/{purchaseRequest}', [PurchaseRequestController::class, 'update'])->whereUuid('purchaseRequest')->name('requests.update');
        Route::post('requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->whereUuid('purchaseRequest')->name('requests.submit');
        Route::post('requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->whereUuid('purchaseRequest')->name('requests.approve');
        Route::post('requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->whereUuid('purchaseRequest')->name('requests.reject');
        Route::post('requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel'])->whereUuid('purchaseRequest')->name('requests.cancel');
        Route::post('requests/{purchaseRequest}/convert', [PurchaseRequestController::class, 'convert'])->whereUuid('purchaseRequest')->middleware('throttle:20,1')->name('requests.convert');

        Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->whereUuid('receipt')->name('receipts.show');
        Route::post('receipts/{receipt}/returns', [ReceiptController::class, 'storeReturn'])->whereUuid('receipt')->name('receipts.returns.store');

        Route::get('invoices', [SupplierInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/create', [SupplierInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('invoices', [SupplierInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [SupplierInvoiceController::class, 'show'])->whereUuid('invoice')->name('invoices.show');
        Route::get('invoices/{invoice}/edit', [SupplierInvoiceController::class, 'edit'])->whereUuid('invoice')->name('invoices.edit');
        Route::put('invoices/{invoice}', [SupplierInvoiceController::class, 'update'])->whereUuid('invoice')->name('invoices.update');
        Route::post('invoices/{invoice}/validate', [SupplierInvoiceController::class, 'validateInvoice'])->whereUuid('invoice')->name('invoices.validate');
        Route::post('invoices/{invoice}/cancel', [SupplierInvoiceController::class, 'cancel'])->whereUuid('invoice')->name('invoices.cancel');
        Route::post('invoices/{invoice}/payments', [SupplierInvoiceController::class, 'pay'])->whereUuid('invoice')->name('invoices.payments.store');
    });

    // Comptabilité : réservée aux formules « Compta »
    Route::prefix('accounting')->name('accounting.')->middleware('feature:accounting')->group(function () {
        Route::get('/', [AccountingController::class, 'index'])->name('index');
        Route::post('setup', [AccountingController::class, 'initialize'])->name('setup');
        Route::post('post-pending', [AccountingController::class, 'postPending'])->middleware('throttle:10,1')->name('post_pending');
        Route::post('expert', [AccountingController::class, 'expert'])->name('expert');
        Route::post('periods/{period}', [AccountingController::class, 'period'])->whereUuid('period')->name('periods.update');
        Route::post('years', [AccountingController::class, 'createYear'])->name('years.store');
        Route::post('years/{year}/close', [AccountingController::class, 'closeYear'])->whereUuid('year')->name('years.close');

        Route::get('entries', [JournalEntryController::class, 'index'])->name('entries.index');
        Route::get('entries/create', [JournalEntryController::class, 'create'])->name('entries.create');
        Route::post('entries', [JournalEntryController::class, 'store'])->name('entries.store');
        Route::get('entries/{entry}', [JournalEntryController::class, 'show'])->whereUuid('entry')->name('entries.show');
        Route::get('entries/{entry}/edit', [JournalEntryController::class, 'edit'])->whereUuid('entry')->name('entries.edit');
        Route::put('entries/{entry}', [JournalEntryController::class, 'update'])->whereUuid('entry')->name('entries.update');
        Route::delete('entries/{entry}', [JournalEntryController::class, 'destroy'])->whereUuid('entry')->name('entries.destroy');
        Route::post('entries/{entry}/post', [JournalEntryController::class, 'post'])->whereUuid('entry')->name('entries.post');
        Route::post('entries/{entry}/reverse', [JournalEntryController::class, 'reverse'])->whereUuid('entry')->name('entries.reverse');
        Route::post('entries/{entry}/attachments', [JournalEntryController::class, 'attach'])->whereUuid('entry')->middleware('throttle:30,1')->name('entries.attachments.store');
        Route::get('entries/{entry}/attachments/{attachment}', [JournalEntryController::class, 'download'])->whereUuid(['entry', 'attachment'])->name('entries.attachments.show');
        Route::delete('entries/{entry}/attachments/{attachment}', [JournalEntryController::class, 'detach'])->whereUuid(['entry', 'attachment'])->name('entries.attachments.destroy');

        Route::get('accounts', [ChartController::class, 'index'])->name('accounts.index');
        Route::post('accounts', [ChartController::class, 'store'])->name('accounts.store');
        Route::put('accounts/{account}', [ChartController::class, 'update'])->whereUuid('account')->name('accounts.update');
        Route::post('mappings', [ChartController::class, 'mapping'])->name('mappings.update');

        Route::get('reports', [ReportController::class, 'index'])->name('reports');

        Route::get('reconciliations', [ReconciliationController::class, 'index'])->name('reconciliations.index');
        Route::post('reconciliations', [ReconciliationController::class, 'store'])->name('reconciliations.store');
        Route::post('reconciliations/{reconciliation}/lines', [ReconciliationController::class, 'toggle'])->whereUuid('reconciliation')->name('reconciliations.lines');
        Route::post('reconciliations/{reconciliation}/complete', [ReconciliationController::class, 'complete'])->whereUuid('reconciliation')->name('reconciliations.complete');
    });

    // Tiers
    foreach (['customers' => 'customer', 'suppliers' => 'supplier'] as $path => $party) {
        Route::get("{$path}/import", [PartyImportController::class, 'create'])->defaults('party', $party)->name("{$path}.import.create");
        Route::get("{$path}/import/template", [PartyImportController::class, 'template'])->defaults('party', $party)->name("{$path}.import.template");
        Route::post("{$path}/import", [PartyImportController::class, 'store'])->defaults('party', $party)->middleware('throttle:10,1')->name("{$path}.import.store");
        Route::get("{$path}/import/{import}", [PartyImportController::class, 'show'])->defaults('party', $party)->whereUuid('import')->name("{$path}.import.show");
        Route::post("{$path}/import/{import}/apply", [PartyImportController::class, 'apply'])->defaults('party', $party)->whereUuid('import')->name("{$path}.import.apply");
        Route::get($path, [PartyController::class, 'index'])->defaults('party', $party)->name("{$path}.index");
        Route::post($path, [PartyController::class, 'store'])->defaults('party', $party)->name("{$path}.store");
        Route::put("{$path}/{party}", [PartyController::class, 'update'])->defaults('party', $party)->whereUuid('party')->name("{$path}.update");
        Route::post("{$path}/{party}/archive", [PartyController::class, 'archive'])->defaults('party', $party)->whereUuid('party')->name("{$path}.archive");
    }
});

// Console plateforme (super-administrateur) : aucun contexte d'entreprise.
Route::bind('platformSubscription', fn (string $value) => TenantController::resolveSubscription($value));

Route::middleware(['auth', 'verified', 'mfa.privileged'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('/', PlatformDashboardController::class)->name('dashboard');
    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])->whereUuid('tenant')->name('tenants.show');
    Route::post('tenants/{tenant}/members/{membership}/resend', [TenantController::class, 'resendInvitation'])->whereUuid('tenant')->whereUuid('membership')->name('members.resend');
    Route::post('tenants/{tenant}/members/{membership}/suspend', [TenantController::class, 'suspendMember'])->whereUuid('tenant')->whereUuid('membership')->name('members.suspend');
    Route::post('tenants/{tenant}/members/{membership}/reactivate', [TenantController::class, 'reactivateMember'])->whereUuid('tenant')->whereUuid('membership')->name('members.reactivate');
    Route::post('subscriptions/{platformSubscription}', [TenantController::class, 'updateSubscription'])
        ->middleware('throttle:30,1')
        ->name('subscriptions.update');
    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::get('billing', [BillingConsoleController::class, 'index'])->name('billing.index');
    Route::post('billing/payments/{payment}/confirm', [BillingConsoleController::class, 'confirmTransfer'])->whereUuid('payment')->name('billing.payments.confirm');
    Route::post('billing/invoices/{invoice}/void', [BillingConsoleController::class, 'voidInvoice'])->whereUuid('invoice')->name('billing.invoices.void');
    Route::get('billing/payments/{payment}/proof', [BillingConsoleController::class, 'proof'])->whereUuid('payment')->name('billing.payments.proof');
    Route::post('billing/payments/{payment}/reject', [BillingConsoleController::class, 'rejectPayment'])->whereUuid('payment')->name('billing.payments.reject');
    Route::post('billing/invoices/{invoice}/credit-notes', [BillingConsoleController::class, 'issueCreditNote'])->whereUuid('invoice')->middleware('throttle:20,1')->name('billing.credit-notes.store');
    Route::get('billing/invoices/{invoice}/receipt', [BillingConsoleController::class, 'receipt'])->whereUuid('invoice')->name('billing.invoices.receipt');
    Route::get('billing/credit-notes/{creditNote}', [BillingConsoleController::class, 'creditNote'])->whereUuid('creditNote')->name('billing.credit-notes.show');
    Route::get('billing/export', [BillingConsoleController::class, 'export'])->name('billing.export');
    Route::put('tenants/{tenant}/profile', [TenantProfileController::class, 'update'])->whereUuid('tenant')->name('tenants.profile.update');
    Route::post('tenants/{tenant}/profile/images/{slot}', [TenantProfileController::class, 'uploadImage'])->whereUuid('tenant')->whereIn('slot', ['logo', 'cover'])->middleware('throttle:20,1')->name('tenants.profile.images.store');
    Route::delete('tenants/{tenant}/profile/images/{slot}', [TenantProfileController::class, 'removeImage'])->whereUuid('tenant')->whereIn('slot', ['logo', 'stamp', 'cover'])->name('tenants.profile.images.destroy');
    Route::get('tenants/{tenant}/profile/images/{slot}', [TenantProfileController::class, 'image'])->whereUuid('tenant')->whereIn('slot', ['logo', 'cover'])->middleware('signed')->name('tenants.profile.images.show');
    Route::post('tenants/{tenant}/support', [SupportController::class, 'grant'])->whereUuid('tenant')->middleware('throttle:10,1')->name('support.grant');
    Route::get('tenants/{tenant}/support', [SupportController::class, 'show'])->whereUuid('tenant')->name('support.show');
    Route::post('tenants/{tenant}/support/revoke', [SupportController::class, 'revoke'])->whereUuid('tenant')->name('support.revoke');
});

// Prestataire de paiement de démonstration (désactivé en production).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('billing/sandbox/{reference}', [SandboxCheckoutController::class, 'show'])->name('billing.sandbox.show');
    Route::post('billing/sandbox/{reference}', [SandboxCheckoutController::class, 'decide'])->middleware('throttle:20,1')->name('billing.sandbox.decide');
});

// Webhooks des prestataires : sans session, authentifiés par signature.
// Document partagé (WhatsApp, email) : lien signé et temporaire, sans compte.
Route::get('d/{document}', [DocumentShareController::class, 'show'])->whereUuid('document')
    ->middleware(['signed', 'throttle:30,1'])->name('documents.shared');

Route::post('webhooks/billing/{provider}', WebhookController::class)
    ->where('provider', '[a-z_]+')
    ->middleware('throttle:120,1')
    ->name('webhooks.billing');

require __DIR__.'/settings.php';
