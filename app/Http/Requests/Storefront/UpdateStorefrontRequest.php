<?php

namespace App\Http\Requests\Storefront;

use App\Domain\Tenancy\TenantContext;
use App\Models\Storefront;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStorefrontRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $storefrontId = Storefront::query()
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->value('id');

        return [
            'slug' => ['required', 'string', 'min:3', 'max:100', 'alpha_dash', Rule::unique('storefronts', 'slug')->ignore($storefrontId)],
            'enabled' => ['sometimes', 'boolean'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:200'],
            'opening_hours' => ['nullable', 'string', 'max:500'],
            'accepts_orders' => ['sometimes', 'boolean'],
            'delivery_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
