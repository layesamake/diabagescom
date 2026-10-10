<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCompanyRequest extends FormRequest
{
    /** Pays de l'espace OHADA ouverts au lancement (Sénégal par défaut). */
    public const array COUNTRIES = ['SN'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'warehouse_code' => mb_strtoupper(trim((string) $this->input('warehouse_code'))),
            'legal_name' => trim((string) $this->input('legal_name')),
            'display_name' => trim((string) ($this->input('display_name') ?: $this->input('legal_name'))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'min:2', 'max:160'],
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'country_code' => ['required', Rule::in(self::COUNTRIES)],
            'timezone' => ['required', 'timezone:all'],
            'warehouse_name' => ['required', 'string', 'min:2', 'max:80'],
            'warehouse_code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,15}$/'],
            'warehouse_address' => ['nullable', 'string', 'max:500'],
            'plan_code' => ['required', 'string', Rule::exists('plans', 'code')->where('active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'legal_name' => __('onboarding.fields.legal_name'),
            'display_name' => __('onboarding.fields.display_name'),
            'country_code' => __('onboarding.fields.country'),
            'timezone' => __('onboarding.fields.timezone'),
            'warehouse_name' => __('onboarding.fields.warehouse_name'),
            'warehouse_code' => __('onboarding.fields.warehouse_code'),
            'warehouse_address' => __('onboarding.fields.warehouse_address'),
            'plan_code' => __('onboarding.fields.plan'),
        ];
    }
}
