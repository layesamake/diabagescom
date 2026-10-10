<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\ProductCategory;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Référentiels du catalogue : catégories (arbre), unités et taux de taxe.
 * Les contraintes PostgreSQL (unicité, cycles) sont traduites en messages clairs.
 */
final class ReferenceDataService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly AuditLog $audit,
        private readonly ImageStore $images,
    ) {}

    /**
     * @param  array{name: string, parent_id?: string|null}  $data
     */
    public function saveCategory(?ProductCategory $category, array $data): ProductCategory
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($category, $data): ProductCategory {
            $category ??= new ProductCategory;
            $isNew = ! $category->exists;
            $category->name = trim($data['name']);
            $category->parent_id = $data['parent_id'] ?? null;

            try {
                $category->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('catalog.category.name_taken')]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23514') {
                    throw ValidationException::withMessages(['parent_id' => __('catalog.category.cycle')]);
                }

                throw $e;
            }

            $this->audit->record($isNew ? 'category.created' : 'category.updated', $category, after: $category->only(['name', 'parent_id']));

            return $category;
        });
    }

    /**
     * Remplace (ou retire, si $file est null) la photo de la catégorie.
     */
    public function setCategoryImage(ProductCategory $category, ?UploadedFile $file): ProductCategory
    {
        $this->access->assertCanWrite($this->context->tenant());

        $path = $file !== null ? $this->images->store($file, "tenants/{$category->tenant_id}/categories") : null;
        $previous = $category->image_path;

        try {
            DB::transaction(function () use ($category, $path): void {
                $category->image_path = $path;
                $category->version++;
                $category->save();
                $this->audit->record($path === null ? 'category.image_removed' : 'category.image_changed', $category);
            });
        } catch (\Throwable $e) {
            $this->images->delete($path);

            throw $e;
        }

        $this->images->delete($previous);

        return $category;
    }

    /**
     * @param  array{code: string, name: string, decimals_allowed: bool}  $data
     */
    public function saveUnit(?Unit $unit, array $data): Unit
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($unit, $data): Unit {
            $unit ??= new Unit;
            $isNew = ! $unit->exists;
            $unit->fill(['code' => mb_strtoupper(trim($data['code'])), 'name' => trim($data['name']), 'decimals_allowed' => $data['decimals_allowed']]);

            try {
                $unit->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['code' => __('catalog.unit.code_taken')]);
            }

            $this->audit->record($isNew ? 'unit.created' : 'unit.updated', $unit, after: $unit->only(['code', 'name', 'decimals_allowed']));

            return $unit;
        });
    }

    /**
     * @param  array{name: string, rate_basis_points: int, is_default: bool, active: bool}  $data
     */
    public function saveTaxRate(?TaxRate $tax, array $data): TaxRate
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($tax, $data): TaxRate {
            $tax ??= new TaxRate;
            $isNew = ! $tax->exists;
            $before = $tax->only(['name', 'rate_basis_points', 'is_default', 'active']);

            if ($data['is_default']) {
                TaxRate::query()
                    ->where('is_default', true)
                    ->when($tax->exists, fn ($q) => $q->whereKeyNot($tax->id))
                    ->update(['is_default' => false]);
            }

            $tax->fill([...$data, 'name' => trim($data['name'])]);

            try {
                $tax->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('catalog.tax.name_taken')]);
            }

            // Les taux appliqués sont figés dans les documents validés : modifier un taux
            // n'affecte que les opérations futures (docs/05).
            $this->audit->record($isNew ? 'tax_rate.created' : 'tax_rate.updated', $tax,
                before: $isNew ? null : $before,
                after: $tax->only(['name', 'rate_basis_points', 'is_default', 'active']));

            return $tax;
        });
    }
}
