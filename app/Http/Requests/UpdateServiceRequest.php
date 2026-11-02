<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('service'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services', 'name')->ignore($this->route('service')),
            ],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:99999999.99'],
        ];
    }
}
