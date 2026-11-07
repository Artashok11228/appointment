<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Appointment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            // No timezone offset allowed: business hours are defined in the app timezone.
            'starts_at' => ['required', 'date_format:Y-m-d H:i:s,Y-m-d H:i'],
        ];
    }
}
