<?php

namespace App\Http\Requests\Simple;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saisie courte de l'article depuis le mode simple.
 * Le SKU, le type et la taxe ne sont pas exposés : le serveur les fixe.
 */
class SimpleProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'sale_price' => ['required', 'integer', 'min:0', 'max:9999999999999'],
            'unit_id' => ['required', 'uuid', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'category_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom de l’article',
            'sale_price' => 'prix de vente',
            'unit_id' => 'vendu par',
            'category_id' => 'famille',
            'image' => 'photo',
        ];
    }
}
