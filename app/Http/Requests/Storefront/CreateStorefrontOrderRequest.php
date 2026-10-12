<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStorefrontOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'website' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'min:2', 'max:160'],
            'customer_phone' => ['required', 'string', 'min:6', 'max:32'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'delivery_address' => ['nullable', 'string', 'max:300'],
            'fulfillment' => ['required', Rule::in(['pickup', 'delivery'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
        ];
    }
}
