<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditLog;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Règles métier du catalogue : création, modification, archivage, codes-barres, image.
 *
 * - Changer un prix de vente ou un coût exige catalog.price_override (docs/02, docs/13).
 * - Verrou optimiste par version : une modification concurrente n'écrase rien.
 * - Un code-barres ne désigne qu'un article dans l'entreprise (contrainte base + message clair).
 * - Toute mutation vérifie l'abonnement et est auditée avec avant/après.
 */
final class ProductService
{
    /** Champs dont le changement est audité. */
    private const array AUDITED = ['sku', 'name', 'type', 'category_id', 'unit_id', 'tax_rate_id', 'sale_price', 'standard_cost', 'active'];

    public const string IMAGE_DISK = ImageStore::DISK;

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly AuditLog $audit,
        private readonly Sequences $sequences,
        private readonly ImageStore $images,
    ) {}

    /**
     * @param  array{sku: string, name: string, description?: string|null, type: string, category_id?: string|null, unit_id: string, tax_rate_id?: string|null, sale_price: int, standard_cost?: int|null, barcode?: string|null, generate_internal_barcode?: bool}  $data
     */
    public function create(array $data, User $actor): Product
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($data): Product {
            $product = new Product;
            $this->assign($product, $data);
            $product->tax_rate_id = $data['tax_rate_id'] ?? $this->defaultTaxRateId();
            $this->saveUnique($product);

            if (! empty($data['barcode'])) {
                $this->attachBarcode($product, (string) $data['barcode'], primary: true);
            } elseif (! empty($data['generate_internal_barcode'])) {
                $this->generateInternalBarcode($product);
            }

            $this->audit->record('product.created', $product, after: $product->only(self::AUDITED));

            return $product;
        });
    }

    /**
     * Création depuis le mode simple : la référence interne est attribuée dans
     * la transaction, pour ne jamais exposer ni dupliquer un SKU à l'écran.
     *
     * @param  array{name: string, sale_price: int, unit_id: string, category_id?: string|null}  $data
     */
    public function createSimple(array $data, User $actor): Product
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(fn (): Product => $this->create([
            'sku' => $this->sequences->formatted('simple_product_sku', 'ART'),
            'name' => $data['name'],
            'type' => 'stockable',
            'category_id' => $data['category_id'] ?? null,
            'unit_id' => $data['unit_id'],
            'sale_price' => $data['sale_price'],
        ], $actor));
    }

    /**
     * @param  array{sku: string, name: string, description?: string|null, type: string, category_id?: string|null, unit_id: string, tax_rate_id: string, sale_price: int, standard_cost?: int|null}  $data
     */
    public function update(Product $product, array $data, int $expectedVersion, User $actor): Product
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($product, $data, $expectedVersion, $actor): Product {
            /** @var Product $locked */
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => __('catalog.product.stale')]);
            }

            $before = $locked->only(self::AUDITED);
            $this->assign($locked, $data);
            $locked->tax_rate_id = $data['tax_rate_id'];

            if ($locked->isDirty(['sale_price', 'standard_cost']) && Gate::forUser($actor)->denies('catalog.price_override')) {
                throw new AuthorizationException(__('catalog.product.price_override_required'));
            }

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->version++;
            $this->saveUnique($locked);

            $after = $locked->only(self::AUDITED);
            $this->audit->record('product.updated', $locked,
                before: array_intersect_key($before, array_diff_assoc($after, $before)),
                after: array_diff_assoc($after, $before));

            return $locked;
        });
    }

    public function setArchived(Product $product, bool $archived): Product
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($product, $archived): Product {
            $product->archived_at = $archived ? now() : null;
            $product->active = ! $archived;
            $product->version++;
            $this->saveUnique($product);

            $this->audit->record($archived ? 'product.archived' : 'product.restored', $product);

            return $product;
        });
    }

    public function addBarcode(Product $product, string $raw, ?string $symbology = null, bool $primary = false): ProductBarcode
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(fn () => $this->attachBarcode($product, $raw, $symbology, $primary));
    }

    public function generateInternalBarcode(Product $product): ProductBarcode
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($product): ProductBarcode {
            $barcode = Barcode::internal($this->sequences->next('internal_barcode'));

            return $this->attachBarcode($product, $barcode->code, Barcode::INTERNAL, primary: ! $product->barcodes()->exists());
        });
    }

    public function removeBarcode(ProductBarcode $barcode): void
    {
        $this->access->assertCanWrite($this->context->tenant());

        DB::transaction(function () use ($barcode): void {
            $product = $barcode->product;
            $barcode->delete();

            // Le premier code restant devient principal.
            if ($barcode->is_primary) {
                $product->barcodes()->oldest()->first()?->update(['is_primary' => true]);
            }

            $this->audit->record('product.barcode_removed', $product, before: ['barcode' => $barcode->code]);
        });
    }

    /**
     * Remplace (ou retire, si $file est null) la photo de l'article.
     */
    public function setImage(Product $product, ?UploadedFile $file): Product
    {
        $this->access->assertCanWrite($this->context->tenant());

        $path = $file !== null ? $this->images->store($file, "tenants/{$product->tenant_id}/products") : null;
        $previous = $product->image_path;

        try {
            DB::transaction(function () use ($product, $path): void {
                $product->image_path = $path;
                $product->version++;
                $this->saveUnique($product);
                $this->audit->record($path === null ? 'product.image_removed' : 'product.image_changed', $product);
            });
        } catch (\Throwable $e) {
            $this->images->delete($path);

            throw $e;
        }

        $this->images->delete($previous);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assign(Product $product, array $data): void
    {
        if (! in_array($data['type'], Product::TYPES, true)) {
            throw new InvalidArgumentException('Unknown product type.');
        }

        $product->sku = trim((string) $data['sku']);
        $product->name = trim((string) $data['name']);
        $product->description = $data['description'] ?? null;
        $product->type = (string) $data['type'];
        $product->category_id = $data['category_id'] ?? null;
        $product->unit_id = (string) $data['unit_id'];
        $product->sale_price = (int) $data['sale_price'];
        $product->standard_cost = (int) ($data['standard_cost'] ?? 0);
    }

    private function attachBarcode(Product $product, string $raw, ?string $symbology = null, bool $primary = false): ProductBarcode
    {
        try {
            $barcode = Barcode::parse($raw, $symbology);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['barcode' => $e->getMessage()]);
        }

        $owner = ProductBarcode::query()->with('product:id,name')->whereRaw('upper(code) = upper(?)', [$barcode->code])->first();

        if ($owner !== null) {
            throw ValidationException::withMessages(['barcode' => __('catalog.barcode.already_used', ['product' => $owner->product->name])]);
        }

        if ($primary) {
            $product->barcodes()->update(['is_primary' => false]);
        }

        try {
            $created = $product->barcodes()->create([
                'code' => $barcode->code,
                'symbology' => $barcode->symbology,
                'is_primary' => $primary,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Création concurrente du même code : la base tranche.
            throw ValidationException::withMessages(['barcode' => __('catalog.barcode.already_used', ['product' => '?'])]);
        }

        $this->audit->record('product.barcode_added', $product, after: ['barcode' => $barcode->code, 'symbology' => $barcode->symbology]);

        return $created;
    }

    /**
     * Copie un article existant : nouvelle fiche, nouveau SKU unique et photo
     * dupliquée. Les codes-barres ne sont jamais recopiés (un code ne désigne
     * qu'un seul article). Pensé pour accélérer la saisie d'articles voisins
     * (variantes de conditionnement, de couleur…).
     */
    public function duplicate(Product $source, User $actor): Product
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($source): Product {
            $copy = new Product;
            $copy->sku = $this->uniqueSku($source->sku);
            $copy->name = trim($source->name.' '.__('catalog.product.duplicate_suffix'));
            $copy->description = $source->description;
            $copy->type = $source->type;
            $copy->category_id = $source->category_id;
            $copy->unit_id = $source->unit_id;
            $copy->tax_rate_id = $source->tax_rate_id;
            $copy->sale_price = $source->sale_price;
            $copy->standard_cost = $source->standard_cost;
            $copy->image_path = $source->image_path !== null
                ? $this->images->copy($source->image_path, "tenants/{$source->tenant_id}/products")
                : null;

            $this->saveUnique($copy);

            $this->audit->record('product.duplicated', $copy,
                after: [...$copy->only(self::AUDITED), 'source_product_id' => $source->id]);

            return $copy;
        });
    }

    /**
     * Le SKU est unique par entreprise sans tenir compte de la casse (index PostgreSQL) :
     * un doublon, même concurrent, devient une erreur de saisie compréhensible.
     */
    private function saveUnique(Product $product): void
    {
        try {
            $product->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => __('catalog.product.sku_taken')]);
        }
    }

    private function defaultTaxRateId(): string
    {
        return (string) (TaxRate::query()->where('is_default', true)->value('id')
            ?? throw ValidationException::withMessages(['tax_rate_id' => __('catalog.product.no_default_tax')]));
    }

    /**
     * SKU-COPIE, puis SKU-COPIE-2, SKU-COPIE-3… en respectant la limite de 40
     * caractères et l'unicité (sans tenir compte de la casse).
     */
    private function uniqueSku(string $base): string
    {
        $base = Str::upper(trim($base));
        $suffix = '-COPIE';
        $attempt = 1;

        do {
            $currentSuffix = $attempt === 1 ? $suffix : "{$suffix}-{$attempt}";
            $candidate = substr($base, 0, 40 - strlen($currentSuffix)).$currentSuffix;
            $attempt++;
        } while (Product::query()->whereRaw('upper(sku) = upper(?)', [$candidate])->exists());

        return $candidate;
    }
}
