<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saisie d'un article. Les références (catégorie, unité, taxe) doivent appartenir
 * à l'entreprise active ; les montants sont des entiers XOF.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorisation vérifiée dans le contrôleur (création / modification).
    }

    protected function prepareForValidation(): void
    {
        foreach (['sale_price', 'standard_cost'] as $amount) {
            if (is_string($this->input($amount))) {
                $this->merge([$amount => preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $this->input($amount))]);
            }
        }

        $this->merge(['sku' => trim((string) $this->input('sku'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        /** @var Product|null $product */
        $product = $this->route('product');
        $inTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        return [
            'sku' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,39}$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($product): void {
                    $taken = Product::query()
                        ->whereRaw('upper(sku) = upper(?)', [(string) $value])
                        ->when($product, fn ($q) => $q->whereKeyNot($product->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('catalog.product.sku_taken'));
                    }
                },
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in(Product::TYPES)],
            'category_id' => ['nullable', 'uuid', $inTenant('product_categories')],
            'unit_id' => ['required', 'uuid', $inTenant('units')],
            'tax_rate_id' => [$product ? 'required' : 'nullable', 'uuid', $inTenant('tax_rates')],
            'sale_price' => ['required', 'integer', 'min:0', 'max:9999999999999'],
            'standard_cost' => ['nullable', 'integer', 'min:0', 'max:9999999999999'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'generate_internal_barcode' => ['sometimes', 'boolean'],
            'version' => [$product ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'référence (SKU)',
            'name' => 'nom',
            'type' => 'type',
            'category_id' => 'catégorie',
            'unit_id' => 'unité',
            'tax_rate_id' => 'taxe',
            'sale_price' => 'prix de vente',
            'standard_cost' => 'coût',
            'barcode' => 'code-barres',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sale_price.integer' => 'Le prix de vente est un nombre entier en XOF, sans décimales.',
            'standard_cost.integer' => 'Le coût est un nombre entier en XOF, sans décimales.',
        ];
    }
}
